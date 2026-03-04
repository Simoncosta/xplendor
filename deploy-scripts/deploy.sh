#!/bin/bash
set -e

APP_DIR="/home/xplendor"
ENV_FILE="$APP_DIR/server/.env"

echo "🔄 Deploy Xplendor (prod)"
cd "$APP_DIR"

echo "📥 Git pull"
git fetch origin main
git reset --hard origin/main

echo "🐳 Backend: build + up"
docker compose --env-file "$ENV_FILE" -f docker-compose.prod.yml up -d --build

echo "📦 Composer"
docker exec -it xplendor-php composer install --no-dev --optimize-autoloader

echo "🗄️ Migrations"
docker exec -it xplendor-php php artisan migrate --force

echo "🧱 Storage link + permissions"
docker exec -it xplendor-php php artisan storage:link || true
docker exec -it xplendor-php sh -lc "chmod -R 775 storage bootstrap/cache && chown -R www-data:www-data storage bootstrap/cache"

echo "🧹 Clear caches"
docker exec -it xplendor-php php artisan optimize:clear
docker exec -it xplendor-php php artisan config:cache
docker exec -it xplendor-php php artisan route:cache

echo "⚛️ Frontend build"
cd "$APP_DIR/web"
yarn install --frozen-lockfile || yarn install
yarn build

echo "🔁 Reload nginx (host)"
sudo nginx -t
sudo systemctl reload nginx

echo "✅ Deploy OK"