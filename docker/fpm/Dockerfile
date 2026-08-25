# PHP version is a build arg so the same image definition can be measured on
# more than one release. Kept to the vanilla runtimes: the Laravel images pin
# composer's platform to a PHP version, so varying it there needs a lock per
# version rather than just a different base image.
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-fpm-alpine

# Enabled explicitly rather than relying on the base image's defaults, so a
# future base image that stops shipping OPcache fails the build loudly here
# instead of silently producing an unfair benchmark.
RUN docker-php-ext-enable opcache

COPY docker/shared/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/fpm/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/fpm/entrypoint.sh /usr/local/bin/app-entrypoint.sh
RUN chmod +x /usr/local/bin/app-entrypoint.sh

WORKDIR /var/www/html
COPY app /var/www/html/app
COPY routes.json /var/www/html/routes.json
COPY performance.json /var/www/html/performance.json

EXPOSE 9000

ENTRYPOINT ["app-entrypoint.sh"]
CMD ["php-fpm"]
