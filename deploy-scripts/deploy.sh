#!/bin/bash
set -e

APP_DIR="/home/xplendor"
ENV_FILE="$APP_DIR/server/.env"

echo "🔄 Deploy Xplendor (prod)"
cd "$APP_DIR"

echo "Docker Down"
docker compose down

echo "📥 Git pull"
git pull origin main

echo "🐳 Backend: build + up"
docker compose -f docker-compose.prod.yml up -d --build

echo "🧱 Storage link + permissions"
docker exec -it xplendor-php php artisan storage:link || true
docker exec -it xplendor-php sh -lc "chmod -R 775 storage bootstrap/cache && chown -R www-data:www-data storage bootstrap/cache"

echo "🧹 Clear caches"
docker exec -it xplendor-php php artisan config:clear
docker exec -it xplendor-php php artisan config:cache
docker exec -it xplendor-php php artisan route:clear
docker exec -it xplendor-php php artisan route:cache
docker exec -it xplendor-php php artisan view:clear

echo "⚛️ Frontend build"
cd "./web"
yarn install
echo "⚛️ Delete old build"
rm -rf build/
echo "⚛️ Build new build"
yarn build

echo "🔁 Reload nginx (docker)"
docker restart xplendor-nginx

echo "Migrations (prod)"
cd ".."
docker exec -it xplendor-php php artisan migrate

echo "✅ Deploy OK"