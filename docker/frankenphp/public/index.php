<?php

declare(strict_types=1);

/**
 * FrankenPHP adapter, worker mode.
 *
 * This file is the document root's index.php on purpose: FrankenPHP only hands
 * a request to a worker when the script the request resolves to *is* the
 * worker's file. With the worker outside the root, the server serves the
 * classic entrypoint instead and the worker never sees a request — which from
 * the outside is indistinguishable from working worker mode.
 *
 * FrankenPHP does not hand the script a request object. It calls back into a
 * closure with PHP's ordinary superglobals populated, so the code inside the
 * callback reads exactly like the FPM entrypoint; the difference is that
 * everything outside the loop is paid once, at worker startup, instead of per
 * request. Having both this and classic mode on the same server is what lets
 * the lab separate "persistent worker" from "which server".
 */

require __DIR__ . '/../app/src/bootstrap.php';

use RuntimeLab\Http\Request;
use RuntimeLab\Routing\RouteRegistry;

const DEFAULT_RUNTIME_NAME = 'frankenphp-worker';

$runtimeName = getenv('BENCHMARK_RUNTIME') ?: DEFAULT_RUNTIME_NAME;
const DEFAULT_MAX_REQUESTS = 500;

// Paid once per worker, not once per request — the cost this mode exists to
// avoid.
$router = RouteRegistry::build();

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

// Worker recycling is equalised with every other runtime in the lab.
//
// Zero means never recycle, which is what every runtime here except Octane
// defaults to. It has to be handled explicitly: a plain `while (handled < max)`
// would evaluate false immediately and the worker would exit before serving
// anything.
$maxRequests = environmentInteger('APP_MAX_REQUESTS', DEFAULT_MAX_REQUESTS);
$isRecyclingEnabled = $maxRequests > 0;
$handledRequests = 0;

$handleRequest = static function () use ($router, $runtimeName): void {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

    $request = new Request($path);
    $response = $router->dispatch($request, $runtimeName);

    http_response_code($response->statusCode->value);
    header('Content-Type: application/json');
    echo $response->toJson();
};

while (!$isRecyclingEnabled || $handledRequests < $maxRequests) {
    // Returns false once the server is shutting down or the worker is being
    // recycled; breaking out lets FrankenPHP start a fresh one.
    $keepRunning = frankenphp_handle_request($handleRequest);
    $handledRequests++;

    if (!$keepRunning) {
        break;
    }
}
