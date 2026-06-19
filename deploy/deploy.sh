#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOMAIN="administrator.winmap.vn"
SERVER_COMPOSE="docker-compose.server.yml"
SERVER_BUILD_COMPOSE="docker-compose.server.build.yml"
WINMAP_ADMIN_APP_IMAGE="${WINMAP_ADMIN_APP_IMAGE:-ghcr.io/lich1101/winmap-admin-app:main}"
WINMAP_ADMIN_WEB_IMAGE="${WINMAP_ADMIN_WEB_IMAGE:-ghcr.io/lich1101/winmap-admin-web:main}"
DO_BUILD=0

if [[ "${1:-}" == "--build-on-server" ]]; then
  DO_BUILD=1
fi

export WINMAP_ADMIN_APP_IMAGE
export WINMAP_ADMIN_WEB_IMAGE

cd "$ROOT_DIR"

if [[ ! -f .env ]]; then
  cp .env.docker.example .env
  echo "Đã tạo .env — chỉnh CAMPAIO_DB_PASSWORD trùng MASTER_DB_PASSWORD Campaio."
fi

# Đảm bảo DB tồn tại trên MySQL Campaio
CAMPAIO_PW="${CAMPAIO_DB_PASSWORD:-${DB_PASSWORD:-}}"
if [[ -n "$CAMPAIO_PW" ]]; then
  docker exec campaiosite-mysql-1 mysql -uroot -p"$CAMPAIO_PW" \
    -e "CREATE DATABASE IF NOT EXISTS winmap_admin CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null || true
fi

if [[ "$DO_BUILD" -eq 1 ]]; then
  echo "==> Build on server & start Docker stack"
  docker compose -f "$SERVER_COMPOSE" -f "$SERVER_BUILD_COMPOSE" build app web
else
  echo "==> Pull GHCR images & start Docker stack"
  docker compose -f "$SERVER_COMPOSE" pull app web
fi

docker compose -f "$SERVER_COMPOSE" up -d

echo "==> Đợi app healthy"
for _ in $(seq 1 60); do
  if docker compose -f "$SERVER_COMPOSE" ps app 2>/dev/null | grep -q healthy; then
    break
  fi
  sleep 3
done

docker compose -f "$SERVER_COMPOSE" restart web >/dev/null
sleep 2

echo "==> Migrate + seed"
docker compose -f "$SERVER_COMPOSE" exec -T app php artisan migrate --force
docker compose -f "$SERVER_COMPOSE" exec -T app php artisan db:seed --force 2>/dev/null || true

echo "==> Kiểm tra DB trên MySQL Campaio"
docker exec campaiosite-mysql-1 mysql -uroot -p"$CAMPAIO_PW" winmap_admin \
  -e "SHOW TABLES; SELECT email, role FROM users LIMIT 5;" 2>/dev/null

if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
  bash "$ROOT_DIR/deploy/install-nginx.sh"
else
  echo "Nginx: sudo bash deploy/install-nginx.sh"
fi

echo ""
echo "Deploy xong."
echo "  App:  https://${DOMAIN}"
echo "  DB:   https://app-mysql.campaio.site → database winmap_admin"
echo "  Login: admin@winmap.local / ADMIN_PASSWORD trong .env"
