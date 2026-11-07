#!/bin/sh
# Prepares Laravel on every start, then runs FrankenPHP. Migrations are forward-only and safe to repeat.
set -e
cd /app
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache
exec "$@"
