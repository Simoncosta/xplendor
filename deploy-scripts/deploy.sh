#!/bin/bash
set -e

APP_DIR="/home/xplendor"

echo "🔄 Deploy Xplendor (prod)"
cd "$APP_DIR"

echo "Docker Down"
docker compose -f docker-compose.prod.yml down

echo "📥 Git pull"
git pull origin main

echo "🐳 Backend: build + up"
docker compose -f docker-compose.prod.yml up -d --build

# As migrações correm LOGO A SEGUIR ao backend arrancar, antes das compilações do web e do
# site: o código novo nunca fica a correr sem as tabelas e as colunas novas.
echo "⏳ À espera da base de dados"
for i in $(seq 1 30); do
  if docker exec xplendor-php php artisan migrate:status > /dev/null 2>&1; then
    break
  fi
  if [ "$i" -eq 30 ]; then
    echo "❌ A base de dados não respondeu em 60 segundos: deploy parado antes das migrações."
    exit 1
  fi
  sleep 2
done

echo "🗃️ Migrations (prod)"
docker exec -it xplendor-php php artisan migrate --force

echo "🧱 Storage link + permissions"
docker exec -it xplendor-php php artisan storage:link || true
docker exec -it xplendor-php sh -lc "chmod -R 775 storage bootstrap/cache && chown -R www-data:www-data storage bootstrap/cache"

echo "🧹 Clear caches"
docker exec -it xplendor-php php artisan config:clear
docker exec -it xplendor-php php artisan config:cache
docker exec -it xplendor-php php artisan route:clear
docker exec -it xplendor-php php artisan route:cache
docker exec -it xplendor-php php artisan view:clear

# O worker arrancou antes de a configuração ser refeita: recomeça com o código e a
# configuração novos (restart: always no docker-compose.prod.yml).
echo "🔁 Fila: reiniciar o worker"
docker exec -it xplendor-php php artisan queue:restart

echo "⚛️ Frontend (app CRA) build — basename /app vem do homepage:/app do package.json"
cd web/
yarn install
echo "⚛️ Delete old build"
rm -rf build/
echo "⚛️ Build new build"
yarn build

echo "🌐 Landing (Rayo / Next.js) build — export estático para site/out"
cd ../site
npm install
echo "🌐 Delete old out"
rm -rf out/
echo "🌐 Build new out"
npm run build

cd ..

echo "🔁 Reload nginx (docker)"
docker restart xplendor-nginx

echo "✅ Deploy OK"
