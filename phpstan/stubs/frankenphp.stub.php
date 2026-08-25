<?php

/**
 * The one FrankenPHP function the worker adapter calls.
 *
 * Provided by the FrankenPHP binary, so it exists nowhere else — without this
 * declaration the worker adapter cannot be analysed outside its own image.
 *
 * Returns false once the server is shutting down or the worker is being
 * recycled, which is what ends the adapter's loop.
 */

declare(strict_types=1);

/**
 * @param callable(): void $handler FrankenPHP calls this with no arguments
 *        and ignores its return value; it reads the request through PHP's
 *        ordinary superglobals, already repopulated for the current request.
 */
function frankenphp_handle_request(callable $handler): bool
{
}
