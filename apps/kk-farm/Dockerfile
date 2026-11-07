# KK Farm production image (D-045): Laravel on FrankenPHP (PHP + Caddy with automatic HTTPS).
# Built on the server by ops/production/deploy.sh.

# 1) PHP dependencies (no dev packages)
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader --ignore-platform-reqs

# 2) Front-end assets
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc* ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build

# 3) Runtime
FROM dunglas/frankenphp:1-php8.4
RUN install-php-extensions pdo_pgsql gd intl zip bcmath opcache exif
COPY ops/production/php.ini /usr/local/etc/php/conf.d/zz-kkfarm.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && rm -f public/hot \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
COPY ops/production/entrypoint.sh /usr/local/bin/kkfarm-entrypoint
RUN chmod +x /usr/local/bin/kkfarm-entrypoint
ENTRYPOINT ["kkfarm-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
