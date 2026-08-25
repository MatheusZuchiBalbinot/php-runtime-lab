#!/usr/bin/env bash
#
# The one command. Runs the whole comparison and files the result.
#
#   ./benchmark.sh                  every runtime, full measurement
#   ./benchmark.sh --small          1 sample of 30s per route  (~35 min)
#   ./benchmark.sh --medium         2 samples of 45s per route (~90 min)
#   ./benchmark.sh --large          3 samples of 60s per route (~2h50) — the default
#   ./benchmark.sh fpm swoole       only these runtimes
#   ./benchmark.sh --resume         finish the newest run that did not complete
#   ./benchmark.sh --check          preflight only, measure nothing
#   ./benchmark.sh --quick          tiny windows, for proving the setup works
#   ./benchmark.sh --runs           list finished runs, newest first
#   ./benchmark.sh --report <dir>   regenerate a run's report from its data
#   ./benchmark.sh --compare <a> <b>  diff two runs, cell by cell
#   ./benchmark.sh --status         how far the newest run has got
#   ./benchmark.sh --watch          the same, refreshing until you stop it
#
# --status and --watch read the run directory, so they work on a run started
# from any shell — including one detached with nohup — and are safe to call
# while it is in progress.
#
# Everything it needs is in performance.json and .env, so there are no
# environment variables to remember. Anything set in the environment still
# wins, which is how a one-off variation is run without editing the config:
#
#   MEASURE_SECONDS=30 ./benchmark.sh
#   PHP_TUNING=jit ./benchmark.sh
#   APP_CPUS=0.5 APP_MEM=256m ./benchmark.sh
#
# The preflight is the reason this script exists rather than a note in the
# README telling you to run run-matrix.sh. A full run takes hours, and the
# failures worth catching are the ones that make it useless without stopping
# it: an image that no longer builds, a runtime that starts but does not
# route, a sweep that dies between routes. Any of those turns an hour of work
# into a report covering only a handful of the runtimes it was supposed to
# measure, with no failure reported anywhere. The preflight measures two
# routes on every runtime with three-second windows, which exercises the same
# code path as the real thing and costs a few minutes instead of the whole
# night.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# shellcheck source=benchmarks/scripts/lib.sh
source benchmarks/scripts/lib.sh

readonly RESULTS_ROOT="benchmarks/results"

# Held for the whole run, so a second invocation is refused instead of racing
# the first.
#
# Two runs on one machine do not fail loudly — they measure each other's
# contention and corrupt each other's output: both write to the same log, which
# ends up padded with NUL bytes, and whichever starts second finds most
# runtimes refused by the busy-machine guard with nothing in the output saying
# why. A directory is the lock because mkdir is atomic: two shells cannot both
# believe they created it.
readonly LOCK_DIR="${RESULTS_ROOT}/.benchmark.lock"

# Routes the preflight measures. Two are enough and two are necessary: one
# proves a runtime answers under load, and the second proves the sweep
# survives the transition between routes — precisely the place a runtime can
# finish one route cleanly and then die silently on the next.
readonly PREFLIGHT_ROUTES=(noop cpu)
readonly PREFLIGHT_MEASURE_SECONDS=3
readonly PREFLIGHT_WARMUP_SECONDS=1

# Per-sample overhead outside the measured window: warmup, graceful stop,
# cooldown, and starting a k6 container. Only used to estimate the wall time.
readonly PER_SAMPLE_OVERHEAD_SECONDS=11

MODE='full'
PRESET=''
RUNTIMES=()

for argument in "$@"; do
    case "$argument" in
        --check) MODE='check' ;;
        --quick) MODE='quick' ;;
        --small | --medium | --large) PRESET="${argument#--}" ;;
        --resume) MODE='resume' ;;
        --compare) MODE='compare' ;;
        --report) MODE='report' ;;
        --runs) MODE='runs' ;;
        --status) MODE='status' ;;
        --watch) MODE='watch' ;;
        -h | --help)
            sed -n '3,29p' "$0" | sed 's/^# \?//'
            exit 0
            ;;
        -*)
            echo "Unknown option: ${argument}" >&2
            echo "Try: $0 --help" >&2
            exit 1
            ;;
        *) RUNTIMES+=("$argument") ;;
    esac
done

# For the read-only modes the positional arguments are run directories, not
# runtimes, so the default-to-every-runtime fill-in must not apply.
# `resume` is excluded as well: its runtime list comes from the manifest of the
# run being continued, and filling it in here first would make that lookup
# unreachable — which quietly widened a two-runtime run to all eight.
IS_RUNTIME_MODE=true
case "$MODE" in
    compare | report | runs | status | watch | resume) IS_RUNTIME_MODE=false ;;
esac

if [ "$IS_RUNTIME_MODE" = true ] && [ "${#RUNTIMES[@]}" -eq 0 ]; then
    mapfile -t RUNTIMES < <(grep -vE '^\s*(#|$)' benchmarks/runtimes.conf | cut -d= -f1)
fi

# ---------------------------------------------------------------- preflight

# Refuses to continue unless Docker is actually reachable.
#
# `docker ps` rather than `docker version`: the latter answers from the client
# alone, so it succeeds while the daemon is down and the failure resurfaces
# minutes later as an unreadable compose error.
assert_docker_is_running() {
    if ! docker ps > /dev/null 2>&1; then
        echo "Docker is not running (or is not reachable from this shell)." >&2
        echo "Start Docker Desktop and try again." >&2
        exit 1
    fi
}

# Claims the machine for this run, or explains who already has it.
#
# The stale-lock case is handled by asking the operating system whether the
# recorded process still exists, rather than by a timeout: a run legitimately
# lasts hours, so any timeout long enough to be safe is too long to be useful.
acquire_lock() {
    if mkdir "$LOCK_DIR" 2>/dev/null; then
        echo "$$" > "${LOCK_DIR}/pid"
        return 0
    fi

    local holder
    holder="$(cat "${LOCK_DIR}/pid" 2>/dev/null || echo '?')"

    if [ "$holder" != '?' ] && ! kill -0 "$holder" 2>/dev/null; then
        echo "Clearing a stale lock from process ${holder}, which is no longer running."
        rm -rf "$LOCK_DIR"
        mkdir "$LOCK_DIR"
        echo "$$" > "${LOCK_DIR}/pid"
        return 0
    fi

    echo "A benchmark is already running (process ${holder})." >&2
    echo "Watch it with:  $0 --watch" >&2
    echo "Two runs on one machine measure each other, not the runtimes." >&2
    exit 1
}

release_lock() {
    rm -rf "$LOCK_DIR"
}

# Measures two routes on every runtime with tiny windows.
#
# Not a health check: it runs the real sweep, so it fails on anything the real
# run would fail on, including the failures that leave a runtime looking fine
# from the outside. Results go to a scratch directory and are discarded.
run_preflight() {
    local failed=()
    local preflightRoot="benchmarks/results/.preflight"

    rm -rf "$preflightRoot"

    echo "Preflight: building images and measuring ${PREFLIGHT_ROUTES[*]} on each runtime."
    echo

    for runtime in "${RUNTIMES[@]}"; do
        printf '  %-28s ' "$runtime"

        # A runtime that failed to tear itself down would otherwise be
        # reported as the next one's failure.
        remove_orphan_containers

        if MEASURE_SECONDS="$PREFLIGHT_MEASURE_SECONDS" \
            WARMUP_SECONDS="$PREFLIGHT_WARMUP_SECONDS" \
            SAMPLES_PER_ROUTE=1 \
            SWEEP_OUTPUT_DIR=".preflight/${runtime}" \
            ./benchmarks/scripts/sweep.sh "$runtime" "${PREFLIGHT_ROUTES[@]}" \
            > "${preflightRoot}.log" 2>&1 &&
            [ -f "${preflightRoot}/${runtime}/summary.json" ]; then
            echo "ok"
        else
            echo "FAILED"
            failed+=("$runtime")
            sed 's/^/      /' "${preflightRoot}.log" | tail -15 >&2
        fi
    done

    rm -rf "$preflightRoot" "${preflightRoot}.log"

    if [ "${#failed[@]}" -gt 0 ]; then
        echo
        echo "Preflight failed for: ${failed[*]}" >&2
        echo "Fix these before spending hours on a full run." >&2
        return 1
    fi

    echo
    echo "Preflight passed: every runtime builds, serves, and completes a sweep."
    return 0
}

# ----------------------------------------------------------------- status

# Seconds between refreshes in --watch. Long enough that the screen is not
# redrawing constantly, short enough to feel live next to a 60s window.
readonly WATCH_INTERVAL_SECONDS=15

# Reports how far the newest run has got, and how much is left.
#
# Everything is derived from the run directory itself rather than from a
# progress file the run has to remember to write. That is deliberate: it works
# for a run started from any shell, survives this script exiting, and cannot
# drift out of step with reality — a sample either exists on disk or it does
# not.
#
# The estimate is based on measurements actually completed, not on the
# configured window, so it absorbs whatever the machine is really doing.
print_status() {
    local runDirectory
    runDirectory="$(ls -dt "${RESULTS_ROOT}"/run-*/ 2>/dev/null | head -1 || true)"

    # The run directory does not exist until the preflight has passed, which
    # takes minutes. Reporting "no run found" during that window reads as
    # nothing having started, when in fact it has — the lock is what
    # distinguishes "not started" from "started, still checking itself".
    # A run that holds the lock but has not created its directory yet is in
    # preflight, and the newest directory on disk belongs to some earlier run.
    # Reporting that one instead would announce FINISHED while images are
    # still building, which reads as "your run is done".
    #
    # The two are told apart by age: the lock is taken before the run directory
    # exists, so a lock newer than the newest directory means the current
    # invocation has not got that far.
    local isPreflightRunning=false
    if [ -d "$LOCK_DIR" ] && { [ -z "$runDirectory" ] || [ "$LOCK_DIR" -nt "$runDirectory" ]; }; then
        isPreflightRunning=true
    fi

    if [ -z "$runDirectory" ] || [ "$isPreflightRunning" = true ]; then
        if [ "$isPreflightRunning" = true ]; then
            echo "Preflight in progress — no measurements yet."
            echo "  Each runtime is being built and briefly measured before the real run starts."
        else
            echo "No run found in ${RESULTS_ROOT}, and none is running."
        fi
        return 0
    fi

    runDirectory="${runDirectory%/}"

    local manifest="${runDirectory}/manifest.json"
    local samplesPerRoute routeCount startedAt startedEpoch
    samplesPerRoute="$(grep -oE '"samples_per_route"[[:space:]]*:[[:space:]]*[0-9]+' "$manifest" | grep -oE '[0-9]+$')"
    startedAt="$(grep -oE '"started_at"[[:space:]]*:[[:space:]]*"[^"]+"' "$manifest" | sed -E 's/.*"([^"]+)"$/\1/')"
    startedEpoch="$(date -d "$startedAt" +%s 2>/dev/null || echo 0)"

    routeCount="$(grep -cE '"label"' routes.json)"
    routeCount=$((routeCount - 1))

    # Flattened before matching: the manifest is written as one line per key
    # while the run is in progress, then rewritten pretty-printed when it
    # finishes, which spreads the runtimes array over several lines. A
    # line-oriented grep here would silently find nothing on a finished run,
    # reporting it as having measured nothing at all.
    mapfile -t runtimes < <(
        tr -d '\n' < "$manifest" |
            grep -oE '"runtimes"[[:space:]]*:[[:space:]]*\[[^]]*\]' |
            grep -oE '"[a-z0-9-]+"' |
            tr -d '"' |
            grep -v '^runtimes$'
    )

    local samplesPerRuntime=$((routeCount * samplesPerRoute))
    local expectedTotal=$((${#runtimes[@]} * samplesPerRuntime))
    local completedTotal=0

    echo "$(basename "$runDirectory")"

    if [ "$startedEpoch" -gt 0 ]; then
        local elapsedMinutes=$((($(date +%s) - startedEpoch) / 60))
        echo "  started ${elapsedMinutes} min ago"
    fi
    echo

    for runtime in "${runtimes[@]}"; do
        local done_ state route
        # 's[0-9]*.json', not 's*.json': the latter also matches summary.json,
        # which would make every finished runtime report one more sample than
        # it actually took.
        done_="$(find "${runDirectory}/${runtime}" -name 's[0-9]*.json' 2>/dev/null | grep -c . || true)"
        completedTotal=$((completedTotal + done_))

        if [ -f "${runDirectory}/${runtime}/summary.json" ]; then
            state="done"
        elif [ "$done_" -gt 0 ]; then
            # The route being worked on is the one whose directory was touched
            # most recently.
            route="$(ls -t "${runDirectory}/${runtime}" 2>/dev/null | head -1 || true)"
            state="running: ${route}"
        else
            state="waiting"
        fi

        printf '  %-28s %2s/%-2s  %s\n' "$runtime" "$done_" "$samplesPerRuntime" "$state"
    done

    echo
    printf '  %s/%s measurements' "$completedTotal" "$expectedTotal"

    # Remaining time is extrapolated from the pace observed so far, which is
    # the only estimate that stays honest when the machine is slower or busier
    # than it was when the window was chosen.
    if [ "$completedTotal" -gt 0 ] && [ "$startedEpoch" -gt 0 ]; then
        local elapsed remainingMinutes
        elapsed=$(($(date +%s) - startedEpoch))
        remainingMinutes=$(((elapsed * (expectedTotal - completedTotal) / completedTotal) / 60))
        printf ' · ~%s min remaining' "$remainingMinutes"
    fi

    if [ -f "${runDirectory}/report.md" ]; then
        printf ' · FINISHED\n  %s/report.md' "$runDirectory"
    fi

    echo
}

# ------------------------------------------------------------------- run

# Prints how long the full measurement is expected to take, before starting it.
#
# The window is the cost lever and it is not obvious: a full run is 144
# measurements, so every second added to the window costs about two and a half
# minutes overall.
announce_plan() {
    local measureSeconds="$1"
    local samplesPerRoute="$2"
    local routeCount
    routeCount="$(grep -cE '"label"' routes.json)"
    routeCount=$((routeCount - 1)) # health is not a workload

    local measurements=$((${#RUNTIMES[@]} * routeCount * samplesPerRoute))
    local estimateMinutes=$(((measurements * (measureSeconds + PER_SAMPLE_OVERHEAD_SECONDS)) / 60))

    echo "=============================================================="
    echo " runtimes:     ${#RUNTIMES[@]} (${RUNTIMES[*]})"
    echo " routes:       ${routeCount}"
    echo " samples:      ${samplesPerRoute} per route"
    echo " window:       ${measureSeconds}s"
    echo " total:        ${measurements} measurements, roughly ${estimateMinutes} min"
    echo "=============================================================="
    echo
}

# Handled before anything touches Docker, and that ordering is load-bearing:
# the cleanup below force-removes load-generator containers, so a --status that
# fell through to it would kill the very run it was asked to report on.
# Runs the stock PHP image over a script in this repo. Every reporting tool
# goes through it, so the project needs no PHP on the host.
run_php_tool() {
    MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd):/work" -w /work php:8.3-cli-alpine php "$@"
}

case "$MODE" in
    runs)
        # One line per run, so the history is navigable without opening files.
        printf '%-58s %-10s %s\n' 'RUN' 'STATE' 'VERDICT'
        for candidate in $(ls -dt "${RESULTS_ROOT}"/run-*/ 2>/dev/null); do
            runName="$(basename "${candidate%/}")"

            if [ -f "${candidate}report.md" ]; then
                state='complete'
                # The verdict block is the first quoted line of the report.
                # `|| true` throughout: a report predating the verdict block
                # has no line to match, and a non-matching grep under `set -e`
                # would end the listing at that row instead of showing it.
                verdict="$(grep -m1 '^> ' "${candidate}report.md" 2>/dev/null |
                    sed 's/^> //; s/\*\*//g' | cut -c1-60 || true)"
                verdict="${verdict:-(report has no verdict)}"
            else
                state='incomplete'
                verdict="$(find "${candidate}" -name summary.json 2>/dev/null | grep -c . || true) runtime(s) measured"
            fi

            printf '%-58s %-10s %s\n' "$runName" "$state" "$verdict"
        done
        exit 0
        ;;
    report)
        reportTarget="${RUNTIMES[0]:-}"
        if [ ! -d "$reportTarget" ]; then
            echo "Usage: $0 --report <run-directory>" >&2
            exit 1
        fi
        run_php_tool benchmarks/scripts/report.php "${reportTarget%/}" > "${reportTarget%/}/report.md"
        echo "Report regenerated: ${reportTarget%/}/report.md"
        exit 0
        ;;
    compare)
        if [ "${#RUNTIMES[@]}" -lt 2 ]; then
            echo "Usage: $0 --compare <baseline-run> <candidate-run>" >&2
            exit 1
        fi
        run_php_tool benchmarks/scripts/compare.php "${RUNTIMES[0]%/}" "${RUNTIMES[1]%/}"
        exit 0
        ;;
    status)
        print_status
        exit 0
        ;;
    watch)
        while :; do
            clear
            print_status
            sleep "$WATCH_INTERVAL_SECONDS"
        done
        ;;
esac

# The newest run that never produced a report is the one to continue: the
# report is written last, so its absence is what marks a run as unfinished.
if [ "$MODE" = 'resume' ]; then
    for candidate in $(ls -dt "${RESULTS_ROOT}"/run-*/ 2>/dev/null); do
        if [ ! -f "${candidate}report.md" ]; then
            RESUME_RUN_ID="$(basename "${candidate%/}")"
            break
        fi
    done

    if [ -z "${RESUME_RUN_ID:-}" ]; then
        echo "No unfinished run to resume — every run in ${RESULTS_ROOT} has a report." >&2
        exit 1
    fi

    export RUN_ID="$RESUME_RUN_ID"
    MODE='full'

    # The set of runtimes comes from the run being resumed, not from the
    # default. Without this a resume quietly widened the run to every runtime,
    # measuring ones the original never asked for and producing a directory
    # that no longer matches its own manifest.
    if [ "${#RUNTIMES[@]}" -eq 0 ]; then
        mapfile -t RUNTIMES < <(
            tr -d '
' < "${RESULTS_ROOT}/${RESUME_RUN_ID}/manifest.json" 2>/dev/null |
                grep -oE '"runtimes"[[:space:]]*:[[:space:]]*\[[^]]*\]' |
                grep -oE '"[a-z0-9-]+"' |
                tr -d '"' |
                grep -v '^runtimes$' || true
        )
    fi

    # Falls back to every runtime only if the manifest could not be read.
    if [ "${#RUNTIMES[@]}" -eq 0 ]; then
        mapfile -t RUNTIMES < <(grep -vE '^\s*(#|$)' benchmarks/runtimes.conf | cut -d= -f1)
    fi

    echo "Resuming ${RESUME_RUN_ID} (${#RUNTIMES[@]} runtimes) — already-measured ones are skipped."
    echo
fi

# Everything still works without a .env — every setting falls back to the same
# default the file ships with. Worth saying once anyway: the file is where a
# budget is changed, and someone who never copied it will look for the knob in
# the wrong place.
warn_about_missing_env_file() {
    if [ ! -f .env ]; then
        echo "Note: no .env found; the defaults from .env.example are in use."
        echo "      To change budgets or ports: cp .env.example .env"
        echo
    fi
}

assert_docker_is_running
warn_about_missing_env_file
acquire_lock

# Containers outlive this script if it is killed, and a half-torn-down stack
# blocks the next run. Cleaning up on every exit path — including the lock — is
# what makes the script safe to interrupt.
trap 'release_lock' EXIT
trap 'echo; echo "Interrupted — cleaning up."; remove_orphan_containers; release_lock; exit 130' INT TERM

remove_orphan_containers

if ! run_preflight; then
    exit 1
fi

if [ "$MODE" = 'check' ]; then
    echo "Preflight only (--check); nothing measured."
    exit 0
fi

if [ "$MODE" = 'quick' ]; then
    # Deliberately too short to be a result: this proves the pipeline end to
    # end, including the report, without committing to a full run.
    export MEASURE_SECONDS=5
    export WARMUP_SECONDS=1
    export SAMPLES_PER_ROUTE=1
fi

# A preset only fills in what the shell has not already set, so an explicit
# override still wins over the size chosen on the command line.
if [ -n "$PRESET" ]; then
    MEASURE_SECONDS="${MEASURE_SECONDS:-$(config_number "${PRESET}_measure_seconds" 60)}"
    SAMPLES_PER_ROUTE="${SAMPLES_PER_ROUTE:-$(config_number "${PRESET}_samples_per_route" 3)}"
    export MEASURE_SECONDS SAMPLES_PER_ROUTE
fi

MEASURE_SECONDS="$(env_value MEASURE_SECONDS "$(config_number measure_seconds 60)")"
SAMPLES_PER_ROUTE="$(env_value SAMPLES_PER_ROUTE "$(config_number samples_per_route 3)")"
export MEASURE_SECONDS SAMPLES_PER_ROUTE

echo
announce_plan "$MEASURE_SECONDS" "$SAMPLES_PER_ROUTE"

./benchmarks/scripts/run-matrix.sh "${RUNTIMES[@]}"
