<?php

declare(strict_types=1);

namespace RuntimeLab\Handlers;

use RuntimeLab\Http\HttpStatusCode;
use RuntimeLab\Http\Request;
use RuntimeLab\Http\Response;
use RuntimeLab\Http\ResponseEnvelope;
use RuntimeLab\Routing\RouteHandlerInterface;

/**
 * Simple liveness/readiness check. Reports the runtime name, host and PHP
 * version so it also doubles as a quick way to confirm which runtime a
 * given container is actually serving.
 */
final class HealthCheckHandler implements RouteHandlerInterface
{
    public function handle(Request $request, string $runtime): Response
    {
        $responseFields = ['php_version' => PHP_VERSION];

        return new Response(HttpStatusCode::OK, ResponseEnvelope::ok($runtime, $responseFields));
    }
}
