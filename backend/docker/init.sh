#!/bin/sh
set -eu

echo "正在初始化 TGFlow 数据库……"
php artisan migrate --force --no-interaction
php artisan admin:create "${ADMIN_USERNAME:-admin}" "${ADMIN_EMAIL:-admin@localhost}" \
    --role=super_admin \
    --password="${ADMIN_PASSWORD}" \
    --without-totp \
    --no-interaction
echo "TGFlow 初始化完成。"
