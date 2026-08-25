<?php

declare(strict_types=1);

namespace RuntimeLab\Support;

use RuntimeException;

/**
 * Decoding of the project's JSON config files, so callers do not repeat the
 * `json_decode($raw, true, 512, ...)` incantation and the read-check-decode
 * sequence around it.
 */
final class Json
{
    /** json_decode's own default, named so the number never appears bare. */
    private const int MAX_NESTING_DEPTH = 512;

    /**
     * Fails with a message naming the file when it is missing, unreadable or
     * malformed.
     *
     * @return array<mixed>
     */
    public static function decodeFile(string $path): array
    {
        $doesFileExist = is_file($path);

        if (!$doesFileExist) {
            throw new RuntimeException("Config file not found: {$path}");
        }

        $rawContents = file_get_contents($path);
        $wasReadable = $rawContents !== false;

        if (!$wasReadable) {
            throw new RuntimeException("Config file could not be read: {$path}");
        }

        return self::decode($rawContents);
    }

    /**
     * @return array<mixed>
     */
    public static function decode(string $json): array
    {
        return json_decode($json, true, self::MAX_NESTING_DEPTH, JSON_THROW_ON_ERROR);
    }
}
