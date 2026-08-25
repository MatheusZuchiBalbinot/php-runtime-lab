<?php

declare(strict_types=1);

namespace RuntimeLab\Handlers;

use RuntimeLab\Config\PerformanceConfig;
use RuntimeLab\Http\HttpStatusCode;
use RuntimeLab\Http\Request;
use RuntimeLab\Http\Response;
use RuntimeLab\Http\ResponseEnvelope;
use RuntimeLab\Routing\RouteHandlerInterface;

/**
 * Holds the request open for a fixed wait, measuring how each runtime handles
 * concurrency while requests are parked.
 *
 * Named for what it is: it sleeps, it does not perform I/O. No syscall on a
 * descriptor, no kernel wait for data. That makes it the *idealised best case*
 * for a coroutine runtime — Swoole hooks usleep and yields, so one worker parks
 * thousands of requests, while a sequential runtime blocks a worker per waiting
 * request and caps at `workers ÷ wait`.
 *
 * Read these numbers as the ceiling a concurrency model could reach, not as a
 * database call. For real I/O, see ExternalIoHandler.
 */
final class BlockingWaitHandler implements RouteHandlerInterface
{
    public function handle(Request $request, string $runtime): Response
    {
        $delayMicroseconds = PerformanceConfig::blockingWaitMicroseconds();

        usleep($delayMicroseconds);

        $responseFields = ['wait_microseconds' => $delayMicroseconds];

        return new Response(HttpStatusCode::OK, ResponseEnvelope::ok($runtime, $responseFields));
    }
}
