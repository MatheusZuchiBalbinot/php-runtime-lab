<?php

declare(strict_types=1);

/**
 * OPcache preload script (phase 6, enabled with PHP_TUNING=preload).
 *
 * Everything required here is compiled once when the server starts and stays
 * linked in shared memory for the life of the process, so no request pays to
 * load it again.
 *
 * Only the application's own classes are preloaded — the runtime adapters
 * under docker/ are not, since each one depends on extensions or vendor code
 * that exists in a single image.
 */

require __DIR__ . '/app/src/bootstrap.php';

$preloadableClasses = [
    RuntimeLab\Config\PerformanceConfig::class,
    RuntimeLab\Handlers\BlockingWaitHandler::class,
    RuntimeLab\Handlers\CpuBoundHandler::class,
    RuntimeLab\Handlers\ExternalIoHandler::class,
    RuntimeLab\Handlers\HealthCheckHandler::class,
    RuntimeLab\Handlers\JsonSerializationHandler::class,
    RuntimeLab\Handlers\MemoryAllocationHandler::class,
    RuntimeLab\Handlers\NoopHandler::class,
    RuntimeLab\Http\HttpStatusCode::class,
    RuntimeLab\Http\Request::class,
    RuntimeLab\Http\Response::class,
    RuntimeLab\Http\ResponseEnvelope::class,
    RuntimeLab\Routing\RouteHandlerInterface::class,
    RuntimeLab\Routing\RouteRegistry::class,
    RuntimeLab\Routing\Router::class,
    RuntimeLab\Runtime\WorkerStats::class,
    RuntimeLab\Support\Json::class,
];

foreach ($preloadableClasses as $class) {
    // Touching the class through the autoloader is what makes OPcache compile
    // and link it into the preloaded set.
    class_exists($class) || interface_exists($class);
}
