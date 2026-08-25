FROM php:8.3-fpm-alpine

# Enabled explicitly rather than relying on the base image's defaults, so a
# future base image that stops shipping OPcache fails the build loudly here
# instead of silently producing an unfair benchmark.
RUN docker-php-ext-enable opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/shared/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/fpm/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/fpm/entrypoint.sh /usr/local/bin/app-entrypoint.sh
COPY docker/laravel-shared/entrypoint.sh /usr/local/bin/laravel-entrypoint.sh
RUN chmod +x /usr/local/bin/app-entrypoint.sh /usr/local/bin/laravel-entrypoint.sh

WORKDIR /var/www/html

# The shared handlers sit beside the Laravel app because laravel/composer.json
# maps RuntimeLab\ to ../app/src — the Laravel deployment runs the identical
# business logic as the vanilla runtimes, which is what makes the comparison
# measure the framework rather than different code.
COPY app/src ./app/src
COPY routes.json ./routes.json
COPY performance.json ./performance.json
COPY laravel ./laravel

RUN composer install --working-dir=laravel --no-dev --no-interaction --no-progress --optimize-autoloader \
    && chown -R www-data:www-data laravel/storage laravel/bootstrap/cache

EXPOSE 9000

# Chained: Laravel caching first, then the FPM pool substitution, then the base
# image's own entrypoint.
ENTRYPOINT ["laravel-entrypoint.sh", "app-entrypoint.sh"]
CMD ["php-fpm"]
