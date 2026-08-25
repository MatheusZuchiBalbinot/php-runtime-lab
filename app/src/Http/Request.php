<?php

declare(strict_types=1);

namespace RuntimeLab\Http;

/**
 * An inbound request, decoupled from any runtime's native request object. Each
 * runtime entrypoint is the adapter that builds one of these before handing it
 * to the shared Router.
 */
final class Request
{
    /**
     * @param string $path Request path, e.g. "/bench/cpu".
     */
    public function __construct(
        public readonly string $path,
    ) {
    }
}
