FROM dunglas/frankenphp:1-php8.4-bookworm

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN install-php-extensions pdo_mysql zip bcmath opcache \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R www-data:www-data /data /config

WORKDIR /app

COPY --chown=www-data:www-data . .
COPY docker/backend/Caddyfile /etc/caddy/Caddyfile

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && composer check-platform-reqs --no-dev \
    && php artisan storage:link \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8000

USER www-data

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]