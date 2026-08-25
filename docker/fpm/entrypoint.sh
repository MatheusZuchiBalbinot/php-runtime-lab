#!/bin/sh
set -eu

# PHP-FPM does not interpolate environment variables into pool directives:
# exporting the worker count into the container is not enough, the value has
# to be written into the pool config before php-fpm parses it. Doing the
# substitution here, at startup, is what makes the shared APP_WORKERS budget
# the value FPM actually runs with instead of dead configuration.
APP_WORKERS="${APP_WORKERS:-4}"

# Worker recycling is equalised across every runtime in the lab: recycling
# costs a full bootstrap, so a policy that differs per runtime silently
# penalises whoever recycles more often.
APP_MAX_REQUESTS="${APP_MAX_REQUESTS:-500}"

POOL_CONFIG_PATH="/usr/local/etc/php-fpm.d/www.conf"

sed -i "s/^pm\.max_children = .*/pm.max_children = ${APP_WORKERS}/" "$POOL_CONFIG_PATH"
sed -i "s/^pm\.max_requests = .*/pm.max_requests = ${APP_MAX_REQUESTS}/" "$POOL_CONFIG_PATH"

echo "php-fpm pool: pm.max_children=${APP_WORKERS} pm.max_requests=${APP_MAX_REQUESTS}" >&2

# Hand off to the base image's entrypoint so its own setup still runs.
exec docker-php-entrypoint "$@"
