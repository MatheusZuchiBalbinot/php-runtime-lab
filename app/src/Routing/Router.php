<?php

declare(strict_types=1);

namespace RuntimeLab\Routing;

use RuntimeLab\Http\HttpStatusCode;
use RuntimeLab\Http\Request;
use RuntimeLab\Http\Response;
use RuntimeLab\Http\ResponseEnvelope;
use RuntimeLab\Runtime\WorkerStats;

/**
 * Maps request paths to RouteHandlerInterface instances and dispatches
 * incoming requests to the matching handler. Built by RouteRegistry from
 * routes.json; it is not responsible for knowing which handler class belongs
 * to which path itself.
 */
final class Router
{
    /** @var array<string, RouteHandlerInterface> */
    private array $handlersByPath = [];

    /**
     * Registers a handler for an exact request path.
     */
    public function register(string $path, RouteHandlerInterface $handler): void
    {
        $this->handlersByPath[$path] = $handler;
    }

    /**
     * Whether a handler is registered for this exact path — checked without
     * invoking it, so routes that reach a live dependency can be verified
     * without performing real I/O.
     */
    public function hasRoute(string $path): bool
    {
        return array_key_exists($path, $this->handlersByPath);
    }

    /**
     * Dispatches a request to its registered handler, or returns a 404 if no
     * handler is registered for the request's path.
     */
    public function dispatch(Request $request, string $runtime): Response
    {
        // Done here rather than in the handlers so that every request is
        // counted exactly once, including the ones that do not match a route,
        // and so the memory baseline is taken before any handler work — a
        // baseline taken inside a handler would exclude whatever the dispatch
        // itself allocated.
        WorkerStats::beginRequest();

        $handler = $this->handlersByPath[$request->path] ?? null;
        $isRouteRegistered = $handler !== null;

        if (!$isRouteRegistered) {
            return new Response(
                HttpStatusCode::NOT_FOUND,
                ResponseEnvelope::notFound($runtime, $request->path),
            );
        }

        return $handler->handle($request, $runtime);
    }
}
