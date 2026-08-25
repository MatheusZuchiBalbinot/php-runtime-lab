<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RuntimeLab\Http\Request as LabRequest;
use RuntimeLab\Routing\Router;
use RuntimeLab\Routing\RouteRegistry;

/**
 * Laravel adapter for the shared benchmark workloads.
 *
 * This is the same adapter pattern the vanilla runtimes use: translate the
 * framework's native request into the shared DTO, dispatch through the shared
 * Router, translate the shared Response back. Reusing the very same handler
 * classes is what makes the framework-overhead comparison meaningful — whatever
 * difference shows up against the vanilla app is the cost of the framework,
 * not of different business logic.
 */
final class BenchmarkController extends Controller
{
    private readonly Router $router;

    public function __construct()
    {
        $this->router = RouteRegistry::build();
    }

    /**
     * Handles any benchmark route, dispatching on the request path.
     *
     * The runtime label is read from config (set per deployment as
     * laravel-fpm, laravel-octane-swoole, ...) rather than taken as an
     * argument, so a response says which stack produced it.
     */
    public function __invoke(\Illuminate\Http\Request $request): JsonResponse
    {
        $runtime = (string) config('benchmark.runtime', 'laravel');

        // Laravel's path() has no leading slash; the shared Router matches
        // routes that do.
        $normalizedPath = '/' . ltrim($request->path(), '/');
        $labRequest = new LabRequest($normalizedPath);

        $response = $this->router->dispatch($labRequest, $runtime);

        return new JsonResponse($response->body, $response->statusCode->value);
    }
}
