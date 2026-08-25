#!/usr/bin/env bash
#
# Measures one thing per route: how much throughput a runtime drains when
# everything available is thrown at it, under a fixed CPU/RAM/worker budget.
#
# Closed loop — each virtual user sends, waits for the response, and sends
# again — so throughput limits itself to whatever the server can absorb. An
# open loop at this point would stack up a queue and then measure the queue,
# not the server — reporting a multi-second tail latency for a run where the
# server actually drained less than it did under lighter load.
#
# Each route is measured N times; the report carries the median and the spread
# between samples. The latency budget is evaluated and reported, but never
# searched for: a search spends half its measurements confirming levels that
# were never in doubt, and what is wanted here is the ceiling, not a rate that
# fits a budget.
#
# The runtime is started once and stopped once per sweep.
#
# Usage: sweep.sh <runtime> [route-label ...]
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/../.."

# shellcheck source=benchmarks/scripts/lib.sh
source "$SCRIPT_DIR/lib.sh"

RUNTIME="${1:-}"

if [ -z "$RUNTIME" ]; then
    echo "Usage: $0 <fpm|swoole|roadrunner> [route-label ...]" >&2
    exit 1
fi

shift || true

PUBLIC_SERVICE="$(resolve_public_service "$RUNTIME")"
TARGET_URL="http://${PUBLIC_SERVICE}:8080"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"

# Where results are filed, relative to benchmarks/results.
#
# run-matrix.sh sets this to <run-id>/<runtime>, giving the layout
# <run>/<runtime>/<route>/s<n>.json. Each directory level answers one question
# — which run, which runtime, which resource — so a finished run can be
# navigated by someone who was not there when it ran. A standalone sweep falls
# back to a directory of its own, so it never scatters files into a matrix run.
SWEEP_OUTPUT_DIR="${SWEEP_OUTPUT_DIR:-sweep-${RUNTIME}-${TIMESTAMP}}"
SWEEP_RESULT_PATH="benchmarks/results/${SWEEP_OUTPUT_DIR}/summary.json"
WORK_DIR="$(mktemp -d)"

mkdir -p "benchmarks/results/${SWEEP_OUTPUT_DIR}"

SWEEP_STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
SWEEP_STARTED_EPOCH="$(date +%s)"

# Routes to sweep: the labels given as arguments, or every benchmark route in
# routes.json (health is not a workload).
if [ "$#" -gt 0 ]; then
    ROUTE_LABELS=("$@")
else
    mapfile -t ROUTE_LABELS < <(
        grep -oE '"label"[[:space:]]*:[[:space:]]*"[^"]+"' routes.json |
            sed -E 's/.*"([^"]+)"$/\1/' |
            grep -v '^health$'
    )
fi


SAMPLES_PER_ROUTE="${SAMPLES_PER_ROUTE:-$(sed -n 's/.*"samples_per_route"[[:space:]]*:[[:space:]]*\([0-9]*\).*/\1/p' performance.json)}"
SAMPLES_PER_ROUTE="${SAMPLES_PER_ROUTE:-3}"

echo "Sweeping $RUNTIME over routes: ${ROUTE_LABELS[*]}"
echo "Samples per route: ${SAMPLES_PER_ROUTE} (median reported)"
export_worker_budget
echo

# Refuses to start if another benchmark is already using this machine.
#
# Two sweeps running at once do not fail loudly — they quietly measure each
# other's contention. A sweep left running against one runtime silently caps
# whatever a second sweep reports for another, with nothing in the output
# saying why. Contention is invisible in the numbers, so it has to be refused
# before it starts.
assert_machine_is_free() {
    local busyContainers
    busyContainers="$(docker ps --format '{{.Names}}' 2>/dev/null | grep -E '^php-runtime-lab-(app|nginx|k6)' || true)"

    if [ -n "$busyContainers" ]; then
        echo "Refusing to start: a benchmark is already running on this machine." >&2
        echo "$busyContainers" | sed 's/^/  /' >&2
        echo "Stop it first — concurrent runs measure each other, not the runtime." >&2
        exit 1
    fi
}

assert_machine_is_free

docker compose --profile "$RUNTIME" up -d --build

cleanup() {
    docker compose --profile "$RUNTIME" down
    rm -rf "$WORK_DIR"
}
trap cleanup EXIT

HOST_PORT="$(published_host_port "$RUNTIME" "$PUBLIC_SERVICE")"
wait_until_healthy "http://localhost:${HOST_PORT}/"

capture_idle_footprint "$RUNTIME"
echo

collect_k6_overrides

# The load profile that saturates: constant virtual users, no arrival rate to
# hit. It is the only profile this script uses — the open-loop profiles are
# reachable through run.sh, which exists for exploring a specific load level.
OVERLOAD_PROFILE='overload'

# No arrival rate to hold. The closed loop finds its own rate, so k6 needs a
# target only because the open-loop profiles share the same entry point.
NO_TARGET_RPS=0

# Measures one route once; echoes the result filename on success.
measure_route() {
    local routeLabel="$1"
    local sample="$2"
    local resultFile="${SWEEP_OUTPUT_DIR}/${routeLabel}/s${sample}.json"

    mkdir -p "benchmarks/results/${SWEEP_OUTPUT_DIR}/${routeLabel}"

    # Utilisation is sampled across the k6 run so the reported throughput can be
    # read together with whether anything in the path was pegged.
    start_utilization_sampler "$RUNTIME" "$WORK_DIR/stats.raw"

    set +e
    docker compose --profile bench run --rm \
        -e TARGET_URL="$TARGET_URL" \
        -e RESULT_FILE="$resultFile" \
        -e ONLY_ROUTE="$routeLabel" \
        -e TARGET_RPS="$NO_TARGET_RPS" \
        -e LOAD_PROFILE="$OVERLOAD_PROFILE" \
        ${K6_ENV_ARGS[@]+"${K6_ENV_ARGS[@]}"} \
        k6 run load-test.js > "$WORK_DIR/k6.log" 2>&1
    local exitCode=$?
    set -e

    stop_utilization_sampler

    # Only a genuine k6 malfunction is fatal. A breached threshold is not a
    # failure here: saturating the runtime is the whole point, so the budget
    # being exceeded is the expected outcome rather than an error.
    if [ "$exitCode" -ne 0 ] && [ "$exitCode" -ne "$K6_THRESHOLD_FAILURE_EXIT_CODE" ]; then
        echo "k6 failed (exit ${exitCode}) on route '${routeLabel}', sample ${sample}:" >&2
        tail -20 "$WORK_DIR/k6.log" >&2
        return 1
    fi

    echo "$resultFile"
}

# Pulls one numeric or boolean field out of a result file.
#
# Read from inside the "routes" object, never from the top of the file: k6
# writes run-level keys that collide by name with the per-route ones, and the
# first match in the file is the run-level one. The top-level "error_rate" is
# the measured-phase submetric, which k6 only materialises when a threshold
# references it — and the overload profile declares no thresholds, so reading
# from the top silently records null for a route that in fact measured zero
# errors.
#
# With ONLY_ROUTE set the routes object holds exactly one route, so the first
# match after that line is unambiguous.
#
# A missing field comes back empty rather than killing the sweep. k6 writes
# `"<route>": null` for a window that produced no usable metrics at all, and
# under `set -e` and `pipefail` a non-matching grep here would take the whole
# run down one sample into a route, printing nothing. Whether an empty value
# is tolerable is the caller's decision, not this function's.
read_result_field() {
    local resultFile="$1"
    local field="$2"

    sed -n '/"routes"/,$p' "benchmarks/results/${resultFile}" |
        grep -oE "\"${field}\"[[:space:]]*:[[:space:]]*[^,\"}]+" |
        head -1 |
        sed -E 's/.*:[[:space:]]*//' || true
}

# Reads the runtime's own per-request memory figure, straight from the app.
#
# Probed here rather than collected by the load generator. Parsing it out of
# every response makes k6 itself the bottleneck — even sampling only one
# response in fifty still measurably drags the fastest routes down — and an
# instrument that changes the reading is worse than none.
#
# Nothing is lost by separating them: how much memory a request needs is a
# property of the workload, not of how hard the server is being pushed. Probing
# runs after the measured samples, so the worker is as warm as it will ever be
# and its allocator has long since settled.
#
# A handful of requests rather than one, with the median taken, so a single
# unlucky request that lands on a worker recycle is not the reported figure.
PROBE_REQUEST_COUNT=15

probe_request_memory() {
    local routePath="$1"
    local observations=""

    for _ in $(seq 1 "$PROBE_REQUEST_COUNT"); do
        local body
        body="$(curl -s --max-time 5 "http://localhost:${HOST_PORT}${routePath}" 2>/dev/null || true)"

        local peakBytes
        peakBytes="$(printf '%s' "$body" |
            grep -oE '"request_memory_peak_bytes":[0-9]+' |
            grep -oE '[0-9]+$' || true)"

        if [ -n "$peakBytes" ]; then
            observations="${observations}${peakBytes}"$'\n'
        fi
    done

    # `|| true` because a route that reports nothing must yield an empty
    # result, not take the sweep down with it: grep exits non-zero on no
    # match, and under `set -e` with `pipefail` an unguarded grep here would
    # kill the whole script. The caller substitutes null.
    printf '%s' "$observations" | grep -v '^$' | median || true
}

# Middle value of the numbers on stdin. Used instead of a mean so one stalled
# sample cannot drag the reported latency.
median() {
    sort -g | awk '{ values[NR] = $1 } END { if (NR == 0) { print "null" } else { print values[int((NR + 1) / 2)] } }'
}


SUMMARY_ENTRIES=()

for routeLabel in "${ROUTE_LABELS[@]}"; do
    echo "── route: ${routeLabel}"

    rpsSamples=""
    p50Samples=""
    avgSamples=""
    p95Samples=""
    p99Samples=""
    maxSamples=""
    errorSamples=""
    requestSamples=""
    waitingSamples=""
    blockedSamples=""
    bytesSamples=""
    budgetHeldCount=0

    # When a route was measured, kept per route rather than only per run.
    # Correlating a result with anything recorded outside this script — a host
    # metric, a container log, an unrelated process that woke up mid-run —
    # needs the window it occupied, and a single run-level timestamp cannot
    # give that for a run several hours long.
    routeStartedAt="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    routeStartedEpoch="$(date +%s)"
    : > "$WORK_DIR/stats.raw"

    # Exactly one thing is measured: how much throughput the runtime drains
    # when everything is thrown at it. Closed loop, no RPS target and no
    # latency budget to satisfy.
    #
    # Repeated N times only to get the spread. A single sample cannot tell a
    # real ceiling from scheduler noise, and the spread is what says whether a
    # difference between two runtimes means anything.
    #
    # The latency budget is still evaluated, but as *information* about the
    # state of saturation, not as a pass/fail criterion. That a runtime drains
    # 400 rps with a 12 second p99 is the finding, not an error.
    for sample in $(seq 1 "$SAMPLES_PER_ROUTE"); do
        printf '   sample %s/%s ... ' "$sample" "$SAMPLES_PER_ROUTE"

        resultFile="$(measure_route "$routeLabel" "$sample")"

        sampleRps="$(read_result_field "$resultFile" 'avg_rps')"

        # One barren sample is dropped; it is not fatal. Losing a sample costs
        # a fraction of a route's precision, while dying here costs every route
        # after it.
        if [ -z "$sampleRps" ]; then
            echo "no usable data — sample discarded"
            continue
        fi

        sampleP50="$(read_result_field "$resultFile" 'p50_ms')"

        # Mean latency, kept because it is the only field that makes the run
        # checkable against Little's law: in a closed loop with a fixed
        # virtual-user count, throughput times mean latency has to come back
        # to that count. Percentiles cannot do that.
        sampleAvg="$(read_result_field "$resultFile" 'avg_ms')"
        sampleP95="$(read_result_field "$resultFile" 'p95_ms')"
        sampleP99="$(read_result_field "$resultFile" 'p99_ms')"
        sampleMax="$(read_result_field "$resultFile" 'max_ms')"
        sampleErrors="$(read_result_field "$resultFile" 'error_rate')"
        sampleRequests="$(read_result_field "$resultFile" 'requests')"
        sampleWithinBudget="$(read_result_field "$resultFile" 'within_budget')"

        # Server time and connection-queue time, kept apart. Total latency
        # cannot distinguish a runtime that is slow to answer from one that is
        # quick but has nowhere to accept the connection, and under deliberate
        # overload that is the difference worth knowing.
        sampleWaiting="$(read_result_field "$resultFile" 'waiting_p95_ms')"
        sampleBlocked="$(read_result_field "$resultFile" 'blocked_p95_ms')"
        sampleBytes="$(read_result_field "$resultFile" 'bytes_per_response')"

        waitingSamples="${waitingSamples}${sampleWaiting}"$'\n'
        blockedSamples="${blockedSamples}${sampleBlocked}"$'\n'
        bytesSamples="${bytesSamples}${sampleBytes}"$'\n'

        rpsSamples="${rpsSamples}${sampleRps}"$'\n'
        p50Samples="${p50Samples}${sampleP50}"$'\n'
        avgSamples="${avgSamples}${sampleAvg}"$'\n'
        p95Samples="${p95Samples}${sampleP95}"$'\n'
        p99Samples="${p99Samples}${sampleP99}"$'\n'
        maxSamples="${maxSamples}${sampleMax}"$'\n'
        errorSamples="${errorSamples}${sampleErrors}"$'\n'
        requestSamples="${requestSamples}${sampleRequests}"$'\n'

        if [ "$sampleWithinBudget" = "true" ]; then
            budgetHeldCount=$((budgetHeldCount + 1))
        fi

        printf '%s rps (p95 %sms)\n' "${sampleRps%%.*}" "${sampleP95%%.*}"
    done

    # Probed with the worker at its warmest, right after the measured samples.
    routePath="$(grep -oE '\{[^}]*"label"[[:space:]]*:[[:space:]]*"'"${routeLabel}"'"[^}]*\}' routes.json |
        grep -oE '"path"[[:space:]]*:[[:space:]]*"[^"]+"' |
        grep -oE '"[^"]+"$' | tr -d '"' | head -1)"
    requestMemoryPeak="$(probe_request_memory "$routePath")"

    routeFinishedAt="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    routeDurationSeconds="$(($(date +%s) - routeStartedEpoch))"

    # Every sample for this route came back empty. Recorded as a null result
    # and moved past, because the alternatives are both worse: aggregating
    # nothing writes a malformed summary, and aborting loses the routes that
    # would have worked.
    if [ -z "$(printf '%s' "$rpsSamples" | tr -d '[:space:]')" ]; then
        echo "   => no usable data on '${routeLabel}' after ${SAMPLES_PER_ROUTE} samples." >&2
        SUMMARY_ENTRIES+=("    \"${routeLabel}\": { \
\"throughput_rps\": null, \
\"error\": \"no usable samples\", \
\"samples\": ${SAMPLES_PER_ROUTE}, \
\"started_at\": \"${routeStartedAt}\", \
\"finished_at\": \"${routeFinishedAt}\", \
\"duration_seconds\": ${routeDurationSeconds} }")
        continue
    fi

    ROUTE_UTILIZATION="$(summarize_utilization "$WORK_DIR/stats.raw")"

    medianRps="$(printf '%s' "$rpsSamples" | grep -v '^$' | median)"
    minRps="$(printf '%s' "$rpsSamples" | grep -v '^$' | sort -g | head -1)"
    maxRps="$(printf '%s' "$rpsSamples" | grep -v '^$' | sort -g | tail -1)"
    medianP50="$(printf '%s' "$p50Samples" | grep -v '^$' | median)"
    medianAvg="$(printf '%s' "$avgSamples" | grep -v '^$' | median)"
    medianP95="$(printf '%s' "$p95Samples" | grep -v '^$' | median)"
    medianP99="$(printf '%s' "$p99Samples" | grep -v '^$' | median)"
    medianErrorRate="$(printf '%s' "$errorSamples" | grep -v '^$' | median)"
    medianWaiting="$(printf '%s' "$waitingSamples" | grep -v '^$' | median)"
    medianBlocked="$(printf '%s' "$blockedSamples" | grep -v '^$' | median)"
    medianBytes="$(printf '%s' "$bytesSamples" | grep -v '^$' | median)"
    totalRequests="$(printf '%s' "$requestSamples" | grep -v '^$' | awk '{ total += $1 } END { print total + 0 }')"

    # Worst single request across every sample, not the median of the maxima:
    # the point of a maximum is that it is the worst thing that happened.
    worstMs="$(printf '%s' "$maxSamples" | grep -v '^$' | sort -g | tail -1)"

    # Spread as a fraction of the median: this is what says whether the samples
    # agree. Without it a steady "400 rps" and a noisy "400 rps" are the same
    # number in the file, and only one of them supports a comparison.
    spreadPct="$(awk -v lo="$minRps" -v hi="$maxRps" -v mid="$medianRps" \
        'BEGIN { if (mid > 0) printf "%.1f", (hi - lo) / mid * 100; else print "0" }')"

    budgetVerdict="false"
    if [ "$budgetHeldCount" -ge "$(((SAMPLES_PER_ROUTE / 2) + 1))" ]; then
        budgetVerdict="true"
    fi

    printf '   => %s rps (median of %s | %s-%s, spread %s%%) · p95 %sms · budget: %s\n' \
        "${medianRps%%.*}" "$SAMPLES_PER_ROUTE" "${minRps%%.*}" "${maxRps%%.*}" \
        "$spreadPct" "${medianP95%%.*}" \
        "$([ "$budgetVerdict" = "true" ] && echo "held" || echo "breached")"

    SUMMARY_ENTRIES+=("    \"${routeLabel}\": { \
\"throughput_rps\": ${medianRps}, \"throughput_min_rps\": ${minRps}, \"throughput_max_rps\": ${maxRps}, \
\"spread_pct\": ${spreadPct}, \
\"avg_ms\": ${medianAvg}, \"p50_ms\": ${medianP50}, \"p95_ms\": ${medianP95}, \"p99_ms\": ${medianP99}, \"max_ms\": ${worstMs}, \
\"server_p95_ms\": ${medianWaiting}, \"connect_queue_p95_ms\": ${medianBlocked}, \"bytes_per_response\": ${medianBytes}, \
\"request_memory_peak_bytes\": ${requestMemoryPeak:-null}, \
\"error_rate\": ${medianErrorRate}, \"requests\": ${totalRequests}, \"held_budget\": ${budgetVerdict}, \
\"samples\": ${SAMPLES_PER_ROUTE}, \"started_at\": \"${routeStartedAt}\", \"finished_at\": \"${routeFinishedAt}\", \"duration_seconds\": ${routeDurationSeconds}, \
\"utilization\": ${ROUTE_UTILIZATION} }")

    # A saturated proxy invalidates the number: it would be the proxy's
    # ceiling reported as the runtime's, not a property of the runtime being
    # measured — raising the proxy's own CPU budget changes the figure even
    # though nothing about the runtime changed.
    # Only the FPM variants have a proxy at all. For every other runtime this
    # grep matches nothing and exits non-zero, which under `set -e` and
    # `pipefail` would kill the sweep mid-route with no message whatsoever.
    # The `|| true` is what makes "there is no proxy" an answer rather than a
    # failure; the emptiness is then handled by the check just below.
    proxyCpuLimit="$(awk -v c="$(env_value NGINX_CPUS 2.0)" 'BEGIN { printf "%.0f", c * 100 }')"
    proxyPeakCpu="$(printf '%s' "$ROUTE_UTILIZATION" |
        grep -oE '"nginx[^"]*": \{"peak_cpu_pct": [0-9.]+' |
        grep -oE '[0-9.]+$' |
        head -1 || true)"

    if [ -n "$proxyPeakCpu" ]; then
        isProxySaturated="$(awk -v peak="$proxyPeakCpu" -v limit="$proxyCpuLimit" \
            'BEGIN { print (peak > limit * 0.9) ? "yes" : "no" }')"

        if [ "$isProxySaturated" = "yes" ]; then
            echo "   WARNING: proxy hit ${proxyPeakCpu}% of its ${proxyCpuLimit}% budget on '${routeLabel}'." >&2
            echo "            This number is likely the proxy's ceiling, not the runtime's. Raise NGINX_CPUS." >&2
        fi
    fi

done

# CPU budgets are recorded as whole percent (2.0 cpus -> 200), matching the
# units docker stats itself reports, so a limit and a peak reading in the
# report are directly comparable without a conversion in the reader's head.
appCpuLimitPct="$(awk -v c="$(env_value APP_CPUS 1.0)" 'BEGIN { printf "%.0f", c * 100 }')"
nginxCpuLimitPct="$(awk -v c="$(env_value NGINX_CPUS 2.0)" 'BEGIN { printf "%.0f", c * 100 }')"
k6CpuLimitPct="$(awk -v c="$K6_CPUS" 'BEGIN { printf "%.0f", c * 100 }')"
stubCpuLimitPct="$(awk -v c="$(env_value STUB_CPUS 2.0)" 'BEGIN { printf "%.0f", c * 100 }')"

{
    echo '{'
    echo "  \"runtime\": \"${RUNTIME}\","
    echo "  \"language\": \"$(describe_runtime "$RUNTIME" language)\","
    echo "  \"framework\": \"$(describe_runtime "$RUNTIME" framework)\","
    echo "  \"model\": \"$(describe_runtime "$RUNTIME" model)\","
    echo "  \"started_at\": \"${SWEEP_STARTED_AT}\","
    echo "  \"finished_at\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\","
    echo "  \"duration_seconds\": $(($(date +%s) - SWEEP_STARTED_EPOCH)),"
    echo "  \"app_cpus\": \"$(env_value APP_CPUS 1.0)\","
    echo "  \"app_mem\": \"$(env_value APP_MEM 512m)\","
    echo "  \"app_workers\": ${APP_WORKERS},"
    echo "  \"app_max_requests_per_worker\": ${APP_MAX_REQUESTS},"
    echo "  \"samples_per_route\": ${SAMPLES_PER_ROUTE},"
    echo "  \"php_tuning\": \"$(env_value PHP_TUNING baseline)\","
    echo "  \"cpu_limit_pct\": { \"app\": ${appCpuLimitPct}, \"nginx\": ${nginxCpuLimitPct}, \"k6\": ${k6CpuLimitPct}, \"stub\": ${stubCpuLimitPct} },"
    echo "  \"host\": $(describe_host),"
    echo "  \"idle_footprint\": ${IDLE_FOOTPRINT},"
    echo '  "routes": {'
    printf '%s\n' "$(IFS=$',\n'; echo "${SUMMARY_ENTRIES[*]}")"
    echo '  }'
    echo '}'
} > "$SWEEP_RESULT_PATH"

echo "Sweep summary saved to ${SWEEP_RESULT_PATH}"
