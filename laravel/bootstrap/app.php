<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    // apiPrefix is emptied so the benchmark routes answer on the same paths as
    // every other runtime in the lab (/bench/cpu, not /api/bench/cpu) while
    // still going through the stateless api middleware group.
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always JSON. Every runtime in this lab answers JSON, including for
        // an unknown path; Laravel's default would render an HTML error page
        // instead, since a request that matches no route never reaches the
        // controller that would have produced the shared envelope. Letting
        // that stand would make the Laravel deployments behave differently
        // from the vanilla ones for the same request.
        $exceptions->shouldRenderJsonWhen(static fn (): bool => true);
    })->create();
