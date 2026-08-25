<?php

declare(strict_types=1);

namespace RuntimeLab\Http;

use RuntimeLab\Runtime\WorkerStats;

/**
 * The response fields shared by every route, so a handler only supplies what is
 * specific to its own workload.
 *
 * Every response carries the worker's request count and memory usage, which
 * turns any route — not just the memory one — into an observation point for
 * creep in a long-lived worker.
 */
final class ResponseEnvelope
{
    private const string OK_STATUS_LABEL = 'ok';
    private const string NOT_FOUND_STATUS_LABEL = 'not_found';

    /**
     * @param array<string, mixed> $additionalFields Specific to the handler's workload.
     *
     * @return array<string, mixed>
     */
    public static function ok(string $runtime, array $additionalFields = []): array
    {
        return array_merge(self::sharedFields(self::OK_STATUS_LABEL, $runtime), $additionalFields);
    }

    /**
     * Same shape as a successful response, so anything consuming these payloads
     * sees one consistent envelope.
     *
     * @return array<string, mixed>
     */
    public static function notFound(string $runtime, string $path): array
    {
        return array_merge(self::sharedFields(self::NOT_FOUND_STATUS_LABEL, $runtime), [
            'path' => $path,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function sharedFields(string $status, string $runtime): array
    {
        return [
            'status' => $status,
            'runtime' => $runtime,
            'hostname' => gethostname(),
            'pid' => getmypid(),
            'worker_requests' => WorkerStats::handledRequestCount(),
            'memory_bytes' => WorkerStats::currentMemoryBytes(),
            'memory_peak_bytes' => WorkerStats::peakMemoryBytes(),
            // What this request cost, as opposed to what the worker holds:
            // `retained` is what it still owns over its starting baseline,
            // `peak` is the high-water mark it needed to exist. In a persistent
            // runtime retained sits near zero once warm, and a value that climbs
            // request after request is a leak.
            'request_memory_retained_bytes' => WorkerStats::requestMemoryBytes(),
            'request_memory_peak_bytes' => WorkerStats::requestPeakMemoryBytes(),
            'timestamp' => microtime(true),
        ];
    }
}
