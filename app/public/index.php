<?php

declare(strict_types=1);

/**
 * FPM adapter: translates PHP's global request superglobals into the
 * shared Request/Response DTOs and dispatches through the shared Router.
 */

require __DIR__ . '/../src/bootstrap.php';

use RuntimeLab\Http\Request;
use RuntimeLab\Routing\RouteRegistry;

// Which runtime is answering comes from the environment because this same
// entrypoint is served by more than one of them: PHP-FPM behind nginx, and
// FrankenPHP in classic mode. A hardcoded name makes both report the same
// runtime and silently mislabels a column of the comparison.
const DEFAULT_RUNTIME_NAME = 'fpm';

$runtimeName = getenv('BENCHMARK_RUNTIME') ?: DEFAULT_RUNTIME_NAME;

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

$router = RouteRegistry::build();

$request = new Request($path);
$response = $router->dispatch($request, $runtimeName);

http_response_code($response->statusCode->value);
header('Content-Type: application/json');
echo $response->toJson();
