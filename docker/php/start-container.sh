#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

if [ ! -f .env ]; then
  cp .env.example .env
fi

export COMPOSER_CACHE_DIR="${COMPOSER_CACHE_DIR:-/tmp/composer-cache}"

if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist
fi

if [ ! -d node_modules ] || [ ! -f node_modules/.package-lock.json ]; then
  npm install --no-fund --no-audit
fi

if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi

php artisan migrate --force

if ! php artisan tinker --execute="echo \\App\\Models\\User::query()->where('role', 'administrator')->exists() ? 'yes' : 'no';" 2>/dev/null | grep -q yes; then
  php artisan db:seed --force
fi

if [ ! -f public/build/manifest.json ]; then
  npm run build
fi

php artisan optimize:clear >/dev/null 2>&1 || true

exec php artisan serve --host=0.0.0.0 --port=8000
