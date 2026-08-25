<?php

declare(strict_types=1);

namespace RuntimeLab\Handlers;

use RuntimeLab\Http\HttpStatusCode;
use RuntimeLab\Http\Request;
use RuntimeLab\Http\Response;
use RuntimeLab\Http\ResponseEnvelope;
use RuntimeLab\Routing\RouteHandlerInterface;

/**
 * Does no work at all — and that is the measurement.
 *
 * What remains is the runtime's own per-request cost: accepting the connection,
 * building a request, routing, serialising a small response. It is where a
 * runtime that re-bootstraps per request is compared against one that does not
 * with nothing diluting the difference, and where a framework's cost shows up
 * against the vanilla stack.
 *
 * Every other route adds work on top of this floor.
 */
final class NoopHandler implements RouteHandlerInterface
{
    public function handle(Request $request, string $runtime): Response
    {
        return new Response(HttpStatusCode::OK, ResponseEnvelope::ok($runtime));
    }
}
