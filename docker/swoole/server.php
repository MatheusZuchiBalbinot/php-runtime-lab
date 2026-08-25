<?php

declare(strict_types=1);

/**
 * Swoole adapter: translates Swoole's native HTTP request/response into the
 * shared Request/Response DTOs and dispatches through the shared Router.
 */

require __DIR__ . '/app/src/bootstrap.php';

use RuntimeLab\Http\Request;
use RuntimeLab\Routing\RouteRegistry;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;
use Swoole\Runtime;

const RUNTIME_NAME = 'swoole';

// Fallback only. The real value comes from APP_WORKERS, the single worker
// budget every runtime in the lab is given, derived from the CPU budget.
const DEFAULT_MAX_REQUESTS = 500;
const DEFAULT_WORKER_COUNT = 4;
const SERVER_HOST = '0.0.0.0';
const SERVER_PORT = 8080;

/**
 * Hooks blocking built-ins (usleep, streams, etc.) so they yield the
 * coroutine instead of blocking the whole worker. Required for the
 * io-bound benchmark route to actually exercise Swoole's concurrency model
 * instead of blocking like a plain FPM worker would.
 */
Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

/**
 * Reads a numeric setting from the environment.
 *
 * Not `getenv(...) ?: $default`: the elvis operator treats the string "0" as
 * false, so a deliberate zero silently becomes the default — and zero is a
 * meaningful value for every setting read through here.
 */
function environmentInteger(string $name, int $default): int
{
    $value = getenv($name);
    $isUnset = $value === false || $value === '';

    return $isUnset ? $default : (int) $value;
}


$workerCount = environmentInteger('APP_WORKERS', DEFAULT_WORKER_COUNT);
$router = RouteRegistry::build();

$server = new Server(SERVER_HOST, SERVER_PORT);

// Worker recycling is equalised across every runtime in the lab, because
// recycling costs a full bootstrap and an uneven policy would credit that cost
// to the concurrency model instead of to the policy. Zero means never recycle,
// which is what every runtime here except Octane defaults to.
$maxRequestsPerWorker = environmentInteger('APP_MAX_REQUESTS', DEFAULT_MAX_REQUESTS);

$serverSettings = [
    'worker_num' => $workerCount,
    'daemonize' => false,
    'max_request' => $maxRequestsPerWorker,
    'enable_coroutine' => true,
    // max_wait_time is left at Swoole's default of 3 seconds. Raising it looks
    // like an improvement and is not.
    //
    // It bounds how long the manager waits for a recycling worker to drain
    // before killing it. A coroutine worker holds many requests in flight, and
    // one parked on hooked network I/O never unwinds — so it holds the worker
    // until the cap expires, however large the cap is. Raising the cap does not
    // rescue that coroutine; it only makes every other worker wait longer to
    // drain instead of serving, which costs throughput by more than an order of
    // magnitude on the I/O routes.
    //
    // Inert while recycling is off, since it then only bounds drain at
    // shutdown. It applies again the moment APP_MAX_REQUESTS is set above zero.
];

$server->set($serverSettings);

/**
 * Per-request callback: builds the shared Request DTO from Swoole's native
 * request, dispatches it through the shared Router, and writes the shared
 * Response DTO back onto Swoole's native response.
 */
$server->on('request', static function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) use ($router): void {
    $path = $swooleRequest->server['request_uri'] ?? '/';

    $request = new Request($path);
    $response = $router->dispatch($request, RUNTIME_NAME);

    $swooleResponse->status($response->statusCode->value);
    $swooleResponse->header('Content-Type', 'application/json');
    $swooleResponse->end($response->toJson());
});

$server->start();
