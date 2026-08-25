FROM php:8.3-cli-alpine

# libstdc++ is a runtime dependency of the compiled swoole.so (not just a
# build dependency), so it is installed outside of .build-deps and survives
# the cleanup below.
# pcntl is not optional for Octane: octane:start installs signal handlers to
# supervise its workers, and without it the command dies on an undefined
# SIGINT constant before serving anything.
RUN apk add --no-cache libstdc++ \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers brotli-dev openssl-dev \
    && docker-php-ext-install pcntl \
    && pecl install swoole \
    && docker-php-ext-enable swoole \
    && apk del .build-deps

RUN docker-php-ext-enable opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

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

EXPOSE 8080

# --workers and --task-workers are passed explicitly and never left at Octane's
# default of "auto". Inside a container, auto resolves to the *host's* core
# count, because `cpus:` is a CFS quota rather than a cpuset: an unset worker
# count made this image spawn ~12 workers on a 1-CPU budget and occupy 89% of
# its memory limit before a single request arrived.
#
# Shell form (via exec) is needed so APP_WORKERS expands at container start;
# exec keeps PHP as PID 1 so Octane still receives SIGTERM.
#
# --max-requests comes from the shared APP_MAX_REQUESTS so worker recycling is
# identical across every runtime; recycling costs a full bootstrap, and an
# uneven policy would penalise whoever recycles more often.
ENTRYPOINT ["laravel-entrypoint.sh"]
CMD ["sh", "-c", "exec php artisan octane:start --server=swoole --host=0.0.0.0 --port=8080 --workers=${APP_WORKERS:-4} --task-workers=${APP_TASK_WORKERS:-1} --max-requests=${APP_MAX_REQUESTS:-500}"]
