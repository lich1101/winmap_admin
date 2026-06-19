#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

mkdir -p \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  storage/app/public \
  bootstrap/cache

if [[ -z "${APP_KEY:-}" ]] && [[ ! -f .env ]]; then
  echo "APP_KEY is missing. Set APP_KEY in Winmap .env or container environment before starting." >&2
  exit 1
fi

php artisan storage:link >/dev/null 2>&1 || true
php artisan optimize:clear >/dev/null 2>&1 || true
php artisan migrate --force

if ! php artisan tinker --execute="echo \\App\\Models\\User::query()->where('role', 'administrator')->exists() ? 'yes' : 'no';" 2>/dev/null | grep -q yes; then
  php artisan db:seed --force
fi

exec php artisan serve --host=0.0.0.0 --port=8000
