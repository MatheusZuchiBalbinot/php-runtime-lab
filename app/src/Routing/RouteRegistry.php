<?php

declare(strict_types=1);

namespace RuntimeLab\Routing;

use RuntimeException;
use RuntimeLab\Handlers\BlockingWaitHandler;
use RuntimeLab\Handlers\CpuBoundHandler;
use RuntimeLab\Handlers\ExternalIoHandler;
use RuntimeLab\Handlers\HealthCheckHandler;
use RuntimeLab\Handlers\JsonSerializationHandler;
use RuntimeLab\Handlers\MemoryAllocationHandler;
use RuntimeLab\Handlers\NoopHandler;
use RuntimeLab\Support\Json;

/**
 * Builds the shared Router from routes.json — the single source of truth for
 * path/label pairs, also read by benchmarks/scripts/load-test.js so no path
 * string is duplicated between the server and the load generator.
 */
final class RouteRegistry
{
    private const string ROUTES_CONFIG_PATH = __DIR__ . '/../../../routes.json';

    /** @var list<array{path: string, label: string}>|null */
    private static ?array $routeDefinitions = null;

    /**
     * A persistent worker calls this once at startup; a process-per-request
     * runtime necessarily calls it per request, and that repeated bootstrap is
     * precisely the cost this lab exists to measure. The parsed file is cached
     * in a static so the cost stays "build the router", not "re-read a file".
     */
    public static function build(): Router
    {
        self::$routeDefinitions ??= self::validateRouteDefinitions(
            Json::decodeFile(self::ROUTES_CONFIG_PATH),
        );

        $router = new Router();

        foreach (self::$routeDefinitions as $route) {
            $router->register($route['path'], self::createHandlerForLabel($route['label']));
        }

        return $router;
    }

    /**
     * Decoded JSON is shapeless as far as the type system is concerned, so
     * without this the return type would be a promise nothing enforces. A
     * malformed entry fails here, naming the index, instead of surfacing later
     * as a route that silently never registered.
     *
     * @param array<mixed> $decodedEntries
     *
     * @return list<array{path: string, label: string}>
     */
    private static function validateRouteDefinitions(array $decodedEntries): array
    {
        $routeDefinitions = [];

        foreach ($decodedEntries as $index => $entry) {
            $isWellFormedEntry = is_array($entry)
                && isset($entry['path'], $entry['label'])
                && is_string($entry['path'])
                && is_string($entry['label']);

            if (!$isWellFormedEntry) {
                throw new RuntimeException(
                    "routes.json: entry #{$index} must have string \"path\" and \"label\" keys.",
                );
            }

            $routeDefinitions[] = ['path' => $entry['path'], 'label' => $entry['label']];
        }

        return $routeDefinitions;
    }

    /** The only place a route label is bound to a concrete handler class. */
    private static function createHandlerForLabel(string $label): RouteHandlerInterface
    {
        return match ($label) {
            'health' => new HealthCheckHandler(),
            'noop' => new NoopHandler(),
            'cpu' => new CpuBoundHandler(),
            'blocking_wait' => new BlockingWaitHandler(),
            'external_io' => new ExternalIoHandler(),
            'json' => new JsonSerializationHandler(),
            'memory' => new MemoryAllocationHandler(),
            default => throw new RuntimeException("No handler bound for route label \"{$label}\"."),
        };
    }
}
