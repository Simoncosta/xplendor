#!/bin/bash
set -e

APP_DIR="/home/xplendor"
SCRAPER_LOG="docker/scraper/scraper.log"

# O código novo vem ANTES de desligar: se o pull falhar, o deploy pára sem desligar nada.
# O registo do scraper esteve seguido pelo git (até ao commit 9223545) e o scraper escreve
# nele; com alterações locais, o pull recusa-se a apagá-lo. Guarda-se uma cópia, repõe-se a
# versão do git, faz-se o pull e devolve-se a cópia (o ficheiro passa a ficar fora do git).
pull_code() {
  local backup=""
  if git ls-files --error-unmatch "$SCRAPER_LOG" > /dev/null 2>&1 && ! git diff --quiet -- "$SCRAPER_LOG"; then
    backup="$(mktemp /tmp/scraper.log.XXXXXX)"
    cp "$SCRAPER_LOG" "$backup"
    git checkout -- "$SCRAPER_LOG"
    echo "   Registo do scraper guardado em $backup antes do pull."
  fi

  if ! git pull --ff-only origin main; then
    if [ -n "$backup" ]; then
      cp "$backup" "$SCRAPER_LOG"
      rm -f "$backup"
    fi
    echo "❌ O git pull falhou: deploy parado, nada foi desligado."
    return 1
  fi

  if [ -n "$backup" ]; then
    mkdir -p "$(dirname "$SCRAPER_LOG")"
    cp "$backup" "$SCRAPER_LOG"
    rm -f "$backup"
    echo "   Registo do scraper devolvido (agora fora do git)."
  fi
}

echo "🔄 Deploy Xplendor (prod)"
cd "$APP_DIR"

echo "📥 Git pull (antes de desligar)"
pull_code || exit 1

# Logo a seguir ao pull: o código está montado nos contentores e não pode ficar a correr
# com a base de dados antiga mais do que o necessário.
echo "Docker Down"
docker compose -f docker-compose.prod.yml down

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
