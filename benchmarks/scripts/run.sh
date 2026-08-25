#!/usr/bin/env bash
#
# Runs one load test against a single runtime, at one load level.
#
# This is the lowest-level entry point and is rarely what you want. To measure
# and compare, use ./benchmark.sh at the repository root; to saturate a single
# runtime across every route, use sweep.sh. This one exists for driving one
# route at one specific arrival rate, which is an exploratory question rather
# than a comparative one.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/../.."

# shellcheck source=benchmarks/scripts/lib.sh
source "$SCRIPT_DIR/lib.sh"

RUNTIME="${1:-}"

if [ -z "$RUNTIME" ]; then
    echo "Usage: $0 <runtime>" >&2
    echo "Known runtimes:" >&2
    grep -vE '^\s*(#|$)' benchmarks/runtimes.conf | cut -d= -f1 | sed 's/^/  /' >&2
    exit 1
fi

PUBLIC_SERVICE="$(resolve_public_service "$RUNTIME")"

export_worker_budget

echo "Starting $RUNTIME..."
docker compose --profile "$RUNTIME" up -d --build

# Tear the stack down on every exit path — success, a failed health check, a
# k6 failure, or Ctrl-C. Without this the containers stay up exactly when a
# run goes wrong, and the next run collides with them.
cleanup() {
    docker compose --profile "$RUNTIME" down
}
trap cleanup EXIT

HOST_PORT="$(published_host_port "$RUNTIME" "$PUBLIC_SERVICE")"

echo "Waiting for $RUNTIME to become healthy on port ${HOST_PORT}..."
wait_until_healthy "http://localhost:${HOST_PORT}/"

capture_idle_footprint "$RUNTIME"

collect_k6_overrides

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
RESULT_FILE="${RUNTIME}-${TIMESTAMP}.json"
TARGET_URL="http://${PUBLIC_SERVICE}:8080"

echo "Running k6 load test against $TARGET_URL..."
set +e
docker compose --profile bench run --rm \
    -e TARGET_URL="$TARGET_URL" \
    -e RESULT_FILE="$RESULT_FILE" \
    ${K6_ENV_ARGS[@]+"${K6_ENV_ARGS[@]}"} \
    k6 run load-test.js
K6_EXIT_CODE=$?
set -e

if [ "$K6_EXIT_CODE" -eq 0 ]; then
    echo "Result saved to benchmarks/results/${RESULT_FILE}"
    exit 0
fi

if [ "$K6_EXIT_CODE" -eq "$K6_THRESHOLD_FAILURE_EXIT_CODE" ]; then
    # A breached threshold means the runtime could not hold the latency/error
    # budget at this load. That is the finding this lab exists to produce, not
    # a failure of the run: the result file is complete and is what tells you
    # where the runtime broke. Exit 0 so a sweep across runtimes is not
    # aborted by the very outcome it is looking for.
    echo "WARNING: thresholds not met at this load — that is a result, not an error." >&2
    echo "Result saved to benchmarks/results/${RESULT_FILE}"
    exit 0
fi

echo "k6 failed with exit code ${K6_EXIT_CODE}; no usable result was produced." >&2
exit "$K6_EXIT_CODE"
