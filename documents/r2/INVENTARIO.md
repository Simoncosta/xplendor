# R2: inventário dos ficheiros guardados (noite de 10 de outubro de 2026)

Levantamento do código (só leitura) e dos tamanhos em dev. Discos em `server/config/filesystems.php`: `local` (storage/app/private), `public` (storage/app/public, servido pelo nginx em `/storage`), `media` (storage/app/media, privado), `r2` (novo, Cloudflare R2) e `s3` (configurado, sem uso).

## Tamanhos em dev

| Disco | Tamanho | Conteúdo |
|---|---|---|
| media | 3,9 MB | Linha Editorial da empresa 1 (6 ficheiros: 2 imagens com as variantes) |
| local (private) | 4,3 MB | faturas do OCR (9 ficheiros, 824 KB), PDFs de orçamentos (4, 2,9 MB), relatórios CSV, um modelo de documento |
| public | 37 MB | imagens de viaturas, logótipos, fotografias, banners (101 ficheiros) |

## O que passa a poder estar no R2 (esta noite)

| Ficheiro | Disco antes | Caminho | Quem lê e como | Configuração |
|---|---|---|---|---|
| Linha Editorial: originais (imagem e vídeo), miniaturas, pré-visualizações, poster dos vídeos | media | `company_{c}/{uuid}/original.*`, `thumb.webp`, `preview.webp`, `poster.jpg` | URL assinado da aplicação (`/api/media/{asset}/{variante}`, 30 min), gerado pelos ecrãs da Linha Editorial (atrás do tenant e do ACL); no R2, redireciona para um endereço assinado do R2 de 5 minutos. O worker (miniaturas, ffmpeg) trabalha numa cópia temporária. A IA das legendas lê as pré-visualizações. | `MEDIA_DISK` (cada asset guarda o seu disco em `media_assets.disk`) |
| Envios em partes por concluir | media (local) | `tmp/{uuid}.part` | o próprio envio | ficam SEMPRE no disco local (num objeto S3 não se acrescenta) |
| Fotos de perfil das contas ligadas (copiadas da Meta) | media | `social/company_{c}/account_{id}.*` | URL assinado `/api/social-avatar/{conta}` | `MEDIA_DISK` |
| PDFs das cobranças da XPLENDOR | local | `charges/company_{c}/{uuid}.pdf` | bytes, atrás do tenant e do ACL (empresa), do `/admin` (root) ou do token da cobrança | `PRIVATE_FILES_DISK` |
| Comprovativos de pagamento | local | `charges/company_{c}/proofs/{uuid}.*` | bytes, só o root | `PRIVATE_FILES_DISK` |
| Faturas do OCR (imagem ou PDF) | local | `ocr-invoices/{c}/{hash}.*` | bytes, atrás do tenant, do ACL e do módulo; o job do OCR lê os bytes | `OCR_INVOICE_DISK`, que por omissão segue `PRIVATE_FILES_DISK` |

## O que fica no disco local (decisão técnica por omissão)

| Ficheiro | Disco | Porquê |
|---|---|---|
| Imagens das viaturas (cortadas e originais), 360, logótipos, fotografias da equipa, avatares, banners do blog, capturas e faturas dos tickets, fotografias dos relatórios de satisfação | public | São servidos diretamente pelo nginx em `/storage` e guardados na base de dados como URLs `/storage/...`; passar para o R2 obriga a mudar o formato e quem os lê. Não foi pedido esta noite. |
| PDFs dos orçamentos | local | Crescem devagar (um por versão); ficam como estão. A mudança é uma linha (usam só `get`/`put`). |
| Modelos de documento (.docx) e documentos gerados | local | O PhpWord e o ZipArchive trabalham em caminhos locais; os gerados são temporários. |
| Relatórios CSV dos comandos, cache de fontes do dompdf | local | Ferramentas internas. |

## Observações para depois (não mexi)

- As **fotografias originais das viaturas** (com possíveis dados EXIF e GPS) ficam públicas em `/storage/.../originals/`.
- As **faturas dos tickets de suporte** (PDF) estão no disco público, acessíveis a quem tiver o URL.
- As **fotografias dos relatórios de satisfação** (dados pessoais de clientes) são URLs públicos.
- Os **avatares dos utilizadores** são guardados como chegam, sem recodificar; na alteração do avatar, o código usa o id do utilizador como id da empresa no caminho.
- A rota antiga **`/api/media/{path}`** expõe todo o disco público, com CORS para localhost.
- Vários `Storage::url()` usam o disco por omissão por acaso; se `FILESYSTEM_DISK` mudar, esses URLs partem. Devem passar a `Storage::disk('public')->url()`.
- O apagamento definitivo de uma empresa não apagava os envios em partes por concluir: corrigido esta noite (apaga-os, e apaga também no R2 e na cópia local).
- `league/commonmark` tem dois avisos de segurança no `composer audit` (já existia antes desta noite). Resolvido no pré-deploy (ponto 6): 2.10.3, sem avisos.

## Resolvido no pré-deploy (ponto 5)

- **Faturas dos tickets** e **fotografias dos relatórios de satisfação:** passam para o disco privado (`PRIVATE_FILES_DISK`), em `support-invoices/company_{c}/` e `satisfaction-reports/company_{c}/{relatório}/`, servidas por URLs assinados de 30 minutos (`/api/files/ticket-invoice/{id}` e `/api/files/report-photo/{id}`), gerados apenas por quem já passou a empresa e o ACL. Os ficheiros antigos migram com `php artisan files:make-private` (simulação por omissão; `--execute` copia, confirma os bytes, atualiza a referência e apaga a cópia pública).
- **Originais das viaturas:** gravados sem EXIF nem GPS. As existentes limpam-se com `php artisan images:strip-exif` (simulação por omissão; também trata os avatares).
- **Rota `/api/media/{path}`:** só serve imagens de viaturas (`company_{c}/cars/.../images|originals/...`), que é o que o editor de imagem precisa; o CORS vem da configuração global (`config/cors.php`), sem localhost fixo.
- **Avatares:** recodificados em WebP (sem EXIF), em `company_{c}/users/`, com o id da empresa certo.
- **`Storage::url()`:** os caminhos públicos pedem-se sempre ao disco `public` (`App\Support\Storage\PublicUrl`).
