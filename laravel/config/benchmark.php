<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Runtime label
    |--------------------------------------------------------------------------
    |
    | Reported in every benchmark response so a result can be attributed to the
    | stack that produced it (laravel-fpm, laravel-octane-swoole,
    | laravel-octane-roadrunner). Set per deployment in docker-compose.yml.
    |
    */

    'runtime' => env('BENCHMARK_RUNTIME', 'laravel'),

];
