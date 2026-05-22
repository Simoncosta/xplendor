# XPLENDOR — Project Guide (CLAUDE.md)

> **Documento de leitura obrigatória antes de qualquer tarefa.**
> Define o que existe, como está estruturado, e que decisões já estão tomadas.
> Se este documento contradiz o código, **o documento ganha** — abrir issue antes de seguir o código.
>
> Última actualização: 2026-05-22 · Versão 1.0

---

## 0. Como ler e usar este documento

- Ler do início ao fim antes da primeira contribuição
- Em sessões seguintes: pelo menos rever as secções **2 (Direção estratégica)**, **15 (Roadmap)** e **16 (O que não fazer)** antes de propor mudanças
- Quando uma decisão arquitetural mudar, **actualizar este ficheiro no mesmo PR** que implementa a mudança
- Quando algo neste documento estiver desactualizado, **assinalar TODO no topo da secção** em vez de seguir o código silenciosamente

---

## 1. O que é o XPLENDOR

XPLENDOR é uma plataforma B2B de gestão de stock + analytics + marketing automation para stands de automóveis em Portugal.

**Estado actual:** 2 clientes pagantes (1 stand de carros, 1 stand de autocaravanas).

**Estado do produto:** funcional, com 12–18 meses de desenvolvimento investidos, com funcionalidades reais entregues (analytics, IA, integração Meta Ads, scraper de mercado, dashboards por persona, IPS scoring).

**Direção estratégica imediata:** agência produtizada (ver secção 2).

**Direção estratégica a 18–24 meses:** reavaliar produtização como SaaS aberto ao mercado.

---

## 2. Direção estratégica — decisões já tomadas

Estas decisões foram tomadas após análise de mercado e auditoria técnica. **Não revisitar sem razão forte.**

### 2.1 Modelo de negócio: agência primeiro, SaaS depois

- O XPLENDOR é vendido aos clientes como **serviço gerido**, não como software self-service
- Os clientes não usam o painel directamente — usamos nós, internamente, para os servir
- A plataforma é **ferramenta interna que nos torna 3–5× mais eficientes** que freelancers de tráfego automóvel
- Pacotes comerciais: €490 / €890 / €1.490/mês + budget de ads — ver `XPLENDOR-Manual-Reposicionamento.md`

### 2.2 Não fazemos rewrite. Fazemos refactor cirúrgico.

A stack actual (Laravel 12 + React 19 + Vite + MariaDB + Redis + Python para scraper) **fica**. Não migramos para Python puro. Não migramos para Next.js. Os problemas identificados na auditoria são de **disciplina e organização**, não de framework — resolvem-se in-place.

### 2.3 Foco vertical

Servimos exclusivamente stands de automóveis em Portugal. Não aceitamos clientes de outros sectores, nem clientes fora de Portugal, mesmo que pareçam fit técnico.

### 2.4 Independência face aos marketplaces

A narrativa central é *"libertar o stand da dependência do Standvirtual"*. **Não integramos com Standvirtual.** O produto direciona tráfego pago para o site do próprio stand, não para marketplaces de terceiros.

---

## 3. Stack técnico

### Backend
| Camada | Tecnologia | Versão |
|---|---|---|
| Framework | Laravel | 12 |
| Linguagem | PHP | 8.2+ |
| Servidor PHP | PHP-FPM | — |
| Base de dados | MariaDB | 10.6 |
| Cache + Queue | Redis | latest stable |
| Auth | Laravel Sanctum | — |
| Auditoria | owen-it/laravel-auditing | — |

### Frontend
| Camada | Tecnologia | Versão |
|---|---|---|
| Framework | React | 19 |
| Build | Vite | — |
| Linguagem | TypeScript | — |
| Estado global | Redux Toolkit | — |
| Routing | React Router | v6 (hash-based em prod) |
| UI base | Reactstrap + Velzon | Bootstrap 5 |
| HTTP | Axios | — |
| Charts | ApexCharts (react-apexcharts) | — |
| Mapas | @react-google-maps/api | — |
| Notificações | react-toastify | — |
| Memoização | reselect (createSelector) | — |

### Infra
| Camada | Tecnologia |
|---|---|
| Proxy | Nginx |
| Containers | Docker Compose |
| Worker | `php artisan queue:work` em container dedicado |
| Scheduler | `php artisan schedule:run` a cada 60s |
| Scraper | Python 3 (Selenium / requests) em container isolado |

---

## 4. Arquitetura geral

```
                  ┌──────────────────────────────────────┐
                  │   Cliente (browser do stand/agente)  │
                  └────────────────┬─────────────────────┘
                                   │ HTTPS
                                   ▼
                          ┌────────────────┐
                          │     Nginx      │  porta 8001
                          └───┬───────┬────┘
                              │       │
            SPA estática build│       │/api/*
                              ▼       ▼
                  ┌────────────────────────────┐
                  │   PHP-FPM  (Laravel 12)    │◄──── Sanctum cookies
                  └────┬───────────┬───────┬───┘
                       │           │       │
                  Redis│           │       │MariaDB 10.6
                  ▼    │           │       ▼
              ┌────────────┐       │  ┌──────────────┐
              │   Queues   │       │  │  Tenants:    │
              │   Cache    │       │  │  companies   │
              └─────┬──────┘       │  │  → cars      │
                    │              │  │  → leads     │
                    ▼              │  │  → metrics   │
            ┌─────────────┐        │  └──────────────┘
            │   Worker    │        │
            │  Scheduler  │        ▼
            └──────┬──────┘  ┌──────────────┐
                   │         │  OpenAI API  │
        docker exec│         │  Meta Graph  │
                   ▼         │  Google APIs │
            ┌─────────────┐  └──────────────┘
            │   Scraper   │
            │   (Python)  │
            └─────────────┘
```

**Princípios desta arquitectura:**
- Laravel é a fonte única de verdade para dados, auth, business logic
- Python é tool especializada accionada via Job → `docker exec` para scraping
- Redis é exclusivamente cache + queue, nunca fonte de verdade
- Frontend é SPA pura atrás de Sanctum (login-walled)

---

## 5. Estrutura de pastas

### Backend (`server/`)

```
server/
├── app/
│   ├── Console/Commands/          # Artisan commands
│   ├── Http/
│   │   ├── Controllers/           # Thin — chamam Services
│   │   ├── Middleware/            # Auth, company scoping
│   │   └── Requests/              # Form Request validation
│   ├── Jobs/                      # Async: AggregateCarPerformanceMetricsJob,
│   │                              #         RunScraperJob, FetchMetaAds
│   ├── Models/                    # Eloquent
│   ├── Repositories/
│   │   ├── Contracts/             # Interfaces
│   │   └── (implementações)
│   └── Services/                  # Business logic
├── database/
│   ├── migrations/
│   └── seeders/
└── routes/
    └── api.php
```

### Frontend (`web/src/`)

```
web/src/
├── helpers/                       # api_helper.ts, laravel_helper.ts
├── pages/
│   ├── Authentication/
│   ├── Cars/
│   │   ├── CarList.tsx
│   │   └── Car/
│   │       ├── CarAnalytics.tsx
│   │       ├── CarAdsPage.tsx
│   │       ├── CarIntelligencePage.tsx
│   │       ├── CarFichaPage.tsx
│   │       ├── CarMarketing.tsx
│   │       ├── CarCreate.tsx
│   │       ├── CarUpdate.tsx
│   │       └── components/
│   ├── Companies/
│   ├── Dashboards/
│   ├── Leads/
│   ├── Users/
│   ├── Blogs/
│   ├── Actions/
│   └── Internal/ScraperRunner/
├── Routes/allRoutes.tsx
├── slices/                        # Redux slices
└── layouts/                       # Velzon shell
```

### Scraper (`scraper/`)

`main.py` / `scraper.py` + `requirements.txt`. Executado via `docker exec xplendor-scraper python /app/scraper.py …` despachado pelo `RunScraperJob`.

---

## 6. Base de dados

### Princípios

- **Multi-tenancy por `company_id` em todas as tabelas de negócio.** Excepção documentada: `scraper_executions.company_id` é nullable (legacy — ver dívida técnica)
- MariaDB 10.6, InnoDB, utf8mb4
- Migrations sempre reversíveis (`down()` implementado)
- Sem soft-deletes nos modelos críticos — atenção a deleções que afectam métricas históricas

### Tabelas principais

| Tabela | Propósito | Notas |
|---|---|---|
| `companies` | Tenant raiz | Todas as outras tabelas referenciam |
| `users` | Utilizadores por empresa | Role enum: `root` / `admin` / `user` |
| `cars` | Viaturas por empresa | `price_gross`, `power_hp`, `segment`, `seats`, `status` |
| `car_views` | Visualizações em tempo real | Por sessão/canal/fonte |
| `car_leads` | Leads capturados | Por canal (form, whatsapp, phone, etc.) |
| `car_interactions` | Eventos granulares | `interaction_type` enum (whatsapp_click, …) |
| `car_performance_metrics` | Agregado diário **T-1** por canal | Upsert por (`period_start`, `period_end`, `car_id`, `channel`) |
| `car_market_snapshots` | Dados do scraper de mercado | Por viatura e data |
| `car_ai_analyses` | Análises IA por viatura | JSON com structured output |
| `car_sale_potential_scores` | IPS 0–100 | Histórico; mais recente = `MAX(id)` |
| `meta_audience_insights` | Audiência Meta Ads | Sincronizado via job |
| `company_integrations` | Tokens OAuth por plataforma | Meta Ads, Carmine |
| `carmine_connections` | Ligação ao Carmine | — |
| `scraper_executions` | Log de execuções do scraper | `status`, `filters` (json), `logs_excerpt`, `output` |
| `audits` | Histórico de alterações | Polimórfico (`owen-it/laravel-auditing`) |

### Relações conhecidas

```
Company
  ├── hasMany User
  ├── hasMany Car
  ├── hasMany CompanyIntegration
  └── hasMany ScraperExecution (company_id nullable — legacy)

Car
  ├── hasMany CarView
  ├── hasMany CarLead
  ├── hasMany CarInteraction
  ├── hasMany CarPerformanceMetric
  ├── hasMany CarMarketSnapshot
  ├── hasMany CarAiAnalysis
  └── hasMany CarSalePotentialScore
```

### Problemas de dados conhecidos

- **`car_performance_metrics.whatsapp_clicks`**: coluna adicionada 2026-03-14 com `DEFAULT 0`. Linhas anteriores ficaram com 0. O job diário só processa T-1, nunca retroactivamente. **Métricas de WhatsApp erradas para o intervalo 2026-03-06 a 2026-03-13.**
  - **Remediação:** `php artisan performance:aggregate --from=DATE --to=DATE --sync` para cada dia do intervalo afectado. Correr fora de horas de pico.
- **Sem índices documentados** em `car_performance_metrics(car_id, period_start)` — necessário para escalar. Adicionar como parte do refactor backend.
- **Sem schema enforcement** em `car_ai_analyses.json_output` — mudanças no formato da resposta IA podem quebrar o frontend silenciosamente.

---

## 7. Models e Eloquent

### Convenções

- Todos os models de negócio têm `company_id` no `$fillable` e usam scope global por empresa (`BelongsToCompany` trait, se já existir; caso contrário, criar e aplicar)
- Casts explícitos em todos os campos JSON, decimal, datetime, enum
- Relações nomeadas em singular para `belongsTo` / `hasOne`, plural para `hasMany` / `belongsToMany`
- **Sem business logic em models** — vai sempre para Services
- Auditoria via `Auditable` trait do `owen-it/laravel-auditing` em models que mudam frequentemente (Car em particular)

### Problemas conhecidos a corrigir incrementalmente

- Falta de `$fillable` / `$guarded` disciplinado em alguns models recentes — auditar antes de adicionar campos novos
- `CarAiAnalysis` guarda output como JSON sem schema — criar um Value Object/DTO para validar formato

---

## 8. API e rotas

### Três namespaces de API

| Prefixo | Auth | Propósito |
|---|---|---|
| `/api/v1/*` | Sanctum SPA (cookie) | Painel autenticado da empresa |
| `/api/public/*` | Token da empresa | Catálogos embebidos, captação de views/leads públicos |
| `/market/snapshots` | Scraper token middleware | Ingestão de dados de mercado |

### Convenções de API

- Controllers **finos** — delegam imediatamente para Services
- Validação **sempre** via Form Request (`app/Http/Requests/`)
- Resposta **sempre** via API Resource (`app/Http/Resources/`) — nunca devolver modelos directamente
- Paginação com formato standard: `{ data, meta: { current_page, last_page, per_page, total } }`
- Erros 4xx com payload `{ message, errors: { campo: [mensagens] } }`
- Erros 5xx com payload `{ message }` apenas (sem stack trace)
- **Versionamento:** quando precisar de breaking changes, criar `/api/v2/*` e manter `/api/v1/*` em paralelo até deprecação anunciada com 90 dias de antecedência

### Segurança a reforçar (parte do refactor)

- **Rate limiting** nas rotas de IA — proteger custos OpenAI (adicionar throttle por `company_id`)
- **Autorização role `root`** em endpoints internos (`/internal/scraper/*`) tanto no frontend (já feito) como no backend (verificar)
- **Tenant isolation cruzado** em `/api/public/*` — testes garantindo que token da empresa A não acede a dados da empresa B

---

## 9. Services e Repositories

### Princípio Controller → Service → Repository

- **Controller**: recebe HTTP, valida via Form Request, chama Service, devolve Resource
- **Service**: orquestra lógica de negócio, chama Repositories e APIs externas
- **Repository**: encapsula queries Eloquent complexas, agregações, classificações

### Services existentes

| Service | Responsabilidade |
|---|---|
| `CarService` | CRUD de viaturas, upload de imagens |
| `CarAnalyticsService` | Agrega métricas, IPS, análises para endpoint de analytics |
| `CarAiAnalysesService` | Gera e persiste análises IA; enforce pós-processamento |
| `MetaAdsService` | OAuth Meta, sincronização de campanhas e métricas |
| `DashboardService` | Agrega dados de dashboard; inclui personas |
| `ScraperService` | Normaliza filtros, cria `ScraperExecution`, despacha job |

### Repositories existentes

| Repository | Responsabilidade |
|---|---|
| `DashboardRepository` | Agregações dashboard; contém `groupCarsByPersona()` com classificação por `price_gross`, `power_hp`, `segment`, `seats` |

### Problemas conhecidos a corrigir

- `CarAiAnalysesService::enforceCampaignDiagnosisRules()` não tem testes unitários — risco de regressão silenciosa. **Adicionar testes parametrizados a cobrir os casos limite documentados** (ex: `goodDelivery=true`, `ctr=1.9`, `clicks=99` — não deve disparar a regra)
- `DashboardRepository::classifyPersona()` usa `segment` como string raw — se o enum de segmentos mudar, classificação quebra silenciosamente. **Encapsular num Value Object `Segment` com constantes**

---

## 10. Frontend — arquitetura

### Stack
React 19 + Vite + TypeScript + Redux Toolkit + Reactstrap (Velzon theme).

### Estrutura de estado (Redux)

- Slices por feature em `web/src/slices/`
- Pattern: `state.<Feature>.data.<resource>` e `state.<Feature>.loading.<resource>`
- Selectors via `createSelector` do `reselect` — sempre, não consumir slices directamente em componentes
- Thunks para chamadas API que partilham dados entre páginas; `useState` local apenas para estado verdadeiramente local (modais, hover, dropdowns)

### Páginas de viatura (refactorização recente — atenção)

Existem **5 páginas independentes** por viatura:

| Rota | Componente | Conteúdo |
|---|---|---|
| `/cars/:id/analytics` | `CarAnalytics` | KPI strip, tráfego (donut), tabela canal, timeline |
| `/cars/:id/intelligence` | `CarIntelligencePage` | Diagnóstico IA, pipeline técnico, intent, silent buyers |
| `/cars/:id/marketing` | `CarMarketing` | Recomendações de marketing, criativo sugerido |
| `/cars/:id/ads` | `CarAdsPage` | SmartAds, campanhas activas, audiência |
| `/cars/:id/ficha` | `CarFichaPage` | Specs, estado, documentação, preço, imagens |

`CarPageNav` é a navegação entre tabs (usa `useParams()`).

**Problema actual:** cada uma das 5 páginas chama o thunk `analyticsCar` independentemente no `useEffect`. Resultado: navegar entre tabs dispara N pedidos para os mesmos dados. **A corrigir no refactor — ver secção 15.**

### Velzon — como tratar

- Velzon é tema Bootstrap 5 comercial. As classes (`fs-11`, `fw-semibold`, `bg-light-subtle`, `text-primary`) **são do tema, não do nosso design system**
- **Não criar mais `sectionStyle` inline.** Já está duplicado em 4 ficheiros — extrair para componente `Section` ou `Card` próprio no refactor
- Não fazer upgrade major do Velzon sem análise de impacto (risco alto)
- Médio prazo: extrair tokens (cores, espaçamentos, border-radius) para CSS variables próprias, criando camada de produto sobre o Bootstrap

### Convenções TypeScript

- **Nunca usar `any`.** É o problema #1 do frontend actual e está a ser corrigido em fases. Todo o código novo deve ter tipos próprios
- Tipos de API vivem em `web/src/types/api.ts` (a criar — ver roadmap)
- Tipos derivam das API Resources do Laravel (estas são a fonte de verdade do schema)
- Optional chaining (`?.`) só é justificado quando o contrato genuinamente permite `undefined`. Se está a ser usado para "defensive coding" porque o tipo é `any`, **tipar correctamente em vez disso**

### Convenções de chamadas API

- **Um único padrão:** Redux Toolkit thunks + selectors. Chamadas locais com `useState`/`useEffect` apenas para casos verdadeiramente isolados (e documentar porquê no código)
- Helpers em `web/src/helpers/api_helper.ts` (transversais) e por recurso (a criar) — não acumular tudo em `laravel_helper.ts`

### Formulários

- Formulários simples: state controlado manual (aceitável)
- Formulários complexos (CarCreate, CarUpdate): considerar React Hook Form + Zod no próximo refactor — não bloqueante

### Erros e feedback

- Todas as chamadas API devem ter tratamento de erro **com feedback ao utilizador** (`react-toastify`)
- Nunca `catch(() => {})` silencioso
- Adicionar **Error Boundary global** (a fazer — ver roadmap)

---

## 11. Autenticação e multi-tenancy

### Auth

- **Laravel Sanctum** com autenticação SPA (cookie-based para o próprio domínio)
- `sessionStorage.authUser` no frontend guarda o objecto de utilizador (incluindo `company_id` e `role`)
- Interceptor Axios em `api_helper.ts` anexa automaticamente o Bearer token
- **Sem 2FA implementado** — listar como risco no roadmap de segurança

### Multi-tenancy

- Recursos de `/api/v1/*` fazem scope por `company_id` derivado do utilizador autenticado **no backend** (não confiar no frontend)
- API pública (`/api/public/*`) usa token da empresa, não utilizador
- **`company_id` nunca deve ser aceite como parâmetro de request** do utilizador autenticado — é sempre derivado do auth

### Roles

| Role | Permissões |
|---|---|
| `root` | Acesso total, incluindo ferramentas internas (ScraperRunner) |
| `admin` | Gestão completa da empresa |
| `user` | Operação corrente (criar/editar viaturas, ver analytics) |

**Importante:** os guards de role são feitos no frontend (renderização condicional). **Verificar e reforçar guards equivalentes no backend** — não confiar apenas no frontend.

---

## 12. Convenções de código

### Geral

- **Língua de identificadores:** inglês (variáveis, funções, classes, tabelas, colunas)
- **Língua de conteúdo voltado ao utilizador:** Português de Portugal (pt-PT, não pt-BR)
- **Comentários:** preferencialmente inglês, mas pt-PT aceite em código de domínio (regras de negócio específicas a Portugal — fiscalidade, segmentos automóveis)

### PHP / Laravel

- PSR-12
- Type hints em todas as assinaturas (PHP 8.2 — usar enums, readonly properties, constructor property promotion)
- DocBlocks em métodos públicos de Services e Repositories
- `declare(strict_types=1);` no topo de ficheiros novos

### TypeScript / React

- Strict mode `true` no `tsconfig.json` (a verificar e corrigir se não estiver)
- Componentes funcionais com hooks — nada de class components
- Props com interfaces explícitas — nunca `any`, nunca `object`
- Imports absolutos via alias (`@/components/...`, `@/slices/...`) — configurar no Vite se não estiver

### Naming

- Models: singular PascalCase (`Car`, `CompanyIntegration`)
- Tabelas: plural snake_case (`cars`, `company_integrations`)
- Controllers: `<Resource>Controller`
- Services: `<Resource>Service`
- Repositories: `<Resource>Repository`
- Componentes React: PascalCase (`CarAnalyticsHeader.tsx`)
- Slices Redux: feature em PascalCase (`Car`, `Dashboard`)

---

## 13. Integrações externas

### OpenAI

- **Cliente único** — não instanciar nova integração em features novas
- Modelo padrão: definir em `config/services.php` (não hardcoded)
- **Rate limit por empresa** — implementar no refactor (proteger custos)
- Prompts em ficheiros dedicados, versionáveis — não inline em Services
- Output esperado **sempre** explícito (formato, comprimento, língua pt-PT)

### Geração de descrições de viatura (feature dedicada)

- Páginas: `/cars/create` e `/cars/edit`
- Botão "Gerar descrição com IA" junto ao campo de descrição
- **Botão disabled** enquanto faltar preencher: Tipo de Veículo, Marca, Modelo, Ano, Preço (sempre); Combustível (só Carro); Cilindrada+Potência (Carro e Autocaravana)
- **Princípio inviolável:** a descrição NÃO repete informação visível na ficha técnica. É a camada editorial que os campos não capturam
- Português de Portugal, 60–100 palavras, texto corrido, sem bullet points
- Proibido: "Descubra", "perfeito para", "aventuras", "liberdade", "elegante", "moderno"
- Foco por tipo:
  - **Carro** → equipamento que se destaca para o segmento e preço, estado geral, diferenciadores face a alternativas similares
  - **Autocaravana** → como o layout e equipamento de habitação funcionam na prática, estado, diferenciador face ao preço
  - **Caravana** → habitabilidade real, estado de conservação, equipamento que acrescenta valor prático

### Meta Ads

- OAuth via `MetaAdsService`, tokens em `company_integrations`
- Sincronização de campanhas e métricas via `FetchMetaAdsJob`
- `meta_audience_insights` é a tabela de audiência
- **Atenção:** a Meta Graph API muda 2× por ano. Quando partir, **não improvisar** — verificar changelog Meta e ajustar `MetaAdsService` num PR dedicado

### Carmine

- Tabela e model existem (`carmine_connections`)
- Funcionalidade UI ainda não totalmente integrada — **estado: parcial**
- Antes de qualquer feature nova com Carmine, validar o que está implementado

### Scraper Python

- Container isolado, comunicação via `docker exec` despachado por `RunScraperJob`
- **Atenção arquitectural:** o worker tem acesso ao Docker socket (`/var/run/docker.sock`). Risco de segurança em produção — auditar e considerar runner dedicado a médio prazo
- Não tem testes — adicionar pelo menos smoke tests

---

## 14. Dívida técnica conhecida (priorizada)

### 🔴 Alta prioridade — atacar no próximo trimestre

1. **Bug histórico `whatsapp_clicks`** — métricas erradas 2026-03-06 a 2026-03-13. Correr `php artisan performance:aggregate --from=DATE --to=DATE --sync` para cada dia
2. **TypeScript `any` generalizado** — bloqueia refactor seguro. Criar `web/src/types/api.ts` com interfaces para `CarAnalyticsResponse`, `IpsScore`, `AiAnalysis`, `PerformanceMetric`
3. **Sem testes para lógica de negócio crítica** — `CarAiAnalysesService::enforceCampaignDiagnosisRules()`, `DashboardRepository::groupCarsByPersona()`, `ScraperService::normalizeFilters()`, `AggregateCarPerformanceMetricsJob`
4. **Fetch duplicado em cada tab de viatura** — 5 páginas fazem o mesmo fetch independente. Resolver com layout component que faz fetch uma vez e partilha estado por `car_id`
5. **`sessionStorage` com dados de utilizador** — avaliar mover para HTTP-only cookie ou pelo menos limitar o que é guardado

### 🟡 Média prioridade

6. **`laravel_helper.ts` a crescer sem organização** — partir em helpers por recurso
7. **Sem lazy loading de rotas React** — implementar `React.lazy()` + `Suspense` no `allRoutes.tsx`
8. **Sem rate limiting nas rotas de IA** — adicionar throttle por `company_id`
9. **`classifyPersona()` acoplado a strings raw de `segment`** — encapsular num enum/VO
10. **Sem Error Boundary React global** — adicionar e ligar a logging

### 🟢 Baixa prioridade

11. `document.title` mutado directamente — substituir por hook `useDocumentTitle`
12. Sem CI/CD — adicionar GitHub Actions para tests + lint em PRs
13. Sem índices documentados em tabelas de métricas — adicionar `(car_id, period_start)` em `car_performance_metrics`
14. `ScraperExecution.company_id` nullable (legacy) — normalizar quando seguro
15. `sectionStyle` inline duplicado — extrair `<Section>` / `<Card>` próprios
16. Accordions B1 (`Dimensões e Pesos`, `Cozinha`, `Casa de Banho`) inline em `CarVehicleDetailsDataFields.tsx` — extrair para `components/vehicleAttributes/` alinhando com padrão B2

---

## 15. Refactor cirúrgico — roadmap 90 dias

### Mês 1 — fundações de tipagem e dados

**Semana 1**
- Criar `web/src/types/api.ts` com interfaces baseadas nas API Resources existentes
- Migrar `CarAnalytics`, `CarAdsPage`, `CarIntelligencePage` para tipos próprios (eliminar `any`)
- Corrigir bug histórico `whatsapp_clicks` em produção (artisan command)

**Semana 2**
- Layout component `CarPageLayout` em `/cars/:id/*` que faz fetch único de `analyticsCar` e partilha via Context ou via key no Redux
- Remover fetches duplicados das 5 páginas individuais
- Mover `CarAnalyticsHeader` para o layout

**Semana 3**
- Testes unitários para `CarAiAnalysesService::enforceCampaignDiagnosisRules()` — todos os casos limite
- Testes unitários para `DashboardRepository::groupCarsByPersona()` — cada combinação de segmento/preço/potência

**Semana 4**
- Rate limiting `/api/v1/ai/*` por `company_id`
- Verificação e reforço de guards `role:root` nos endpoints `/internal/*` no backend (não confiar só no frontend)
- Adicionar índice composto `(car_id, period_start)` em `car_performance_metrics`

### Mês 2 — organização e robustez

**Semana 5–6**
- Partir `laravel_helper.ts` em ficheiros por recurso (`carsApi.ts`, `dashboardApi.ts`, `leadsApi.ts`, …)
- Adoptar padrão único de chamadas API (Redux thunks). Documentar excepções no código
- Adicionar Error Boundary global + integração com logging

**Semana 7–8**
- Lazy loading de rotas em `allRoutes.tsx` com `React.lazy()` + `Suspense`
- Substituir `document.title = …` por hook `useDocumentTitle`
- Encapsular `segment` num enum/Value Object em PHP

### Mês 3 — produto e estabilidade

**Semana 9–10**
- Extrair tokens Velzon para CSS variables próprias; primeira versão do `<Section>` / `<Card>` próprio
- Migrar 2–3 páginas piloto para usar os componentes próprios

**Semana 11–12**
- Smoke tests Python do scraper
- GitHub Actions com lint + tests em PRs
- Auditoria de mass assignment / `$fillable` em todos os models

### O que **não** está no roadmap (deliberadamente)

- Migração para Next.js
- Migração para Python backend
- Reescrita do estado para Zustand/Jotai
- Substituição do Velzon por sistema próprio do zero
- WebSockets / real-time notifications
- Implementação de WhatsApp Business API (faz parte da fase de Agência, não do refactor técnico)

---

## 16. O que NÃO fazer

- **Não reescrever** — o stack actual fica. Decisão tomada, ver secção 2.2
- **Não migrar para Next.js** — sem benefício real para SPA B2B autenticada
- **Não migrar para Python no backend** — Laravel é mais produtivo para o que fazemos
- **Não integrar com Standvirtual** — quebra a narrativa comercial
- **Não criar marketplace público XPLENDOR** — não competimos com Standvirtual
- **Não construir DMS completo** (faturação, oficina, contabilidade) — usar integrações com Vendus, Moloni, Eticadata
- **Não devolver Models directamente da API** — sempre via Resource
- **Não pôr business logic em Controllers** — vai para Services
- **Não usar `any` em código novo**
- **Não criar migrations sem `down()`** implementado
- **Não fazer mass assignment** sem `$fillable` ou `$guarded` definidos
- **Não confiar no frontend** para guards de role/permissão — sempre validar no backend
- **Não aceitar `company_id` como parâmetro de request** de utilizador autenticado — derivar do auth
- **Não fazer upgrade major do Velzon** sem análise de impacto
- **Não usar emojis ou pt-BR** em conteúdo voltado ao utilizador

---

## 17. Workflow de tarefas

Ao receber uma tarefa, seguir esta ordem:

1. **Ler este CLAUDE.md** (pelo menos secções 2, 14, 15, 16)
2. **Analisar código existente** relacionado antes de propor solução — seguir padrões já estabelecidos
3. **Verificar se há decisão prévia** que afecta a tarefa (secção 16 em particular)
4. **Mostrar plano** antes de escrever código quando a tarefa envolver múltiplos ficheiros ou alterações estruturais
5. **Implementar** seguindo as convenções
6. **Adicionar testes** para lógica de negócio crítica
7. **Actualizar este CLAUDE.md** se a tarefa mudou alguma decisão arquitectural
8. **Não inventar** estruturas, tabelas, ou ficheiros — confirmar antes

### Antes de começar features novas

- Confirmar que não estamos a violar a regra de "agência primeiro" — a feature ajuda-nos a servir clientes da agência, ou é especulação para SaaS futuro?
- Se for especulação para SaaS, **adiar**. Voltamos a esta conversa quando tivermos 12+ clientes da agência e cashflow positivo (ver secção 18)

---

## 18. Quando voltar a pensar em SaaS aberto ao exterior

Reavaliar produtização como SaaS self-service apenas quando **todas** estas condições forem verdadeiras:

- ✅ Pelo menos 12 clientes activos da agência
- ✅ ARR superior a €150k
- ✅ Pelo menos 3 case studies publicados com resultados mensuráveis
- ✅ Equipa de pelo menos 3 pessoas (fundadores + 1 hire)
- ✅ Cashflow positivo há pelo menos 6 meses
- ✅ A sentirmos que estamos a recusar clientes por falta de capacidade

Antes disto, **SaaS aberto é distração.** A agência é o negócio.

---

## 19. Glossário rápido

- **IPS** — Índice de Potencial de Venda (score 0–100 calculado por viatura, persistido em `car_sale_potential_scores`)
- **Persona** — agrupamento de viaturas por características (preço, potência, segmento, lugares) usado no dashboard
- **Stand** — concessionário/vendedor de veículos (cliente XPLENDOR)
- **Marketplace** — Standvirtual, OLX, Custojusto (não integramos, ver secção 2.4)
- **Action Center** — área da app que sugere acções concretas ao stand baseadas em analytics e IA
- **SmartAds** — recomendações de campanha geradas pelo `CarAiAnalysesService`

---

## 20. Documentos relacionados

- `XPLENDOR-Manual-Reposicionamento.md` — manual comercial da agência (pricing, contrato-tipo, scripts de venda)
- Auditoria estrutural completa (2026-05-22) — relatório base que deu origem a este documento

---

*Fim do documento. Manter actualizado.*