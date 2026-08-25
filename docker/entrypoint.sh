#!/bin/sh
# Container entrypoint: prepare runtime state, cache config for production,
# then hand off to the container CMD. Migrations are NOT run automatically —
# run them explicitly against a tested database copy (AGENTS.md deployment
# discipline): docker compose exec app php artisan migrate --force

set -e

# Storage symlinks + writable runtime dirs (named volumes may start empty).
php artisan storage:link || true
mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Production caches (config:cache bakes env — the container reads env from
# compose, so this is safe and skips per-request .env parsing).
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
