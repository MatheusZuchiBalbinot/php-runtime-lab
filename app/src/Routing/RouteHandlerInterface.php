<?php

declare(strict_types=1);

namespace RuntimeLab\Routing;

use RuntimeLab\Http\Request;
use RuntimeLab\Http\Response;

/**
 * Implemented by every route handler. Handlers hold the benchmark workload and
 * are runtime agnostic — the same instance is dispatched to no matter which
 * runtime received the request.
 */
interface RouteHandlerInterface
{
    /**
     * @param string $runtime Name of the runtime serving this request, echoed
     *                        back in the response so a mislabelled deployment
     *                        is visible in the results.
     */
    public function handle(Request $request, string $runtime): Response;
}
