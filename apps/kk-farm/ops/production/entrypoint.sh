#!/bin/sh
# Prepares Laravel on every start, then runs FrankenPHP. Migrations are forward-only and safe to repeat.
set -e
cd /app
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache
# D-164: Laravel's scheduler (maternity round and other timed jobs) runs every minute in the background as www-data.
( while true; do su -s /bin/sh -c "cd /app && php artisan schedule:run --no-interaction >> storage/logs/schedule.log 2>&1" www-data; sleep 60; done ) &
exec "$@"
