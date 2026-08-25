# PHP version is a build arg so the same image definition can be measured on
# more than one release. Kept to the vanilla runtimes: the Laravel images pin
# composer's platform to a PHP version, so varying it there needs a lock per
# version rather than just a different base image.
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli-alpine

# libstdc++ is a runtime dependency of the compiled swoole.so (not just a
# build dependency), so it's installed outside of .build-deps and survives
# the `apk del .build-deps` cleanup below.
RUN apk add --no-cache libstdc++ \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers brotli-dev openssl-dev \
    && pecl install swoole \
    && docker-php-ext-enable swoole \
    && apk del .build-deps

# Enabled explicitly rather than relying on the base image's defaults, so a
# future base image that stops shipping OPcache fails the build loudly here
# instead of silently producing an unfair benchmark. Kept in its own layer
# so it does not invalidate the (slow) Swoole compilation above.
RUN docker-php-ext-enable opcache

COPY docker/shared/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
COPY app /var/www/html/app
COPY routes.json /var/www/html/routes.json
COPY performance.json /var/www/html/performance.json
COPY docker/swoole/server.php /var/www/html/server.php

EXPOSE 8080

CMD ["php", "/var/www/html/server.php"]
