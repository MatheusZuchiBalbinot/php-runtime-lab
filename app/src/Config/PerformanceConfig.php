<?php

declare(strict_types=1);

namespace RuntimeLab\Config;

use RuntimeException;
use RuntimeLab\Support\Json;

/**
 * Workload parameters, read from performance.json so tuning what a route does
 * never means hunting for a magic number inside a handler.
 *
 * The file's "load" section is read by benchmarks/scripts/load-test.js instead,
 * and is deliberately not exposed here: nothing in the application consumes it.
 */
final class PerformanceConfig
{
    private const string CONFIG_PATH = __DIR__ . '/../../../performance.json';

    /** @var array<string, array<string, int|string>>|null */
    private static ?array $parameters = null;

    /** Mixing rounds the cpu route runs in the PHP VM, not in a C extension. */
    public static function cpuIterations(): int
    {
        return self::integer('cpu', 'iterations');
    }

    public static function blockingWaitMicroseconds(): int
    {
        return self::integer('blocking_wait', 'wait_microseconds');
    }

    /**
     * From the environment rather than performance.json because it is topology,
     * not workload shape: the hostname is a Compose service name and differs
     * per deployment.
     *
     * @return non-empty-string Both branches are non-empty, which lets callers
     *                          pass this straight to cURL.
     */
    public static function externalIoUrl(): string
    {
        $configuredUrl = getenv('EXTERNAL_IO_URL');

        return is_string($configuredUrl) && $configuredUrl !== ''
            ? $configuredUrl
            : 'http://stub:8080/';
    }

    /** Past this, the call is reported as failed rather than stalling a worker. */
    public static function externalIoTimeoutMilliseconds(): int
    {
        return self::integer('external_io', 'timeout_milliseconds');
    }

    public static function jsonPayloadRecordCount(): int
    {
        return self::integer('json', 'payload_record_count');
    }

    public static function jsonEncodedHashPreviewLength(): int
    {
        return self::integer('json', 'encoded_hash_preview_length');
    }

    /** Held for the whole request, so it counts against the worker's footprint. */
    public static function memoryRetainedMebibytes(): int
    {
        return self::integer('memory', 'retained_mebibytes');
    }

    /** Allocate-then-discard cycles, to exercise the allocator and not just the footprint. */
    public static function memoryChurnCycles(): int
    {
        return self::integer('memory', 'churn_cycles');
    }

    /**
     * Throws rather than casting. A silent `(int)` would turn a typo in
     * performance.json into a workload of zero iterations — a benchmark that
     * measures nothing while reporting a healthy-looking result.
     */
    private static function integer(string $section, string $key): int
    {
        $value = self::value($section, $key);
        $isNumericValue = is_int($value) || (is_string($value) && ctype_digit($value));

        if (!$isNumericValue) {
            throw new RuntimeException(
                "performance.json: \"{$section}.{$key}\" must be an integer.",
            );
        }

        return (int) $value;
    }

    /** Loads and caches the file on first access. */
    private static function value(string $section, string $key): mixed
    {
        self::$parameters ??= Json::decodeFile(self::CONFIG_PATH);

        $hasSection = array_key_exists($section, self::$parameters);

        if (!$hasSection) {
            throw new RuntimeException("performance.json: missing section \"{$section}\".");
        }

        $hasKey = array_key_exists($key, self::$parameters[$section]);

        if (!$hasKey) {
            throw new RuntimeException("performance.json: missing key \"{$section}.{$key}\".");
        }

        return self::$parameters[$section][$key];
    }
}
