<?php

declare(strict_types=1);

use App\Http\Controllers\BenchmarkController;
use Illuminate\Support\Facades\Route;
use RuntimeLab\Support\Json;

/**
 * The benchmark routes are registered as stateless API routes rather than web
 * routes on purpose. The web middleware group starts a session, shares errors
 * from it and validates CSRF tokens — real work, but work no JSON endpoint
 * would do, and it would have the benchmark measuring session storage instead
 * of the framework's request handling.
 *
 * The list itself comes from the same routes.json the vanilla app and the k6
 * load test read, so the Laravel deployment answers exactly the same paths
 * without restating them here.
 */
$routeDefinitions = Json::decodeFile(base_path('../routes.json'));

foreach ($routeDefinitions as $route) {
    Route::get($route['path'], BenchmarkController::class);
}
