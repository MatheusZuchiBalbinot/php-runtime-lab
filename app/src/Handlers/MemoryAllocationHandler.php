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
 * Exhausts memory **bandwidth**: the cost of allocating and writing.
 *
 * The payload is held for the whole request, so the footprint is real while the
 * request is in flight, and a churn loop runs on top so the allocator is
 * exercised rather than the route being one large allocation.
 *
 * Capacity is deliberately not the constraint here, and cannot be: with a fixed
 * worker count only that many requests are ever in flight, so filling a 512 MiB
 * budget would take ~128 MiB per request — and writing that much collapses
 * throughput long before the budget is reached. Capacity shows up elsewhere in
 * the lab, as each runtime's idle footprint.
 */
final class MemoryAllocationHandler implements RouteHandlerInterface
{
    private const int BYTES_PER_MEBIBYTE = 1024 * 1024;

    /** Large enough to leave the allocator's small-object bins. */
    private const int CHURN_CHUNK_BYTES = 64 * 1024;

    public function handle(Request $request, string $runtime): Response
    {
        $retainedMebibytes = PerformanceConfig::memoryRetainedMebibytes();
        $churnCycles = PerformanceConfig::memoryChurnCycles();

        // str_repeat produces a single real allocation of a known size, unlike
        // an array of integers whose true footprint is dominated by zval
        // overhead and hard to reason about against a byte budget.
        $retainedPayload = str_repeat('x', $retainedMebibytes * self::BYTES_PER_MEBIBYTE);

        $churnedBytes = 0;

        for ($cycle = 0; $cycle < $churnCycles; $cycle++) {
            $scratch = str_repeat('y', self::CHURN_CHUNK_BYTES);
            $churnedBytes += strlen($scratch);
            unset($scratch);
        }

        $responseFields = [
            'retained_mebibytes' => $retainedMebibytes,
            'churn_cycles' => $churnCycles,
            'churned_bytes' => $churnedBytes,
            // Proves the payload was still resident when the response was
            // built; a freed one would make this route meaningless.
            'retained_bytes_live' => strlen($retainedPayload),
        ];

        $response = new Response(HttpStatusCode::OK, ResponseEnvelope::ok($runtime, $responseFields));

        unset($retainedPayload);

        return $response;
    }
}
