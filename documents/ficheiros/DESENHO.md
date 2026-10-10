# Ficheiros: desenho (F0)

Escrito na noite de 10 de outubro de 2026, a partir do pedido em `documents/NOITE-FICHEIROS.md` e de um levantamento do código (só leitura). Os tamanhos são os do dev.

## 1. Mapa dos ficheiros que a plataforma guarda por empresa

Discos (`server/config/filesystems.php`):
- `public`: `storage/app/public`, servido pelo nginx em `/storage`;
- `local`: `storage/app/private`;
- `media`: `storage/app/media` (privado);
- `r2`: Cloudflare R2, privado.

As variáveis do `.env` escolhem o disco:
- `MEDIA_DISK`: Linha Editorial;
- `PRIVATE_FILES_DISK`: cobranças, faturas dos pedidos e fotografias dos relatórios;
- `OCR_INVOICE_DISK`: faturas do OCR; por omissão, segue `PRIVATE_FILES_DISK`.

| Ficheiro | Disco e caminho | Tabela (referência) | Quem o pode ver hoje | Retenção e apagamento |
|---|---|---|---|---|
| Imagens das viaturas (WebP) | public: `company_{c}/cars/{marca-modelo}-{id}/images/…` | `car_images.image` (`/storage/…`) | público (site do stand, API pública); na plataforma, `automovel.ver` (módulo Stock) | apagadas ao retirar a imagem e ao apagar a viatura |
| Originais das viaturas | public: `…/originals/…` (sem EXIF desde o pré-deploy) | `car_images.original_path` | público por URL; na plataforma, `automovel.ver` | só ao apagar a viatura (ao retirar uma imagem, o original fica órfão) |
| 360 exterior | public: `…/360_exterior/{ordem}.webp` | `car_360_exterior_images.image` | público; `automovel.ver` | ao retirar e ao apagar a viatura |
| Logótipo | public: `company_{c}/logo/logo.webp` | `companies.logo_path` | público; `empresa.ver` | substituído no mesmo sítio |
| Fotografias da equipa | public: `company_{c}/team/{uuid}.webp` | `collaborators.photo_path` (relativo) | público (página da equipa); `utilizadores.ver` | ao substituir e ao apagar |
| Avatares | public: `company_{c}/users/…` | `users.avatar`, `user_invites.avatar` | `utilizadores.ver` | ao substituir |
| Banners do blog | public: `company_{c}/blogs/…` | `blogs.banner`, `blogs.og_image` | público; `blog.ver` | ao substituir e ao apagar o artigo |
| Capturas dos pedidos de suporte | public: `company_{c}/tickets/…` | `support_tickets.screenshot_path` | `suporte.ver` | nunca |
| Faturas dos pedidos de suporte | privado: `support-invoices/company_{c}/…` | `support_tickets.invoice_path` | só o Administrador (`faturacao_xplendor.ver`) | nunca (as antigas, em `/storage`, migram com `files:make-private`) |
| Fotografias dos relatórios de satisfação | privado: `satisfaction-reports/company_{c}/{relatório}/…` | `satisfaction_report_photos.path` | `automovel.ver`, módulo Pós-venda | o cliente pode apagar |
| Linha Editorial: originais e variantes | media: `company_{c}/{uuid}/original.*`, `thumb.webp`, `preview.webp`, `poster.jpg` | `media_assets` (disk, dir, variants) | `editorial.ver`, módulo Linha Editorial | retenção (ver §2.5) |
| Fotos das contas ligadas | media: `social/company_{c}/account_{id}.*` | `social_connection_accounts.profile_picture_path` | quem vê as integrações | ao desligar |
| Cobranças da XPLENDOR (PDF) | privado: `charges/company_{c}/{uuid}.pdf` | `expense_charges.invoice_path` | só o Administrador | 10 anos (nunca apagadas) |
| Comprovativos de pagamento | privado: `charges/company_{c}/proofs/…` | `expense_charges.proof_path` | o root (e o Administrador, pelo pedido) | como as cobranças |
| Faturas do OCR | ocr_disk: `ocr-invoices/{c}/…` | `ocr_invoices.image_path` | `restauracao.ver`, módulo Restauração: Faturas | apagadas: ficheiro apagado ao fim de 30 dias (`ocr-purge-deleted-files`) |
| PDFs dos orçamentos da XPLENDOR | local: `quotes/{orçamento}/{número}-v{n}.pdf` | `quote_versions.pdf_path` (`quotes.company_id`) | só o Administrador | nunca |
| Modelos de documento (.docx) | local: `company_{c}/document_templates/…` | `document_templates.original_path` | `automovel.ver`, módulo Documentos | ao substituir e ao apagar |

**Ficam de fora dos Ficheiros (e porquê):**
- **Temporários**, apagados logo:
  - partes dos envios (`media: tmp/*.part`);
  - documentos gerados a partir dos modelos e o modelo de exemplo (`local: tmp/…`);
  - ZIP das imagens das viaturas;
  - cópias locais de trabalho do R2 (`storage/app/tmp-copias`).
- **Ficheiros internos da plataforma, sem empresa:**
  - relatórios CSV dos comandos (`local: reports/`);
  - resultados da comparação do OCR (`local: ocr-compare/`);
  - o JSON dos perfis do ACL;
  - o registo da migração para o R2 (só base de dados).
- **Fotos das contas ligadas:** são cópias pequenas da Meta, já mostradas nas integrações, e voltam a ser copiadas todas as semanas.

**Problemas encontrados no levantamento** (não mexi; ficam para depois):
- o original de uma imagem de viatura retirada fica órfão no disco;
- as viaturas gravadas como rascunho deixam ficheiros em `draft-{id}`;
- os quadros do 360 têm nomes que colidem (a ordem);
- uma fatura de pedido ou um comprovativo substituídos deixam o antigo no disco;
- as capturas dos pedidos nunca se apagam;
- os PDFs dos orçamentos e os modelos de documento estão fixos no disco `local` (não seguem o R2).

## 2. O catálogo único

### 2.1 Tabelas (migração aditiva `2026_12_28_100000_create_files_catalog`)

- **`file_folders`**: as pastas, só na base de dados (`company_id`, `parent_id`, `name`).
  - `system_key` marca as pastas do sistema, por exemplo `editorial` e `editorial:2026-10`.
  - Apagar põe a pasta na reciclagem (`deleted_at`, `purge_after`).
  - Mudar o nome ou mover só muda a linha; nenhum ficheiro é copiado.
- **`stored_files`**: cada ficheiro do catálogo.
  - Onde está: `disk` + `path` (nunca muda), mais nome, tipo e tamanho.
  - De onde vem: `source` (`upload` nos Ficheiros; `media`, `ocr`, `cobranca`, … nos módulos) e `source_ref`, único por empresa, por exemplo `car_images:12:original`.
  - `is_system`: os ficheiros dos módulos não se apagam nem se movem aqui.
  - `media_asset_id`: a media da Linha Editorial (as variantes, para as imagens e os vídeos).
  - `missing_at`: o módulo de origem já não o tem.
  - Reciclagem: `deleted_at`, `purge_after`.
- **`stored_file_shortcuts`**: "Guardar numa pasta". O mesmo ficheiro aparece numa pasta das pessoas, sem cópia, e apagar o atalho não apaga o ficheiro.
- **`media_assets.original_disk` + `original_path`**: uma media da Linha Editorial cujo original já existe noutro sítio (por exemplo, uma imagem de viatura escolhida para uma publicação). Só as variantes são ficheiros novos, e a retenção nunca apaga esse original.

### 2.2 Como entram os ficheiros que já existem, sem serem copiados

- O registo (`App\Services\Files\SystemFileCatalog`) tem um "registador" por origem. Cada um lê a tabela do módulo e grava ou atualiza a linha em `stored_files`, com o disco e o caminho de origem e o tamanho lido do disco. Nada é copiado.
- O que saiu do módulo fica com `missing_at` (deixa de aparecer); nunca se apaga nada no disco.
- **Comando `php artisan files:catalog`:** simulação por omissão (quantos ficheiros por origem e por empresa); `--execute` grava. É idempotente: a chave é `source_ref`.
- **Manter em dia sem observadores em todos os módulos:**
  - ao abrir uma pasta do sistema, o registo dessa origem corre para essa empresa, no máximo de 5 em 5 minutos;
  - de noite, a tarefa interna `files-catalog-nightly` corre para todas as empresas.
  - Os ficheiros enviados nos Ficheiros entram diretamente.

### 2.3 Ligação à Linha Editorial

- **A media enviada numa publicação** já é um `media_assets`. O registo põe-na na pasta `Linha Editorial / AAAA-MM`, pelo mês do envio.
- **As imagens e os vídeos enviados nos Ficheiros** usam o mesmo envio em partes da Linha Editorial (`MediaService`, os mesmos limites e o mesmo processamento). Ficam com `media_asset_id`, e por isso podem ser escolhidos numa publicação sem cópia.
- **Os documentos** (PDF, Office) vão para `PRIVATE_FILES_DISK`, como ficheiros simples.
- **"Escolher dos Ficheiros"** devolve a media do ficheiro e a publicação usa o `setMedia` de hoje (por identificador de media).
  - Um ficheiro do sistema sem media (por exemplo, uma imagem de viatura) ganha uma media com o original no sítio onde está; as variantes geram-se uma vez.
  - O vídeo passa pelo mesmo processamento.
- **"Em uso":** as publicações cujas versões usam a media (`editorial_post_version_media`). Um ficheiro em uso não se apaga.

### 2.4 Envio, tipos e quota

- **Tipos aceites:**
  - imagens JPEG, PNG e WebP, e vídeos MP4 e MOV, pela Linha Editorial (até 30 MB e 300 MB);
  - documentos até 50 MB: PDF, Word, Excel, PowerPoint (antigos e atuais) e OpenDocument; também GIF e SVG.
- **Confirmados pelo conteúdo, não só pela extensão:**
  - assinatura do PDF;
  - estrutura dos ficheiros Office: OOXML é um ZIP com `[Content_Types].xml`; os antigos são OLE2;
  - tipo real das imagens.
- **Recusados sempre:** executáveis (MZ, ELF, Mach-O), scripts (`#!`, `<?php`) e HTML.
- **O SVG:** recusado se tiver `<script`, `on…=` ou `javascript:`. Quando aceite, sai sempre como descarga, com `Content-Security-Policy: sandbox` e `nosniff`; nunca abre no browser como página.
- **Nomes limpos:** sem caminhos, sem caracteres de controlo, até 200 carateres.
- **Quota:** verifica-se antes do envio (a soma do "Espaço por empresa" mais o tamanho). Os documentos enviados contam num tipo novo, "Ficheiros"; as imagens e os vídeos já contam na Linha Editorial.

### 2.5 Retenção e reciclagem

- **Reciclagem:** apagar nos Ficheiros põe o ficheiro, a pasta ou o atalho na reciclagem durante 30 dias, com restauro.
  - A tarefa interna `files-purge-recycle` (diária) apaga de vez o que passou os 30 dias: o ficheiro enviado e a sua media, se nada a usar.
  - Os ficheiros do sistema nunca vão para a reciclagem aqui.
- **A retenção da Linha Editorial (`MediaRetentionJob`) passa a respeitar os Ficheiros:**
  - uma media com um ficheiro enviado nos Ficheiros ou guardado numa pasta das pessoas nunca é apagada como "enviada e não usada" (7 dias), nem perde o original aos 12 meses;
  - uma media com o original noutro sítio (`original_path`) nunca apaga esse original; só as suas variantes.

### 2.6 Acessos

- Rotas em `/companies/{id}/files…`, com o portão da empresa, o ACL (área `ficheiros`) e o módulo `ficheiros`.
- **Cada empresa vê só os seus ficheiros.** Um identificador de outra empresa responde 404.
- **O root vê todos.** Os acessos do root aos ficheiros de outra empresa ficam no registo que já existe, a tabela `audits` (owen-it), com o evento `ficheiros.acesso_root`, a empresa, a ação e o ficheiro.
- **Abrir e descarregar:** endereços assinados de 30 minutos, gerados só depois do ACL. No R2, redirecionam para um endereço assinado de 5 minutos, como os media.

## 3. Pastas do sistema e a permissão de cada uma

A regra: os Ficheiros nunca abrem um ficheiro que a pessoa não pudesse abrir no módulo de origem. As pastas dos módulos desligados não aparecem.

| Pasta | Ficheiros | Módulo | Permissão | Notas |
|---|---|---|---|---|
| Linha Editorial / AAAA-MM | media das publicações (originais, com as variantes) | Linha Editorial | `editorial.ver` | |
| Faturas de fornecedores / AAAA-MM | faturas do OCR (as não apagadas) | Restauração: Faturas | `restauracao.ver` **e** `financas.ver` | nunca a agência (decisão 9) |
| Faturação XPLENDOR / Cobranças, Comprovativos, Pedidos de suporte, Orçamentos | PDFs das cobranças, comprovativos, faturas dos pedidos, PDFs dos orçamentos | (base) | `faturacao_xplendor.ver` (só o Administrador) | o `Access` já recusa a agência e o root noutras empresas |
| Suporte | capturas de ecrã dos pedidos | (base) | `suporte.ver` | |
| Relatórios de satisfação | fotografias dos clientes | Pós-venda | `automovel.ver` | dados pessoais: nunca a agência |
| Viaturas / {viatura} | imagens, originais e 360 | Stock de Veículos | `automovel.ver` | |
| Marca e logótipos | logótipo | (base) | `empresa.ver` | |
| Blog | banners dos artigos | (base) | `blog.ver` | |
| Equipa | fotografias da equipa e avatares | (base) | `utilizadores.ver` | |
| Modelos de documento | modelos .docx | Documentos | `automovel.ver` | |

As pastas das pessoas seguem a área `ficheiros`: ver, criar, editar e apagar.

## 4. O que pode correr mal

- **Abrir mais do que o módulo deixa:** uma pasta do sistema sem a regra certa. Cada pasta tem a sua regra numa tabela única (`SystemFolders`), com teste por pasta, e o pedido de abrir volta a verificar a regra (não confia na listagem).
- **Ver ficheiros de outra empresa:** todas as consultas filtram por `company_id`, e um identificador de outra empresa responde 404. Há teste de isolamento.
- **Apagar o que não é dos Ficheiros:** os ficheiros do sistema não se apagam nem se movem aqui. A reciclagem só apaga de vez os ficheiros enviados nos Ficheiros, e a sua media só se nada a usar.
- **A retenção da Linha Editorial apagar um ficheiro guardado:** a retenção passa a excluir as media com ficheiro nos Ficheiros, com teste.
- **Registo desatualizado:** um ficheiro novo num módulo só aparece depois da sincronização (ao abrir a pasta, ou de noite), e um apagado fica com `missing_at`. Nunca se abre um ficheiro sem confirmar que existe.
- **Ficheiros grandes no registo:** o tamanho lê-se do disco uma vez (no R2, um pedido HEAD por ficheiro; o primeiro registo em produção demora).
- **Conteúdo perigoso:** HTML, SVG com script ou executáveis com outra extensão. A verificação é pelo conteúdo, e o SVG sai sempre como descarga.
- **Quota:** documentos grandes enchem a quota da empresa. A quota é verificada antes, e o "Espaço por empresa" mostra o tipo "Ficheiros".
- **Nomes repetidos numa pasta:** acrescenta-se " (2)".

## 5. Plano R2-2: os ficheiros públicos no R2 (só documento)

### 5.1 Inventário

**Em dev:**

| Tipo | Ficheiros | Tamanho |
|---|---|---|
| Imagens das viaturas | 58 | 10,8 MB |
| Originais das viaturas | 17 | 21,9 MB |
| 360 exterior | 0 | 0 |
| Logótipos | 2 | 28 KB |
| Equipa | 2 | 8 KB |
| Avatares | 4 | 1,5 MB |
| Banners do blog | 4 | 0,6 MB |
| Capturas dos pedidos | 0 | 0 |
| Fotografias antigas dos relatórios (por migrar com `files:make-private`) | 2 | 0,3 MB |
| **Total do disco público** | **90** | **35 MB** |

Os originais são 2/3 do tamanho.

**Para medir em produção** (só leitura):
```
cd /home/xplendor/server/storage/app/public
for p in "cars/*/images" "cars/*/originals" "cars/*/360_exterior" logo team users blogs tickets; do
  n=$(find . -path "./company_*/$p/*" -type f | wc -l); s=$(find . -path "./company_*/$p/*" -type f -print0 | xargs -0 du -ck | tail -1 | cut -f1)
  echo "$p: $n ficheiros, ${s:-0} KB"; done; du -sh .
```

### 5.2 Quem os lê e onde estão os URL

- **Base de dados:**
  - com o prefixo `/storage/`: `car_images.image` e `original_path`, `car_360_exterior_images.image`, `companies.logo_path`, `blogs.banner` e `og_image`, `support_tickets.screenshot_path`;
  - relativos ao disco: `collaborators.photo_path`, `users.avatar`, `user_invites.avatar`.
- **API pública** (os sites dos stands):
  - `CarPublicResource` devolve `car_images.image` tal como está (`/storage/…`), e o site junta o domínio da API;
  - o blog e a equipa fazem o mesmo.
- **Site dos stands:** as páginas geradas guardam esses URL (com o domínio da API) no HTML e nos sitemaps.
- **Meta:**
  - a publicação a partir da Linha Editorial usa endereços assinados da media, não o disco público;
  - os anúncios e catálogos que alguém tenha criado à mão na Meta com um URL `/storage/…` são o caso de risco (não há código que os envie, mas podem existir).
- **O editor de imagem** lê pela rota `/api/media/{path}` (limitada às imagens das viaturas).

### 5.3 As duas opções de domínio (a decisão é do Simon)

- **A. Pôr o `xplendor.tech` na Cloudflare** (DNS e proxy):
  - uma regra no domínio serve `/storage/*` a partir do R2 (Worker ou "R2 custom domain" num caminho);
  - os URL não mudam: os links publicados continuam a funcionar sem mexer na base de dados;
  - **mas** todo o domínio passa pela Cloudflare (DNS, certificados, e o tráfego da aplicação), uma mudança maior e com mais risco.
- **B. Um domínio só para imagens** (por exemplo, `img.xplendor.tech`, ligado ao bucket público):
  - mudança pequena e isolada;
  - os URL novos passam a apontar para lá: a base de dados guarda o caminho e o domínio vem da configuração;
  - os URL antigos (`/storage/…`) continuam a funcionar pelo nginx, que pode servir do disco ou redirecionar (301) para o domínio novo;
  - os links publicados não partem desde que o nginx mantenha `/storage/*`.

**Recomendação técnica: B**, com o redirecionamento no nginx: menos risco, reversível e sem mexer no domínio principal.

### 5.4 O comando de migração (para escrever na R2-2)

- `php artisan storage:public-to-r2`: simulação por omissão (ficheiros e MB por tipo); `--execute` copia com confirmação do tamanho e do SHA-256.
- É retomável e usa o mesmo registo `storage_migration_items` (tipos novos: `viatura`, `viatura_original`, `viatura_360`, `logotipo`, `equipa`, `avatar`, `banner`, `captura`).
- **Nunca apaga o local:** isso fica para o `storage:purge-local`, uns dias depois, como nos privados.
- Os URL na base de dados **não mudam** (guardam `/storage/…`). Quem gera o URL público passa a usar `PUBLIC_FILES_BASE_URL` (vazio = o de hoje).

### 5.5 Como não partir links já publicados

- O nginx mantém `/storage/*`:
  - primeiro serve do disco local (as cópias continuam lá);
  - depois do `purge-local`, redireciona com 301 para o domínio das imagens.
- Os sites dos stands e os sitemaps continuam a funcionar (seguem o 301); os novos já levam o domínio novo.
- Os URL guardados fora (Meta, emails já enviados) seguem o 301.

### 5.6 Como voltar atrás

- Antes do `purge-local`: `PUBLIC_FILES_BASE_URL` vazio e `config:cache`. Volta tudo ao disco local, sem perda.
- Depois do `purge-local`: copiar de volta do R2 (o mesmo comando ao contrário, `--from=r2`), e só depois tirar o 301.

### 5.7 As fatias

1. Medir em produção (5.1) e decidir o domínio (5.3).
2. `PUBLIC_FILES_BASE_URL` e a geração dos URL num só sítio (`PublicUrl`), sem mudar nada com a variável vazia.
3. O comando de migração (simulação e `--execute`), com testes.
4. Em produção: copiar e confirmar, ligar a variável e o 301 no nginx.
5. Uns dias depois: `storage:purge-local` para os públicos.
6. Os novos envios vão diretamente para o R2 (os serviços de imagens das viaturas, logótipos e banners escrevem no disco público configurado).
