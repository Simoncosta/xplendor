# XPLENDOR — Project Guide (CLAUDE.md)

> **Documento de leitura obrigatória antes de qualquer tarefa.**
> Define o que existe, como está estruturado, e que decisões já estão tomadas.
> Se este documento contradiz o código, **o documento ganha** — abrir issue antes de seguir o código.
>
> Última actualização: 2026-05-26 · Versão 1.6

---

## 0. Como ler e usar este documento

- Ler do início ao fim antes da primeira contribuição
- Em sessões seguintes: pelo menos rever as secções **2 (Direção estratégica)**, **14 (Dívida técnica)**, **15 (Roadmap)** e **16 (O que não fazer)** antes de propor mudanças
- Quando uma decisão arquitetural mudar, **actualizar este ficheiro no mesmo PR** que implementa a mudança
- Quando algo neste documento estiver desactualizado, **assinalar TODO no topo da secção** em vez de seguir o código silenciosamente

### Histórico de versões

| Versão | Data | Resumo |
|---|---|---|
| 1.0 | 2026-05-22 | Versão inicial após auditoria estrutural completa |
| 1.1 | 2026-05-22 | Adicionadas Fases A, B1, B2, C, D. Tabelas em falta. Dívida técnica nova. Correcções de PII na API pública. |
| 1.2 | 2026-05-25 | Sessões 2026-05-23 a 2026-05-25: F1a/b/c (Dashboard honesto), G (Acções ocultas), H1 (auditoria 5 tabs), H2a/b (fetch + Ficha), H3a/b/c/d (eliminações + simplificações), X1/X2/X3/X4/X5/X6 (bug fixes MarketPositionCard + IPS). Items 29-51 de dívida técnica. |
| 1.3 | 2026-05-25 | X7 — Fix C do item 50: persistir aggregate_id em sessionStorage. Items 48 e 49 resolvidos. Endpoint GET por id específico (?aggregate_id=N). Fix migration SQLite para testes. 8/8 testes verdes. |
| 1.3.1 | 2026-05-25 | X7.1 — Correcção do mapeamento de resposta no helper: bug histórico desde Fase E exposto pelo X7. 5/5 testes frontend novos. |
| 1.4 | 2026-05-25 | 6 sub-fases: X7.1 (fix mapeamento helper), Y1.1 (useIsMobile hook + LeadList mobile), Y1.2 (CarPageNav overflow scroll), Y2 (item 52: preço promocional), Y2.1 (UX comparáveis + fix tipo MarketComparable), Y2.2 (links mortos: search_url + check-link + cache). |
| 1.5 | 2026-05-26 | Z1.a (fix reorder FilePond), Z1.b (sentinel existing_images_present), Z2.a (original_path + crop backend), Z2.b (ImageCropperModal + integração FilePond), Z2.c (endpoint recrop + UI + testes). |
| 1.6 | 2026-05-26 | D2/D3/D4 (Redis correctamente configurado: REDIS_HOST, QUEUE_CONNECTION, CACHE_STORE). Y3.a (CarAnalyticsHeader trim mobile), Y3.b (CarList off-canvas filtros), Y3.c (Dashboard signal cards grid + SummaryDashboard borderBottom). |

---

## 1. O que é o XPLENDOR

XPLENDOR é uma plataforma B2B de gestão de stock + analytics + marketing automation para stands de automóveis em Portugal.

**Estado actual:** 2 clientes pagantes (1 stand de carros, 1 stand de autocaravanas — Quebom & Parracho).

**Estado do produto:** funcional, com 12–18 meses de desenvolvimento investidos, com funcionalidades reais entregues (analytics, IA, integração Meta Ads, scraper de mercado, dashboards por persona, IPS scoring, gestão completa de atributos de habitação para autocaravanas).

**Direção estratégica imediata:** agência produtizada (ver secção 2).

**Direção estratégica a 18–24 meses:** reavaliar produtização como SaaS aberto ao mercado.

---

## 2. Direção estratégica — decisões já tomadas

Estas decisões foram tomadas após análise de mercado e auditoria técnica. **Não revisitar sem razão forte.**

### 2.1 Modelo de negócio: agência primeiro, SaaS depois

- O XPLENDOR é vendido aos clientes como **serviço gerido**, não como software self-service
- Os clientes podem usar o painel se assim quiserem directamente — usamos mais nós, internamente, para os servir
- A plataforma é **ferramenta interna que nos torna 3–5× mais eficientes** que freelancers de tráfego automóvel
- Pacotes comerciais: €490 / €890 / €1.490/mês + budget de ads — ver `XPLENDOR-Manual-Reposicionamento.md`

### 2.2 Não fazemos rewrite. Fazemos refactor cirúrgico.

A stack actual (Laravel 12 + React 19 + Vite + MariaDB + Redis + Python para scraper) **fica**. Não migramos para Python puro. Não migramos para Next.js. Os problemas identificados na auditoria são de **disciplina e organização**, não de framework — resolvem-se in-place.

### 2.3 Foco vertical

Servimos exclusivamente stands de automóveis em Portugal. Não aceitamos clientes de outros sectores, nem clientes fora de Portugal, mesmo que pareçam fit técnico.

### 2.4 Independência face aos marketplaces

A narrativa central é *"libertar o stand da dependência do Standvirtual"*. **Não integramos com Standvirtual.** O produto direciona tráfego pago para o site do próprio stand, não para marketplaces de terceiros.

### 2.5 Sites públicos dos clientes são projectos separados

Os sites públicos dos stands são projectos React **separados** do XPLENDOR. Consomem a `Api/Public/*` via HTTP. Quando alterações na API pública têm breaking change, **a actualização dos sites é responsabilidade humana coordenada**, não automatizada.

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
                                   │
                                   ▼
                  ┌──────────────────────────────────────┐
                  │  Sites React separados dos clientes  │
                  │  (consomem Api/Public/*)             │
                  └──────────────────────────────────────┘
```

**Princípios desta arquitectura:**
- Laravel é a fonte única de verdade para dados, auth, business logic
- Python é tool especializada accionada via Job → `docker exec` para scraping
- Redis é exclusivamente cache + queue, nunca fonte de verdade
- Frontend interno é SPA pura atrás de Sanctum (login-walled)
- Sites públicos dos clientes são consumidores externos via `Api/Public/*`

---

## 5. Estrutura de pastas

### Backend (`server/`)

```
server/
├── app/
│   ├── Console/Commands/          # Artisan commands
│   │   └── MigrateVehicleAttributesShapeCommand.php  # migração old→new shape
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── Api/Public/        # endpoints públicos consumidos por sites externos
│   │   ├── Middleware/            # Auth, company scoping, check_company_api_token
│   │   ├── Requests/              # Form Request validation
│   │   │   └── Public/            # validação dos endpoints públicos
│   │   └── Resources/
│   │       └── Public/            # Resources que controlam o que sai publicamente
│   │           ├── CarPublicResource.php
│   │           └── RESOURCE_SHAPE.md  # documentação do contrato da API pública
│   ├── Jobs/                      # Async: AggregateCarPerformanceMetricsJob,
│   │                              #         RunScraperJob, FetchMetaAdsJob
│   ├── Models/                    # Eloquent
│   ├── Repositories/
│   │   ├── Contracts/             # Interfaces
│   │   ├── CarPublicRepository.php  # queries específicas da API pública (com JSON filters)
│   │   └── (outros)
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
│   │       ├── components/
│   │       │   ├── CarVehicleDetailsDataFields.tsx
│   │       │   ├── CarEquipmentDataFields.tsx
│   │       │   └── vehicleAttributes/        # accordions B2 extraídos
│   │       │       ├── EnergyClimateAccordion.tsx
│   │       │       ├── ExteriorAccordion.tsx
│   │       │       ├── SecurityAccordion.tsx
│   │       │       ├── ChassisStructureAccordion.tsx
│   │       │       └── InteriorFurnitureAccordion.tsx
│   │       └── data/
│   │           ├── extraGroups.ts            # listas de items por grupo de extras
│   │           └── vehicleAttributes.ts      # tipos + BED_LABELS
│   ├── Companies/
│   ├── Dashboards/
│   ├── Landing/                  # landing pública (agência) — não usar em painel autenticado
│   │   ├── components/
│   │   ├── sections/
│   │   └── data/
│   ├── Leads/
│   ├── Users/
│   ├── Blogs/
│   ├── Actions/
│   └── Internal/ScraperRunner/
├── Routes/allRoutes.tsx
├── hooks/                         # Hooks reutilizáveis: useIsMobile(breakpoint), useMetaOAuth
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
| `companies` | Tenant raiz | Token público em `public_api_token` |
| `users` | Utilizadores por empresa | Role enum: `root` / `admin` / `user` |
| `cars` | Viaturas por empresa | `price_gross`, `power_hp`, `segment`, `seats`, `status`, `car_category_id` |
| `car_categories` | Lookup de categorias por tipo de viatura | Atrelado a `vehicle_type` |
| `car_views` | Visualizações em tempo real | Por sessão/canal/fonte |
| `car_leads` | Leads capturados | Por canal (form, whatsapp, phone, etc.) |
| `car_interactions` | Eventos granulares | `interaction_type` enum (whatsapp_click, …) |
| `car_performance_metrics` | Agregado diário **T-1** por canal | Upsert por (`period_start`, `period_end`, `car_id`, `channel`) |
| `car_market_snapshots` | Dados do scraper de mercado | Por viatura e data |
| `car_ai_analyses` | Análises IA por viatura | JSON com structured output |
| `car_sale_potential_scores` | IPS 0–100 | Histórico; mais recente = `MAX(id)` |
| `car_external_images` | Imagens externas por URL | Provavelmente Carmine ou scraper |
| `car_sales` | Registo de venda efectiva por viatura | — |
| `car_sale_attributions` | Atribuição de venda a canal/campanha | — |
| `car_sales_learning` | Histórico para modelo de venda | — |
| `car_ad_campaigns` | Campanhas de anúncio por viatura | — |
| `car_ad_attributions` | Atribuição de resultado a campanha | — |
| `campaign_car_metrics_daily` | Métricas diárias por campanha e viatura | — |
| `car_funnel_metrics_daily` | Métricas de funil diárias por viatura | — |
| `car_marketing_ideas` | Ideias de marketing geradas por IA por viatura | — |
| `vehicle_attributes` | **Atributos de habitação** (motorhome/caravan) | JSON estruturado por secções — ver secção 6.1 |
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
  ├── hasOne VehicleAttribute             (só motorhome/caravan)
  ├── belongsTo CarCategory               (car_category_id, só motorhome)
  ├── hasMany CarView
  ├── hasMany CarLead
  ├── hasMany CarInteraction
  ├── hasMany CarPerformanceMetric
  ├── hasMany CarMarketSnapshot
  ├── hasMany CarAiAnalysis
  └── hasMany CarSalePotentialScore
```

### 6.1 Estrutura do JSON `vehicle_attributes.attributes`

Estrutura por secções, normalizada via `VehicleAttribute::normalizeShape()`. Aceita formato antigo (flat) por retro-compatibilidade.

```json
{
  "dimensions": {
    "length_m": 6.00,
    "width_m": 2.40,
    "height_m": 2.50
  },
  "weights": {
    "gross_weight_kg": 3300,
    "tare_kg": 2800,
    "towable_weight_kg": 750
  },
  "habitation_basics": {
    "has_bathroom": true,
    "has_kitchen": true,
    "kitchen": {
      "has_stove": true,
      "has_oven": false,
      "has_microwave": true,
      "has_extractor": true,
      "has_fridge": true,
      "fridge_type": "trivalent",
      "fridge_litres": 142,
      "fridge_shelves": 3
    },
    "bathroom": {
      "has_toilet": true,
      "has_shower": true,
      "shower_type": "separate",
      "clean_water_litres": 100,
      "waste_water_litres": 90
    }
  },
  "energy_climate": {
    "water_heater_source": "gas",
    "water_heater_brand": "Truma",
    "ambient_heating_source": "gas",
    "ambient_heating_brand": "Truma",
    "has_solar_panel": true,
    "solar_panel_watts": 150,
    "has_inverter": true,
    "inverter_watts": 2000,
    "has_gpl": false,
    "gpl_bottles_count": null,
    "has_external_power_socket": true,
    "battery_count": 2,
    "cabin_battery_count": 1,
    "cell_battery_count": 1
  },
  "exterior": {
    "has_awning": true,
    "awning_brand": "Thule",
    "has_national_antenna": false,
    "has_parabolic_antenna": false,
    "has_bike_rack": true,
    "has_motorbike_rack": false,
    "has_electric_step": true,
    "has_manual_step": false,
    "has_stabilizers": true,
    "has_spare_wheel": true,
    "has_fix_n_go_kit": false,
    "has_bull_eye": false,
    "has_external_wc": false,
    "has_hubcaps": true
  },
  "security": {
    "has_alarm": true,
    "has_hatch_lock": true,
    "has_cabin_lock": true,
    "has_safe_door": false,
    "has_gas_lock": true,
    "has_entry_door_lock": true,
    "other_locks_notes": ""
  },
  "chassis_structure": {
    "chassis_type": "alko",
    "chassis_notes": "",
    "has_turbovent_skylight": true,
    "has_panoramic_skylight": true,
    "has_40x40_skylight": true,
    "other_skylights_notes": "",
    "has_remifront": true,
    "has_window_blackouts": true,
    "has_mosquito_nets": true,
    "has_door_mosquito_net": false,
    "has_cabin_blackouts": true,
    "cabin_blackout_type": ""
  },
  "interior_furniture": {
    "has_foldable_table": true,
    "has_rotating_seats": true,
    "upholstery_state": "good",
    "has_curtains": true,
    "has_led_lighting": true,
    "has_halo_lighting": false,
    "has_tv_support": true,
    "has_tv": true,
    "has_command_panel": true,
    "has_water_infiltrations": false,
    "infiltrations_notes": ""
  },
  "beds": [
    { "type": "cama_garagem" },
    { "type": "cama_central" }
  ],
  "autonomy_km": 800
}
```

**Tipos de cama válidos** (13 slugs):
- Visíveis no UI (12): `camas_gemeas`, `cama_central`, `cama_francesa`, `cama_basculante`, `cama_capucino`, `cama_garagem`, `beliche`, `cama_transversal`, `cama_elevatoria_eletrica`, `cama_suspensa`, `cama_convertivel`, `outra`
- Legacy preservado (1): `cama_rebativel_cabine` — só aparece como opção quando já está seleccionado num registo existente

**Enums:**
- `fridge_type`: `trivalent` | `compressor` | `absorption` | `none`
- `shower_type`: `separate` | `combined` | `none`
- `water_heater_source` / `ambient_heating_source`: `electric` | `gas` | `diesel` | `none`
- `chassis_type`: `standard` | `alko` | `other`
- `upholstery_state`: `good` | `fair` | `worn` | `replaced`

**Helper único de normalização:** `VehicleAttribute::normalizeShape($raw)`. Usado por:
1. `Car::getVehicleAttributesAttribute()` (accessor)
2. `CarDescriptionService` (geração de descrições com IA)
3. `MigrateVehicleAttributesShapeCommand` (artisan command de migração)

### Problemas de dados conhecidos

- **`car_performance_metrics.whatsapp_clicks`**: coluna adicionada 2026-03-14 com `DEFAULT 0`. Métricas erradas para 2026-03-06 a 2026-03-13. Remediação: `php artisan performance:aggregate --from=DATE --to=DATE --sync`.
- **`car_id 57` tem `length: -0.1745`** — bug histórico. Corrigir manualmente ou aceitar normalização para `-0.001745`.
- **`subsegment` está NULL em todos os motorhomes/caravans** na BD — o campo existe mas não está a ser gravado pelo UI. Investigar.
- **`lifestyle` está zombie** — campo no schema do `cars`, sem cast, sem UI, nunca preenchido. Candidato a remoção.
- **`car_categories` BD divergente do seeder** — seeder tem 7 categorias (Atrelado Tenda, Capucine, Caravana, Caravana Pickup, Furgão, Integral, Perfilada); BD tem 4 (Campervan, Capucino, Integral, Perfilada). "Capucine" no seeder vs "Capucino" na BD. Não corrigir agora — slug em uso.
- **Sem índices documentados** em `car_performance_metrics(car_id, period_start)`.
- **Queries em JSON sobre `vehicle_attributes.attributes` não usam índices.** Aceitável até ~5.000 viaturas por empresa.
- **Sem schema enforcement** em `car_ai_analyses.json_output`.

---

## 7. Models e Eloquent

### Convenções

- Todos os models de negócio têm `company_id` no `$fillable` e usam scope global por empresa
- Casts explícitos em todos os campos JSON, decimal, datetime, enum
- Relações nomeadas em singular para `belongsTo` / `hasOne`, plural para `hasMany` / `belongsToMany`
- **Sem business logic em models** — vai sempre para Services
- Auditoria via `Auditable` trait do `owen-it/laravel-auditing` em models que mudam frequentemente

### Helper `VehicleAttribute::normalizeShape()`

**Fonte única de verdade** para a estrutura do JSON `attributes`. Sempre que se lê este JSON, passa por aqui. Sempre que se escreve, grava-se no formato novo.

```php
VehicleAttribute::normalizeShape(null);          // → estrutura vazia válida
VehicleAttribute::normalizeShape([]);            // → estrutura vazia válida
VehicleAttribute::normalizeShape($oldFlat);      // → migra para formato novo
VehicleAttribute::normalizeShape($newStructured);// → devolve as-is
```

Inclui também `normalizeBedTypes()` que mapeia strings antigas para slugs novos:

| String antiga | Slug novo |
|---|---|
| `"central"` | `"cama_central"` |
| `"rebatível na cabine"` | `"cama_rebativel_cabine"` |
| `"beliche"` | `"beliche"` |
| `"transversal"` | `"cama_transversal"` |
| `"cama de garagem"` | `"cama_garagem"` |
| `"outra"` | `"outra"` |
| Slug desconhecido | `"outra"` (fallback) |

### Problemas conhecidos a corrigir incrementalmente

- Falta de `$fillable` / `$guarded` disciplinado em alguns models recentes — auditar antes de adicionar campos novos
- `CarAiAnalysis` guarda output como JSON sem schema — criar um Value Object/DTO para validar formato

---

## 8. API e rotas

### Três namespaces de API

| Prefixo | Auth | Propósito |
|---|---|---|
| `/api/v1/*` | Sanctum SPA (cookie) | Painel autenticado da empresa |
| `/api/public/*` | Token da empresa (`?token=<uuid>` na query string) | Catálogos embebidos consumidos por sites externos dos clientes |
| `/market/snapshots` | Scraper token middleware | Ingestão de dados de mercado |

### Convenções de API

- Controllers **finos** — delegam imediatamente para Services
- Validação **sempre** via Form Request (`app/Http/Requests/`)
- Resposta **sempre** via API Resource (`app/Http/Resources/`) — nunca devolver modelos directamente
- Paginação com formato standard: `{ data, meta: { current_page, last_page, per_page, total } }`
- Erros 4xx com payload `{ message, errors: { campo: [mensagens] } }`
- Erros 5xx com payload `{ message }` apenas (sem stack trace)
- **Versionamento:** quando precisar de breaking changes na API pública, **coordenar com actualização manual dos sites externos** dos clientes. Não criar `/api/v2/*` automaticamente — versionar só quando houver consumidores externos terceiros.

### 8.1 API pública (`/api/public/*`)

**Middleware:** `check_company_api_token` lê `?token=<uuid>` da query string e injecta `public_api_company` no request. Usar `$request->input('public_api_company')` para obter o modelo da empresa, **sem fazer query redundante**.

**Status visíveis publicamente:** apenas `active` e `available_soon`. Outros valores (`sold`, `draft`, `inactive`, `reserved`) são filtrados automaticamente no `CarPublicRepository`.

**Resource pública:** `CarPublicResource` em `app/Http/Resources/Public/`. Documentação completa em `RESOURCE_SHAPE.md` ao lado da Resource.

**Estrutura achatada da resposta pública** (não expõe a estrutura interna do JSON):

```json
{
  "id": 55,
  "brand": { "id": 1, "name": "Fiat" },
  "model": { "id": 12, "name": "Ducato" },
  "category": { "id": 6, "name": "Integral", "slug": "integral" },
  "specs": { "seats": 5, "length_m": 6.0, "gross_weight_kg": 3300 },
  "habitation": { "has_kitchen": true, "has_bathroom": true },
  "features": { "has_solar_panel": true, "has_awning": false },
  "beds": [{ "type": "cama_garagem", "label": "Cama de garagem" }],
  "seller": { "name": "...", "avatar": "...", "mobile": "...", "whatsapp": "..." },
  "is_trade_in": false
}
```

**Para viaturas não-motorhome:** `habitation`, `features`, `beds`, `category` são `null`. Mais previsível que omitir chaves.

### 8.2 Campos NUNCA expostos publicamente

A `CarPublicResource` omite explicitamente estes campos. Não voltar a expor sem revisão de privacidade:

- `internal_notes` — notas internas dos vendedores
- `vin` — número de chassis (PII)
- `license_plate` — matrícula (PII)
- `company_id`, `carmine_id`, `seller_user_id`, `car_brand_id`, `car_model_id`, `car_category_id` — FKs internas
- `lifestyle`, `price_net` — campos sem dados ou B2B internos
- `views_count`, `leads_count` — métricas internas
- Relação `seller` raw (User completo com `email`, `role`, `birthdate`, `deleted_at`, etc.)

A entidade `seller` na resposta pública contém apenas: `name`, `avatar`, `mobile`, `whatsapp`. Calculada por `appendPublicSellerContact`. **Não carregar `with(['seller'])` nos endpoints públicos.**

### 8.3 Filtros suportados na API pública

**Filtros antigos (manter):**
`doors`, `condition`, `min_price_gross`, `max_price_gross`, `exterior_colors`, `interior_colors`, `registration_years`, `fuel_types`, `transmissions`, `vehicle_type`, `segment`, `brand`, `model`, `orderBy`, `orderDirection`, `perPage`.

**Filtros novos (Fase D):**
- `category=integral` — filtra por `car_category.slug`
- `bed_types=cama_central,cama_garagem` — OR entre slugs
- `min_seats` / `max_seats`
- `min_length_m` / `max_length_m` (decimal)
- `has_bathroom=true` / `has_kitchen=true` / `has_solar_panel=true`

**Documentação completa:** `app/Http/Resources/Public/RESOURCE_SHAPE.md`.

### Segurança a reforçar (parte do refactor contínuo)

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
| `CarService` | CRUD de viaturas, upload de imagens, normalização de payload |
| `CarAnalyticsService` | Agrega métricas, IPS, análises para endpoint de analytics |
| `CarAiAnalysesService` | Gera e persiste análises IA; enforce pós-processamento |
| `CarDescriptionService` | Gera descrições via OpenAI, lê `vehicle_attributes` via helper |
| `MetaAdsService` | OAuth Meta, sincronização de campanhas e métricas |
| `DashboardService` | Agrega dados de dashboard; inclui personas |
| `ScraperService` | Normaliza filtros, cria `ScraperExecution`, despacha job |
| `VehicleAttributeService` | Orquestra repo de atributos (read/write/upsert) |

### Repositories existentes

| Repository | Responsabilidade |
|---|---|
| `DashboardRepository` | Agregações dashboard; contém `groupCarsByPersona()` |
| `CarPublicRepository` | Queries da API pública, incluindo filtros JSON em `vehicle_attributes.attributes` |
| `VehicleAttributeRepository` | CRUD de atributos de viatura |

### Problemas conhecidos a corrigir

- `CarAiAnalysesService::enforceCampaignDiagnosisRules()` não tem testes unitários
- `DashboardRepository::classifyPersona()` usa `segment` como string raw — encapsular num Value Object

---

## 10. Frontend — arquitetura

### Stack
React 19 + Vite + TypeScript + Redux Toolkit + Reactstrap (Velzon theme).

### Estrutura de estado (Redux)

- Slices por feature em `web/src/slices/`
- Pattern: `state.<Feature>.data.<resource>` e `state.<Feature>.loading.<resource>`
- Selectors via `createSelector` do `reselect` — sempre, não consumir slices directamente em componentes
- Thunks para chamadas API que partilham dados entre páginas; `useState` local apenas para estado verdadeiramente local

### Páginas de viatura (5 páginas independentes)

| Rota | Componente | Conteúdo |
|---|---|---|
| `/cars/:id/analytics` | `CarAnalytics` | KPI strip, tráfego (donut), tabela canal, timeline |
| `/cars/:id/intelligence` | `CarIntelligencePage` | Diagnóstico IA, pipeline técnico, intent, silent buyers |
| `/cars/:id/marketing` | `CarMarketing` | Recomendações de marketing, criativo sugerido |
| `/cars/:id/ads` | `CarAdsPage` | SmartAds, campanhas activas, audiência |
| `/cars/:id/ficha` | `CarFichaPage` | Specs, estado, documentação, preço, imagens |

`CarPageNav` é a navegação entre tabs (usa `useParams()`).

**Problema actual:** cada uma das 5 páginas chama o thunk `analyticsCar` independentemente. Resolução no refactor — ver secção 14.

### Formulário de viatura (`CarCreate` / `CarUpdate`)

Suporta tipos: carro, moto, motorhome, caravana.

**Para motorhome e caravan:** 8 accordions de atributos de habitação (Fases B1 + B2):
1. Dimensões e Pesos (B1)
2. Cozinha (B1)
3. Casa de Banho (B1)
4. Energia e Aquecimento (B2)
5. Exterior (B2)
6. Segurança e Fechaduras (B2)
7. Chassis e Estrutura (B2)
8. Mobiliário Interior (B2)

Accordions 4-8 vivem em `web/src/pages/Cars/Car/components/vehicleAttributes/` como sub-componentes próprios. Accordions 1-3 estão inline em `CarVehicleDetailsDataFields.tsx` (dívida técnica registada).

### Velzon — como tratar

- Velzon é tema Bootstrap 5 comercial. As classes (`fs-11`, `fw-semibold`, `bg-light-subtle`, `text-primary`) **são do tema, não do nosso design system**
- **Não criar mais `sectionStyle` inline.** Já está duplicado em 4 ficheiros — extrair para componente `Section` ou `Card` próprio
- Não fazer upgrade major do Velzon sem análise de impacto (risco alto)
- Médio prazo: extrair tokens (cores, espaçamentos, border-radius) para CSS variables próprias

### Convenções TypeScript

- **Nunca usar `any`.** É o problema #1 do frontend actual e está a ser corrigido em fases. Todo o código novo deve ter tipos próprios
- Tipos de API vivem em `web/src/types/api.ts` (a criar progressivamente)
- Tipos derivam das API Resources do Laravel
- Optional chaining (`?.`) só é justificado quando o contrato genuinamente permite `undefined`

### Convenções de chamadas API

- **Um único padrão:** Redux Toolkit thunks + selectors
- Helpers em `web/src/helpers/api_helper.ts` (transversais) e por recurso (a criar) — não acumular tudo em `laravel_helper.ts`

### Formulários

- Formulários simples: state controlado manual (aceitável)
- Formulários complexos (CarCreate, CarUpdate): usar Formik (já em uso) + considerar Yup ou Zod para validação no próximo refactor

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
- API pública (`/api/public/*`) usa token da empresa via `?token=<uuid>` na query string
- **`company_id` nunca deve ser aceite como parâmetro de request** do utilizador autenticado — é sempre derivado do auth

### Roles

| Role | Permissões |
|---|---|
| `root` | Acesso total, incluindo ferramentas internas (ScraperRunner) |
| `admin` | Gestão completa da empresa |
| `user` | Operação corrente (criar/editar viaturas, ver analytics) |

**Importante:** os guards de role são feitos no frontend (renderização condicional). **Verificar e reforçar guards equivalentes no backend.**

---

## 12. Convenções de código

### Geral

- **Língua de identificadores:** inglês (variáveis, funções, classes, tabelas, colunas)
- **Língua de conteúdo voltado ao utilizador:** Português de Portugal (pt-PT, não pt-BR)
- **Comentários:** preferencialmente inglês, mas pt-PT aceite em código de domínio
- **Slugs e enums:** snake_case (`cama_central`, `chassis_alko`)
- **Labels:** pt-PT factual, sem buzzwords (sem "moderno", "elegante", "perfeito para…")

### PHP / Laravel

- PSR-12
- Type hints em todas as assinaturas (PHP 8.2 — usar enums, readonly properties, constructor property promotion)
- DocBlocks em métodos públicos de Services e Repositories
- `declare(strict_types=1);` no topo de ficheiros novos

### TypeScript / React

- Strict mode `true` no `tsconfig.json`
- Componentes funcionais com hooks — nada de class components
- Props com interfaces explícitas — nunca `any`, nunca `object`
- Imports absolutos via alias (`@/components/...`, `@/slices/...`)

### Naming

- Models: singular PascalCase (`Car`, `CompanyIntegration`)
- Tabelas: plural snake_case (`cars`, `company_integrations`)
- Controllers: `<Resource>Controller`
- Services: `<Resource>Service`
- Repositories: `<Resource>Repository`
- Resources: `<Resource>Resource` ou `<Resource>PublicResource` para endpoints públicos
- Componentes React: PascalCase
- Slices Redux: feature em PascalCase

---

## 13. Integrações externas

### OpenAI

- **Cliente único** — não instanciar nova integração em features novas
- Modelo padrão: definir em `config/services.php` (não hardcoded)
- **Rate limit por empresa** — implementar no refactor (proteger custos)
- Prompts em ficheiros dedicados, versionáveis — não inline em Services
- Output esperado **sempre** explícito (formato, comprimento, língua pt-PT)
- **Variável de ambiente obrigatória:** `OPENAI_KEY`. Deve estar em `.env.example` e validada em deploy.

### Geração de descrições de viatura

- Páginas: `/cars/create` e `/cars/edit`
- Botão "Gerar descrição com IA" junto ao campo de descrição
- **Botão disabled** enquanto faltar preencher: Tipo de Veículo, Marca, Modelo, Ano, Preço (sempre); Combustível (só Carro); Cilindrada+Potência (Carro e Autocaravana)
- **Princípio inviolável:** a descrição NÃO repete informação visível na ficha técnica
- Português de Portugal, 60–100 palavras, texto corrido, sem bullet points
- Proibido: "Descubra", "perfeito para", "aventuras", "liberdade", "elegante", "moderno"
- Foco por tipo:
  - **Carro** → equipamento que se destaca para o segmento e preço, estado geral
  - **Autocaravana** → como o layout e equipamento de habitação funcionam na prática, estado
  - **Caravana** → habitabilidade real, estado de conservação, equipamento que acrescenta valor prático

### Meta Ads

- OAuth via `MetaAdsService`, tokens em `company_integrations`
- Sincronização de campanhas e métricas via `FetchMetaAdsJob`
- `meta_audience_insights` é a tabela de audiência
- **Atenção:** a Meta Graph API muda 2× por ano. Quando partir, **não improvisar**

### Carmine

- Tabela e model existem (`carmine_connections`)
- Funcionalidade UI ainda não totalmente integrada — **estado: parcial**

### Scraper Python

- Container isolado, comunicação via `docker exec` despachado por `RunScraperJob`
- **Atenção arquitectural:** o worker tem acesso ao Docker socket (`/var/run/docker.sock`). Risco de segurança em produção
- Não tem testes — adicionar pelo menos smoke tests

---

## 14. Dívida técnica conhecida (priorizada)

### 🔴 Alta prioridade — atacar no próximo trimestre

1. **Bug histórico `whatsapp_clicks`** — métricas erradas 2026-03-06 a 2026-03-13. Correr `php artisan performance:aggregate --from=DATE --to=DATE --sync` para cada dia
2. **TypeScript `any` generalizado** — bloqueia refactor seguro. Criar `web/src/types/api.ts` progressivamente
3. **Sem testes para lógica de negócio crítica** — `CarAiAnalysesService::enforceCampaignDiagnosisRules()`, `DashboardRepository::groupCarsByPersona()`, `ScraperService::normalizeFilters()`, `AggregateCarPerformanceMetricsJob`
4. **Fetch duplicado em cada tab de viatura** — 5 páginas fazem o mesmo fetch independente
5. **`sessionStorage` com dados de utilizador** — avaliar mover para HTTP-only cookie

### 🟡 Média prioridade

6. **`laravel_helper.ts` a crescer sem organização** — partir em helpers por recurso
7. **Sem lazy loading de rotas React** — implementar `React.lazy()` + `Suspense`
8. **Sem rate limiting nas rotas de IA** — adicionar throttle por `company_id`
9. **`classifyPersona()` acoplado a strings raw de `segment`** — encapsular num enum/VO
10. **Sem Error Boundary React global** — adicionar e ligar a logging
11. **B1 accordions inline em `CarVehicleDetailsDataFields.tsx`** — Os 3 accordions da Fase B1 estão inline no componente parent. Os 5 da B2 foram extraídos para sub-componentes em `components/vehicleAttributes/`. Extrair também os 3 da B1 para alinhar com o padrão
12. **`subsegment` NULL em todos os motorhomes** — campo existe no schema mas não está a ser gravado pelo UI. Investigar e corrigir
13. **Catch silencioso em `CarDescriptionDataFields.tsx`** — `catch {}` apenas mostra toast genérico. Propagar mensagem de erro real quando disponível
14. **Validação de min/max em campos numéricos do `vehicle_attributes`** — o car 57 tem `length: -0.1745`. Validação backend já existe na Fase B1 (min 0.1 m), mas o registo histórico não foi limpo

### 🟢 Baixa prioridade

15. `document.title` mutado directamente — substituir por hook `useDocumentTitle`
16. Sem CI/CD — adicionar GitHub Actions para tests + lint em PRs
17. Sem índices documentados em tabelas de métricas — adicionar `(car_id, period_start)` em `car_performance_metrics`
18. `ScraperExecution.company_id` nullable (legacy) — normalizar quando seguro
19. `sectionStyle` inline duplicado — extrair `<Section>` / `<Card>` próprios
20. **`lifestyle` campo zombie** no `cars` — sem cast, sem UI, nunca preenchido. Remover ou implementar
21. **Queries em JSON sobre `vehicle_attributes.attributes` não usam índices.** Aceitável até ~5.000 viaturas por empresa. Quando esse limite for atingido, adicionar índices funcionais em MariaDB para campos filtráveis (`length_m`, `has_solar_panel`, slugs de `beds`)
22. **Divergência seeder vs DB nas `car_categories`** — seeder tem "Capucine", BD tem "Capucino". Seeder tem 7 categorias, BD tem 4. Não alterar slugs em uso, mas alinhar seeder com realidade da BD
23. **Redundância eliminada na Fase D mas verificar outros sítios** — `appendPublicSellerContact` ainda existe; auditar se há queries duplicadas similares
24. **`car_id 57` com `length: -0.1745`** — registo único, corrigir manualmente em produção: `UPDATE vehicle_attributes SET ...`
25. ~~**`GenerateWeeklyMarketingIdeasJob` desactivado**~~ — **Eliminado em H3a (2026-05-23)**. Funcionalidade "Conteúdo da semana" removida completamente (model, service, job, repo, controller, migration DROP TABLE — 80 registos eliminados). Ver item 41.

53. **Originais não são eliminados quando a imagem é apagada (Z2.a, low priority)** — `CarService::update()` elimina o ficheiro em `images/` mas não o correspondente em `originals/`. Orphaned originals acumulam em storage. Corrigir quando houver limpeza de storage agendada ou quando atacarmos o endpoint de delete de imagem individual.

54. **Re-crop não actualiza o tile do FilePond (Z2.c, low priority)** — Após re-crop, o thumbnail na secção "Editar cortes" é actualizado via `?v=timestamp`. O tile no FilePond pond mantém o old cached preview até refresh da página. Corrigir via remoção + re-adição do item ao FilePond após re-crop bem sucedido — complexo dado o modelo de estado do react-filepond. Adiar para v2 do cropper.

### 🔴 Alta prioridade (adicionado E3a)

29. **Duas fontes de verdade paralelas para "posição no mercado"** —
    Após a Sub-fase E3a, o sistema tem dois mecanismos paralelos:
    - `CarMarketIntelligenceService` (legacy): lê de `car_market_snapshots`,
      alimenta toda a pipeline de IA (`CarAiAnalysesService`,
      `CarDecisionEngineService`, `CarIssueEngine`, `VehiclePromptBuilder`,
      possivelmente `CarSalePotentialScoreService`). Thresholds
      `below_market`/`aligned_market`/`above_market`.
    - `CarMarketAggregate` (E2): tabela própria com aggregates ricos
      (mediana, top 5, confidence), alimenta UI da ficha e futuramente
      o Dashboard. Thresholds `competitive`/`fair`/`slightly_high`/`overpriced`.

    **Migrar pipeline de IA para `CarMarketAggregate`** numa sub-fase
    dedicada após o capítulo E estar em produção. Envolve:
    - Substituir leitura de `car_market_snapshots` por
      `latest_market_aggregate` nos 5 services dependentes
    - Re-treinar prompts da IA que assumem `market_intelligence.market_position`
    - Validar que motor de decisão (`CarDecisionEngineService`) continua
      a produzir scoring consistente
    - Eventualmente eliminar `CarMarketIntelligenceService.php`

    Não atacar antes do capítulo E estar deployed e estável (3-5 dias
    em produção).

30. **6 ficheiros frontend orphans eliminados na E3a** — `MarketIntelligenceCard`,
    `TabAnaliseIA`, `TabMetricas`, `TabOverview`, `TabPerformance`, `TabViatura`.
    Tinham sido criados em iterações anteriores mas nunca foram integrados
    em rotas activas. Registado para evitar reintrodução acidental.

31. **`car_market_aggregates` não tem `scraped_at`** — A coluna estava no
    plano da E2 mas ficou de fora da migration. `updated_at` é usado como
    proxy para "última vez que o scrape correu". Adicionar `scraped_at`
    quando houver necessidade de distinguir "última actualização" de
    "último scrape" (ex: refresh manual vs schedule periódico).

32. **`getAnalyticsDashboard` thunk alimenta Dashboard e /insights** —
    Após F1a, o nome é semanticamente impreciso (já não é exclusivamente
    para Dashboard). Renomear para `getAnalyticsCompany` ou
    `getCompanyOverview` quando houver mudança maior no Redux state.
    Não bloqueia funcionamento.

33. **Página `/insights` desactivada em 2026-05-23** — rota removida, item
    do menu removido, link do dashboard removido. Componentes
    (DashboardInsightsCard, StockIntelligenceDashboardCard,
    MarketingWorkspaceTabs com prop visibleTabs, InsightsPage,
    visibilityHelpers + 12 testes) ficam no codebase para reuso futuro.

    Motivo: a página foi construída com lógica de análise de marketplace
    (oportunidades de compra, segmentos saturados, marca em destaque),
    não de gestão de stand. Para stands com poucas viaturas e sem
    campanhas activas, a informação não gera decisões accionáveis.

    Recuperar quando houver decisão clara sobre que análise é útil para
    o utilizador concreto (provavelmente reorientada para análise sobre
    as viaturas do próprio stock, não sobre o mercado em geral).

34. **Backend gera `action.label`, `action.suggestion`, `problem` em
    `immediate_actions` que o frontend já não renderiza** — Após F1c,
    estes campos continuam a ser gerados (potencialmente com custo de
    processamento/IA) mas não aparecem na UI. Investigar
    `DashboardService` onde estes campos são populados e decidir se
    eliminar a geração quando voltarmos com lógica nova de
    "probabilidade de venda" e "acção sugerida real".

35. **Item "Acções" oculto do sidebar em 2026-05-23** — rota `/actions`
    mantida em `allRoutes.tsx` mas item de menu removido. A página
    existe mas conteúdo não está pronto para uso de cliente.
    Reintroduzir item quando houver conteúdo accionável real.

36. ~~**`CarAdsPage` usa chamada API directa (`getCarAudienceAnalysisApi`)
    fora do Redux com useState local**~~ — **Resolvido em H3b (2026-05-23)**
    via eliminação da aba. Endpoint backend mantido para uso futuro.

37. **Guard de fetch em tabs de viatura não invalida por tempo** —
    Após H2a (Opção B), as 2 tabs restantes (Analytics, Intelligence) saltam
    fetch se já têm carAnalytics para o mesmo car.id. Mas se o utilizador
    deixa a tab aberta e volta horas depois, vê dados potencialmente
    stale (não há TTL). Aceitável para escala actual (sessão típica curta).
    Adicionar TTL ou invalidação por evento quando relevante.

38. **CarPageLayout com Outlet não implementado** — A Opção A da
    investigação H2a (nested routes via Outlet) ficou registada como
    design correcto a longo prazo. Requer suportar nested routes em
    `Routes/index.tsx` (hoje não usa Outlet em lado nenhum), refactor
    de `CarAnalyticsHeader` para ler do Redux, e refactor de `CarPageNav`
    para `useLocation()`. Implementar quando atacarmos auditoria profunda
    da página de viatura (Parte 2 sessão dedicada).

39. **`CarSpecsResource` faz 2 queries extra no `toArray()`** — H2b optou
    por simplicidade (queries directas a `CarSalePotentialScore` e
    `CarAiAnalysis` dentro do Resource). Aceitável para escala actual
    (1 viatura por request). Optimizar para eager load no controller
    quando houver evidência de bottleneck.

40. **`CarAnalyticsHeader` recebe `car` como objecto plano sem tipo** —
    Props definidas como `any`. Após H2b, a Ficha passa um adapter
    `carForHeader` construído a partir de `CarSpecs`. Tipar
    `CarAnalyticsHeader` com interface própria quando atacarmos
    auditoria de tipagem geral do frontend.

41. ~~**`RecommendedCreative` type em `SmartAdsRecommendationCard` é zombie**~~ —
    **Componente eliminado em H3b (2026-05-23)**. Backend continua a
    devolver `recommended_creative: null` (stub de H3a). Limpeza
    backend agendada para sub-fase futura (ver item 42).

42. **Aba `/cars/:id/ads` eliminada em 2026-05-23 (H3b)** — frontend
    completo removido (CarAdsPage, SmartAdsRecommendationCard,
    AudienceSuggestionCard, 822 linhas).

    Backend INTACTO:
    - Tabelas car_ad_campaigns, car_ad_attributions,
      campaign_car_metrics_daily, meta_audience_insights
    - MetaAdsService, OAuth, jobs de sync, schedule
    - Endpoints /audience-analysis e /audience
    - CarAdCampaignMapper em pages/Companies (reaproveitável)

    Razão para eliminar frontend: fluxo actual (mapear campanha já
    criada na Meta) é retrabalho. Visão futura: criar campanhas
    directo no XPLENDOR sem abrir Business Manager.

    Razão para manter backend: OAuth Meta é trabalho de 1-2 dias
    para reconstruir; dados de campanhas têm valor histórico;
    integração estável será reaproveitada em página futura.

    Candidatos a limpeza futura (não atacar agora):
    - Campos órfãos no payload analyticsCar: smart_ads_recommendation,
      recommended_creative (já zombie via item 41), ai_analysis.recommended_channel
    - Decidir destino em sub-fase dedicada quando construirmos
      página nova de criação de campanhas.

43. **Aba Tráfego & Canais simplificada em 2026-05-23 (H3c)** —
    KPIs reduzidos de 10 (6 topo + 4 Métricas Resumidas) para 4
    essenciais: Views / Leads / WhatsApp / Conversão. Timeline
    filtrada para apenas eventos com sinal comercial real
    (interactions, leads, marcador car_created) — view_group
    eliminado: para car 4, payload passou de 367 entradas para 14.

    Backend: filtro em CarAnalyticsService::buildTimeline() —
    payload mais leve (~96% redução para viaturas com views altos).

    Bónus: campo metrics.whatsapp_clicks novo, alimentado por
    car_interactions (real-time) em vez de car_performance_metrics
    (que tinha bug histórico item 1 do CLAUDE.md). Resolve sintoma
    do bug sem mexer no agregado.

    Campos órfãos no payload (candidatos a limpeza futura):
    - metrics.views_24h, metrics.views_7d, metrics.interactions,
      metrics.interest_rate
    - performance.totals.* (4 campos)
    - Componente CarAnalyticsKpiStrip.tsx (mantido mas órfão —
      pode ser reaproveitado noutras páginas no futuro)

44. **Aba `/cars/:id/intelligence` reduzida a MarketPositionCard em
    2026-05-23 (H3d)** — CarIntelligencePage.tsx passou de 477 para
    ~78 linhas (-84%).

    Frontend eliminado (~1007 linhas total):
    - CarIntelligencePage.tsx: -399 linhas
      - Blocos inline: CarDiagnosisBlock, TechnicalPipeline,
        IntentSignalsBlock, SilentBuyerCompactBlock
      - Helpers inline: buildSignalCards, buildScoreSignal,
        buildBusinessDiagnosis, resolveBusinessActionLabel,
        buildActionReason, resolveScoreColor, statusMark,
        translateBenchmark, translateIntentLevel
      - Handler handleRefreshAndReanalyze (toast pipeline)
    - CarAnalyticsData.ts: ipsFactorLabels removido (-8 linhas)
    - Componentes zombie eliminados (-560 linhas):
      - LeadRealityGapCard.tsx (170 linhas — nunca importado)
      - SilentBuyerIntentCard.tsx (142 linhas — nunca importado)
      - ContactPerformanceCard.tsx (248 linhas — nunca importado)

    Mantido: MarketPositionCard (E3a — único bloco com dados reais
    externos do Standvirtual via car_market_aggregates).

    Backend INTACTO. 4 campos no payload analyticsCar viram zombies
    no frontend (continuam calculados pelo backend):
    - intent_analysis
    - lead_reality_gap
    - meta_ads_targeting_status
    - silent_buyers (versão da Intelligence; Dashboard usa fonte
      diferente)

    Razão para manter cálculo: alimentam pipeline de IA legacy
    (item 29). Limpeza fica para sub-fase futura quando migrarmos
    pipeline IA para CarMarketAggregate.

    Thunks órfãos no slice car-ai-analises (não eliminados):
    - refreshCarMetaAds
    - regenerateCarAnalysis

45. **Bug `triggered_by` ENUM resolvido em 2026-05-23 (X2)** —
    Coluna `triggered_by` em `car_sale_potential_scores` não aceitava
    `'promo_price_change'`. CarObserver despachava o job com este valor
    quando preço promocional mudava, falhando silenciosamente
    (SQLSTATE 01000 Data truncated). 2.836 falhas acumuladas em 59 dias.

    Fix: migration ALTER ENUM adicionando 'promo_price_change' aos
    valores aceites. CarObserver intacto (já estava semanticamente
    correcto). Down() da migration tem guard contra perda de dados.

    Decisão: failed_jobs limpa sem retry. Os 2.836 jobs eram históricos
    (25/03 → 23/05) e re-disparar produziria scores actuais com triggers
    obsoletos, não recuperaria scores históricos.

🔴 46. **IPS produz 68% de zeros — calibração suspeita (alta prioridade)** —
    Descoberto durante validação X2: nas últimas 30 dias, 578 dos 843
    scores recentes são 0 (68.6%). Apenas 4 viaturas com score 61-80,
    zero entre 41-60 e 81-100. Distribuição não saudável.

    Hipóteses: (a) IPS genuinamente reflecte stock difícil; (b) thresholds
    mal calibrados em `scoreDaysInStock`, `scoreEngagementRate`,
    `scoreContactRate` ou outros sub-scores; (c) bug no agregador.

    Investigar em sub-fase dedicada: ler `CarSalePotentialScoreService`
    e sub-métodos de scoring; analisar viaturas com score 0 manualmente;
    comparar com viaturas que efectivamente venderam.

    Bloqueia: redesign profundo do Dashboard com "probabilidade de venda"
    (mencionado em sessão anterior). Sem IPS confiável, badge
    "Probabilidade de venda X/100" é teatro.

47. ~~**`RecalculateAllCarScoresJob` cron parado há 32 dias**~~ —
    **Auto-resolvido em 2026-05-23.** Os containers foram recriados
    nesse dia e o scheduler voltou a funcionar. Confirmado em X6
    (2026-05-25): último score com `triggered_by=scheduled` é de
    2026-05-24 01:00:27. 42 scores scheduled nos últimos 7 dias.

    Atenção: o scheduler tem instabilidade nova documentada no
    item 51 — gaps inexplicáveis de horas sem o container morrer.

48. **Polling do MarketPositionCard sem estado de timeout — resolvido em 2026-05-23 (X1)** —
    Quando MAX_POLL_ATTEMPTS (18 tentativas de 10s) era atingido sem
    receber status terminal, stopPolling() era chamado mas
    setRefreshing(false) não. UI ficava em loading perpétuo até
    utilizador trocar de aba.

    Fix: novo estado `pollTimedOut` que dispara render de
    TimedOutState component com mensagem honesta em pt-PT e botão
    "Tentar novamente". setRefreshing(false) chamado em todos os
    caminhos terminais (success, timeout).

    Fragilidade adicional resolvida em 2026-05-25 (X7):
    O frontend agora persiste `aggregate_id` em sessionStorage
    e usa `GET ?aggregate_id=N` para polling e remontagem.
    Eliminado o risco de `latestOfMany()` devolver o aggregate
    errado em cenários de refresh paralelo. Ver item 50 Fix C.

49. **MarketPositionCard — race condition de navegação rápida (parcial)** —
    Resolvido em 2026-05-23 (X3): toast `success` e `startPolling()` agora
    verificam `mountedRef` antes de disparar, evitando:
    - Toast na página errada quando utilizador navega antes do POST completar
    - Interval fantasma após unmount (fazia requests sem efeito)

    Resolvido em 2026-05-25 (X7 — Fix C do item 50):
    `writeStoredAggregateId()` é chamado ANTES do guard
    `mountedRef.current` no `handleRefresh`. O aggregate_id fica
    em sessionStorage mesmo que o componente desmonte durante o
    POST. Na remontagem, o `useEffect` lê o id persistido e faz
    GET por id específico (`?aggregate_id=N`) em vez de
    `latestOfMany` — vê correctamente o aggregate `pending` e
    retoma o polling sem mostrar NeverRunState.

🔴 50. **MarketPositionCard — 4 problemas interligados de estado e infraestrutura (parcial — Fix A e Fix B resolvidos)** —
    Descoberto em 2026-05-23 (X4) durante investigação de bug
    "Analisar mercado fica 5+ minutos sem dados". Investigação revelou
    4 problemas distintos a interagir:

    **(a) Worker tem `--max-time=3600`** — reinicia de hora em hora.
    Hoje observou-se gap de 19 minutos entre 13:00 e 13:19 onde jobs
    ficaram em fila sem processamento. Se utilizador clica "Analisar
    mercado" durante esta janela, polling de 3 minutos esgota antes do
    job correr. Aggregate fica em `pending` na BD.

    **(b) Fila e cache em MariaDB, não Redis** — `QUEUE_CONNECTION=database`
    e `CACHE_STORE=database`. O `REDIS_HOST=127.0.0.1` está configurado
    mas inacessível dentro dos containers (devia ser nome do serviço
    Docker). A tabela `jobs` é o ponto único de verdade da fila — funcional
    mas mais lento e propenso a contenção do que Redis.

    **(c) GET no `useEffect.catch` produzia NeverRunState para qualquer
    erro** — incluindo timeout de rede, 5xx transitório, ou resposta mal-
    formada. Não distinguia "não existe aggregate" (legítimo NeverRun) de
    "erro de rede".

    **(d) ErrorState/FailedState não têm botão de retry no body** — só
    o botão "↻ Actualizar agora" no header. Utilizador que cai num
    aggregate `error` pode não perceber que pode tentar de novo.

    **Estado dos fixes:**

    - ✅ **Fix A** (frontend) — resolvido em 2026-05-25 (X5). Distinguir
      erro de rede de "aggregate inexistente" no `useEffect.catch`. Novo
      estado `networkError` e componente `NetworkErrorState` com botão
      "Tentar novamente". Backend devolve 200+null para "sem aggregate"
      (tratado no `.then()`), por isso ALL casos no `.catch()` são
      erros genuínos → `NetworkErrorState`. `retryFetch` re-faz o GET
      e repõe estado correctamente. `NeverRunState` mantém-se apenas
      para resposta legítima `{ data: null }` do servidor.

    - ✅ **Fix B** (backend) — resolvido em 2026-05-25 (X6).
      `MarkStaleAggregatesAsErrorJob` em `app/Jobs/`. Scheduled
      `everyFiveMinutes()` em `routes/console.php` com `withoutOverlapping()`
      e `onFailure` callback que loga em erro. Bulk update via `whereIn`.
      Marca aggregates em `pending`/`running` há mais de 10 minutos como
      `error`. Log estruturado com `count`, `ids`, `car_ids`, `statuses`
      para auditoria. Limitação conhecida (item 51): durante janelas de
      congelamento do scheduler, Fix B não corre. Aggregates podem ficar
      pendentes durante essas janelas.

    - ✅ **Fix C** (frontend + backend) — resolvido em 2026-05-25 (X7).
      `writeStoredAggregateId(carId, result.aggregate_id)` persiste o
      id em sessionStorage (chave `xplendor:mkt_agg:{carId}`, TTL 20min)
      ANTES do guard `mountedRef`. Endpoint GET aceita agora
      `?aggregate_id=N` opcional — verifica `car_id` para isolamento.
      Polling usa o id específico. Clearing automático quando status
      terminal. Fallback para `latestOfMany` se TTL expirado ou id
      ausente. 2 novos testes backend (8/8 verdes). Items 48 e 49
      resolvidos. Migration X2 corrigida para SQLite (era blocker
      de todos os testes do ficheiro).

    - ✅ **Fix C.1** (frontend) — resolvido em 2026-05-25 (X7.1).
      Bug histórico desde a Fase E (commit "fase e"), exposto pelo X7
      ao tentar usar `result.aggregate_id`. O helper `marketAggregate_helper.ts`
      usava `res.data.data` (dois níveis) mas o interceptor global de axios
      em `api_helper.ts` já desempacota `response.data`, por isso `res` = body
      JSON e `res.data` = campo `data` interior. Dois sintomas:
      (1) POST: `result.aggregate_id` lançava TypeError → catch → toast de erro
      mesmo com HTTP 202;
      (2) GET: `fetchMarketAggregate` retornava sempre `null` → NeverRunState
      em qualquer navegação, independentemente de existir aggregate.
      Fix: genérico alterado de `<{ data: T }>` para `<T>`, acesso de
      `res.data.data` para `res.data`. 5 novos testes frontend verdes.
      Bug estava silencioso antes do X7 porque o resultado do POST era
      descartado (sem acesso a `.aggregate_id`) e o NeverRunState nunca
      foi reportado.

    - ✅ **Fix D** (infra) — parcialmente resolvido em 2026-05-26. `REDIS_HOST=redis` (D2), `QUEUE_CONNECTION=redis` (D3), `CACHE_STORE=redis` (D4). Fila em Redis db=0, cache em Redis db=1. Worker `--max-time=3600` mantido (Docker `restart: always` cobre o restart horário). D6 (visibilidade do scheduler em prod) ainda pendente.

    **Relacionado com:**
    - Items 48 e 49 — todos beneficiam do Fix C
    - Item 51 — gap do scheduler afecta correr do Fix B
    - Configuração Redis vs MariaDB merece documentação na secção 3
      do CLAUDE.md

🔴 51. **Scheduler tem gaps inexplicados — alta prioridade** —
    Descoberto em 2026-05-25 (X6) durante investigação prévia ao Fix B.
    Container `xplendor-scheduler` (Up 2 days) ficou suspenso ~11h30
    entre 21:00 (2026-05-24) e 08:30 (2026-05-25). 23 invocações de
    `fetch-meta-ads-metrics` perdidas. `RecalculateAllCarScoresJob`
    das 01:00 não correu nesse dia.

    O loop `while true` que invoca `schedule:run` não morreu, apenas
    suspendeu. Docker considera o container saudável. Detecção
    silenciosa.

    Hipótese: uma invocação síncrona de `schedule:run` bloqueou
    (provavelmente um job que congela em vez de timeout), travando
    o loop.

    **Impacto:**
    - Jobs schedulados podem perder execução durante horas sem alerta
    - Fix B (X6) corre via scheduler — quando scheduler congela, Fix B
      também não corre. Aggregates podem ficar pendentes durante
      a janela de congelamento.

    **Plano de investigação (sessão dedicada):**
    - Identificar o último job antes do gap (logs de ~21:00)
    - Substituir loop `while true` por supervisor/cron robusto com
      timeout por invocação
    - Considerar `php artisan schedule:work` em vez de `schedule:run`
      em loop manual (built-in do Laravel, comportamento mais previsível)
    - Adicionar health check externo (ex: heartbeat file actualizado a
      cada invocação, monitorizar com Docker healthcheck)

    Relacionado com: item 50 Fix B (depende deste scheduler para correr).

✅ 52. **Comparação de mercado ignora preço promocional — resolvido em 2026-05-25 (Y2)** —
    Descoberto em 2026-05-25 durante validação E2E do Fix C.1.
    `MarketSnapshotService` gravava `price_gross` em `car_price_gross` no aggregate,
    ignorando `promo_price_gross`. Resultado: sinal de mercado calculado sobre o
    preço de lista mesmo quando o comprador vê um preço promocional — potencialmente
    enganador (ex: "ligeiramente acima" quando o efectivo é competitivo).

    Fix Y2:
    - Migration: `promo_price_gross` nullable decimal(10,2) em `car_market_aggregates`
    - `MarketSnapshotService::snapshotForCar()`: grava `promo_price_gross` quando
      `promo < gross && promo > 0` (mesma lógica do accessor `has_promo_price`)
    - `CarMarketAggregate::effectivePrice()`: novo método, retorna promo se activa,
      senão gross. `priceDifference()` e `priceSignal()` passaram a usar
      `effectivePrice()` em vez de `car_price_gross` directamente
    - `CarMarketAggregateResource`: `comparison.car_price` = `effectivePrice()`;
      `comparison.car_price_gross` presente apenas quando promo activa (PVP de referência)
    - `MarketPositionCard`: label "Preço promo" + linha "↑ PVP: €X" quando promo activa
    - `MarketAggregateComparison` em `types/api.ts`: `car_price_gross?: number` (opcional)
    - 5 novos testes unitários (16/16 verdes); 8/8 testes de feature verdes

    Follow-ons resolvidos na mesma sessão:
    - **Y2.1** — UX da lista de comparáveis reescrita; bug histórico do tipo
      `MarketComparable` corrigido (`brand`/`model`/`km` → `fuel`/`gearbox`/`region`/`year|null`)
    - **Y2.2** — Links mortos tratados: `search_url` pré-computado no snapshot;
      endpoint `check-link` com SSRF guard; cache in-memory TTL 60s no frontend;
      detecção validada empiricamente (HTTP 410 = expirado, HTTP 200 = activo)

---

## 15. Refactor cirúrgico — fases concluídas e roadmap

### ✅ Fases concluídas (sessão 2026-05-22)

**Fase A — Items de equipamento adicionados ao UI**
6 items novos em `cars.extras`: `EBD`, `ASR`, `Sensores de marcha atrás`, `Ar condicionado`, `Vidros eléctricos`, `Bancos rotativos`. Listas extraídas para `data/extraGroups.ts`.

**Fase B1 — Atributos de habitação essenciais**
Reestruturação do JSON `vehicle_attributes.attributes` em 3 secções: `dimensions`, `weights`, `habitation_basics` (com sub-objectos `kitchen` e `bathroom` completos). Helper `VehicleAttribute::normalizeShape()` centralizado. 16 testes verdes. Artisan command `vehicle-attributes:migrate-shape` com dry-run.

**Fase B2 — Atributos de habitação avançados**
5 secções novas (57 campos): `energy_climate`, `exterior`, `security`, `chassis_structure`, `interior_furniture`. 5 accordions extraídos para sub-componentes em `components/vehicleAttributes/`. Validação nested em `CarRequest`. 22 testes verdes.

**Fase C — Nomenclatura de camas + integração car_categories**
13 tipos de cama (11 novos visíveis + "outra" + 1 legacy preservado). Mapeamento não-destrutivo de strings antigas. `car_categories` já estava implementado de ponta a ponta. 22 testes no `VehicleAttributeNormalizationTest`.

**Fase D — API pública: Resource + filtros + correcção PII**
`CarPublicResource` substitui devolução raw de Model. **Correcção crítica:** removida exposição de `email` do seller, `vin`, `license_plate`, `internal_notes`, `role` e outros campos sensíveis. 7 filtros novos no `index`: `category`, `bed_types`, ranges de seats/length_m, `has_*`. `filters()` endpoint alargado com agregados (`count` por opção). 11 testes verdes. Status filtrado implicitamente para `active|available_soon`. Contrato documentado em `RESOURCE_SHAPE.md`.

### ✅ Fases concluídas (sessões 2026-05-23)

**F1a/b/c — Dashboard honesto**
Separação Dashboard / Insights (F1a). Visibilidade condicional do menu Insights (F1b). Eliminação completa de `/insights` (F1c-pre + F1c). Dashboard sem teatro decorativo. Item 33, 34 da dívida técnica.

**G — Item "Acções" oculto do sidebar**
Rota mantida em `allRoutes.tsx` mas item de menu removido. Página existe mas conteúdo não está pronto. Reintroduzir quando houver conteúdo accionável real. Item 35.

**H1 — Auditoria estrutural das 5 tabs de viatura**
Relatório completo guardado em `docs/auditorias/H1-tabs-viatura-2026-05-23.md`. Identificou fetch duplicado, over-fetching, chamadas API directas fora do Redux. Base para H2 e H3.

**H2a — Guard contra fetch duplicado**
Opção B (Redux guard) implementada. 3 ficheiros, +2 linhas cada, guard `if (existingId === Number(id)) return`. Item 37 (sem TTL), 38 (CarPageLayout futuro).

**H2b — Endpoint dedicado leve para Ficha**
Novo `GET /api/v1/cars/{id}/specs` + `CarSpecsResource`. Resolve over-fetching da Ficha (~20% do payload `analyticsCar` antes). 5 testes verdes. Items 39, 40.

**H3a — Eliminar "Conteúdo da semana" completo**
~1300 linhas eliminadas (647 frontend + 610 backend) + migration DROP TABLE `car_marketing_ideas` (80 registos). CarDescriptionService e VehiclePromptBuilder mantidos (uso diferente). Rota redirect inline `/cars/:id/marketing` → `/cars/:id/analytics`. Items 25 (eliminado), 41.

**H3b — Eliminar aba "Decisão de investimento" (Ads)**
822 linhas removidas (CarAdsPage 217, SmartAdsRecommendationCard 424, AudienceSuggestionCard 181). Backend Meta INTACTO (OAuth, jobs, endpoints) para reaproveitar em página futura de criação de campanhas. Rota redirect `/cars/:id/ads` → `/cars/:id/analytics`. Items 36 (resolvido), 41, 42.

**H3c — Simplificar Tráfego & Canais**
4 KPIs essenciais (Views/Leads/WhatsApp/Conversão) em vez de 10. Timeline filtrada apenas para `car_interactions` reais (timeline car 4 passou de 367 entradas para 14, -96%). `metrics.whatsapp_clicks` novo a partir de `car_interactions` (real-time) resolve sintoma do bug histórico item 1. Item 43.

**H3d — Reduzir aba "Mercado & Público" (Intelligence) a MarketPositionCard**
`CarIntelligencePage.tsx`: 477 → 85 linhas (-82%, -392 linhas). 3 ficheiros zombie eliminados (LeadRealityGapCard, SilentBuyerIntentCard, ContactPerformanceCard, -560 linhas). 13 funções helper inline removidas. ~960 linhas total. Backend intacto (4 campos viram zombies no payload, alimentam pipeline IA legacy item 29). Item 44.

**X1 — Timeout honesto no polling do MarketPositionCard**
`pollTimedOut` state + `TimedOutState` component com mensagem pt-PT "A análise está a demorar mais do que o esperado" + botão "Tentar novamente". `setRefreshing(false)` em todos os caminhos terminais. Item 48.

**X2 — Bug `triggered_by` ENUM `'promo_price_change'`**
2.836 falhas acumuladas em 59 dias (25/03 → 23/05). Migration ALTER ENUM adicionando valor, com guard no `down()` contra perda de dados. `failed_jobs` limpa sem retry. Items 45 (resolvido), 46 (🔴 novo), 47 (auto-resolvido depois em X6).

**X3 — Race condition zombie state no MarketPositionCard**
`mountedRef = useRef(true)` + guard `if (!mountedRef.current) return` após cada `await` em `handleRefresh`. Evita toast/interval fantasma em navegação rápida. Item 49.

**X4 — Investigação profunda "5 minutos sem dados"**
Não foi sub-fase de implementação. Investigação revelou 4 problemas interligados no MarketPositionCard (worker `--max-time`, fila em MariaDB, GET catch, ErrorState sem retry). Documentado como item 50 com 4 fixes propostos (A, B, C, D).

**X5 — Fix A do item 50: distinguir erro de rede de aggregate inexistente**
Novo estado `networkError` + `NetworkErrorState` component. Distinguir 404/null (legítimo NeverRun) de erro de rede/servidor no `useEffect.catch`. +44/-3 linhas. Item 50 Fix A ✅.

**X6 — Fix B do item 50: scheduler para marcar aggregates zombie**
Novo `MarkStaleAggregatesAsErrorJob` em `app/Jobs/`. Scheduled `everyFiveMinutes()` com `withoutOverlapping()` e `onFailure` callback. Marca aggregates em `pending`/`running` há mais de 10 minutos como `error`. Investigação prévia confirmou item 47 auto-resolvido em 2026-05-23 e revelou novo item 51 (gap do scheduler de 11h30). Item 50 Fix B ✅. Item 51 🔴.

**X7 — Fix C do item 50: persistir aggregate_id em sessionStorage**
Backend: endpoint `GET /market-aggregate?aggregate_id=N` com verificação `car_id` para isolamento (query param opcional, backward compatible). Frontend: `writeStoredAggregateId()` ANTES do guard `mountedRef` em `handleRefresh`; `readStoredAggregateId()` + `clearStoredAggregateId()` no `useEffect`, polling, e `retryFetch`; TTL 20 min; chave `xplendor:mkt_agg:{carId}`; fallback para `latestOfMany` se TTL expirado. 2 novos testes backend + asserção `aggregate_id` em teste existente (8/8 verdes). Migration X2 corrigida para SQLite (bloqueava todos os testes do ficheiro). Items 48 e 49 ✅. Item 50 Fix C ✅.

**X7.1 — Correcção do mapeamento de resposta no helper**
Bug histórico desde a Fase E (commit "fase e", 2026-05-22), exposto pelo X7 ao tentar usar `result.aggregate_id`. O helper `marketAggregate_helper.ts` usava `res.data.data` mas o interceptor de axios em `api_helper.ts` já desempacota `response.data` — portanto `res` = body JSON e `res.data` = campo `data` interior. Sintomas produção: (1) toast de erro mesmo com HTTP 202 de sucesso; (2) NeverRunState em qualquer navegação com aggregate existente. Fix: genérico de `<{ data: T }>` para `<T>`, acesso de `res.data.data` para `res.data` (2 funções). 5 novos testes frontend com mock da saída do interceptor. Item 50 Fix C.1 ✅.

**Y1.1 — useIsMobile hook + LeadList mobile**
Hook `web/src/hooks/useIsMobile(breakpoint)` partilhado, com `useState` inicial + `addEventListener("resize")`. `CarList` migrado de estado local para `useIsMobile(680)`. `LeadList` recebia sempre layout tabular; passou a receber `mobileMode={useIsMobile(680)}` — tabela de 6 colunas colapsa em cards abaixo de 680px.

**Y1.2 — CarPageNav overflow scroll em mobile**
Substituído `flexWrap: "wrap"` por `flexWrap: "nowrap" + overflowX: "auto"` no container de tabs. Adicionado `flexShrink: 0` em cada link para evitar compressão dos labels com badges. Tabs ficam sempre numa única linha e fazem scroll horizontal em viewports estreitos.

**Y2 — Preço promocional na comparação de mercado (item 52)**
Migration `promo_price_gross` nullable em `car_market_aggregates`. Novo método `effectivePrice()` no model. `priceDifference()` e `priceSignal()` usam preço efectivo (promo se activo, senão gross). Resource emite `comparison.car_price_gross` apenas quando promo activa. UI: label "Preço promo" + linha "↑ PVP: €X" no MetricBox. 5 novos testes unitários (16/16). Item 52 ✅.

**Y2.1 — UX da lista de comparáveis + correcção do tipo MarketComparable**
`ComparablesList.tsx` reescrito. Chips de combustível/caixa/região/ano traduzidos para pt-PT (omitidos silenciosamente quando null). Diferença percentual `(price - effectivePrice) / effectivePrice` colorida (verde/vermelho). Substituída props `carPrice` por `effectivePrice` (usa `comparison.car_price` da Resource, não `car_price_gross`). Texto do contador alterado para "Análise baseada em N anúncios · Standvirtual" + subtítulo "A mostrar os N mais próximos da mediana".

**Bug histórico corrigido:** `MarketComparable` em `types/api.ts` declarava `brand`, `model`, `km` — campos que nunca existiram na resposta do backend (desde Fase E3a). O backend envia `title`, `fuel`, `gearbox`, `region`, `year | null`. Interface corrigida; frontend passa a consumir e renderizar todos estes campos.

**Y2.2 — Links mortos do Standvirtual: search_url + check-link + cache**
Migration `search_url` nullable text em `car_market_aggregates`. `MarketSnapshotService::buildSearchUrl()` pré-computa URL de pesquisa (marca/modelo/ano±1/combustível, mesmos query params do scraper Python) no momento do snapshot. `CarController::checkMarketLink()`: endpoint `GET /market-aggregate/check-link?url=` — proxies HEAD para Standvirtual, devolve `{ available: bool }`; SSRF-guardado a `https://www.standvirtual.com/`; fail-open (timeout/5xx → `available: false`). `CarMarketAggregateResource` inclui `search_url`. `checkMarketLink()` adicionado ao helper. `ComparablesList` recebe `companyId`, `carId`, `searchUrl`; clique em `↗` verifica link via endpoint com cache `useRef<Map<string, {available, expiresAt}>>` TTL 60s; link morto → abre `searchUrl` + toast informativo.

Detecção validada empiricamente (2026-05-25) com URLs reais da BD: listagem activa → HTTP 200; expirada/vendida → HTTP 410 Gone.

### ✅ Fases concluídas (sessão 2026-05-26)

**Z1.a — Fix reorder FilePond**
Removido `setFiles(nextFiles)` do handler `onreorderfiles`. Causa: `react-filepond` só define `allowFilesSync=false` no `onupdatefiles`, não no `onreorderfiles` — chamar `setFiles` forçava re-render e FilePond re-processava os itens como novos uploads. Fix: uma linha removida. O handler passa a chamar apenas `syncFormikFromPond(nextFiles)`.

**Z1.b — Sentinel `existing_images_present` (fix delete-all-images)**
FormData não consegue representar um array vazio — sem o sentinel, zero iterações de `forEach` = chave ausente no request, e o backend interpretava como "não tocar nas imagens". Sentinel `existing_images_present=1` distingue "lista vazia intencional" de "chave ausente". 3 ficheiros alterados (frontend, backend service, backend validation). 3 testes feature (3/3 verdes).

**Z2.a — Preservar original + aceitar crop params no upload**
Migration `original_path nullable text` em `car_images`. `CarImageService::handleUploads` sempre guarda o original em `originals/` (extensão real, sem conversão) e aplica `->crop(w,h,x,y)->toWebp(85)` quando crop params presentes. `CarImage.$fillable` actualizado. `CarService` store/update persistem `original_path`. `CarRequest` valida `images_meta.*.crop.{x,y,width,height}`. `CarSpecsResource` expõe `original_path`. 3 testes feature (3/3 verdes).

**Z2.b — Crop modal 16:9 no upload (react-easy-crop)**
`react-easy-crop@5.5.7` instalado (8.3 KB gzip). `ImageCropperModal`: proporção 16:9 obrigatória, zoom slider, rotate esquerda/direita, pt-PT, sem botão "Ignorar" (modal sempre confirma). `CarImagesDataFields`: fila sequencial de crop (um modal de cada vez para selecções múltiplas), `cropParamsMapRef` (ref evita stale closures em `syncFormikFromPond`), ref ao FilePond para remoção programática ao cancelar. `buildCarFormData` serializa `images_meta[i][crop][x/y/width/height]`. `CarStoredImage` e `CarImageMeta` actualizados com `original_path` e `crop`.

**Z2.c — Endpoint de re-crop + UI + testes**
`POST /api/v1/companies/{id}/cars/{carId}/images/{imageId}/recrop` — aplica novo corte ao original preservado, sobrescreve a imagem cortada em storage. Validação de tenant (car_id + company_id). 422 se `original_path IS NULL` com mensagem pt-PT. `CarImageService::recrop()` + `CarService::recropImage()` + `CarController::recropImage()`. UI: secção "Editar cortes" abaixo do FilePond pond com thumbnails 16:9 das imagens stored que têm `original_path`; ícone de tesoura em hover; abre `ImageCropperModal` com a imagem original; após sucesso, bust-cache do thumbnail com `?v=timestamp`. 4 testes feature + 3 do Z2.a = 7/7 verdes.

**Nota sobre re-crop e FilePond:** o tile do FilePond não reflecte o novo corte até refresh da página. Apenas o thumbnail na secção "Editar cortes" é actualizado via cache-bust. Comportamento aceite na v1 — documentado aqui para futuro.

**Nota sobre imagens legadas (pré-Z2.a):** `original_path = NULL` — botão de re-crop não aparece. Zero retroactividade. Documentado.

**D2/D3/D4 — Redis correctamente configurado**
`REDIS_HOST=127.0.0.1` → `REDIS_HOST=redis` (D2). `QUEUE_CONNECTION=redis` (D3). `CACHE_STORE=redis` (D4). Fila e cache migradas de MariaDB para Redis. `.env.example` actualizado nos 3 commits correspondentes. Worker e scheduler reiniciados a seguir a cada mudança. Validado: `redis-cli PING` → `PONG` em ambas as connections; jobs processados em <1s; `Cache::put/get` OK.

**Y3.a — CarAnalyticsHeader trim mobile**
Abaixo de `md` (768px), ocultos via `d-none d-md-inline` / `d-none d-md-inline-flex`: data de publicação, matrícula, badge IPS (numérico), badge IPS (classificação), badge urgência IA, badge alerta de preço. Botão "Editar viatura" passa a mostrar apenas ícone em mobile (`d-none d-md-inline` no texto). `text-truncate` + `minWidth: 0` adicionados ao `h5` para marcas longas ("Mercedes-Benz Classe E") em viewports estreitos.

**Y3.b — CarList filtros off-canvas**
Abaixo de 768px, a sidebar de filtros (`Col xl={3} lg={4}`) é substituída por um Offcanvas (Reactstrap, desliza da esquerda, `scrollable`, ESC fecha, backdrop fecha). Botão "Filtros (N)" aparece na toolbar apenas quando `isFiltersMobile`. `activeFilterCount` conta filtros activos excluindo `statusFilter === "active"` (estado default). `filterPanelContent` extraído para const reutilizado em desktop e Offcanvas. `handleClearFilters` extraído de inline para função nomeada.

**Y3.c — Dashboard signal cards grid + SummaryDashboard border switch**
`ActionRequiredCarsDashboard`: signal cards passam de `d-flex flex-wrap` para `row g-2` com `col-6 col-lg-3` — grid 2×2 em mobile/tablet e 4×1 em desktop. `minWidth: 78` removido (Bootstrap gere a largura). `key` movido para o wrapper `col-6`. `SummaryDashboard`: `useIsMobile(1200)` + `isCompact` — abaixo de 1200px as separações passam de `borderRight` para `borderBottom`, eliminando linhas verticais órfãs no layout 2×2 (`col-md-6`).

### 🚧 Próximo

**Mês 1 — fundações de tipagem e dados**
- `web/src/types/api.ts` com interfaces baseadas nas Resources
- Migrar `CarAnalytics`, `CarAdsPage`, `CarIntelligencePage` para tipos próprios
- Corrigir bug histórico `whatsapp_clicks`
- Layout component `CarPageLayout` para resolver fetch duplicado nas 5 tabs

**Mês 2 — organização e robustez**
- Partir `laravel_helper.ts` em helpers por recurso
- Adoptar padrão único de chamadas API (Redux thunks)
- Error Boundary global + integração com logging
- Lazy loading de rotas em `allRoutes.tsx`
- Rate limiting `/api/v1/ai/*` por `company_id`

**Mês 3 — produto e estabilidade**
- Extrair tokens Velzon para CSS variables próprias
- Smoke tests Python do scraper
- GitHub Actions com lint + tests em PRs
- Investigar e corrigir `subsegment NULL` em motorhomes
- Decidir destino do campo `lifestyle` zombie

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
- **Não devolver Models directamente da API** — sempre via Resource (princípio aplicado e enforced na Fase D)
- **Não pôr business logic em Controllers** — vai para Services
- **Não usar `any` em código novo**
- **Não criar migrations sem `down()`** implementado
- **Não fazer mass assignment** sem `$fillable` ou `$guarded` definidos
- **Não confiar no frontend** para guards de role/permissão — sempre validar no backend
- **Não aceitar `company_id` como parâmetro de request** de utilizador autenticado — derivar do auth
- **Não fazer upgrade major do Velzon** sem análise de impacto
- **Não usar emojis ou pt-BR** em conteúdo voltado ao utilizador
- **Não nomear concorrentes pelo nome** em material público (landing, conteúdos) — usar "marketplaces"
- **Não carregar `with(['seller'])` em endpoints públicos** — expõe PII do User
- **Não expor `vin`, `license_plate`, `internal_notes`, `email` do seller** na API pública
- **Não adicionar filtros novos na API pública sem actualizar `RESOURCE_SHAPE.md`** — é o contrato com os sites externos
- **Não correr `vehicle-attributes:migrate-shape --force`** sem aprovação explícita — migração destrutiva
- **Não fazer breaking changes na API pública sem coordenar deploy** com actualização dos sites React dos clientes

---

## 17. Workflow de tarefas

Ao receber uma tarefa, seguir esta ordem:

1. **Ler este CLAUDE.md** (pelo menos secções 2, 8, 14, 15, 16)
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

### Quando trabalhar na API pública

- **Sempre actualizar `RESOURCE_SHAPE.md`** no mesmo PR
- **Sempre validar** se a alteração quebra os sites React externos dos clientes
- **Se quebrar**, coordenar deploy: actualizar sites primeiro, depois deploy do backend
- **Nunca expor** campos da lista da secção 8.2

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
- **Habitação** — equipamento da célula da autocaravana (cozinha, casa de banho, camas, energia, exterior)
- **Trade-in** (`is_trade_in`) — viatura entregue em retoma. Coluna na BD ainda é `is_resume`; renomeado apenas na API pública.

---

## 20. Documentos relacionados

- `XPLENDOR-Manual-Reposicionamento.md` — manual comercial da agência (pricing, contrato-tipo, scripts de venda)
- `app/Http/Resources/Public/RESOURCE_SHAPE.md` — contrato da API pública (formato de resposta, breaking changes)
- Auditoria estrutural completa (2026-05-22) — relatório base que deu origem a este documento

---

*Fim do documento. Manter actualizado.*