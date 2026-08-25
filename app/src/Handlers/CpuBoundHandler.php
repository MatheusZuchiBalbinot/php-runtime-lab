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
 * Deterministic CPU workload executed **by the PHP virtual machine itself**.
 *
 * Every operation in the hot loop is a VM opcode — integer arithmetic, shifts,
 * comparisons — with no call into a C extension. That constraint is what makes
 * the route sensitive to the axes this lab varies: JIT compiles opcodes, and
 * interpreter improvements between PHP versions land here.
 *
 * A workload built on a C function like hash() would spend its time inside the
 * extension instead, and report the same number under every tuning profile.
 */
final class CpuBoundHandler implements RouteHandlerInterface
{
    /**
     * The prime modulus keeps the accumulator in a range that never overflows
     * to float. An overflow would switch the arithmetic from integer to
     * floating point midway, making the workload depend on how far the loop had
     * got rather than on the iteration count.
     */
    private const int MIXING_MULTIPLIER = 31;
    private const int MIXING_MODULUS = 1000003;

    /** Stops the accumulator settling into a short cycle. */
    private const int AVALANCHE_SHIFT = 3;

    public function handle(Request $request, string $runtime): Response
    {
        $iterationCount = PerformanceConfig::cpuIterations();
        $accumulator = 1;

        for ($iteration = 0; $iteration < $iterationCount; $iteration++) {
            $accumulator = ($accumulator * self::MIXING_MULTIPLIER + $iteration) % self::MIXING_MODULUS;
            $accumulator ^= $accumulator >> self::AVALANCHE_SHIFT;
        }

        $responseFields = [
            'iterations' => $iterationCount,
            // Returned so the loop cannot be treated as dead code, and so a
            // runtime that quietly computed something else is detectable: the
            // value is fully determined by the iteration count, so it must be
            // identical across every runtime.
            'checksum' => $accumulator,
        ];

        return new Response(HttpStatusCode::OK, ResponseEnvelope::ok($runtime, $responseFields));
    }
}
