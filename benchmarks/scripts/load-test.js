import http from 'k6/http';
import { Counter, Rate, Trend } from 'k6/metrics';

// routes.json is the single source of truth for which paths exist, shared
// with app/src/Routing/RouteRegistry.php on the PHP side. Adding/removing a
// benchmark route here never requires touching this script.
const ROUTE_DEFINITIONS = JSON.parse(open('/routes.json'));

// ONLY_ROUTE narrows the run to a single workload. sweep.sh relies on it:
// routes saturate at very different loads, so measuring them together would
// let the weakest one set the pace for all of them.
const ONLY_ROUTE = __ENV.ONLY_ROUTE || '';

// Only the liveness route is excluded. The trivial `noop` route is measured
// deliberately: with the workload removed, what is left is the runtime's own
// per-request cost, which is the purest read on bootstrap-per-request versus
// persistent worker — the comparison this lab exists to make.
const BENCHMARK_ROUTES = ROUTE_DEFINITIONS
    .filter((route) => route.label !== 'health')
    .filter((route) => ONLY_ROUTE === '' || route.label === ONLY_ROUTE);

if (BENCHMARK_ROUTES.length === 0) {
    throw new Error(`ONLY_ROUTE="${ONLY_ROUTE}" matches no benchmark route in routes.json.`);
}

// performance.json is the same file the PHP side reads for workload
// intensity (App\Config\PerformanceConfig). Its "load" section holds the
// client-side half of the same question — how hard to push — so that every
// tunable that shapes a benchmark run lives in one file instead of being
// split between the app and the load generator.
const PERFORMANCE_CONFIG = JSON.parse(open('/performance.json'));
const LOAD_CONFIG = PERFORMANCE_CONFIG.load;

const TARGET_URL = __ENV.TARGET_URL || 'http://localhost:8080';
const RESULT_FILE = __ENV.RESULT_FILE || 'result.json';

// Environment variables override the file, so a one-off run ("just try 500
// rps") never requires editing committed config.
const MEASURE_SECONDS = Number(__ENV.MEASURE_SECONDS || LOAD_CONFIG.measure_seconds);
const TARGET_RPS = Number(__ENV.TARGET_RPS || LOAD_CONFIG.target_rps);

// Routes are measured one at a time, so each one needs an exclusive slice of
// wall-clock time. k6's default gracefulStop is 30s, which lets a finished
// scenario keep draining in-flight iterations long into the next scenario's
// window — two workloads would end up hitting the runtime at once and the
// per-route numbers would silently describe a mixture. Each route therefore
// gets: its measured window, a bounded drain, and an idle cooldown that lets
// the runtime settle (GC, worker recycling) before the next workload starts.
const GRACEFUL_STOP_SECONDS = Number(__ENV.GRACEFUL_STOP_SECONDS || LOAD_CONFIG.graceful_stop_seconds);
const COOLDOWN_SECONDS = Number(__ENV.COOLDOWN_SECONDS || LOAD_CONFIG.cooldown_seconds);

// Traffic sent before the measured window and thrown away. Without it the cold
// cost of the first requests lands inside the sample — and unevenly, since a
// persistent worker pays compilation once and then runs warm while FPM never
// warms, so measuring cold understates the very advantage being quantified.
const WARMUP_SECONDS = Number(__ENV.WARMUP_SECONDS || LOAD_CONFIG.warmup_seconds);

const SCENARIO_SLOT_SECONDS =
    WARMUP_SECONDS + MEASURE_SECONDS + GRACEFUL_STOP_SECONDS + COOLDOWN_SECONDS;

// Evaluated and reported, never used to search for a rate. Without a latency
// threshold a runtime that answers every request eventually — 12s p99 under an
// overloaded pool, say — would be scored as a clean pass, because slow
// responses are still HTTP 200.
const LATENCY_BUDGET_P95_MS = Number(__ENV.LATENCY_BUDGET_P95_MS || LOAD_CONFIG.latency_budget_p95_ms);
const ERROR_RATE_BUDGET = Number(__ENV.ERROR_RATE_BUDGET || LOAD_CONFIG.error_rate_budget);

// Two profiles, answering two different questions:
//
//   constant  holds a target arrival rate for the whole window. For asking
//             "can it sustain N?" — and the answer only means something if N
//             was actually held for the duration.
//   overload  closed loop, no target at all. Each VU sends, waits, sends
//             again, so throughput self-limits to what the server retires.
//             This is "exhaust it": the ceiling in requests/s, with no
//             latency budget applied. Every sweep uses this one.
//
// There is deliberately no third, ramping profile. A ramp from 0 to target
// touches the target only in its final instant, so it mostly measures how
// quickly the runtime gets out of the way rather than what it can sustain —
// not a useful default for either question above, and a default nobody would
// choose on purpose is a trap, not an option.
//
// Overload is deliberately closed loop. An open loop fired far above capacity
// queues rather than measures: the reported latency describes the queue, and
// throughput comes out lower than the server can actually retire. A queue is
// not a throughput measurement.
const LOAD_PROFILE = __ENV.LOAD_PROFILE || 'constant';
const IS_OVERLOAD_PROFILE = LOAD_PROFILE === 'overload';

const OVERLOAD_VUS = Number(__ENV.OVERLOAD_VUS || PERFORMANCE_CONFIG.overload.vus);

// An arrival-rate executor needs a VU for every request still in flight. If
// the pool runs out, k6 logs "Insufficient VUs" and quietly applies less load
// than asked for — the reported RPS would then be a limit of the load
// generator, not of the runtime under test.
//
// The ceiling is therefore derived from Little's law at the edge of the
// budget (a request allowed to take the full p95 budget needs
// rate x budget VUs in flight), doubled for headroom, so it scales with the
// load being requested instead of being a fixed number that silently caps
// high-RPS runs. An explicit MAX_VUS still wins.
//
// The derivation assumes the worst allowed latency, so it over-provisions
// heavily on fast routes; a ceiling keeps that from asking for more VUs than
// the k6 container can hold. Hitting the ceiling is not silent — the run then
// falls short of its target and is reported as load_applied: false.
const VUS_REQUIRED_AT_BUDGET = Math.ceil(TARGET_RPS * (LATENCY_BUDGET_P95_MS / 1000) * 2);
const MAX_VUS = Number(
    __ENV.MAX_VUS
        || Math.min(
            Math.max(LOAD_CONFIG.max_vus, VUS_REQUIRED_AT_BUDGET),
            LOAD_CONFIG.max_vus_ceiling,
        ),
);
const PRE_ALLOCATED_VUS = Number(
    __ENV.PRE_ALLOCATED_VUS || Math.min(LOAD_CONFIG.pre_allocated_vus, MAX_VUS),
);

const durationTrendsByLabel = {};
const requestCountersByLabel = {};
const errorRatesByLabel = {};


for (const route of BENCHMARK_ROUTES) {
    durationTrendsByLabel[route.label] = new Trend(`${route.label}_duration`, true);
    requestCountersByLabel[route.label] = new Counter(`${route.label}_requests`);
    errorRatesByLabel[route.label] = new Rate(`${route.label}_errors`);
}

// Budgets are asserted per route, not just globally: the routes exercise
// deliberately different workloads, so one route collapsing would otherwise
// be averaged away by three healthy ones.
// k6 built-ins worth keeping, each scoped to the measured phase.
//
// `http_req_duration` is the number everyone quotes, but it is a sum:
// blocked + connecting + sending + waiting + receiving. Only `waiting` is the
// server thinking; `blocked` is time spent queueing for a connection slot,
// which belongs to the client and the socket layer. Reporting only the sum
// makes a connection-queue problem indistinguishable from a slow runtime,
// which matters exactly here, where the whole method is to overload on
// purpose.
const MEASURED_PHASE_TRENDS = {
    waiting: 'http_req_waiting{phase:measured}',
    // Time spent writing the request onto the socket. Easy to dismiss as
    // always-zero and it is not: if the server stops draining its receive
    // buffer, the client blocks here, and the stall shows up in the total
    // while every other phase stays small. A tail that appears in the sum but
    // in none of its parts is a phase that is not being measured.
    sending: 'http_req_sending{phase:measured}',
    blocked: 'http_req_blocked{phase:measured}',
    connecting: 'http_req_connecting{phase:measured}',
    receiving: 'http_req_receiving{phase:measured}',
};

/**
 * k6 only materialises a tagged submetric when something references it, so
 * every entry above needs a threshold to exist at all. These are declared
 * unbreakable on purpose: they are here to create the submetric, not to
 * assert anything, and the overload profile must never fail on a budget.
 */
function buildSubmetricThresholds() {
    const thresholds = {
        // Also what keeps the run-level error rate from reading as a silent
        // null: it is this submetric, and without a threshold naming it here,
        // k6 never creates it.
        [`http_req_failed{phase:measured}`]: ['rate>=0'],
    };

    for (const metricName of Object.values(MEASURED_PHASE_TRENDS)) {
        thresholds[metricName] = ['max>=0'];
    }

    return thresholds;
}

function buildThresholds() {
    // Overload has no budget to breach — breaching it is the point — so it
    // carries only the submetric scaffolding and never exits with a failure
    // code.
    if (IS_OVERLOAD_PROFILE) {
        return buildSubmetricThresholds();
    }

    // Scoped to the measured phase so warmup traffic cannot fail the run.
    const thresholds = {
        ...buildSubmetricThresholds(),
        [`http_req_failed{phase:measured}`]: [`rate<${ERROR_RATE_BUDGET}`],
    };

    for (const route of BENCHMARK_ROUTES) {
        thresholds[`${route.label}_duration`] = [`p(95)<${LATENCY_BUDGET_P95_MS}`];
        thresholds[`${route.label}_errors`] = [`rate<${ERROR_RATE_BUDGET}`];
    }

    return thresholds;
}

function buildScenarios() {
    const scenarios = {};

    BENCHMARK_ROUTES.forEach((route, index) => {
        const slotStartSeconds = index * SCENARIO_SLOT_SECONDS;
        const startTimeSeconds = slotStartSeconds + WARMUP_SECONDS;

        const sharedSettings = {
            exec: 'runBenchmarkRoute',
            env: { ROUTE_LABEL: route.label, ROUTE_PATH: route.path },
            startTime: `${startTimeSeconds}s`,
            gracefulStop: `${GRACEFUL_STOP_SECONDS}s`,
            timeUnit: '1s',
            preAllocatedVUs: PRE_ALLOCATED_VUS,
            maxVUs: MAX_VUS,
        };

        // Runs the same route at the same rate immediately before the measured
        // window, through an exec function that records nothing — the point is
        // to leave the runtime warm, not to collect data from it.
        if (WARMUP_SECONDS > 0) {
            scenarios[`${route.label}_warmup`] = IS_OVERLOAD_PROFILE
                ? {
                    executor: 'constant-vus',
                    exec: 'warmupRoute',
                    env: { ROUTE_PATH: route.path },
                    startTime: `${slotStartSeconds}s`,
                    duration: `${WARMUP_SECONDS}s`,
                    gracefulStop: '0s',
                    vus: OVERLOAD_VUS,
                }
                : {
                    executor: 'constant-arrival-rate',
                    exec: 'warmupRoute',
                    env: { ROUTE_PATH: route.path },
                    startTime: `${slotStartSeconds}s`,
                    duration: `${WARMUP_SECONDS}s`,
                    gracefulStop: '0s',
                    rate: TARGET_RPS,
                    timeUnit: '1s',
                    preAllocatedVUs: PRE_ALLOCATED_VUS,
                    maxVUs: MAX_VUS,
                };
        }

        if (IS_OVERLOAD_PROFILE) {
            // Built explicitly rather than spread from sharedSettings:
            // constant-vus rejects preAllocatedVUs/maxVUs/timeUnit, which only
            // mean something to the arrival-rate executors. k6 fails the whole
            // run on an unknown field rather than ignoring it.
            scenarios[route.label] = {
                executor: 'constant-vus',
                exec: 'runBenchmarkRoute',
                env: { ROUTE_LABEL: route.label, ROUTE_PATH: route.path },
                startTime: `${startTimeSeconds}s`,
                gracefulStop: `${GRACEFUL_STOP_SECONDS}s`,
                vus: OVERLOAD_VUS,
                duration: `${MEASURE_SECONDS}s`,
            };

            return;
        }

        scenarios[route.label] = {
            ...sharedSettings,
            executor: 'constant-arrival-rate',
            rate: TARGET_RPS,
            duration: `${MEASURE_SECONDS}s`,
        };
    });

    return scenarios;
}

export const options = {
    scenarios: buildScenarios(),
    // k6 only exposes the percentiles listed here on metric.values inside
    // handleSummary — p(99) has to be requested explicitly or it's silently
    // absent from the exported JSON.
    summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'p(99)'],
    thresholds: buildThresholds(),
};

// Warms the runtime without recording anything: no custom metric is touched,
// so nothing from this phase reaches the per-route verdict.
export function warmupRoute() {
    // Tagged so the built-in metrics can tell warmup apart from the measured
    // window; without that, a slow or failed cold request would count against
    // the global error threshold and fail a run that was actually fine.
    http.get(`${TARGET_URL}${__ENV.ROUTE_PATH}`, { tags: { phase: 'warmup' } });
}

export function runBenchmarkRoute() {
    const routeLabel = __ENV.ROUTE_LABEL;
    const routePath = __ENV.ROUTE_PATH;

    const response = http.get(`${TARGET_URL}${routePath}`, {
        tags: { route: routeLabel, phase: 'measured' },
    });

    const isFailedRequest = response.status !== 200;

    durationTrendsByLabel[routeLabel].add(response.timings.duration);
    requestCountersByLabel[routeLabel].add(1);
    errorRatesByLabel[routeLabel].add(isFailedRequest);

    // Deliberately nothing else here. Parsing the response body to read the
    // runtime's own per-request memory figure costs enough to make the
    // generator the bottleneck, even when sampling one response in fifty.
    // That figure does not depend on the load level — it is a property of the
    // workload — so sweep.sh probes it after the measured window, where it
    // costs the measurement nothing.
}


/**
 * Splits the request time into its phases, at the median and the 95th.
 *
 * Returned flat (waiting_p50_ms, blocked_p95_ms, ...) so the shell summariser
 * can read each one with the same field lookup it uses for everything else.
 */
function breakdownOf(data) {
    const breakdown = {};

    for (const [phase, metricName] of Object.entries(MEASURED_PHASE_TRENDS)) {
        const metric = data.metrics[metricName];

        breakdown[`${phase}_p50_ms`] = metric ? metric.values.med : null;
        breakdown[`${phase}_p95_ms`] = metric ? metric.values['p(95)'] : null;
        // The tail is carried per phase, not just for the total: when total
        // p99 jumps by two orders of magnitude over p95, this is what says
        // which phase the stall is actually in.
        breakdown[`${phase}_p99_ms`] = metric ? metric.values['p(99)'] : null;
        breakdown[`${phase}_max_ms`] = metric ? metric.values.max : null;
    }

    return breakdown;
}

export function handleSummary(data) {
    const perRouteSummary = {};

    for (const route of BENCHMARK_ROUTES) {
        const requestsMetric = data.metrics[`${route.label}_requests`];
        const durationMetric = data.metrics[`${route.label}_duration`];
        const errorsMetric = data.metrics[`${route.label}_errors`];
        const hasRouteData = requestsMetric !== undefined && durationMetric !== undefined;

        if (!hasRouteData) {
            perRouteSummary[route.label] = null;
            continue;
        }

        const p95Ms = durationMetric.values['p(95)'];
        const errorRate = errorsMetric !== undefined ? errorsMetric.values.rate : null;
        const achievedRps = requestsMetric.values.count / MEASURE_SECONDS;

        // Under the constant profile the generator is supposed to hold the
        // exact target for the whole window. Falling short means k6 itself ran
        // out of capacity, so the run describes the generator rather than the
        // runtime, and a "pass" at a load that was never applied would be a
        // lie.
        //
        // Closed loop has no target to fall short of: whatever came out is the
        // measurement, so the check is skipped there.
        const wasLoadApplied = IS_OVERLOAD_PROFILE || achievedRps >= TARGET_RPS * 0.95;

        const isWithinBudget = p95Ms < LATENCY_BUDGET_P95_MS && errorRate < ERROR_RATE_BUDGET;

        perRouteSummary[route.label] = {
            requests: requestsMetric.values.count,
            target_rps: TARGET_RPS,
            avg_rps: achievedRps,
            avg_ms: durationMetric.values.avg,
            // The whole distribution, not just its tail. k6 already computes
            // every one of these — leaving them out of the export cost nothing
            // to fix and everything to diagnose: a mean above the p95 means
            // the shape is bimodal, and without the median there is no way to
            // tell a runtime that is uniformly slow from one that is fast
            // with a stalling tail.
            p50_ms: durationMetric.values.med,
            p90_ms: durationMetric.values['p(90)'],
            p95_ms: p95Ms,
            p99_ms: durationMetric.values['p(99)'],
            max_ms: durationMetric.values.max,
            // Where the time actually went. See MEASURED_PHASE_TRENDS.
            ...breakdownOf(data),
            bytes_received: data.metrics.data_received
                ? data.metrics.data_received.values.count
                : null,
            // Response size is a runtime property too: the same handler behind
            // a framework can ship different headers, and bytes on the wire
            // become the bottleneck before the CPU does on a trivial route.
            bytes_per_response: data.metrics.data_received && requestsMetric.values.count > 0
                ? data.metrics.data_received.values.count / requestsMetric.values.count
                : null,
            error_rate: errorRate,
            // Whether the generator actually delivered the requested load.
            load_applied: wasLoadApplied,
            // Whether this route held the budget at this load — the actual
            // answer the comparison table is built from.
            within_budget: isWithinBudget,
        };
    }

    const summary = {
        target_url: TARGET_URL,
        generated_at: new Date().toISOString(),
        load_profile: LOAD_PROFILE,
        overload_vus: IS_OVERLOAD_PROFILE ? OVERLOAD_VUS : null,
        measure_seconds: MEASURE_SECONDS,
        target_rps: TARGET_RPS,
        max_vus: MAX_VUS,
        graceful_stop_seconds: GRACEFUL_STOP_SECONDS,
        cooldown_seconds: COOLDOWN_SECONDS,
        latency_budget_p95_ms: LATENCY_BUDGET_P95_MS,
        error_rate_budget: ERROR_RATE_BUDGET,
        warmup_seconds: WARMUP_SECONDS,
        // The measured-phase submetric, so warmup traffic is excluded.
        error_rate: data.metrics['http_req_failed{phase:measured}']
            ? data.metrics['http_req_failed{phase:measured}'].values.rate
            : null,
        routes: perRouteSummary,
    };

    const summaryJson = JSON.stringify(summary, null, 2);

    return {
        [`/results/${RESULT_FILE}`]: summaryJson,
        stdout: summaryJson,
    };
}
