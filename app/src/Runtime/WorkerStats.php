<?php

declare(strict_types=1);

namespace RuntimeLab\Runtime;

/**
 * Per-worker counters that make the persistent-worker question observable.
 *
 * These are process-scoped statics, so they reset on every request under a
 * process-per-request runtime while growing monotonically inside a long-lived
 * worker — which is exactly the contrast the benchmark looks for.
 */
final class WorkerStats
{
    private static int $handledRequestCount = 0;

    /** Allocator usage at the moment the current request began. */
    private static int $requestStartBytes = 0;

    /**
     * Tracked by hand because the engine's own peak is reset per request, which
     * is what makes the per-request figures possible. Without this the lifetime
     * ceiling would be lost.
     */
    private static int $lifetimePeakBytes = 0;

    /**
     * Counts the request and takes the memory baseline the per-request figures
     * are measured against.
     */
    public static function beginRequest(): void
    {
        self::$handledRequestCount++;

        // Carried forward before the reset below discards it.
        self::$lifetimePeakBytes = max(self::$lifetimePeakBytes, memory_get_peak_usage(false));

        // Without this (PHP 8.2+) the engine's peak is monotonic for the life of
        // the process, so a persistent worker would report the largest request
        // ever served rather than this one.
        memory_reset_peak_usage();

        self::$requestStartBytes = memory_get_usage(false);
    }

    public static function handledRequestCount(): int
    {
        return self::$handledRequestCount;
    }

    /**
     * Memory this request still holds over the baseline it started from — what
     * serving it cost in memory that was not already there.
     *
     * In a persistent worker it hovers around zero once warm, because the
     * allocator reuses what the previous request released; a figure that stays
     * positive request after request is a leak into the long-lived process.
     */
    public static function requestMemoryBytes(): int
    {
        return memory_get_usage(false) - self::$requestStartBytes;
    }

    /**
     * The high-water mark this request reached over its baseline.
     *
     * Distinct from the figure above: a request that allocates 8 MiB and frees
     * it before responding retains nothing but still needed 8 MiB to exist.
     * That transient requirement is what sizes a worker.
     */
    public static function requestPeakMemoryBytes(): int
    {
        return memory_get_peak_usage(false) - self::$requestStartBytes;
    }

    /** Memory the engine currently holds. A steady climb here is creep. */
    public static function currentMemoryBytes(): int
    {
        return memory_get_usage(true);
    }

    /**
     * Highest memory ever held across the worker's life. Monotonic by
     * definition, so it plateaus and cannot show creep on its own — it is the
     * ceiling that was reached, not a trend.
     */
    public static function peakMemoryBytes(): int
    {
        return max(self::$lifetimePeakBytes, memory_get_peak_usage(false));
    }
}
