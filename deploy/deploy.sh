#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOMAIN="administrator.winmap.vn"

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

echo "==> Build & start Docker stack"
docker compose up -d --build

echo "==> Đợi app healthy"
for _ in $(seq 1 60); do
  if docker compose ps app 2>/dev/null | grep -q healthy; then
    break
  fi
  sleep 3
done

docker compose restart web >/dev/null
sleep 2

echo "==> Migrate + seed"
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan db:seed --force 2>/dev/null || true

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
