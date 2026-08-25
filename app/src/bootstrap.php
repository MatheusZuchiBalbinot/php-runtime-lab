<?php

declare(strict_types=1);

/**
 * Minimal PSR-4 autoloader for "RuntimeLab\", used by the entrypoints that have
 * no Composer dependencies of their own. RoadRunner and Laravel use Composer's
 * autoloader instead, mapping the same namespace to this same directory.
 *
 * The shared code lives under "RuntimeLab\" rather than "App\" so the Laravel
 * application can reuse these handlers: Laravel reserves "App\" for itself, and
 * two libraries cannot occupy one namespace root.
 */

const RUNTIME_LAB_NAMESPACE_PREFIX = 'RuntimeLab\\';
const RUNTIME_LAB_SOURCE_DIRECTORY = __DIR__;

spl_autoload_register(static function (string $class): void {
    $isRuntimeLabClass = str_starts_with($class, RUNTIME_LAB_NAMESPACE_PREFIX);

    if (!$isRuntimeLabClass) {
        return;
    }

    $relativeClass = substr($class, strlen(RUNTIME_LAB_NAMESPACE_PREFIX));
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
    $filePath = RUNTIME_LAB_SOURCE_DIRECTORY . DIRECTORY_SEPARATOR . $relativePath;

    $doesFileExist = is_file($filePath);

    if ($doesFileExist) {
        require $filePath;
    }
});
