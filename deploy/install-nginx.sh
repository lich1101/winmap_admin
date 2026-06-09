#!/usr/bin/env bash
set -euo pipefail

DOMAIN="administrator.winmap.vn"
CONF_SRC="/var/www/winmap_admin/deploy/nginx-administrator.winmap.vn.conf"
CONF_DST="/etc/nginx/sites-available/${DOMAIN}"

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  echo "Chạy bằng root: sudo bash $0"
  exit 1
fi

cp "$CONF_SRC" "$CONF_DST"
ln -sf "$CONF_DST" "/etc/nginx/sites-enabled/${DOMAIN}"

nginx -t
systemctl reload nginx

echo "OK: HTTP ${DOMAIN} -> 127.0.0.1:8088"

if [[ ! -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]]; then
  echo "Chạy SSL: certbot --nginx -d ${DOMAIN}"
  certbot --nginx -d "${DOMAIN}" --non-interactive --agree-tos -m admin@winmap.local || true
fi

echo "Kiểm tra: curl -sI https://${DOMAIN}/up | head -5"
