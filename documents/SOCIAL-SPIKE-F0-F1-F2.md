# Spike social: F0 técnico, F1 marcas geridas, F2 publicador

Data: 5 de outubro de 2026. Só leitura: nenhum ficheiro do projeto foi alterado além deste relatório.
Base de código: ramo `main`, commit `e49fa53` ("Fase 0").

Convenções deste documento:
- **[confirmado]**: dito na documentação oficial da Meta (URL indicado).
- **[a confirmar]**: a documentação é omissa ou contraditória, ou é inferência nossa. Validar num teste real antes de construir em cima.
- "Operador" é a empresa registada que opera a marca (agência ou a própria empresa).

---

## Índice

1. [F0 técnico (documentação oficial da Meta)](#f0)
2. [F1 modelo de marcas geridas](#f1)
3. [F2 publicador (desenho)](#f2)
4. [Decisões em aberto](#decisoes)
5. [Riscos](#riscos)
6. [Anexo: o que existe hoje no código](#anexo)

---

<a id="f0"></a>
## 1. F0 técnico

As referências da Graph API mostram v26.0 e os guias usam v25.0 (a versão que o código já usa).

### 1.1 Resumo

| Tema | Instagram | Facebook (Páginas) |
|---|---|---|
| Stories via API | Sim, `media_type=STORIES`, **só contas Business** (Creator não) [confirmado] | Sim, `/{page_id}/photo_stories` e `/{page_id}/video_stories` [confirmado] |
| Agendamento nativo | **Não existe.** O agendamento é nosso [confirmado por omissão; a Meta recomenda à app controlar o limite "if your app allows app users to schedule posts"] | Sim: `published=false` + `scheduled_publish_time` [confirmado] |
| Limite diário de publicação | 100 publicações por 24h móveis no guia; a referência de `content_publishing_limit` diz 50. Carrossel conta como 1. Criação de containers: 400 por 24h [confirmado, números contraditórios] | Sem limite numérico documentado para posts normais [a confirmar]. Reels: 30 por 24h [confirmado]. Rate limits por "Business Use Case" |
| Etiquetas de produto | Sim, só com Facebook Login, Shop aprovada (com checkout para parte dos negócios), catálogo; não em Stories nem Live; 25 publicações etiquetadas por 24h [confirmado] | Sem documentação de etiquetas de produto em posts de Página via API [a confirmar; assumir que não] |
| Mensagem privada a comentários | Sim: 1 mensagem por comentário, até 7 dias [confirmado] | Sim: 1 mensagem por comentário ou post, até 7 dias, `pages_messaging` [confirmado] |

### 1.2 Stories

**Instagram** ([content-publishing](https://developers.facebook.com/docs/instagram-platform/content-publishing), [ig-user/media](https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-user/media)):

- "Content Publishing is available to all Instagram Professional accounts, except Stories, which are only available to business accounts."
- **Imagem:** JPEG, até 8 MB, 9:16 recomendado.
- **Vídeo:** MOV ou MP4, H264 ou HEVC, AAC até 48 kHz, 23 a 60 fps, 3 a 60 s, até 100 MB, até 25 Mbps.
- **Não suportado:** stickers (link, sondagem, localização), etiquetas de produto e colaboradores.
  - Citação: "Publishing stickers (i.e., link, poll, location) is not supported; however mentioning users without a sticker is supported."
  - Menções sem sticker são suportadas.
- Para distinguir uma story publicada, usa-se `media_product_type` (o `media_type` devolve IMAGE ou VIDEO).

**Facebook** ([page-stories-api](https://developers.facebook.com/docs/page-stories-api)):

- **Permissões:** `pages_manage_posts`, `pages_read_engagement` e `pages_show_list`; com system users de negócio, também `business_management`.
- **Foto:** jpeg, bmp, png, gif ou tiff, até 10 MB.
- **Vídeo:** mp4, 9:16, mínimo 540x960, 24 a 60 fps.
- **Duração do vídeo:** a mesma página diz "3 to 90 seconds" e "cannot exceed 60 seconds" [a confirmar]. Validar com 60 s.
- Os media não podem ser reutilizados de posts anteriores.

### 1.3 Agendamento nativo no Facebook

| Tipo | Janela documentada | Fonte |
|---|---|---|
| Posts (guia) | 10 min a 30 dias | [pages-api/posts](https://developers.facebook.com/docs/pages-api/posts) |
| Posts (referência `/feed`) | 10 min a 75 dias | [page/feed](https://developers.facebook.com/docs/graph-api/reference/page/feed/) |
| Vídeos | 10 min a 6 meses | [page/videos](https://developers.facebook.com/docs/graph-api/reference/page/videos/) |
| Reels (`video_state=SCHEDULED`) | mais de 10 min, até 29 dias | [reels-publishing](https://developers.facebook.com/docs/video-api/guides/reels-publishing) |

Como funciona:
- **Fotos:** `scheduled_publish_time` em `/photos`.
- **Multi-foto:** fotos não publicadas com `temporary=true`, juntas com `attached_media` em `/feed` com `published=false`, `scheduled_publish_time` e `unpublished_content_type=SCHEDULED`.
- **Listar agendados:** `GET /{page-id}/scheduled_posts`. É só de leitura: "You can't perform this operation on this endpoint".
- **Editar:** "Posts can only be updated by the app that created them".
- **Cancelar:** fazer DELETE ao post agendado deve cancelá-lo, mas não encontrei frase explícita [a confirmar].

**Recomendação:** usar a janela mínima comum, **10 min a 29 dias**. Fora dela (menos de 10 min, mais de 29 dias, ou Stories) o agendamento fica do nosso lado.

### 1.4 Limites diários e rate limits

**Instagram:**
- [Guia](https://developers.facebook.com/docs/instagram-platform/content-publishing): "Instagram accounts are limited to 100 API-published posts within a 24-hour moving period. Carousels count as a single post."
- Referência [`content_publishing_limit`](https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-user/content_publishing_limit): `quota_total` "currently 50", `quota_duration` 86400.
- **Decisão técnica:** não fixar o número no código. Ler `quota_total` e `quota_usage` em cada publicação.
- Criação de containers: "An Instagram account can only create 400 containers within a rolling 24 hour period."
- Se as Stories e os Reels contam para os 100 ou 50: não documentado [a confirmar].

**Facebook:**
- Reels: 30 publicações por 24h móveis.
- Posts normais: sem número publicado [a confirmar].

**Rate limits gerais** ([rate-limiting](https://developers.facebook.com/docs/graph-api/overview/rate-limiting)):
- Pages API (Business Use Case): 4800 × utilizadores envolvidos por 24h.
- Instagram: 4800 × impressões por 24h.
- Messenger: 200 × utilizadores envolvidos por 24h.
- Monitorizar os cabeçalhos `X-App-Usage` e `X-Business-Use-Case-Usage` (inclui `estimated_time_to_regain_access`).

### 1.5 Etiquetas de produto (Instagram)

Fonte: [product-tagging](https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/product-tagging).

- **Requisitos:**
  - conta Business com "an approved Instagram Shop" e catálogo;
  - função de administrador no portefólio dono da loja;
  - app de empresa verificada.
- **Permissões:** `instagram_basic`, `instagram_content_publish`, `instagram_shopping_tag_products`, `catalog_management` e `business_management`.
- **Onde se aplica:**
  - feed (imagem e vídeo): até 20 etiquetas;
  - Reels: até 30, sem coordenadas;
  - carrossel: 20 no total, 5 por item;
  - não suportado em Stories, Live nem contas Creator.
- **Restrição desde 10 de agosto de 2023:** "some businesses without checkout-enabled Shops will no longer be able to tag their products". Na prática, a maioria dos clientes portugueses (sem checkout no Instagram) pode não ter acesso.
- **Não funciona com Instagram Login**, só com Facebook Login ([overview](https://developers.facebook.com/docs/instagram-platform/overview)).
- **Contradição na documentação:** o guia geral diz "Shopping tags are not supported". Prevalece o guia específico.

### 1.6 Mensagens privadas a comentários

**Facebook** ([private-replies](https://developers.facebook.com/docs/messenger-platform/discovery/private-replies)):
- **Endpoint:** `POST /{PAGE-ID}/messages` com `recipient.comment_id` (ou `post_id`).
- **Regras:**
  - "a single message";
  - "within 7 days from when the post or comment was created";
  - a conversa só continua se a pessoa responder, dentro da janela de 24h.
- **Requisitos:** token de Página com a tarefa `MESSAGING` e a permissão `pages_messaging`, que depende de `pages_manage_metadata` e `pages_show_list`.

**Instagram** ([private-replies](https://developers.facebook.com/docs/instagram-platform/private-replies)):
- **Endpoint:** `POST /<IG_ID>/messages` com `recipient.comment_id`.
- **Regras:** 1 mensagem por comentário, até 7 dias; seguimento só se a pessoa responder, dentro de 24h.
- **Permissões documentadas:**
  - Facebook Login: `instagram_basic`, `instagram_manage_comments` e `pages_read_engagement`;
  - Instagram Login: `instagram_business_basic` e `instagram_business_manage_comments`.
- Se é preciso também uma permissão de mensagens (`instagram_manage_messages`): a página não diz [a confirmar].

**Comentários públicos:**
- Facebook: `pages_manage_engagement` (criar, editar e apagar comentários), que depende de `pages_read_user_content`.
- Instagram: `instagram_manage_comments` ([comment-moderation](https://developers.facebook.com/docs/instagram-platform/comment-moderation)).

### 1.7 Acesso de parceiro (agência com acesso aos ativos do cliente)

**Lado do negócio (Meta Business):**
- Fontes: [ajuda 1717412048538897](https://www.facebook.com/business/help/1717412048538897) e [708679622611131](https://www.facebook.com/business/help/708679622611131).
- **Requisitos do cliente:** ter controlo total do seu portefólio de negócios e receber da agência o ID do portefólio dela.
- **Caminho:** Definições, Utilizadores, Parceiros, Adicionar, "Give a partner access to your assets", escolher os ativos (Página, conta de anúncios, etc.) e o nível de acesso.
- **Níveis de acesso:**
  - total: não permite repartilhar o ativo;
  - parcial: só as tarefas indicadas ("creating content, managing ads and responding to messages").
- **Depois, a agência** atribui o ativo a pessoas do seu próprio portefólio.
- Partilha de contas Instagram pelo mesmo fluxo: provável, sem frase oficial citada [a confirmar].

**Lado da app (XPLENDOR):**
- [Facebook Login for Business](https://developers.facebook.com/docs/facebook-login/facebook-login-for-business) (FLfB) é "the preferred authentication and authorization solution for tech providers".
- A app tem de ser do tipo Business. As permissões e os ativos pedidos definem-se numa "configuração" (`config_id`).
- **Dois tipos de token:**
  - token de utilizador;
  - BISU (Business Integration System User), para "programmatic, automated actions on your business clients' assets", que por omissão não expira.
- "Your app can only access the assets that were designated by your business client when they completed the Facebook Login for Business flow."
- **Listar Páginas:** `GET /me/accounts` devolve "The Facebook Pages that a person owns or is able to perform tasks on" (`pages_show_list`).
- **Conta Instagram de uma Página:** `GET /{page-id}?fields=instagram_business_account`.

**Permissões que o acesso de parceiro pede, por funcionalidade:**

| Funcionalidade | Permissões |
|---|---|
| Listar Páginas e ler | `pages_show_list`, `pages_read_engagement` |
| Publicar no Facebook (feed, fotos, vídeos, stories) | `pages_manage_posts` |
| Publicar no Instagram | `instagram_basic`, `instagram_content_publish` (+ `pages_read_engagement`) |
| Instagram quando a função na Página vem via portefólio | **também `ads_management` e `ads_read`** [confirmado: "If the app user was granted a role on the Page ... via the Business Manager, your app will also need: ads_management [and] ads_read"] |
| Ativos do portefólio (`/me/businesses`, `owned_pages`, `client_pages`) | `business_management` |
| Comentários | `pages_manage_engagement`, `pages_read_user_content`, `instagram_manage_comments` |
| Mensagens privadas | `pages_messaging` (+ `pages_manage_metadata`) |
| Métricas | `read_insights`, `instagram_manage_insights` |

- Que o `/me/accounts` devolve Páginas acedidas via parceiro sem `business_management`: não confirmado.
- **Na prática, o acesso de parceiro obriga a voltar a pedir `business_management`**, que acabámos de retirar por não ser usado nos anúncios. Isso só faz sentido quando houver código que o use, para não voltar a ser recusado no App Review.

**Barreiras de aprovação:**
- **Advanced Access:** obrigatório para servir contas que não são nossas.
  - "Advanced Access is the access level required if your app serves Instagram professional accounts that you don't own or manage."
  - Para Páginas: "All Page-related Permissions and Features require approval through the App Review process".
- **Business Verification:** obrigatória para Advanced Access desde 1 de fevereiro de 2023.
- **Access Verification (Tech Provider):** obrigatória para quem serve outras empresas. Aplica-se a quase todas as permissões acima.
  - Lista: `pages_manage_posts`, `pages_read_engagement`, `pages_show_list`, `instagram_basic`, `instagram_content_publish`, `business_management`, `ads_read`, `ads_management`, `read_insights`, etc.
  - Decisão em cerca de 5 dias ([access-verification](https://developers.facebook.com/docs/development/release/access-verification)).

### 1.8 Instagram Login vs Facebook Login for Business

| | Instagram Login | Facebook Login (for Business) |
|---|---|---|
| Página Facebook ligada | Não é preciso | Obrigatória |
| Publicação, comentários, insights | Sim | Sim |
| Etiquetas de produto | Não | Sim |
| Acesso de parceiro de agência | Não se aplica (quem entra é o titular da conta) [inferência] | Sim |
| Facebook (Páginas) no mesmo login | Não | Sim |

**Recomendação:** Facebook Login for Business como caminho principal. Ver a decisão 4.

### 1.9 Validação de media e containers (Instagram)

Fontes: [ig-user/media](https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-user/media) e [content-publishing](https://developers.facebook.com/docs/instagram-platform/content-publishing).

**Especificações:**
- **Imagem de feed:**
  - "JPEG is the only image format supported";
  - até 8 MB, rácio entre 4:5 e 1.91:1;
  - largura entre 320 e 1440 px;
  - convertida para sRGB.
- **Reels:** MOV ou MP4, 3 s a 15 min, até 300 MB, 23 a 60 fps, 9:16 recomendado. Não entram em carrosséis.
- **Carrossel:** até 10 itens, cortados pelo rácio da primeira imagem; sem `location_id` nos itens.

**Regras de publicação:**
- **Media acessível:** "The media must be hosted on a publicly accessible server at the time of the attempt". O upload resumable só existe para vídeo. Os URLs devem ser só US-ASCII.
- **Estados do container:** `IN_PROGRESS`, `FINISHED`, `ERROR`, `EXPIRED` ("not published within 24 hours") e `PUBLISHED`. A Meta recomenda consultar "once per minute, for no more than 5 minutes".
- **Bloqueios:** Páginas com Page Publishing Authorization pendente; Páginas com 2FA obrigatória exigem que o utilizador tenha 2FA.

**Implicação no código atual:** as imagens das viaturas são gravadas em **WebP**, e o Instagram só aceita JPEG. É preciso converter na publicação.

---

<a id="f1"></a>
## 2. F1 Modelo de marcas geridas

### 2.0 Situação atual (resumo; detalhe no anexo)

- **Tudo é por empresa:**
  - o middleware `tenant` (`EnsureTenantAccess`) só compara `user.company_id` com o `{id}` da rota;
  - cada utilizador tem **uma** empresa (`users.company_id`);
  - os papéis são `user|admin|root`, sem permissões finas.
- **Não existe conceito de marca:** todas as ocorrências de "brand" no código são marcas de viaturas.
- **Publicação social não existe:** a integração Meta só lê anúncios (`ads_read`).
- **Linha editorial:** catálogo global (`content_sectors`, `content_anchors`) e quatro tabelas por empresa. O ramo está em `companies.content_sector_id`.

### 2.1 a) Modelo de dados

Regra base: **cada marca tem exatamente uma empresa dona (operador)**. Pode ter uma ligação opcional a uma empresa registada (a empresa que a marca representa) e pode ter membros convidados. Cada empresa tem uma marca por omissão, que é ela própria.

```
brands
  id
  owner_company_id        FK companies          (operador; quem paga, gere ligações e membros)
  linked_company_id       FK companies, null    (empresa registada que a marca representa; null = cliente sem registo)
  default_for_company_id  FK companies, null, UNIQUE  (preenchido só na marca por omissão; garante 1 por empresa sem índice parcial)
  name, slug (UNIQUE por owner), logo_path
  content_sector_id       FK content_sectors, null   (ramo da linha editorial, passa da empresa para a marca)
  status                  active | archived
  timestamps, softDeletes

brand_profiles            (1:1 com brands; separado para não alargar a tabela principal e permitir histórico)
  brand_id                PK/FK
  tone_of_voice           text      (descrição livre + exemplos)
  audience                text
  pillars                 json      ([{name, description}], ordem = prioridade)
  words_to_use            json      (lista)
  words_to_avoid          json      (lista)
  language                'pt-PT' por omissão
  hashtags_default        json, cta_default text, emoji_policy (none|light|free), notes text
  updated_by_user_id, timestamps

brand_members             (acesso de empresas convidadas; a dona não precisa de linha)
  id, brand_id
  company_id              FK companies      (a empresa convidada; todos os seus utilizadores herdam o papel)
  role                    editor | approver | viewer
  status                  pending | active | revoked
  invited_by_user_id, accepted_by_user_id, accepted_at, timestamps
  UNIQUE(brand_id, company_id)

brand_member_users        (opcional, fase 2: exceções por utilizador dentro da empresa dona ou convidada)
  brand_id, user_id, role

(o antigo brand_review_links foi substituído pelo link por LOTE da F3: content_review_links,
 content_review_link_items e content_review_link_opens; ver a nota abaixo e a decisão 6)
```

**Nota (F3, decidido em outubro de 2026):** a aprovação por link deixa de ser um link por marca e passa a ser **um link por lote de publicações** (`content_review_links`), com o padrão do link do orçamento:
- token de 64 caracteres em hash (pesquisa) e cifrado (para a equipa o voltar a copiar), revogável, validade de 14 dias que se pode prolongar;
- o token vai no fragmento do URL (`/aprovar#<token>`) e chega à API num cabeçalho, com `Referrer-Policy: no-referrer` e `noindex`;
- cada item guarda a versão enviada (`content_review_link_items.version_id`); se a equipa alterar a publicação, o item fica "atualizado pela equipa" até ser reenviado no mesmo link;
- aberturas sem robôs nem equipa, a mesma visita em 30 minutos, avisos limitados (serviço partilhado com o orçamento);
- aprovar ou pedir alterações por publicação e "Aprovar tudo".
As tabelas levam `company_id` agora e `brand_id` quando as marcas existirem. Um cliente sem registo usa só este link.

**Papéis e o que cada um pode fazer** (fixos no código; ver decisão 9):

| Capacidade | Dona: admin | Dona: user | Convidada: editor | Convidada: approver | Convidada: viewer | Link de revisão (sem conta) |
|---|---|---|---|---|---|---|
| Ver calendário, publicações, perfil | sim | sim | sim | sim | sim | só as publicações enviadas para revisão |
| Editar perfil de marca | sim | sim | sim | não | não | não |
| Criar e editar publicações | sim | sim | sim | não | não | não |
| Aprovar ou pedir alterações | sim | configurável | não | sim | não | sim |
| Publicar ou agendar na rede | sim | sim, se aprovada | não | não | não | não |
| Ligar e desligar redes | sim | não | não | não | não | não |
| Gerir membros | sim | não | não | não | não | não |

**Ligações às redes por marca** (detalhe em F2):
- `social_connections` guarda o token de quem fez o login Meta.
- `brand_social_accounts` guarda a Página ou a conta Instagram atribuída à marca.
- Ficam **separadas de `company_integrations`**, que continua a servir os anúncios por empresa (ver decisão 8).

### 2.2 b) Linha editorial por marca: o que muda e como migrar sem perder dados

**O que é hoje por empresa:**

| Dado | Onde | Chaves |
|---|---|---|
| Ramo (setor) | `companies.content_sector_id` | FK `content_sectors` |
| Meses abertos e fechados | `editorial_months` | UNIQUE `(company_id, year, month)` |
| Âncoras herdadas escondidas | `editorial_hidden_anchors` | UNIQUE `eha_company_anchor_year_uq (company_id, anchor_id, occurrence_year)` |
| Âncoras próprias | `editorial_own_anchors` | índice `company_id` |
| Publicações | `editorial_posts` | índices `company_id`, `publish_date` |
| Ativação do módulo | `company_modules` (`linha_editorial`) | continua por empresa |

**O que fica global, sem alterações:** `content_sectors`, `content_anchors` e os seeds.

**Código a adaptar:**
- `EditorialLineService`: cerca de 15 consultas `where('company_id')` e o `$company->contentSector`.
- `EditorialPostService`: 4 consultas.
- `EditorialLineController`: o guard de tenancy e o `Company::find`.
- Frontend: `laravel_helper.ts` (função `ED`), `EditorialCalendarPage`, `EditorialSectorSettings`, `SectorChooser` e a aba no `CompanyProfileEditor`.
- Testes Unit (6 ficheiros) que criam a empresa com `content_sector_id`.

**Migração proposta, em fases e aditiva até ao fim da fase 3:**

1. **M1, criar as tabelas** `brands`, `brand_profiles`, `brand_members` (aditivo).
2. **M2, criar a marca por omissão** de cada empresa, **incluindo as apagadas com soft delete**, para que nenhum dado fique órfão:
   - `owner_company_id = linked_company_id = default_for_company_id = companies.id`;
   - `name = trade_name ?? fiscal_name`;
   - `logo_path` e `content_sector_id` copiados da empresa;
   - `brand_profiles` vazio, com `audience` e `notes` vazios.
   - É idempotente: `insertOrIgnore` por `default_for_company_id`.
3. **M3, `brand_id` nullable** em `editorial_months`, `editorial_hidden_anchors`, `editorial_own_anchors` e `editorial_posts`, com backfill `brand_id = marca por omissão de company_id`.
   - O `company_id` **fica** como dono desnormalizado (cascata, relatórios, segurança extra).
   - Acrescentar os novos UNIQUE por marca: `(brand_id, year, month)` e `(brand_id, anchor_id, occurrence_year)`.
4. **M4, código** passa a ler e escrever por `brand_id`.
   - As rotas novas ficam em `/brands/{brand}/editorial/...`.
   - As rotas antigas `/companies/{id}/editorial/...` passam a servir a marca por omissão (compatibilidade durante uma versão).
   - `companies.content_sector_id` deixa de ser escrito; a leitura vem da marca.
5. **M5, não aditiva, precisa de OK explícito:** retirar os UNIQUE antigos por `company_id`.
   - Sem isso, uma empresa não pode ter duas marcas com o mesmo mês ou a mesma âncora escondida.
   - Opcionalmente, pôr `brand_id NOT NULL`. Ver decisão 16.

**Verificação da migração:** antes e depois de M2/M3, contar as linhas por empresa em cada tabela editorial e confirmar que o total com `brand_id` é igual ao total por `company_id`. Correr primeiro em MariaDB de desenvolvimento.

**A ter em conta:**
- Os timestamps destas tabelas não têm `ON UPDATE`. O problema que tivemos com `sold_at` não se repete aqui, mas convém confirmar com `SHOW CREATE TABLE` antes de M3.
- Colunas sociais da empresa (`instagram`, `facebook`, `website`, `logo_path`): copiar para a marca por omissão, como informação, sem as apagar da empresa.
- `facebook_access_token` (em texto simples, não usado): **não copiar**. Propor a sua limpeza noutra tarefa.

### 2.3 c) Ligar as redes de uma marca sem registo

Os dois caminhos acabam no mesmo sítio: um token Meta guardado (cifrado) em `social_connections` e uma ou mais contas atribuídas à marca em `brand_social_accounts`. Muda quem faz o login e com que acessos.

| Critério | (1) Acesso de parceiro + login Meta da agência | (2) Link de ligação enviado ao cliente |
|---|---|---|
| O que o cliente faz | No Meta Business: adiciona o portefólio da agência como parceiro e partilha a Página e o Instagram. Nada na XPLENDOR | Abre um link da XPLENDOR (sem conta), faz login no Facebook e escolhe a Página e o Instagram |
| Requisitos do cliente | Portefólio de negócios com controlo total | Ser administrador da Página; conta Instagram Business ligada à Página |
| Quem fica dono do token | Um utilizador da agência (ou BISU) | Um utilizador do cliente |
| Escala | Um login serve todas as marcas que a agência gere | Um login por marca |
| Se a pessoa sai | Troca-se o utilizador da agência; o acesso do parceiro mantém-se | A ligação parte quando essa pessoa perde a função na Página |
| Permissões Meta | As da publicação, mais `business_management`, mais `ads_management`/`ads_read` para Instagram com função via portefólio | As da publicação; `business_management` só se for preciso |
| Superfície de ataque | Só rotas autenticadas | Rota pública com token de uso único |
| Consentimento (RGPD, App Review) | Dado no Meta Business, fora da XPLENDOR | Explícito e registado na XPLENDOR |
| Prática de mercado | É como as agências já trabalham | Comum em ferramentas para pequenos negócios |

**Recomendação:**
- **Construir uma só ligação** (FLfB com escolha de Páginas e contas Instagram), usada nos dois casos.
- **Entregar primeiro o caminho (1)**: é o habitual nas agências, não exige rota pública e cobre marcas registadas e não registadas.
- **Juntar o (2) logo a seguir**, como alternativa para clientes sem portefólio de negócios. O link é de uso único, expira em 72h, fica ligado a uma marca e só regista contas.
- Nos dois casos guardar quem ligou, quando e que permissões foram concedidas (`debug_token` → `scopes`).

### 2.4 d) Dono quando a marca é de uma empresa registada

| | A. Operador dono + empresa cliente convidada | B. Empresa cliente dona + delegação ao operador |
|---|---|---|
| Modelo | Igual para marcas com e sem registo (só muda `linked_company_id` e um `brand_members`) | Dois modelos: com registo o cliente é dono, sem registo é a agência |
| Quem paga e tem os limites do plano | O operador | O cliente (precisa de subscrição) ou regras de faturação cruzadas |
| Ligações às redes | Da agência (acesso de parceiro) | Do cliente, ou delegadas |
| Fim da relação | A agência fica com a marca. É preciso uma ação de **transferência de dono** e exportação para o cliente | O cliente revoga a delegação e fica com tudo |
| Dados que o cliente já tem na marca por omissão (ex.: linha editorial da Quebom) | Ficam separados da marca gerida pela agência | A agência trabalha diretamente sobre eles |
| Complexidade de tenancy | Menor: dono mais convidados | Maior: delegação com âmbito e prazo |

**Recomendação: A, com transferência de dono.**
- **Regras:**
  - o operador é dono da marca;
  - a empresa registada ligada entra como convidada, com papel `approver` por omissão;
  - "transferir dono" muda `owner_company_id` para a empresa ligada, com aceitação das duas partes; a operação só existe quando `linked_company_id` está preenchido.
- **Porquê:** mantém um só modelo, coerente com as marcas sem registo, e põe o custo e as ligações do lado de quem opera.
- **Preço a pagar:** se a Quebom já usa a linha editorial na sua marca por omissão, a marca gerida pela agência começa vazia.
- **Mitigação:** uma ação "convidar operador para a minha marca", que é a opção B limitada à marca por omissão. Fica para uma fase posterior, se houver casos reais.

### 2.5 e) Tenancy por marca

**Princípio:** a empresa continua a ser o tenant de faturação, módulos e utilizadores; a marca é um recurso com controlo de acesso próprio. O `tenant` atual mantém-se nas rotas `/companies/{id}`. Nasce um grupo novo `/brands/{brand}`.

**Middleware `brand` (novo), em `Route::prefix('/brands/{brand}')->middleware(['brand'])`:**

1. Resolve `{brand}` (id) e devolve 404 se não existir ou estiver arquivada (exceto para root).
2. Calcula o papel do utilizador na marca:
   - `root`: tudo, como hoje;
   - `user.company_id == owner_company_id`: `owner_admin` ou `owner_user`, conforme `users.role`;
   - membro ativo em `brand_members` com `company_id == user.company_id`: o papel da linha;
   - nenhum dos anteriores: **403** (mesmo formato e mensagem do `tenant`).
3. Põe no request `brand` e `brand_role`, para os controllers não voltarem a consultar.

**Middleware `brand.can:<capacidade>`:** verifica a matriz da secção 2.1 (por exemplo `brand.can:publish`, `brand.can:manage_connections`). Em alternativa, uma `BrandPolicy` com Gates. Hoje o projeto não usa Policies, por isso um middleware é mais coerente.

**Recursos filhos** (publicações, media, contas sociais): helpers `xBelongsToBrand` que devolvem 404, como os `xBelongsToCompany` atuais. Cada consulta filtra sempre por `brand_id`. Sem global scopes, que o projeto também não usa.

**Integração com os middlewares atuais:**
- **`check_company_subscription`:** hoje usa a empresa do utilizador. Para as rotas de marca deve ver a **subscrição do dono**. Uma empresa convidada sem subscrição ativa deve poder rever e aprovar (decisão 5). Isto pede uma variante, `check_brand_owner_subscription`.
- **`ensure_module`:** verificar o módulo na **empresa dona** (ex.: `social_publisher`, `linha_editorial`), não na do utilizador.
- **`block_when_impersonating`:** aplicar a ligar e desligar redes, gerir membros e transferir dono. Publicar em impersonation também deve ser bloqueado: é uma ação externa irreversível.

**Testes:**
1. **Varrimento de rotas** (não existe hoje). Um teste percorre `Route::getRoutes()`:
   - toda a rota com `{brand}` tem o middleware `brand`;
   - toda a rota com `{id}` debaixo de `companies` tem `tenant`;
   - nenhuma rota com `{brand}` está fora do grupo `auth:sanctum`.
   - Falha com a lista das rotas em falta. Apanha rotas novas sem guarda.
2. **Matriz de acesso automática.** Para cada rota com `{brand}`, preenchendo os parâmetros com fixtures:
   - utilizador de uma empresa sem relação → 403;
   - convidado `viewer` em rotas de escrita → 403;
   - recurso filho de outra marca → 404.
   - Os métodos e capacidades vêm de um mapa declarado junto das rotas, para o teste saber o que esperar.
3. **Casos explícitos:**
   - impersonation limitado às marcas do alvo;
   - root vê tudo;
   - convidado sem subscrição consegue aprovar e não consegue publicar;
   - marca arquivada.
4. **Achado lateral:** `UserController::index/show` (linhas 28 e 101) compara `company_id` sem exceção para root. Não é deste spike; fica registado.

### 2.6 f) Impacto nos módulos atuais

| Área | Impacto | Proposta |
|---|---|---|
| Dashboards (automóvel, restauração, hub, marketing) | Nenhum direto: medem o negócio da empresa | Continuam por empresa. Métricas de publicações orgânicas por marca serão um dashboard novo |
| Integração Meta de anúncios (`company_integrations`, `ads_read`) | O UNIQUE `(company_id, platform)` impede várias Páginas ou contas por empresa | Não mexer. A publicação usa tabelas próprias por marca. Ligar uma conta de anúncios a uma marca fica para mais tarde (decisão 8) |
| GA4, PingWin, CoverManager | Nenhum | Continuam por empresa |
| Impersonation | O token é do utilizador-alvo, por isso vê exatamente as marcas do alvo | Bloquear ações externas e de gestão (secção 2.5) |
| Módulos por empresa | A marca herda os módulos do dono | Novo módulo `social_publisher` (depende de `linha_editorial`), fora dos presets |
| Planos | Não há limite de marcas | `plans.brand_limit` (decisão 14) |
| Frontend | O `companyId` sai do `sessionStorage.authUser` em cada página | `BrandContext` com a marca ativa e um seletor de marca no topo para quem tem mais de uma. As páginas editoriais passam a usar `brandId` |
| Convites | Hoje só convidam utilizadores para a própria empresa | Convidar uma **empresa** para uma marca: por email do admin, ou criando uma empresa convidada leve se ainda não existir (decisão 5) |
| Política de privacidade e App Review | Dados novos: tokens de Página, publicações, media, comentários | Atualizar as páginas legais e os vídeos do App Review por cada permissão nova |

---

<a id="f2"></a>
## 3. F2 Publicador (desenho)

Princípios:
- Tudo pertence a uma marca e a uma conta social da marca.
- A publicação é uma máquina de estados persistida.
- Cada passo externo guarda o identificador devolvido pela Meta **antes** de avançar, para que uma repetição continue em vez de duplicar.

### 3.1 Tabelas

```
social_connections                  (um login Meta: de um utilizador da agência, de um cliente via link, ou BISU)
  id, owner_company_id
  provider            'meta'
  meta_user_id, meta_user_name
  access_token        text, cifrado (EncryptedLegacy)
  token_type          user | bisu
  token_expires_at, scopes json, connected_by_user_id, connected_via ('agency_login'|'client_link')
  status              active | expired | revoked | error
  last_checked_at, error_message, timestamps

brand_social_accounts               (uma Página ou conta Instagram atribuída a uma marca)
  id, brand_id, social_connection_id
  platform            facebook_page | instagram_business
  external_id         (page_id ou ig_user_id), name, username, picture_url
  page_access_token   text, cifrado, null (só Facebook; derivado do token do utilizador)
  linked_page_id      (para Instagram: a Página a que está ligado)
  account_type        (Instagram: BUSINESS | CREATOR; determina se há Stories)
  capabilities json   (ex.: can_publish_stories, product_tagging_eligible)
  status              active | needs_reconnect | revoked
  quota_total, quota_usage, quota_checked_at   (último content_publishing_limit lido)
  timestamps
  UNIQUE(brand_id, platform, external_id)
  UNIQUE(platform, external_id, deleted_at null)   (a mesma conta não fica em duas marcas)

media_assets
  id, brand_id, uploaded_by_user_id
  disk, path, public_path_token (uuid, para URL não adivinhável)
  mime, width, height, duration_ms, size_bytes, sha256
  variants json        (ex.: jpeg_srgb para Instagram, recorte 4:5, 9:16)
  validation json      (por formato: ok | aviso | erro, com mensagens)
  status               processing | ready | rejected
  timestamps
  UNIQUE(brand_id, sha256)

social_publications                 (um envio para UMA conta; uma ideia editorial pode gerar várias)
  id, brand_id, brand_social_account_id
  editorial_post_id   FK editorial_posts, null
  platform, format    ig_feed_image | ig_carousel | ig_reel | ig_story | fb_post | fb_photos | fb_video | fb_reel | fb_story
  caption text, link null, first_comment null
  scheduled_at        (UTC), timezone ('Europe/Lisbon')
  status              (ver 3.3)
  requires_approval   bool (copiado da definição da marca no momento da criação)
  version             int (sobe a cada edição de conteúdo; uma edição depois de aprovada volta a pedir aprovação)
  approved_version, approved_by_user_id | approved_via_link_id (content_review_links), approved_at
  native_scheduled    bool (Facebook agendado do lado da Meta)
  external_container_id, external_children json, external_id, permalink
  attempts, next_attempt_at, last_error_code, last_error_message, last_error_kind (transient|permanent|unknown)
  claimed_at, claim_token             (reserva atómica pelo despachante)
  published_at, cancelled_at, created_by_user_id, timestamps
  INDEX(status, next_attempt_at), INDEX(brand_social_account_id, published_at)

social_publication_media
  publication_id, position, media_asset_id, variant_key, user_tags json null
  UNIQUE(publication_id, position)

social_publication_events          (histórico e auditoria: estados, aprovações, comentários, tentativas)
  id, publication_id, type ('state'|'review'|'attempt'|'comment')
  from_status, to_status, step ('create_container'|'poll'|'publish'|'verify'|'schedule_native'|'cancel')
  http_status, meta_error_code, meta_error_subcode, message, duration_ms
  actor_user_id | actor_link_id, created_at
```

`editorial_posts` continua a ser o item de planeamento: data, título, formato, âncora. A publicação real vive em `social_publications`. Uma publicação editorial com destino Instagram e Facebook gera duas linhas, uma por conta, e cada uma com o seu estado (decisão 7).

### 3.2 Jobs e agendamento

**Agendador (`routes/console.php`): `DispatchDueSocialPublicationsJob` a cada minuto**, com `withoutOverlapping()`.
- Seleciona as publicações `scheduled` com `scheduled_at <= now() + antecedência`. A antecedência é de 10 min para o Instagram, para criar o container e processar o vídeo.
- Seleciona também as `retry_wait` com `next_attempt_at <= now()`.
- Reserva cada linha atomicamente: `UPDATE ... SET status='publishing', claim_token=?, claimed_at=now() WHERE id=? AND status IN (...)`. Só despacha se afetou 1 linha.

**`PublishInstagramJob(publicationId)`:**
- `WithoutOverlapping("social-publish:{id}")`.
- Limitador por conta (`RateLimited` / `Redis::throttle("social-account:{accountId}")`).
- `$tries = 1`. A repetição é controlada por nós via `next_attempt_at`, nunca às cegas, como nas escritas externas que já existem.

**Passos do Instagram:**
1. **Container.** Se já existe `external_container_id` e o estado não é `EXPIRED`, reutiliza. Senão cria (os filhos primeiro, num carrossel) e **grava o id antes de continuar**.
2. **Estado.** Consulta o estado do container. Com `IN_PROGRESS`, faz `release(60)`, até 5 min para imagem e mais para vídeo. Com `ERROR`, o erro é permanente e a mensagem vem do `status`.
3. **Quota.** Lê `content_publishing_limit`. Se esgotou, passa a `deferred_quota` com `next_attempt_at` no momento em que a janela de 24h liberta.
4. **Publicação.**
   - Marca o evento `publish_sent` **antes** de chamar `media_publish`.
   - Com resposta: grava `external_id`, muda para `published` e lê o `permalink`.
   - Sem resposta (timeout ou ligação caída): estado `unknown`. Não repete.
5. **Reconciliação** (só no estado `unknown`). `GET /{ig-user}/media?fields=id,caption,timestamp` nos últimos minutos:
   - encontrou pela legenda e pela hora: `published`;
   - não encontrou depois de 2 consultas espaçadas: volta a `retry_wait`.

**`PublishFacebookJob(publicationId)`:**
- **Entre 10 min e 29 dias** (posts, fotos, vídeos, Reels): **agendamento nativo** no momento da aprovação.
  - Cria com `published=false` e `scheduled_publish_time`, grava `external_id`.
  - Estado `scheduled_native`; um job de verificação confirma a publicação depois da hora.
- **Menos de 10 min, ou Stories:** publica na hora marcada pelo nosso agendador.
- **Cancelar ou editar um agendado nativo:** DELETE ou update ao post (só a app que o criou pode). O comportamento do DELETE sobre agendados está por confirmar num teste.

**Jobs de apoio:**
- **`CheckSocialConnectionsJob` (diário):** `debug_token` de cada ligação e verificação das contas.
  - Passa a `needs_reconnect` e pausa as publicações dessa conta (estado `blocked`).
  - Avisa o dono 7 dias antes de o token expirar.
- **`CleanupSocialMediaJob` (diário):** apaga as variantes públicas das publicações publicadas há mais de N dias. O original fica, de acordo com a política de retenção.

### 3.3 Estados

```
draft ──► in_review ──► approved ──► scheduled ──► publishing ──► published
  ▲           │             │            │              │
  │           ▼             │            │              ├──► retry_wait ──► (volta a publishing)
  └──── changes_requested   │            │              ├──► deferred_quota ──► (volta a scheduled)
                            │            │              ├──► unknown ──► (reconciliação)
                            │            │              └──► failed (permanente)
                            │            └──► scheduled_native (Facebook) ──► published
                            └──► cancelled (de qualquer estado antes de published)
blocked: conta needs_reconnect; volta a scheduled quando a conta é religada
```

**Regras das transições:**
- Uma marca sem aprovação obrigatória vai de `draft` diretamente para `approved`.
- Editar o conteúdo sobe `version`; se `version != approved_version`, volta a `in_review`.
- Só `brand.can:publish` muda de `approved` para `scheduled`.
- Uma publicação só passa a `published` com `external_id` gravado.

### 3.4 Tentativas e classificação de erros

| Classe | Exemplos | Ação |
|---|---|---|
| Transitório | HTTP 429 e 5xx, códigos de limite da Graph (o `MetaAdsService` já trata `RETRYABLE_GRAPH_CODES`), container `IN_PROGRESS`, falha de ligação antes de `media_publish` | `retry_wait` com backoff exponencial e jitter (1, 2, 4, 8, 16 min), no máximo 5 tentativas ou 2h. Depois passa a `failed` |
| Permanente | Token inválido (código 190) → conta `needs_reconnect` e `blocked`; permissão em falta; parâmetro ou media inválidos; container `ERROR` | `failed`, com mensagem legível para o utilizador e sem nova tentativa |
| Desconhecido | Timeout ou ligação caída **depois** de enviar `media_publish` | `unknown` → reconciliação (3.2) |

- A tabela de códigos e subcódigos de erro concretos do Instagram (família 2207xxx) tem de ser construída com casos reais [a confirmar].
- Reutilizar o `sendWithRetry` do `MetaAdsService` só nas leituras. Os passos que escrevem não podem ter repetição automática no cliente HTTP.

### 3.5 Limite diário por conta

**Antes de agendar (na interface e na API):**
- Contar as publicações da conta numa janela de 24h móveis à volta da hora pedida (`published`, mais `scheduled`, mais `scheduled_native`).
- Comparar com o último `quota_total` lido, ou com 50 por omissão, o valor mais baixo documentado.
- Se ultrapassar, mostrar um aviso e sugerir a hora seguinte livre.

**Antes de publicar:** ler `content_publishing_limit` (é a fonte de verdade). Se esgotou, passa a `deferred_quota`.

**Outros contadores por conta:**

| Contador | Limite |
|---|---|
| Containers Instagram | 400 por 24h |
| Etiquetas de produto (quando existirem) | 25 por 24h |
| Reels no Facebook | 30 por 24h |

**Rate limits globais:** ler `X-Business-Use-Case-Usage`. Acima de 80%, o despachante abranda nessa conta.

### 3.6 Validação de media

**No upload (job `ProcessMediaAssetJob`):**
1. **Tipo e dimensões.** Identificar o tipo real pelo conteúdo, não pela extensão. Imagem: dimensões. Vídeo: `ffprobe` dá codec, fps, duração, bitrate e áudio.
   - O `ffmpeg`/`ffprobe` não existe hoje no contentor PHP; é preciso acrescentá-lo.
2. **Variantes.**
   - JPEG sRGB para o Instagram: as imagens atuais estão em WebP, que o Instagram não aceita.
   - Largura entre 320 e 1440 px, até 8 MB.
   - Recortes sugeridos para 4:5, 1:1 e 9:16.
3. **Relatório por formato** em `validation`. Exemplos:
   - `ig_feed_image`: rácio fora de 4:5 a 1.91:1 → erro (oferecer recorte);
   - `ig_story`: diferente de 9:16 → aviso;
   - `ig_reel`: fora de 3 s a 15 min ou acima de 300 MB → erro;
   - `fb_story`: vídeo com mais de 60 s → erro.
4. **Carrossel:** 2 a 10 itens; aviso de que todos são cortados pelo rácio do primeiro; Reels não entram.

**Na publicação, URL pública:**
- O URL é `https://<domínio>/storage/social/<uuid>/<ficheiro>.jpg`: só ASCII, não adivinhável, com HTTPS em produção.
- Há uma inconsistência por resolver: `Storage::url()` devolve URL absoluto, mas há código que concatena `APP_URL` com `logo_path`. A publicação deve usar uma única função de URL público, testada.
- O `APP_URL` local (`http://localhost:3000`) não serve para a Meta ir buscar os ficheiros. Em desenvolvimento é preciso um túnel ou um bucket público para testar.

### 3.7 Pré-visualização

**No frontend**, um componente por formato:
- feed do Instagram, com o recorte que a Meta vai aplicar;
- carrossel, com o recorte pela primeira imagem;
- story 9:16, com as zonas seguras;
- post do Facebook, com a pré-visualização do link (`og:` lida pelo backend);
- Reels.

**Contadores na legenda:** caracteres, hashtags e menções. Os limites do Instagram (2200 caracteres, 30 hashtags, 20 menções) são os conhecidos no mercado, mas não foram confirmados nesta pesquisa [a confirmar].

**`POST /brands/{brand}/publications/validate`:** devolve os erros e avisos do servidor (media, legenda, quota, janela de agendamento, conta Creator sem Stories) antes de gravar. A interface mostra-os ao lado da pré-visualização.

**Perfil de marca:** a pré-visualização assinala as palavras a evitar que aparecem na legenda (verificação simples, sem IA nesta fase).

### 3.8 Rotas (todas por marca)

```
GET    /brands                                   (marcas do utilizador: donas + convidadas)
POST   /brands                                   (owner_admin)
GET    /brands/{brand}   PATCH /brands/{brand}   (perfil, definições: requires_approval)
GET|POST|DELETE /brands/{brand}/members[...]      brand.can:manage_members, block_when_impersonating
GET    /brands/{brand}/social/oauth-url          brand.can:manage_connections
GET    /brands/{brand}/social/accounts           (Páginas e IG disponíveis na ligação + atribuídas)
POST   /brands/{brand}/social/accounts           (atribuir)      brand.can:manage_connections, block_when_impersonating
DELETE /brands/{brand}/social/accounts/{id}      (retirar)       idem
POST   /brands/{brand}/media                     brand.can:edit
GET|POST|PATCH /brands/{brand}/publications[...]  brand.can:edit
POST   /brands/{brand}/publications/validate
POST   /brands/{brand}/publications/{id}/submit | approve | request-changes | schedule | cancel
GET    /public/review  POST /public/review/...    (link por lote, sem conta; token no cabeçalho, nunca no caminho; throttle; só os itens do lote)
```

---

<a id="decisoes"></a>
## 4. Decisões em aberto

Cada decisão tem opções e uma recomendação (R).

1. **Dono da marca quando o cliente é uma empresa registada.**
   - Opções: (A) operador dono e cliente convidado; (B) cliente dono e delegação ao operador.
   - **R: A**, com a operação "transferir dono" e, mais tarde, "convidar operador para a marca por omissão" (secção 2.4).
2. **Como ligar as redes de uma marca sem registo.**
   - Opções: (1) acesso de parceiro com o login Meta da agência; (2) link de ligação enviado ao cliente; (3) os dois.
   - **R: (3)**, com uma só ligação técnica; o (1) primeiro e o (2) logo a seguir (secção 2.3).
3. **Tipo de token.**
   - Opções: (a) token de utilizador e tokens de Página derivados; (b) BISU via configuração FLfB.
   - **R: (a) para começar.** Estudar o (b) quando houver aprovação como Tech Provider; o BISU não expira e não depende de uma pessoa.
4. **Login.**
   - Opções: Facebook Login for Business; Instagram Login; os dois.
   - **R: Facebook Login for Business.** Cobre o acesso de parceiro, Facebook e Instagram num só login, e as etiquetas de produto no futuro. O Instagram Login só se aparecerem contas Instagram sem Página.
5. **Empresa convidada sem subscrição ativa.**
   - Opções: (a) bloquear; (b) permitir ver e aprovar nas marcas partilhadas; (c) criar "empresa convidada" leve, sem módulos.
   - **R: (b)**, e (c) para clientes que ainda não têm empresa. A faturação fica no dono.
6. **Aprovação por um cliente sem registo.**
   - Opções: (a) obrigar a criar conta; (b) link de revisão sem conta, com âmbito restrito, prazo e revogável.
   - **R: (b)**, como link **por lote** (`content_review_links`, F3) e não por marca. Regista quem decidiu (nome indicado, dispositivo e hora) em `editorial_post_reviews` e no histórico; o IP não é guardado. Quem tem conta também pode aprovar dentro da XPLENDOR (administrador ou aprovador marcado, nunca em impersonation).
7. **Planeamento e publicação.**
   - Opções: (a) alargar `editorial_posts` com media e estado de publicação; (b) tabela `social_publications` ligada.
   - **R: (b).** Uma ideia editorial pode ir para várias contas com estados diferentes, e o planeamento atual não muda.
8. **Anúncios e redes da marca.**
   - Opções: (a) manter `company_integrations` (anúncios) separada das ligações sociais da marca; (b) unificar tudo por marca.
   - **R: (a) agora.** Ligar uma conta de anúncios a uma marca fica para uma fase de análise por marca.
9. **Granularidade das permissões.**
   - Opções: (a) papéis fixos com mapa no código; (b) capacidades configuráveis por marca.
   - **R: (a)**, com a única exceção configurável "o user da dona pode aprovar" (definição da marca).
10. **Aprovação obrigatória.**
    - Opções: sempre; nunca; por marca.
    - **R: por marca.** Por omissão é obrigatória quando há empresa ligada ou link de revisão, e opcional nas marcas por omissão.
11. **Onde ficam os media públicos.**
    - Opções: (a) disco `public` com caminho uuid e limpeza; (b) S3 com CDN; (c) rota assinada temporária.
    - **R: (a) agora**, (b) quando o volume o pedir. A (c) arrisca a Meta não conseguir ir buscar o ficheiro se a assinatura expirar entre tentativas.
12. **Etiquetas de produto e mensagens privadas.**
    - Opções: incluir na F2; adiar.
    - **R: adiar.** As etiquetas exigem loja com checkout e as mensagens exigem `pages_messaging`; cada uma é uma revisão Meta à parte.
13. **Stories na F2.**
    - Opções: incluir; adiar.
    - **R: incluir** as do Instagram (só contas Business, sem stickers nem links) e as do Facebook, com validação clara para contas Creator.
14. **Limite de marcas por plano.**
    - Opções: sem limite; `plans.brand_limit`; preço por marca.
    - **R: `plans.brand_limit`.** A decisão comercial é tua.
15. **Rotas editoriais antigas.**
    - Opções: (a) mudar tudo de uma vez para `/brands/{brand}`; (b) manter `/companies/{id}/editorial` a servir a marca por omissão durante uma versão.
    - **R: (b).**
16. **Retirar os UNIQUE antigos por `company_id`** (migração não aditiva) **e pôr `brand_id NOT NULL`.**
    - Opções: fazer em M5; adiar até haver a primeira empresa com duas marcas.
    - **R:** fazer em M5, num passo próprio com simulação e o teu OK, porque contraria a regra "só migrações aditivas".
17. **Ramo editorial.**
    - Opções: fica na empresa; passa para a marca.
    - **R: passa para a marca.** Uma agência gere marcas de ramos diferentes. `companies.content_sector_id` fica só de leitura.
18. **`business_management` de volta.**
    - Opções: pedi-lo já na revisão das permissões de publicação; só quando o acesso de parceiro estiver construído.
    - **R: só com o acesso de parceiro construído e demonstrável**, para não voltar a ser recusado por falta de uso.

---

<a id="riscos"></a>
## 5. Riscos

1. **Aprovações da Meta: prazo e recusa.** São precisas Business Verification, Access Verification (Tech Provider) e App Review por permissão: `pages_manage_posts`, `pages_read_engagement`, `pages_show_list`, `instagram_basic`, `instagram_content_publish`, `business_management` e `ads_management`. Cada uma precisa de um caso de uso e de um vídeo. Pode levar semanas. Começar a preparar em paralelo com a F1.
2. **Instagram via portefólio exige `ads_management`.** É uma permissão de escrita em anúncios que não usamos para nada além disto. Justificá-la no App Review é delicado [confirmado na documentação, efeito na revisão a confirmar].
3. **Publicação duplicada.** Um timeout depois de `media_publish` pode publicar duas vezes se houver repetição cega. Mitigação: estado `unknown`, reconciliação e `$tries=1` nos passos de escrita.
4. **Ligações que partem em silêncio.** Token expirado, parceiro removido, função na Página retirada, 2FA ou autorização de publicação de Página pendente. Mitigação: verificação diária, estado `blocked`, avisos ao dono.
5. **Documentação contraditória.** Limite de 50 ou 100, janela de 30 ou 75 dias, story de 60 ou 90 s. Mitigação: ler as quotas em tempo real, usar os limites mais conservadores e testar cada um em conta real antes de construir em cima.
6. **Media.**
   - WebP não é aceite pelo Instagram.
   - O URL público tem de ser HTTPS e ASCII.
   - Em desenvolvimento o `APP_URL` é localhost, por isso a Meta não alcança os ficheiros.
   - Falta `ffmpeg` no contentor.
   - O disco vai crescer com vídeo.
7. **Tenancy mais complexa.** Hoje assume-se "um utilizador = uma empresa = todos os dados". Com marcas partilhadas entre empresas, um esquecimento de filtro mostra dados de outro cliente. Mitigação: o middleware `brand`, o varrimento de rotas, a matriz automática e o filtro por `brand_id` em todas as consultas.
8. **Frontend com `companyId` espalhado.** Dezenas de páginas leem o `authUser` do `sessionStorage`. Passar para uma marca ativa sem regressões exige um `BrandContext` e uma migração página a página, começando pela linha editorial.
9. **Migração da linha editorial.**
   - A M5 é não aditiva.
   - O backfill tem de incluir as empresas com soft delete.
   - É preciso confirmar no MariaDB que as tabelas editoriais não têm `ON UPDATE CURRENT_TIMESTAMP` (o problema já visto em `sold_at`).
10. **RGPD.** Marcas de clientes sem registo põem na XPLENDOR dados de terceiros: tokens, conteúdos, eventualmente comentários e mensagens.
    - Papéis: a agência é responsável perante o cliente e a XPLENDOR é subcontratante.
    - É preciso atualizar os termos e a política de privacidade (incluindo os dados novos da Meta) e preparar um acordo de tratamento de dados para agências.
11. **Containers do Instagram expiram em 24h.** Se o container for criado no momento de agendar, a publicação falha. Tem de ser criado perto da hora.
12. **Precisão e fuso.** O agendador corre a cada minuto e a fila pode atrasar. As horas são guardadas em UTC e mostradas em Europe/Lisbon; a mudança de hora tem de estar coberta por testes.
13. **Stories só em contas Business.** Contas Creator (comuns em pequenos negócios) não podem publicar Stories via API. É preciso detetar o tipo de conta e avisar antes de agendar.
14. **Achado lateral:** `UserController::index/show` sem exceção para root (linhas 28 e 101). Fica fora do âmbito, mas convém corrigir antes de alargar a tenancy.

---

<a id="anexo"></a>
## 6. Anexo: o que existe hoje no código (referências)

**Tenancy:**
- `server/app/Http/Middleware/EnsureTenantAccess.php:25-41`: `user.company_id == {id}` ou root.
- Aliases em `server/app/Providers/AppServiceProvider.php:211-218`.
- Grupo `/companies/{id}` em `server/routes/api.php:105-416`.
- Os controllers repetem a verificação por segurança e os recursos filhos devolvem 404 (`xBelongsToCompany`).

**Utilizadores:**
- `users.company_id` (uma empresa por utilizador); `role` é `user|admin|root`.
- Sem Policies, Gates nem abilities do Sanctum. As regras vivem nos `authorize()` dos FormRequests.
- Convites em `user_invites` (`UserService::store`, `registerByInvite`).

**Impersonation:**
- `ImpersonationController` cria um token Sanctum do utilizador-alvo (30 min). A sessão fica em `impersonation_sessions`.
- `BlockWhenImpersonating` está nas rotas de credenciais, utilizadores e `PUT editorial/sector`.

**Módulos:**
- `company_modules` (`company_id`, `module_key`).
- `server/app/Modules/ModuleRegistry.php`: `linha_editorial` fora dos presets.
- `EnsureModuleActive` usa `route('id') ?? user.company_id`.

**Linha editorial:**
- Tabelas globais: `content_sectors`, `content_anchors`.
- Tabelas por empresa: `editorial_months`, `editorial_hidden_anchors`, `editorial_own_anchors`, `editorial_posts`; o ramo está em `companies.content_sector_id`.
- Services: `EditorialLineService`, `EditorialPostService`, `EditorialAnchorResolver`.
- Controller e rotas: `EditorialLineController`, rotas em `server/routes/api.php:309-336`.
- Frontend: `web/src/pages/Editorial/`.
- Testes: só Unit, sobre os services. Não há testes HTTP do módulo (403 de tenant, módulo e impersonation por cobrir).

**Meta hoje:**
- `MetaOAuthController` (scope `ads_read`; o state em cache guarda só o `companyId`).
- `company_integrations`: UNIQUE `(company_id, platform)`; `page_id` existe mas não é usado.
- Não há código de publicação.

**Media:**
- Disco `public` (`/storage`, servido pelo nginx).
- Viaturas em WebP (`CarImageService`).
- `Storage::url()` absoluto vs concatenação com `APP_URL` em `SatisfactionReportController:130`.

**Filas:**
- Redis, `retry_after` 90, worker `--tries=3`.
- Padrões `WithoutOverlapping`, `release()` em rate limit, `$tries=1` nas escritas externas, idempotência por `source_key`.
