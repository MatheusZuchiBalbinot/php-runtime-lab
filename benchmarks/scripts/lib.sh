#!/usr/bin/env bash
#
# Shared helpers for the benchmark orchestration scripts (run.sh, sweep.sh).
# Sourced, never executed directly.

# Git Bash / MSYS on Windows rewrites arguments that look like absolute POSIX
# paths into Windows paths, so a container path such as /scripts/load-test.js
# reaches Docker as "C:/Program Files/Git/scripts/load-test.js". Scripts pass
# the load test by a relative path (the k6 service declares working_dir), and
# these guards cover the remaining arguments.
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'

readonly RUNTIMES_CONFIG_PATH="benchmarks/runtimes.conf"
readonly HEALTHCHECK_TIMEOUT_SECONDS=30

# Bound on a single healthcheck attempt, so no one attempt can outlive the
# budget above. Kept well under it: a runtime that has not answered in five
# seconds is not answering, and retrying is cheaper than waiting.
readonly HEALTHCHECK_ATTEMPT_TIMEOUT_SECONDS=5

# How many utilisation ticks pass between readings of the host's own load.
# Each reading costs a container start, so it is deliberately infrequent: the
# load worth catching is sustained, and sustained load does not hide between
# samples.
readonly HOST_LOAD_SAMPLE_EVERY=10

# Docker reports memory as a human string ("44.1MiB", "1.2GiB"). Two separate
# awk programs need it as a plain number, so the unit table is defined once here
# and prepended to whichever program needs it rather than written out twice.
readonly AWK_MEBIBYTES='
function toMebibytes(raw,   value, unit, factor) {
    value = raw; sub(/[A-Za-z]+$/, "", value)
    unit  = raw; sub(/^[0-9.]+/, "", unit)

    factor = 1
    if (unit == "GiB") { factor = 1024 }
    else if (unit == "KiB") { factor = 1 / 1024 }
    else if (unit == "B") { factor = 1 / 1048576 }

    return value * factor
}'
readonly K6_THRESHOLD_FAILURE_EXIT_CODE=99

# Reads a setting the way docker compose does: the shell environment wins, then
# .env, then the default.
#
# Compose reads .env on its own, so a container gets the right budget either
# way — but the scripts also *record* these values, and a script that falls
# back to its own hardcoded default records a limit the container never had.
# A mismatch there is not cosmetic: the report cross-checks utilisation
# against the recorded limit, so a wrong recorded limit turns a comfortable
# margin into a false saturation warning, or hides a real one. A false alarm
# is as damaging as a missed one — both teach you to distrust the instrument.
env_value() {
    local key="$1"
    local fallback="$2"
    local fromShell="${!key:-}"

    if [ -n "$fromShell" ]; then
        echo "$fromShell"
        return 0
    fi

    local fromFile
    fromFile="$(grep -E "^${key}=" .env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '\r')"

    echo "${fromFile:-$fallback}"
}

# Removes containers left behind by an interrupted or leaky run.
#
# Load-generator containers are started with `docker run --rm` outside of
# Compose, so `compose down` does not reach them; they survive a Ctrl-C and the
# next run then refuses to start because the machine looks busy.
#
# Called between runtimes as well as at startup: without that, one runtime
# failing to tear itself down cascades, because every runtime after it is
# refused by the busy-machine guard.
remove_orphan_containers() {
    local orphans
    orphans="$(docker ps -aq --filter "ancestor=grafana/k6:latest" 2>/dev/null || true)"

    if [ -n "$orphans" ]; then
        # shellcheck disable=SC2086
        docker rm -f $orphans > /dev/null 2>&1 || true
    fi

    docker compose --profile all down --remove-orphans > /dev/null 2>&1 || true
}

# Reads a numeric setting out of performance.json, falling back if absent.
#
# Keys are matched with their opening quote, so a documentation key such as
# "_measure_seconds" — the convention this file uses to explain the setting
# next to it — is never mistaken for the setting itself.
config_number() {
    local key="$1"
    local fallback="$2"
    local value

    value="$(grep -oE "\"${key}\"[[:space:]]*:[[:space:]]*[0-9.]+" performance.json 2>/dev/null |
        grep -oE '[0-9.]+$' |
        head -1)"

    echo "${value:-$fallback}"
}

# Derives the worker count every runtime must run with, from the CPU budget.
#
# One formula for every runtime is the whole point: with an equal allowance,
# a difference in the results belongs to the concurrency model rather than to a
# number someone picked per runtime. Deriving it from APP_CPUS (instead of
# hardcoding it) keeps the proportion when the budget is varied.
#
# Exports APP_WORKERS for docker compose to read.
export_worker_budget() {
    local appCpus
    appCpus="$(env_value APP_CPUS 1.0)"
    local workersPerCpu

    workersPerCpu="$(sed -n 's/.*"workers_per_cpu"[[:space:]]*:[[:space:]]*\([0-9]*\).*/\1/p' performance.json)"
    workersPerCpu="${workersPerCpu:-4}"

    # awk rather than shell arithmetic because APP_CPUS is fractional (0.5).
    APP_WORKERS="$(awk -v cpus="$appCpus" -v perCpu="$workersPerCpu" \
        'BEGIN { workers = int(cpus * perCpu); if (workers < cpus * perCpu) workers++; if (workers < 1) workers = 1; print workers }')"

    export APP_WORKERS

    # Recycling costs a full bootstrap, so an uneven policy silently penalises
    # whichever runtime recycles more often. One value for every one of them.
    APP_MAX_REQUESTS="$(sed -n 's/.*"max_requests_per_worker"[[:space:]]*:[[:space:]]*\([0-9]*\).*/\1/p' performance.json)"
    APP_MAX_REQUESTS="${APP_MAX_REQUESTS:-500}"
    export APP_MAX_REQUESTS

    # FrankenPHP's worker mode needs a thread pool strictly larger than its
    # worker count; the spare thread is that server's structural requirement,
    # not extra capacity. Every runtime still serves with APP_WORKERS workers.
    APP_THREADS=$((APP_WORKERS + 1))
    export APP_THREADS

    export_load_generator_budget "$appCpus"

    local recyclingPolicy="recycling every ${APP_MAX_REQUESTS} requests"
    if [ "$APP_MAX_REQUESTS" -eq 0 ]; then
        recyclingPolicy="no worker recycling"
    fi

    echo "Worker budget: ${APP_WORKERS} (${appCpus} cpus x ${workersPerCpu} per cpu), ${recyclingPolicy} — applied to every runtime."
}

# Sizes the load generator as a multiple of the application's budget.
#
# A benchmark whose generator saturates reports the generator. Deriving the
# budget from APP_CPUS rather than pinning a number keeps the margin intact
# when the application budget is varied, which is the whole point of having the
# CPU budget be a knob.
#
# Exports K6_CPUS and K6_MEM for docker compose to read. An explicit value in
# the environment still wins, so a deliberate experiment can shrink the
# generator on purpose.
export_load_generator_budget() {
    local appCpus="$1"
    local multiplier

    multiplier="$(config_number load_generator_multiplier 5)"

    local defaultK6Cpus
    defaultK6Cpus="$(awk -v c="$appCpus" -v m="$multiplier" 'BEGIN { printf "%.1f", c * m }')"
    K6_CPUS="$(env_value K6_CPUS "$defaultK6Cpus")"
    export K6_CPUS

    # Memory is scaled from the same multiplier. The app budget carries a unit
    # suffix ("512m"), which is stripped for the arithmetic and restored after.
    local appMem appMemNumber appMemUnit defaultK6Mem
    appMem="$(env_value APP_MEM 512m)"
    appMemNumber="${appMem//[!0-9]/}"
    appMemUnit="${appMem//[0-9]/}"

    defaultK6Mem="$(awk -v n="$appMemNumber" -v m="$multiplier" -v u="$appMemUnit" 'BEGIN { printf "%d%s", n * m, u }')"
    K6_MEM="$(env_value K6_MEM "$defaultK6Mem")"
    export K6_MEM

    echo "Load generator budget: ${K6_CPUS} cpus, ${K6_MEM} (${multiplier}x the application) — it must never be the constraint."
}

# Describes the machine the measurement ran on, as a JSON object.
#
# A throughput number without the host it came from is not reproducible and not
# comparable against another machine's run. Reports what Docker sees — on
# Windows that is the WSL2 VM, which is the host that actually matters for
# these containers, not the Windows box around it.
describe_host() {
    docker info --format '{"docker_version": "{{.ServerVersion}}", "os": "{{.OperatingSystem}}", "kernel": "{{.KernelVersion}}", "cpus": {{.NCPU}}, "memory_bytes": {{.MemTotal}}}' 2>/dev/null ||
        echo '{"error": "docker info unavailable"}'
}

# Samples container CPU and memory into a file until stopped.
#
# Utilisation has to be captured *during* the measured window. Sampling
# opportunistically instead catches cooldowns and container startup, which
# reads as an idle system even while the container is genuinely saturated
# under load.
#
# This is what turns "350 rps" into "350 rps with the CPU pegged", and it is
# the only way to tell whether the runtime was the constraint or something
# else in the path was — a proxy on a smaller budget, for instance.
start_utilization_sampler() {
    local runtime="$1"
    local outFile="$2"

    (
        ticksSinceHostSample=0

        while :; do
            # Containers are re-resolved on every tick rather than listed once
            # at startup. The load generator is started afterwards, by
            # `compose run` under a different profile, so a list captured
            # once at startup would never contain it, leaving its utilisation
            # reported as zero — which leaves the one claim the method depends
            # on unverifiable: that the generator was not the thing being
            # measured.
            #
            # Matched by name prefix so it covers the app, the proxy, the
            # dependency stub and the generator alike, whatever profile each
            # was started under.
            local containerIds
            containerIds="$(docker ps -q --filter "name=php-runtime-lab-" 2>/dev/null | tr '\n' ' ')"

            if [ -n "${containerIds// /}" ]; then
                # shellcheck disable=SC2086
                # Nulls appear when a container vanishes mid-sample; they would
                # otherwise corrupt the command substitution that reads this file.
                #
                # MemUsage is sampled alongside the percentage because absolute
                # resident memory is the only reuse-aware total there is: a page
                # the worker recycles between requests is counted once, so the
                # figure answers "how much memory did serving this load need"
                # rather than "how much was allocated in total".
                docker stats --no-stream --format '{{.Name}}|{{.CPUPerc}}|{{.MemPerc}}|{{.MemUsage}}' $containerIds 2>/dev/null | tr -d '\000'
            fi

            # The machine underneath, recorded as a pseudo-container so it
            # aggregates with everything else.
            #
            # Container CPU alone cannot tell "this runtime is noisy" from
            # "the machine was busy while this runtime was measured" — and
            # without the host's own load as the missing half, a scattered
            # sample stays merely suspect instead of becoming explainable.
            #
            # Read once every HOST_LOAD_SAMPLE_EVERY ticks, not every tick,
            # because it costs a container start. Sustained load — a virus
            # scan, an indexer, another benchmark — is what corrupts a
            # measurement, and sustained load does not hide between samples.
            # A reader on every tick would itself be the contention it is
            # looking for.
            #
            # Two /proc/stat readings a second apart rather than the load
            # average: loadavg is smoothed over a full minute, so inside a 60s
            # window it is still climbing when the window ends, understating a
            # host that has in fact been under load for the whole window.
            #
            # No privileges needed: a container shares the kernel, so
            # /proc/stat already accounts for the whole VM. Emitted in the same
            # units docker stats uses — percent of one core, then percent of
            # the whole machine — so it needs no special handling downstream.
            ticksSinceHostSample=$((ticksSinceHostSample + 1))

            if [ "$ticksSinceHostSample" -ge "$HOST_LOAD_SAMPLE_EVERY" ]; then
                ticksSinceHostSample=0
                docker run --rm alpine:latest sh -c '
                    read -r _ u1 n1 s1 i1 w1 q1 f1 _ < /proc/stat
                    busy1=$((u1 + n1 + s1 + w1 + q1 + f1)); total1=$((busy1 + i1))
                    sleep 1
                    read -r _ u2 n2 s2 i2 w2 q2 f2 _ < /proc/stat
                    busy2=$((u2 + n2 + s2 + w2 + q2 + f2)); total2=$((busy2 + i2))
                    delta=$((total2 - total1))
                    [ "$delta" -gt 0 ] || exit 0
                    machinePct=$(((busy2 - busy1) * 100 / delta))
                    totalMachinePct=$((machinePct * $(nproc)))
                    printf "host|%s%%|%s%%|0B / 0B
" "$totalMachinePct" "$machinePct"
                ' 2>/dev/null || true
            fi

            sleep 1
        done
    ) >> "$outFile" 2>/dev/null &

    UTILIZATION_SAMPLER_PID=$!
}

stop_utilization_sampler() {
    if [ -n "${UTILIZATION_SAMPLER_PID:-}" ]; then
        kill "$UTILIZATION_SAMPLER_PID" 2>/dev/null || true
        wait "$UTILIZATION_SAMPLER_PID" 2>/dev/null || true
        UTILIZATION_SAMPLER_PID=""
    fi
}

# Reduces a sample file to the peak CPU and memory per container, as JSON.
#
# Peak rather than mean: the question is whether anything in the path hit its
# ceiling, and a mean over a window that includes ramp-up hides exactly that.
# Docker reports CPU as a percentage of one core, so a container limited to
# 0.5 CPU tops out near 50 — the limits are recorded alongside so saturation is
# readable without knowing the budget by heart.
summarize_utilization() {
    local sampleFile="$1"

    if [ ! -s "$sampleFile" ]; then
        echo '{}'
        return 0
    fi

    awk -F'|' "$AWK_MEBIBYTES"'
        {
            gsub(/%/, "", $2); gsub(/%/, "", $3)


            if ($2 + 0 > cpu[$1]) cpu[$1] = $2 + 0
            if ($3 + 0 > mem[$1]) mem[$1] = $3 + 0

            # "44.1MiB / 512MiB" -> 44.1
            split($4, usage, "/")
            gsub(/[ 	]/, "", usage[1])
            usedMib = toMebibytes(usage[1])
            if (usedMib > memMib[$1]) memMib[$1] = usedMib
        }
        END {
            printf "{"
            first = 1
            for (container in cpu) {
                if (!first) printf ", "
                name = container
                sub(/^php-runtime-lab-/, "", name)
                sub(/-1$/, "", name)
                # The generator is a throwaway container, so its name carries a
                # fresh hash every sample. Collapsed to a stable key, or each
                # sample would contribute its own column and none would
                # aggregate.
                sub(/^k6-run-.*/, "k6", name)
                printf "\"%s\": {\"peak_cpu_pct\": %.1f, \"peak_mem_pct\": %.1f, \"peak_mem_mib\": %.1f}", name, cpu[container], mem[container], memMib[container]
                first = 0
            }
            printf "}"
        }
    ' "$sampleFile"
}

# Normalises a docker stats memory figure ("44.1MiB", "1.2GiB") to MiB, so
# footprints across runtimes are comparable as plain numbers.
to_mebibytes() {
    awk "$AWK_MEBIBYTES"'{ printf "%.1f", toMebibytes($1) }'
}

# Captures how much memory each of a runtime's containers occupies while idle,
# before any load arrives, and warns past the configured ratio.
#
# This is a headline result, not just a precaution. What a model costs merely
# to exist separates the runtimes sharply — a process-per-request server and a
# persistent worker pool can idle an order of magnitude apart inside the same
# memory budget — and it is the one number a throughput table cannot show, so
# it is returned as JSON rather than printed, and reaches the result file
# instead of the scrollback.
#
# Sets IDLE_FOOTPRINT.
capture_idle_footprint() {
    local runtime="$1"
    local warnRatio stats
    local entries=()

    warnRatio="$(sed -n 's/.*"idle_memory_warn_ratio"[[:space:]]*:[[:space:]]*\([0-9.]*\).*/\1/p' performance.json)"
    warnRatio="${warnRatio:-0.35}"

    stats="$(docker compose --profile "$runtime" ps -q 2>/dev/null |
        xargs -r docker stats --no-stream --format '{{.Name}}|{{.MemUsage}}|{{.MemPerc}}' 2>/dev/null)"

    IDLE_FOOTPRINT='{}'

    if [ -z "$stats" ]; then
        return 0
    fi

    # Fed by here-string rather than a pipe: a piped loop runs in a subshell,
    # where the assignment to IDLE_FOOTPRINT would be discarded on exit.
    while IFS='|' read -r containerName memoryUsage memoryPercent; do
        [ -n "$containerName" ] || continue

        local usedMebibytes isOverWarnRatio
        usedMebibytes="$(printf '%s' "${memoryUsage%%/*}" | to_mebibytes)"
        isOverWarnRatio="$(printf '%s' "${memoryPercent%\%}" |
            awk -v warn="$warnRatio" '{ print ($1 / 100 > warn) ? "yes" : "no" }')"

        if [ "$isOverWarnRatio" = "yes" ]; then
            echo "  WARNING: ${containerName} already uses ${memoryPercent} of its memory budget while idle." >&2
        else
            echo "  idle memory: ${containerName} ${usedMebibytes} MiB (${memoryPercent})"
        fi

        entries+=("\"${containerName}\": {\"idle_mebibytes\": ${usedMebibytes}, \"idle_mem_pct\": ${memoryPercent%\%}}")
    done <<< "$stats"

    IDLE_FOOTPRINT="{$(IFS=,; echo "${entries[*]}")}"
}

# Reads one taxonomy field of a runtime from runtimes.conf.
#
#   describe_runtime fpm language   -> php
#   describe_runtime fpm framework  -> vanilla
#   describe_runtime fpm model      -> process-per-request
#
# Kept next to resolve_public_service because both parse the same line: the
# taxonomy lives with the service mapping so a new runtime is still one line.
describe_runtime() {
    local runtime="$1"
    local field="$2"
    local fieldIndex

    case "$field" in
        language) fieldIndex=2 ;;
        framework) fieldIndex=3 ;;
        model) fieldIndex=4 ;;
        *)
            echo "Unknown taxonomy field '${field}'." >&2
            return 1
            ;;
    esac

    grep -E "^${runtime}=" "$RUNTIMES_CONFIG_PATH" 2>/dev/null |
        cut -d= -f2 |
        cut -d'|' -f"$fieldIndex"
}

# Resolves a runtime name to the Compose service that serves its HTTP traffic,
# reading benchmarks/runtimes.conf so no script keeps its own copy of the map.
# Exits with a usage message when the runtime is unknown.
resolve_public_service() {
    local runtime="$1"
    local publicService

    publicService="$(grep -E "^${runtime}=" "$RUNTIMES_CONFIG_PATH" 2>/dev/null | cut -d= -f2 | cut -d'|' -f1)"

    if [ -z "$publicService" ]; then
        local knownRuntimes
        knownRuntimes="$(grep -vE '^\s*(#|$)' "$RUNTIMES_CONFIG_PATH" | cut -d= -f1 | tr '\n' '|' | sed 's/|$//')"
        echo "Unknown runtime '${runtime}'. Expected one of: ${knownRuntimes}" >&2
        return 1
    fi

    echo "$publicService"
}

# Reads back the host port Docker actually published for a service, instead of
# re-deriving it from .env.
published_host_port() {
    local runtime="$1"
    local service="$2"
    local publishedAddress

    publishedAddress="$(docker compose --profile "$runtime" port "$service" 8080)"

    if [ -z "$publishedAddress" ]; then
        echo "Could not determine the published host port for service '${service}'." >&2
        return 1
    fi

    echo "${publishedAddress##*:}"
}

# Polls a URL until it answers, so a load test never starts against a runtime
# that is still booting.
wait_until_healthy() {
    local url="$1"
    local elapsedSeconds=0

    while [ "$elapsedSeconds" -lt "$HEALTHCHECK_TIMEOUT_SECONDS" ]; do
        # The per-attempt bounds are what make the loop's own timeout mean
        # anything. Without them the counter below only advances once curl
        # returns, so a single hung connection stalls inside one iteration
        # forever — the loop's own timeout becomes meaningless while it waits,
        # believing zero seconds have passed, on a container that may already
        # be healthy.
        if curl -fsS --connect-timeout 2 --max-time "$HEALTHCHECK_ATTEMPT_TIMEOUT_SECONDS" \
            "$url" > /dev/null 2>&1; then
            return 0
        fi
        sleep 1
        elapsedSeconds=$((elapsedSeconds + 1))
    done

    echo "Runtime did not become healthy within ${HEALTHCHECK_TIMEOUT_SECONDS}s." >&2
    return 1
}

# Collects "-e NAME=VALUE" arguments for the load-shape settings that are set
# in the environment, leaving performance.json to own every default.
# Populates the K6_ENV_ARGS array in the caller's scope.
collect_k6_overrides() {
    K6_ENV_ARGS=()

    local overrideName
    local overrideValue

    for overrideName in MEASURE_SECONDS TARGET_RPS WARMUP_SECONDS \
                        GRACEFUL_STOP_SECONDS COOLDOWN_SECONDS \
                        LATENCY_BUDGET_P95_MS ERROR_RATE_BUDGET PRE_ALLOCATED_VUS MAX_VUS; do
        overrideValue="${!overrideName:-}"
        if [ -n "$overrideValue" ]; then
            K6_ENV_ARGS+=(-e "${overrideName}=${overrideValue}")
        fi
    done
}
