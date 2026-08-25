#!/bin/sh
#
# Caches Laravel's config and routes before the server starts.
#
# Without this the framework re-reads every config file and re-registers every
# route on each request, which is not how anyone deploys Laravel — the
# benchmark would be measuring a misconfigured application and reporting the
# result as "the cost of the framework". The distortion is also lopsided: under
# Octane the config loads once per worker anyway, so the missing cache
# penalises the classic FPM deployment specifically, which is exactly the
# comparison this lab exists to make.
#
# Done at container start rather than at build time on purpose. `config:cache`
# freezes the values of env() at the moment it runs, and this image takes its
# environment from docker-compose at runtime: caching during the build would
# bake in whatever was set then, so every service would report the same wrong
# BENCHMARK_RUNTIME.
set -eu

cd /var/www/html/laravel

php artisan config:cache > /dev/null
php artisan route:cache > /dev/null

echo "laravel: config and routes cached (runtime=${BENCHMARK_RUNTIME:-unset})" >&2

exec "$@"
