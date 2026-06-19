FROM php:8.3-cli

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libsqlite3-dev \
        libzip-dev \
    && docker-php-ext-install intl pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY hestiapredict/composer.json hestiapredict/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY hestiapredict/ ./
RUN composer dump-autoload --optimize \
    && mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8000

CMD ["sh", "-c", "mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache && chmod -R 775 storage bootstrap/cache && php artisan package:discover --ansi && php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8000"]
