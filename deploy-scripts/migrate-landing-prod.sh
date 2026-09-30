#!/bin/bash
# =============================================================================
# XPLENDOR — MIGRAÇÃO PONTUAL (uma vez): landing Rayo na RAIZ + app CRA em /app
# =============================================================================
# Corre UMA VEZ na VPS, depois do git pull que traz:
#   - docker/nginx/prod/default.conf  (raiz=Rayo, /app=CRA, redirect /r/, Laravel intacto)
#   - docker-compose.prod.yml         (novo mount ./site/out:/var/www/site/out:ro)
#   - site/next.config.ts, site/tsconfig.json  (Rayo raiz, type-check ligado)
#   - web/package.json                (homepage:/app → build do CRA em /app)
#   - deploy-scripts/deploy.sh        (passa a construir também o Rayo em cada deploy)
#
# PORQUÊ um script à parte (e não só o deploy.sh): o compose MUDOU (novo mount site/out).
# `docker restart xplendor-nginx` NÃO aplica mudanças de compose — é preciso recriar o
# container com `up -d --force-recreate`. Isto faz-se uma vez, aqui.
#
# ⚠️ Executar a partir de $APP_DIR (a raiz do repo na VPS). NÃO toca em prod sozinho — és tu.
# =============================================================================
set -e

APP_DIR="/home/xplendor"
cd "$APP_DIR"

echo "==> [0/6] Pré-requisitos"
node -v || { echo "❌ Node não encontrado. O Rayo (Next 16) precisa de Node >= 18.18 (idealmente 20)."; exit 1; }
echo "    (confirma acima que a versão do Node é >= 18.18; se não for, instala antes de continuar)"

echo "==> [1/6] Git pull (traz nginx prod, compose, next.config, package.json, deploy.sh)"
git pull origin main

echo "==> [2/6] Build da app CRA (basename /app vem do homepage:/app)"
cd "$APP_DIR/web"
yarn install
rm -rf build/
yarn build
# Sanidade: o index tem de referenciar assets em /app/static/…
grep -q "/app/static/" build/index.html && echo "    OK: build do CRA com basename /app" \
  || { echo "❌ build do CRA NÃO tem basename /app (verifica homepage:/app no package.json)"; exit 1; }

echo "==> [3/6] Build da landing Rayo (export estático → site/out)"
cd "$APP_DIR/site"
npm install
rm -rf out/
npm run build
# Sanidade: o export tem index e assets na raiz (/_next), sem /landing-preview
test -f out/index.html || { echo "❌ site/out/index.html não gerado"; exit 1; }
grep -q "/_next/" out/index.html && echo "    OK: Rayo exportado com assets na raiz (/_next)" \
  || { echo "❌ Rayo sem assets /_next no index"; exit 1; }

echo "==> [4/6] Recriar o nginx com o novo compose (aplica o mount site/out + a nova conf)"
cd "$APP_DIR"
docker compose -f docker-compose.prod.yml up -d --force-recreate webserver

echo "==> [5/6] Validar a config do nginx"
docker exec xplendor-nginx nginx -t

echo "==> [6/6] Smoke tests (HTTPS)"
BASE="https://xplendor.tech"
echo -n "    / (Rayo)                : "; curl -sk -o /dev/null -w "HTTP %{http_code}\n" "$BASE/"
echo -n "    /app/ (CRA)             : "; curl -sk -o /dev/null -w "HTTP %{http_code}\n" "$BASE/app/"
echo -n "    /app (301→/app/)        : "; curl -sk -o /dev/null -w "HTTP %{http_code} -> %{redirect_url}\n" "$BASE/app"
echo -n "    /api/v1/login (POST)    : "; curl -sk -o /dev/null -w "HTTP %{http_code} %{content_type}\n" -X POST "$BASE/api/v1/login" -H "Accept: application/json" -d '{}'
echo -n "    /xplendor.js (Laravel)  : "; curl -sk -o /dev/null -w "HTTP %{http_code} %{content_type}\n" "$BASE/xplendor.js"
echo -n "    /r/teste (301→/app/r/)  : "; curl -sk -o /dev/null -w "HTTP %{http_code} -> %{redirect_url}\n" "$BASE/r/teste"

echo ""
echo "✅ Migração aplicada. Esperado: / e /app/ = 200; /app = 301 ->/app/; /api = 422 JSON;"
echo "   /xplendor.js = 200 js; /r/teste = 301 -> /app/r/teste."
echo ""
echo "Depois desta migração, os deploys normais (deploy.sh) já reconstroem o Rayo sozinhos."
echo ""
echo "=============================================================================="
echo "REVERSÃO (se algo partir) — volta a raiz para o CRA:"
echo "  cd $APP_DIR"
echo "  git checkout docker/nginx/prod/default.conf docker-compose.prod.yml"
echo "  docker compose -f docker-compose.prod.yml up -d --force-recreate webserver"
echo "  docker exec xplendor-nginx nginx -t"
echo "(o web/build antigo — CRA na raiz — continua montado; a raiz volta a servir o CRA.)"
echo "=============================================================================="
