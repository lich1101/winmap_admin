# Triển khai Winmap Admin (độc lập)

Dự án **tách riêng** khỏi `campaio.site` về code & Docker app, nhưng **dùng chung MySQL Campaio**.

## Stack Docker

| Service | Container | Port | Ghi chú |
|---------|-----------|------|---------|
| `app` | `winmap_admin_app` | nội bộ 8000 | Laravel + React |
| `web` | `winmap_admin_web` | `127.0.0.1:8088` | Nginx → app |

**Database:** MySQL chung Campaio (`campaiosite-mysql-1`)

| | |
|---|---|
| phpMyAdmin | https://app-mysql.campaio.site |
| Database | `winmap_admin` |
| Host (trong Docker) | `mysql` (mạng `campaiosite_default`) |

## Deploy

```bash
cd /var/www/winmap_admin
bash deploy/deploy.sh
```

## Tạo database lần đầu (trên MySQL Campaio)

```bash
docker exec campaiosite-mysql-1 mysql -uroot -p"$MASTER_DB_PASSWORD" \
  -e "CREATE DATABASE IF NOT EXISTS winmap_admin CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
```

## Domain

- https://administrator.winmap.vn
- Nginx host → `127.0.0.1:8088`
- SSL: Let's Encrypt

## Đăng nhập

| | |
|---|---|
| Email | `admin@winmap.local` |
| Mật khẩu | `ADMIN_PASSWORD` trong `.env` |

## `.env` quan trọng

```env
DB_HOST=mysql
DB_DATABASE=winmap_admin
DB_USERNAME=root
DB_PASSWORD=<cùng MASTER_DB_PASSWORD của Campaio>
CAMPAIO_DB_PASSWORD=<cùng MASTER_DB_PASSWORD của Campaio>
```

App container join mạng `campaiosite_default` để resolve hostname `mysql`.
