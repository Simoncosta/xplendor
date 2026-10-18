# Site do consultor (XPLENDOR)

O site público dos consultores imobiliários: um só serviço Next.js (modo servidor, `output: "standalone"`) para todos os consultores. O consultor é reconhecido pelo host do pedido; tudo o que é pessoal vem do perfil do consultor na XPLENDOR, pela API do site (`/api/public/site/v1`, servidor a servidor, com o token do contentor).

## Dev

1. Na XPLENDOR (dev): o token do contentor, `php artisan consultor:site-token --site=<empresa>`.
2. `.env.local` (fora do git), a partir de `.env.example`: `CONSULTOR_API_URL=http://localhost:8001/api/public/site/v1`, o token, `CONSULTOR_REVALIDATE_SECRET` e `CONSULTOR_DEV_HOSTS=localhost:3001=<host do site>`.
3. `npm install` e `npm run dev` (porta 3001).
4. Um site em rascunho dá 404. Para o ver: `php artisan consultor:preview-link <empresa> --base=http://localhost:3001` e abrir o link (1 hora), ou o botão "Pré-visualizar" na página Site do consultor.

## Verificações

`npm run typecheck`, `npm run lint`, `npm test` (testes unitários) e `npm run build`.

Os textos fixos do site estão todos em `content/textos.ts`.
