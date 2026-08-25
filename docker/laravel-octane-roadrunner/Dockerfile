# Pinned to the same RoadRunner release the vanilla RoadRunner image uses, so
# the two are comparable and the server binary cannot drift away from the PHP
# worker libraries it speaks a shared protocol with.
FROM ghcr.io/roadrunner-server/roadrunner:2025.1.15 AS roadrunner-binary

FROM php:8.3-cli-alpine AS runtime

# pcntl is not optional for Octane: octane:start installs signal handlers to
# supervise its workers, and without it the command dies on an undefined
# SIGINT constant before serving anything.
RUN apk add --no-cache libstdc++ \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && docker-php-ext-install sockets pcntl \
    && apk del .build-deps

RUN docker-php-ext-enable opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=roadrunner-binary /usr/bin/rr /usr/local/bin/rr

COPY docker/shared/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/laravel-shared/entrypoint.sh /usr/local/bin/laravel-entrypoint.sh
RUN chmod +x /usr/local/bin/laravel-entrypoint.sh

WORKDIR /var/www/html

COPY app/src ./app/src
COPY routes.json ./routes.json
COPY performance.json ./performance.json
COPY laravel ./laravel

RUN composer install --working-dir=laravel --no-dev --no-interaction --no-progress --optimize-autoloader

WORKDIR /var/www/html/laravel

# Octane expects the rr binary next to the application; the pinned one from the
# build stage is linked in rather than letting octane:install download an
# arbitrary version at build time.
RUN ln -s /usr/local/bin/rr ./rr

EXPOSE 8080

# --workers is passed explicitly and never left at Octane's default of "auto".
# Inside a container, auto resolves to the *host's* core count, because `cpus:`
# is a CFS quota rather than a cpuset: an unset worker count made this image
# spawn ~12 workers on a 1-CPU budget and sit at 458 MiB of its 512 MiB limit
# before a single request arrived.
#
# Shell form (via exec) is needed so APP_WORKERS expands at container start;
# exec keeps PHP as PID 1 so Octane still receives SIGTERM.
ENTRYPOINT ["laravel-entrypoint.sh"]
CMD ["sh", "-c", "exec php artisan octane:start --server=roadrunner --host=0.0.0.0 --port=8080 --rpc-port=6001 --workers=${APP_WORKERS:-4} --max-requests=${APP_MAX_REQUESTS:-500}"]
