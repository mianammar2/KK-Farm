#!/usr/bin/env bash
# Local dev server with upload limits large enough for camera photos (see ops/php/uploads.ini).
# `php artisan serve` starts a child PHP process; PHP_INI_SCAN_DIR is inherited by it, so the limits apply.
cd "$(dirname "$0")/.."
SCAN="$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')"
PHP_INI_SCAN_DIR="${SCAN}:$(pwd)/ops/php" exec php artisan serve --host=127.0.0.1 --port=8000
