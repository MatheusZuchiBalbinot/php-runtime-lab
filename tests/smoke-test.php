<?php

declare(strict_types=1);

/**
 * Dependency-free smoke test for the shared application layer.
 *
 * It exercises the same Router the three runtime adapters dispatch through,
 * without going over HTTP, so a broken handler or a malformed config file is
 * caught in seconds instead of surfacing as a mysterious benchmark result.
 * Deliberately plain PHP rather than PHPUnit: the project's premise is that
 * nothing but Docker is needed to work on it.
 *
 * Run with: docker compose --profile test run --rm test
 */

require __DIR__ . '/../app/src/bootstrap.php';

use RuntimeLab\Http\HttpStatusCode;
use RuntimeLab\Http\Request;
use RuntimeLab\Routing\RouteRegistry;
use RuntimeLab\Support\Json;

const TEST_RUNTIME_NAME = 'test';
const EXIT_CODE_FAILURE = 1;

/** @var list<string> */
$failures = [];

/**
 * Records a failure unless the condition holds.
 */
function check(bool $hasPassed, string $description): void
{
    global $failures;

    if ($hasPassed) {
        echo "  ok   {$description}\n";

        return;
    }

    echo "  FAIL {$description}\n";
    $failures[] = $description;
}

echo "Routing\n";

$routeDefinitions = Json::decodeFile(__DIR__ . '/../routes.json');
$router = RouteRegistry::build();

// Every route declared in routes.json must resolve to a handler that answers
// 200 with a well-formed envelope. This is what stops routes.json and the
// handler wiring in RouteRegistry from drifting apart.
foreach ($routeDefinitions as $route) {
    $path = $route['path'];

    // Routes flagged in routes.json reach out to a live service. This test is
    // deliberately dependency-free — it runs on the stock PHP image with no
    // network — so those are checked only for being wired to a handler; their
    // behaviour is covered by the HTTP verification against a running stack.
    $needsLiveDependency = ($route['requires_dependency'] ?? false) === true;

    if ($needsLiveDependency) {
        $isWired = $router->hasRoute($path);

        check($isWired, "{$path} is wired to a handler (dependency not called here)");

        continue;
    }

    $response = $router->dispatch(new Request($path), TEST_RUNTIME_NAME);
    $body = $response->body;

    $respondsOk = $response->statusCode === HttpStatusCode::OK;
    $carriesEnvelope = ($body['status'] ?? null) === 'ok'
        && ($body['runtime'] ?? null) === TEST_RUNTIME_NAME;
    $reportsWorkerStats = isset($body['worker_requests'], $body['memory_bytes'], $body['memory_peak_bytes']);
    $serializesToJson = json_decode($response->toJson(), true) !== null;

    check($respondsOk, "{$path} responds 200");
    check($carriesEnvelope, "{$path} carries the shared envelope");
    check($reportsWorkerStats, "{$path} reports worker stats");
    check($serializesToJson, "{$path} serializes to valid JSON");
}

$unknownResponse = $router->dispatch(new Request('/definitely-not-a-route'), TEST_RUNTIME_NAME);
$respondsNotFound = $unknownResponse->statusCode === HttpStatusCode::NOT_FOUND;
$unknownUsesEnvelope = ($unknownResponse->body['status'] ?? null) === 'not_found';

check($respondsNotFound, 'unknown path responds 404');
check($unknownUsesEnvelope, 'unknown path uses the shared envelope');

echo "\nWorker stats\n";

// The request counter is what makes memory creep interpretable, so it has to
// actually advance as the worker handles requests.
$homeRequest = new Request('/');
$beforeCount = $router->dispatch($homeRequest, TEST_RUNTIME_NAME)->body['worker_requests'];
$afterCount = $router->dispatch($homeRequest, TEST_RUNTIME_NAME)->body['worker_requests'];
$counterAdvanced = $afterCount === $beforeCount + 1;

check($counterAdvanced, 'request counter advances per dispatch');

echo "\nStatus codes\n";

// Only the reason phrases are worth asserting: a case's ->value is fixed by
// the enum declaration itself, so comparing it to a literal tests nothing.
// The phrases come from a match() and can genuinely be wrong.
$hasRegisteredPhrases = HttpStatusCode::NOT_FOUND->reasonPhrase() === 'Not Found'
    && HttpStatusCode::INTERNAL_SERVER_ERROR->reasonPhrase() === 'Internal Server Error';
$classifiesSuccess = HttpStatusCode::OK->isSuccessful() && !HttpStatusCode::OK->isError();
$classifiesError = HttpStatusCode::INTERNAL_SERVER_ERROR->isError();

check($hasRegisteredPhrases, 'reason phrases match the registered names');
check($classifiesSuccess, '2xx classifies as successful');
check($classifiesError, '5xx classifies as an error');

// Every case must have a reason phrase: the match() in reasonPhrase() is
// exhaustive, so a case added without one raises \UnhandledMatchError here
// rather than in production.
$hasPhraseForEveryCase = true;
foreach (HttpStatusCode::cases() as $statusCode) {
    if ($statusCode->reasonPhrase() === '') {
        $hasPhraseForEveryCase = false;
    }
}
check($hasPhraseForEveryCase, 'every status code has a reason phrase');

echo "\nPerformance config\n";

// Guards the silent-zero failure mode: a mistyped key must raise, not quietly
// produce a workload that does nothing.
try {
    RuntimeLab\Config\PerformanceConfig::cpuIterations();
    RuntimeLab\Config\PerformanceConfig::blockingWaitMicroseconds();
    RuntimeLab\Config\PerformanceConfig::memoryRetainedMebibytes();
    RuntimeLab\Config\PerformanceConfig::memoryChurnCycles();
    check(true, 'performance.json parses and validates');
} catch (Throwable $exception) {
    check(false, 'performance.json parses and validates: ' . $exception->getMessage());
}

echo "\nRoute labels\n";

// Each label becomes a k6 metric name ("<label>_duration"), and k6 rejects
// anything outside letters, numbers and underscores — a hyphen there fails the
// whole run partway through a sweep, minutes in. Catching it here turns that
// into an instant, obvious failure.
$hasK6SafeLabels = true;
foreach ($routeDefinitions as $route) {
    $label = $route['label'];
    $isK6SafeLabel = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $label) === 1;

    if (!$isK6SafeLabel) {
        echo "       offending label: {$label}\n";
        $hasK6SafeLabels = false;
    }
}
check($hasK6SafeLabels, 'every route label is usable as a k6 metric name');

echo "\nWorkload shape\n";

// The memory route only means something if the payload is still resident when
// the response is built. A payload freed before the envelope is assembled
// measures nothing, and the route still answers 200 while doing so.
$memoryBody = $router->dispatch(new Request('/bench/memory'), TEST_RUNTIME_NAME)->body;
$retainedMebibytes = RuntimeLab\Config\PerformanceConfig::memoryRetainedMebibytes();
$expectedRetainedBytes = $retainedMebibytes * 1024 * 1024;
$holdsPayload = ($memoryBody['retained_bytes_live'] ?? 0) === $expectedRetainedBytes;

check($holdsPayload, 'memory route holds its payload for the whole request');

// noop must stay trivial: it is the baseline every other route is read
// against, so any work creeping into it would shift the whole comparison.
$noopBody = $router->dispatch(new Request('/bench/noop'), TEST_RUNTIME_NAME)->body;
$hasWorkloadFields = isset($noopBody['hash'], $noopBody['record_count'], $noopBody['retained_mebibytes']);

check(!$hasWorkloadFields, 'noop route carries no workload fields');

$hasFailures = $failures !== [];

echo "\n";

if ($hasFailures) {
    echo count($failures) . " check(s) failed.\n";
    exit(EXIT_CODE_FAILURE);
}

echo "All checks passed.\n";
