# PHP version is a build arg so the same image definition can be measured on
# more than one release. Kept to the vanilla runtimes: the Laravel images pin
# composer's platform to a PHP version, so varying it there needs a lock per
# version rather than just a different base image.
ARG PHP_VERSION=8.3
# Pinned to the exact release that composer.lock resolves spiral/roadrunner
# to. The RoadRunner server binary and the PHP worker libraries speak a shared
# protocol, so letting the two drift apart is a latent breakage — an earlier
# build here paired a 2024.3 binary with 2025.x libraries purely by accident.
FROM ghcr.io/roadrunner-server/roadrunner:2025.1.15 AS roadrunner-binary

FROM composer:2 AS composer-binary

FROM php:${PHP_VERSION}-cli-alpine AS runtime

RUN apk add --no-cache libstdc++ \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && docker-php-ext-install sockets \
    && apk del .build-deps

# Enabled explicitly rather than relying on the base image's defaults, so a
# future base image that stops shipping OPcache fails the build loudly here
# instead of silently producing an unfair benchmark.
RUN docker-php-ext-enable opcache

COPY docker/shared/php.ini /usr/local/etc/php/conf.d/zz-app.ini

COPY --from=composer-binary /usr/bin/composer /usr/bin/composer
COPY --from=roadrunner-binary /usr/bin/rr /usr/local/bin/rr

WORKDIR /var/www/html

COPY docker/roadrunner/composer.json ./composer.json
COPY docker/roadrunner/composer.lock ./composer.lock
COPY app ./app
COPY routes.json ./routes.json
COPY performance.json ./performance.json

# `install`, never `update`: it resolves strictly from the committed lock, so
# two builds of the same commit produce the same dependency set and a result
# stays attributable to the code rather than to whatever was latest on
# Packagist that day.
RUN composer install --no-dev --no-interaction --no-progress

COPY docker/roadrunner/worker.php ./worker.php
COPY docker/roadrunner/.rr.yaml ./.rr.yaml

EXPOSE 8080

CMD ["rr", "serve", "-c", ".rr.yaml"]
