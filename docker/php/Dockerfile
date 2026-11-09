# Local development only. Cloudron runs the LAMP app, not this image.
FROM php:8.4-fpm

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip sqlite3 \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions intl bcmath zip pcntl opcache gd

COPY php.ini /usr/local/etc/php/conf.d/zz-myapes-account.ini
COPY fpm-pool.conf /usr/local/etc/php-fpm.d/zz-myapes-account.conf

RUN git config --system --add safe.directory /app \
    && mkdir -p /app/storage/framework/cache/data /app/storage/framework/sessions \
        /app/storage/framework/views /app/storage/framework/testing

WORKDIR /app

# FPM workers run as root, like `docker compose exec`, so both can write the same cache files.
CMD ["php-fpm", "--allow-to-run-as-root"]
