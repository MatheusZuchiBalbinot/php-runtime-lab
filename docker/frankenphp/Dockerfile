# FrankenPHP bundles Caddy and PHP into one binary and can serve the same
# application in two modes — classic (bootstrap per request) and worker
# (persistent). Both are built from this file; the mode is chosen by which
# Caddyfile the service mounts, so the two differ only in that setting.
ARG PHP_VERSION=8.3
FROM dunglas/frankenphp:1-php${PHP_VERSION}-alpine

# Enabled explicitly rather than relying on the image's defaults, so an image
# that stops shipping OPcache fails the build loudly here instead of silently
# producing an unfair benchmark. FrankenPHP embeds PHP rather than loading it
# as a SAPI module, so that conf.d is honoured here is worth not assuming.
RUN docker-php-ext-enable opcache

COPY docker/shared/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

COPY app ./app
COPY routes.json ./routes.json
COPY performance.json ./performance.json
COPY docker/frankenphp/public ./frankenphp-public

EXPOSE 8080
