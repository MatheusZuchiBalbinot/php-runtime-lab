#!/usr/bin/env bash
#
# Runs the full comparison and files the result as one self-describing run.
#
# This is the entry point for producing numbers anyone is meant to read. It
# exists because the alternative — invoking sweep.sh by hand once per runtime —
# produced results that could not be told apart afterwards: loose JSON files in
# a flat directory, with no record of which host, which tuning profile or which
# budget they came from. A throughput figure without that context is not a
# result, it is a number.
#
# Each run becomes a directory whose name carries everything that changes what
# the numbers mean:
#
#   run-20260821-0030-php8.3-baseline-1cpu-512m-w4-s3/
#     manifest.json     every parameter and the host it ran on
#     report.md         the comparison tables
#     fpm/
#       summary.json    medians, dispersion and utilisation per route
#       cpu/s1.json     each individual k6 measurement behind them
#       memory/s1.json
#     swoole/
#       ...
#
# Run, then runtime, then the resource being exhausted: each level answers one
# question, so a result can be found without knowing how it was produced.
#
# Usage:
#   ./benchmarks/scripts/run-matrix.sh                  # every runtime
#   ./benchmarks/scripts/run-matrix.sh fpm swoole       # a subset
#
# Every knob honoured by sweep.sh works here too, so a tuning comparison is
# just a different invocation:
#   PHP_TUNING=jit ./benchmarks/scripts/run-matrix.sh
#   APP_CPUS=0.5 APP_MEM=256m ./benchmarks/scripts/run-matrix.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/../.."

# shellcheck source=benchmarks/scripts/lib.sh
source "$SCRIPT_DIR/lib.sh"

RESULTS_ROOT="benchmarks/results"

# Runtimes to measure: the ones named as arguments, or every runtime declared
# in runtimes.conf.
if [ "$#" -gt 0 ]; then
    RUNTIMES=("$@")
else
    mapfile -t RUNTIMES < <(grep -vE '^\s*(#|$)' benchmarks/runtimes.conf | cut -d= -f1)
fi

# Validated before anything starts, and loudly: an unknown name that aborts with
# no output reads as "nothing happened" rather than "you asked for a runtime
# that does not exist". Arguments are runtimes, not routes — `run-matrix.sh fpm
# noop` is a common slip.
for runtime in "${RUNTIMES[@]}"; do
    if ! resolve_public_service "$runtime" > /dev/null 2>&1; then
        echo "Unknown runtime: '${runtime}'" >&2
        echo "Arguments are runtimes, not routes. Known runtimes:" >&2
        grep -vE '^\s*(#|$)' benchmarks/runtimes.conf | cut -d= -f1 | sed 's/^/  /' >&2
        exit 1
    fi
done

export_worker_budget

APP_CPUS="$(env_value APP_CPUS 1.0)"
APP_MEM="$(env_value APP_MEM 512m)"
PHP_TUNING="$(env_value PHP_TUNING baseline)"
SAMPLES_PER_ROUTE="${SAMPLES_PER_ROUTE:-$(sed -n 's/.*"samples_per_route"[[:space:]]*:[[:space:]]*\([0-9]*\).*/\1/p' performance.json)}"
SAMPLES_PER_ROUTE="${SAMPLES_PER_ROUTE:-3}"
export SAMPLES_PER_ROUTE

# The run name carries every parameter that changes what the numbers mean, so
# two runs can be told apart in a directory listing without opening either. PHP
# version is in there because it is a comparison axis in its own right.
PHP_VERSION="$(env_value PHP_VERSION 8.3)"
# The measured window is part of the name alongside the sample count: the two
# together are the size of the run, and without the window a one-sample 30s run
# and a one-sample 60s run are the same directory name for different numbers.
MEASURE_SECONDS="${MEASURE_SECONDS:-60}"
# RUN_ID carries a timestamp, so every invocation would otherwise open a new
# directory and resuming could never engage. Passing an existing id in the
# environment is what makes it possible to continue that run instead.
RUN_ID="${RUN_ID:-run-$(date +%Y%m%d-%H%M)-php${PHP_VERSION}-${PHP_TUNING}-${APP_CPUS%.*}cpu-${APP_MEM}-w${APP_WORKERS}-s${SAMPLES_PER_ROUTE}x${MEASURE_SECONDS}s}"
RUN_DIR="${RESULTS_ROOT}/${RUN_ID}"

# An existing directory is resumed rather than refused.
#
# A matrix takes hours, and losing all of it because the process died on the
# seventh runtime would be a real cost. Everything needed to resume is already
# on disk: a runtime either wrote its summary or it did not, and a summary is
# only written once every route completed.
#
# RESUME=0 forces a clean start, for when the point is to re-measure rather
# than to finish.
RESUME_RUN="${RESUME:-1}"

if [ -e "$RUN_DIR" ] && [ "$RESUME_RUN" != "1" ]; then
    echo "Run directory already exists: ${RUN_DIR}" >&2
    echo "Set RESUME=1 to continue it instead of starting over." >&2
    exit 1
fi

mkdir -p "$RUN_DIR"

echo "=============================================================="
echo " ${RUN_ID}"
echo " runtimes: ${RUNTIMES[*]}"
echo "=============================================================="
echo

STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
STARTED_EPOCH="$(date +%s)"

# The manifest is written before the first measurement so an interrupted run
# still says what it was trying to do. On a resume it is left alone: it already
# records when the run started and under which parameters, and rewriting it
# would replace that with the time of the retry.
if [ ! -f "${RUN_DIR}/manifest.json" ]; then
cat > "${RUN_DIR}/manifest.json" <<EOF
{
  "run_id": "${RUN_ID}",
  "started_at": "${STARTED_AT}",
  "runtimes": [$(printf '"%s",' "${RUNTIMES[@]}" | sed 's/,$//')],
  "budget": {
    "app_cpus": "${APP_CPUS}",
    "app_mem": "${APP_MEM}",
    "app_workers": ${APP_WORKERS},
    "app_max_requests_per_worker": ${APP_MAX_REQUESTS},
    "nginx_cpus": "$(env_value NGINX_CPUS 2.0)",
    "k6_cpus": "$(env_value K6_CPUS 2.0)"
  },
  "load": {
    "samples_per_route": ${SAMPLES_PER_ROUTE},
    "measure_seconds": ${MEASURE_SECONDS:-60},
    "warmup_seconds": ${WARMUP_SECONDS:-5},
    "overload_vus": $(grep -oE '"vus"[[:space:]]*:[[:space:]]*[0-9]+' performance.json | grep -oE '[0-9]+$' | head -1),
    "latency_budget_p95_ms": $(grep -oE '"latency_budget_p95_ms"[[:space:]]*:[[:space:]]*[0-9]+' performance.json | grep -oE '[0-9]+$' | head -1)
  },
  "php_tuning": "${PHP_TUNING}",
  "php_version": "${PHP_VERSION}",
  "host": $(describe_host)
}
EOF
fi

FAILED_RUNTIMES=()

SKIPPED_RUNTIMES=()

for runtime in "${RUNTIMES[@]}"; do
    # Already measured by an earlier attempt at this same run.
    if [ "$RESUME_RUN" = "1" ] && [ -f "${RUN_DIR}/${runtime}/summary.json" ]; then
        echo "-------- ${runtime} (already measured, skipping) --------"
        echo
        SKIPPED_RUNTIMES+=("$runtime")
        continue
    fi

    echo "-------- ${runtime} --------"

    # sweep.sh writes straight into its slot under this run; nothing is moved
    # afterwards, so an interrupted matrix leaves behind exactly the runtimes
    # it finished rather than a half-migrated directory.
    #
    # The exit status has to come from PIPESTATUS, not from the pipeline. A
    # pipeline reports its LAST command, which here is the grep — so a sweep
    # that dies halfway would still "succeed" as long as grep had matched a
    # line earlier, silently reporting a collapsed runtime as fine.
    # `set +e` rather than a trailing `|| true`: running `true` after the
    # pipeline overwrites PIPESTATUS with its own status, which would turn
    # every genuine failure into "FAILED (exit 0)".
    set +e
    SWEEP_OUTPUT_DIR="${RUN_ID}/${runtime}" ./benchmarks/scripts/sweep.sh "$runtime" 2>&1 |
        grep -E "Worker budget|idle memory|WARNING|route:|sample |=> "
    sweepExitCode="${PIPESTATUS[0]}"
    set -e

    # A summary is only written after every route completes, so its absence
    # catches a sweep that died without a non-zero status of its own.
    hasSummary=false
    if [ -f "${RUN_DIR}/${runtime}/summary.json" ]; then
        hasSummary=true
    fi

    if [ "$sweepExitCode" -ne 0 ] || [ "$hasSummary" = false ]; then
        # A runtime that fails is recorded and the matrix continues: losing the
        # remaining runtimes because one broke would waste the whole run.
        echo "   FAILED (exit ${sweepExitCode}) — continuing with the remaining runtimes." >&2
        FAILED_RUNTIMES+=("$runtime")
    fi

    echo
done

FINISHED_EPOCH="$(date +%s)"
DURATION_MINUTES=$(((FINISHED_EPOCH - STARTED_EPOCH) / 60))

# The manifest was written before the first measurement so an interrupted run
# still says what it was trying to do; now that it finished, it can also say
# when. Rewritten through a JSON parser rather than appended to, so the file is
# valid either way — and through the same stock PHP image the report uses,
# rather than adding an interpreter this project does not otherwise need.
MSYS_NO_PATHCONV=1 docker run --rm \
    -v "$(pwd):/work" -w /work php:8.3-cli-alpine \
    php -r '
        $path = $argv[1];
        $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $manifest["finished_at"] = $argv[2];
        $manifest["duration_seconds"] = (int) $argv[3];
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    ' "${RUN_DIR}/manifest.json" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$((FINISHED_EPOCH - STARTED_EPOCH))"

echo "Generating report..."
MSYS_NO_PATHCONV=1 docker run --rm \
    -v "$(pwd):/work" -w /work php:8.3-cli-alpine \
    php benchmarks/scripts/report.php "$RUN_DIR" > "${RUN_DIR}/report.md"

echo
echo "=============================================================="
echo " done in ${DURATION_MINUTES} min"
echo " ${RUN_DIR}/report.md"
if [ "${#SKIPPED_RUNTIMES[@]}" -gt 0 ]; then
    echo " resumed: ${#SKIPPED_RUNTIMES[@]} runtime(s) were already measured"
fi
if [ "${#FAILED_RUNTIMES[@]}" -gt 0 ]; then
    echo " FAILED: ${FAILED_RUNTIMES[*]}"
fi
echo "=============================================================="
