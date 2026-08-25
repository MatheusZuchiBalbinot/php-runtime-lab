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
 * Real I/O: an HTTP call to the dependency stub over a socket, with a real
 * syscall and a real kernel wait.
 *
 * This is the route that can contradict the rest of the lab. blocking-wait
 * sleeps, and Swoole hooks usleep() so a coroutine yields — an idealised best
 * case. Here the question is whether the runtime's *driver* yields, which is
 * what decides the answer in a real application. Unhooked, the worker blocks on
 * the socket exactly like PHP-FPM and the coroutine advantage disappears.
 *
 * The stub's delay defaults to the blocking-wait value, so the two form a
 * controlled pair: identical wait, one simulated and one real. The gap between
 * them is the cost of real I/O plus whatever the driver does.
 */
final class ExternalIoHandler implements RouteHandlerInterface
{
    private const int MILLISECONDS_PER_SECOND = 1000;

    /**
     * The pool only holds handles nobody is using, so its natural size is the
     * worker's peak concurrency. The cap stops a spike from leaving a worker
     * holding sockets open indefinitely.
     */
    private const int MAX_POOLED_HANDLES = 256;

    /**
     * Idle cURL handles, each holding a live connection to the dependency.
     *
     * Static, so the pool lives as long as the process. That is what makes this
     * route measure the execution model rather than the operating system: a
     * persistent worker keeps its connections between requests, a
     * process-per-request runtime starts empty every time. The difference is a
     * finding, not a bias.
     *
     * A pool rather than one shared handle: under a coroutine runtime several
     * requests run concurrently inside one worker, and one handle used by two
     * of them would interleave their state. A handle leaves the pool for the
     * whole time it is in use, and taking it involves no I/O, so no coroutine
     * can switch midway and observe it half-claimed.
     *
     * @var list<\CurlHandle>
     */
    private static array $idleHandles = [];

    public function handle(Request $request, string $runtime): Response
    {
        $dependencyUrl = PerformanceConfig::externalIoUrl();
        $timeoutMilliseconds = PerformanceConfig::externalIoTimeoutMilliseconds();

        $curlHandle = self::acquireHandle($dependencyUrl, $timeoutMilliseconds);

        if ($curlHandle === null) {
            $failureFields = ['dependency_error' => 'curl_init failed'];

            return new Response(
                HttpStatusCode::INTERNAL_SERVER_ERROR,
                ResponseEnvelope::ok($runtime, $failureFields),
            );
        }

        $responseBody = curl_exec($curlHandle);
        $upstreamStatus = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curlHandle);

        $didCallSucceed = $responseBody !== false && $upstreamStatus === HttpStatusCode::OK->value;

        // A failed handle may sit on a half-closed socket; pooling it would
        // hand that failure to the next request.
        if ($didCallSucceed) {
            self::releaseHandle($curlHandle);
        } else {
            curl_close($curlHandle);
        }

        // A failing dependency must not answer 200: the sweep would read a fast
        // error as high throughput.
        if (!$didCallSucceed) {
            $failureFields = [
                'dependency_status' => $upstreamStatus,
                'dependency_error' => $curlError !== '' ? $curlError : 'unexpected status',
            ];

            return new Response(
                HttpStatusCode::BAD_GATEWAY,
                ResponseEnvelope::ok($runtime, $failureFields),
            );
        }

        $responseFields = [
            'dependency_status' => $upstreamStatus,
            'dependency_bytes' => strlen((string) $responseBody),
            'timeout_ms' => $timeoutMilliseconds,
            'timeout_seconds' => $timeoutMilliseconds / self::MILLISECONDS_PER_SECOND,
        ];

        return new Response(HttpStatusCode::OK, ResponseEnvelope::ok($runtime, $responseFields));
    }

    /**
     * Takes a ready handle from the pool, or creates one.
     *
     * Reuse skips the TCP handshake, which is the point. Without it every
     * request opens a connection that then sits in TIME_WAIT for 60 seconds; at
     * a few hundred requests per second that exhausts the ~28,000 ephemeral
     * ports Linux offers, and the failures that follow look like a fault in the
     * fastest runtime rather than in the harness.
     *
     * @param non-empty-string $dependencyUrl cURL rejects an empty URL, so the
     *                                        narrower type is load-bearing here.
     *
     * @return \CurlHandle|null Null only when cURL cannot allocate a handle.
     */
    private static function acquireHandle(string $dependencyUrl, int $timeoutMilliseconds): ?\CurlHandle
    {
        $pooledHandle = array_pop(self::$idleHandles);

        if ($pooledHandle instanceof \CurlHandle) {
            return $pooledHandle;
        }

        $newHandle = curl_init();

        if ($newHandle === false) {
            return null;
        }

        curl_setopt_array($newHandle, [
            CURLOPT_URL => $dependencyUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutMilliseconds,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMilliseconds,
            // Both needed for the connection to survive the call: cURL caches
            // connections per handle, and FORBID_REUSE would close the socket
            // as soon as the transfer finished.
            CURLOPT_FORBID_REUSE => false,
            CURLOPT_FRESH_CONNECT => false,
        ]);

        return $newHandle;
    }

    /**
     * Returns a healthy handle to the pool, keeping its connection open. Past
     * the cap it is closed instead, releasing its socket.
     */
    private static function releaseHandle(\CurlHandle $curlHandle): void
    {
        $isPoolFull = count(self::$idleHandles) >= self::MAX_POOLED_HANDLES;

        if ($isPoolFull) {
            curl_close($curlHandle);

            return;
        }

        self::$idleHandles[] = $curlHandle;
    }
}
