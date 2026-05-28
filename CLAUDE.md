# XPLENDOR — Project Guide (CLAUDE.md)

> **Documento de leitura obrigatória antes de qualquer tarefa.**
> Define o que existe, como está estruturado, e que decisões já estão tomadas.
> Se este documento contradiz o código, **o documento ganha** — abrir issue antes de seguir o código.
>
> Última actualização: 2026-05-28 · Versão 1.9.10

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
| 1.6 | 2026-05-26 | Fix D completo (D1 investigação infra, D2 REDIS_HOST, D3 fila Redis, D4 cache Redis, D6 scheduler output visível em prod). Y3 mobile: Y3.a (CarAnalyticsHeader trim), Y3.b (CarList filtros off-canvas), Y3.c (Dashboard grid + SummaryDashboard borderBottom). |
| 1.7 | 2026-05-26 | Y3.d mobile cards: Y3.d.0 (fix overlap desktop /cars), Y3.d.1 (renderMobileCard prop XTanStackTable), Y3.d.2 (car mobile card com imagem 16:9 + chips + nav), Y3.d.3 (lead mobile card com avatar + status select + acções), Y3.d.4 (UsersList migração useIsMobile). |
| 1.7.1 | 2026-05-26 | Y3.d.5 — Fix regressão Y1.2: `minWidth: 0` no CarPageNav outer div elimina propagação de min-content para o flex parent, que causava overflow horizontal em todas as rotas `/cars/:id/*` em viewports < ~500px. |
| 1.7.2 | 2026-05-26 | Y3.d.6 — Fix overflow residual em CarAnalytics: margin negativa do Bootstrap `.row.g-3` nos KPIs excedia padding do pai em viewports mobile; `overflow: hidden` no d-grid wrapper resolve. |
| 1.8 | 2026-05-26 | **DOCS** — Revisão integral do CLAUDE.md. Schema actualizado (`car_market_aggregates`, `car_images`, `original_path`, `search_url`, `promo_price_gross`). 3 endpoints documentados em 8.4 (recrop, check-link, GET por `aggregate_id`). Padrões mobile e aprendizagens CSS documentados em 10. Items resolvidos compactados em tabela (14.2). Secção 15 reorganizada por capítulos Z, D, Y3. |
| 1.8.1 | 2026-05-26 | Y4.a — `LeadStatusBadge` component (substitui `<select>` HTML nativo na coluna Estado de LeadList, desktop e mobile card). Dropdown Reactstrap com soft badges Bootstrap. |
| 1.8.2 | 2026-05-26 | Y4.c — Fix scroll fantasma vertical (~538px em todas as telas autenticadas). Override do `min-height: 1400px` do Velzon para `min-height: 100vh` via `_xplendor-overrides.scss`. |
| 1.8.3 | 2026-05-26 | Y4.b — Login polish: textos pt-PT, alert de erro funcional (msg genérica), ícones nos inputs (ri-mail-line + ri-lock-2-line), loading state no botão, eye toggle dentro do InputGroup. |
| 1.9.0 | 2026-05-26 | Fase F — Bug crítico de scores zombie no sync Carmine resolvido (F.2/F.3). Casts adicionados ao Car model (price_gross, is_resume, is_metallic, etc.). updated_at removido do payload Carmine. Comparação antes de update no CarmineConnectionService. |
| 1.9.1 | 2026-05-27 | M1 — Feedback Matilde: label "Baterias célula" (era "celular"), enum shower_type `separate` → `independent` (label "Independente"). 7 alterações em 6 ficheiros. BD em prod vazia neste campo. |
| 1.9.2 | 2026-05-27 | M1.c — shower_type expandido para 3 tipos distintos: `separate` (cabine dentro WC), `independent` (cabine fora WC), `combined` (sem cabine). Removido `none` — ausência do campo = sem duche. |
| 1.9.3 | 2026-05-27 | M2 — Energia avançada: `solar_panel_count` (1-10) + `inverter_type` (`pure_sine`/`modified_sine`). Layout lado-a-lado no accordion Energia e Aquecimento. |
| 1.9.4 | 2026-05-27 | M3.preview — Toast de erros 422 em CarCreate/CarUpdate. Helper genérico `showApiErrorToast` em `web/src/helpers/error_helper.ts`. |
| 1.9.5 | 2026-05-27 | M3 — Exterior: `has_external_ladder` + objecto aninhado `garage` com 4 booleanos. |
| 1.9.6 | 2026-05-27 | M4 — Cozinha: `has_extending_counter` ("Acrescento de banca"), 3.º checkbox na linha do Fogão/Forno. |
| 1.9.7 | 2026-05-27 | M5 — Sala: nova secção `living_room` no JSON + novo accordion `LivingRoomAccordion.tsx` (accordionId=9). |
| 1.9.8 | 2026-05-27 | P0 — Fix label 'Cilindros' (era 'Cilindradas') + campo oculto para motorhome/caravan. |
| 1.9.9 | 2026-05-28 | API Pública — viaturas `reserved` expostas publicamente + unificação da fonte de status (`CarPublicRepository::PUBLIC_STATUSES`, public const). Corrigida duplicação entre Repository e Controller `show()`. RESOURCE_SHAPE.md e secção 8.1 actualizados. |
| 1.9.10 | 2026-05-28 | API Pública — ordenação da listagem por status (`FIELD(status, 'active', 'available_soon', 'reserved', 'sold')`) como critério primário; orderBy do request desempata. Só na listagem, não no `show()`. |

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

### Estado Redis (clarificação)

- **Dev:** sempre usou Redis para queue e cache.
- **Prod:** migrado para Redis em 2026-05-26 (Fix D). Anteriormente usava `QUEUE_CONNECTION=database` e `CACHE_STORE=database` porque `REDIS_HOST=127.0.0.1` era inacessível dentro dos containers Docker (devia ser o nome do serviço, `redis`).
- Convenção actual: fila em Redis db=0, cache em Redis db=1.
- Worker mantém `--max-time=3600` (restart horário coberto por Docker `restart: always`).
- Scheduler em prod com output visível desde D6 (`docker logs xplendor-scheduler`).
- Quando alterar `.env` em produção, **correr `php artisan config:cache`** — restart de workers não chega (descoberto em D2).

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
| `car_market_aggregates` | Snapshot agregado de mercado por viatura (Fase E2) | `mediana`, `confidence`, `price_signal`, `promo_price_gross` (Y2), `search_url` (Y2.2), `status` (`pending`/`running`/`done`/`error`). Persiste o mais recente; histórico noutras tabelas se necessário |
| `car_images` | Imagens por viatura | `image` (path cortada em formato WebP), `original_path` (Z2.a, nullable, preserva original em `originals/`) |
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
      "has_extending_counter": true,
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
      "shower_type": "independent",
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
    "solar_panel_count": 2,
    "solar_panel_watts": 150,
    "has_inverter": true,
    "inverter_type": "pure_sine",
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
    "has_hubcaps": true,
    "has_external_ladder": true,
    "garage": {
      "has_garage": true,
      "has_double_opening": true,
      "is_spacious": true,
      "has_height_adjuster": true
    }
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
  "living_room": {
    "layout": "face_to_face",
    "has_extending_table": true
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
- `shower_type`: `separate` | `independent` | `combined` (ausência = sem duche)
- `inverter_type`: `pure_sine` | `modified_sine`
- `water_heater_source` / `ambient_heating_source`: `electric` | `gas` | `diesel` | `none`
- `chassis_type`: `standard` | `alko` | `other`
- `upholstery_state`: `good` | `fair` | `worn` | `replaced`
- `living_room.layout`: `face_to_face` | `l_shape` | `panoramic`

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

**Status visíveis publicamente:** `active`, `sold`, `available_soon`, `reserved` (fonte única: `CarPublicRepository::PUBLIC_STATUSES`). Valores `draft` e `inactive` são filtrados. O site externo decide como renderizar cada status (ex: badge 'Reservado' para reserved, 'Vendido' para sold).

**Ordenação da listagem pública:** critério primário por status via `FIELD(status, 'active', 'available_soon', 'reserved', 'sold')` em `applyOrder()` — viaturas disponíveis aparecem antes das vendidas. O `orderBy`/`orderDirection` do request (default `created_at asc`) fica como desempate dentro de cada grupo de status. Aplica-se apenas à listagem (`getPublicCars`), não ao `show()`.

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

### 8.4 Endpoints internos relevantes adicionados em 2026-05

Documentados aqui porque foram introduzidos em sub-fases recentes e ainda não estavam reflectidos no documento.

**Re-crop de imagem (Z2.c):**
`POST /api/v1/companies/{companyId}/cars/{carId}/images/{imageId}/recrop`
Aplica novo corte ao `original_path` preservado, sobrescreve a imagem cortada em storage. Validação de tenant (`car_id` + `company_id`). 422 se `original_path IS NULL` com mensagem em pt-PT (imagens legadas pré-Z2.a não têm botão de re-crop no UI).

**Verificar link de pesquisa Standvirtual (Y2.2):**
`GET /api/v1/companies/{companyId}/cars/{carId}/market-aggregate/check-link?url=`
Proxies HEAD para Standvirtual, devolve `{ available: bool }`. SSRF-guardado a `https://www.standvirtual.com/`; fail-open (timeout/5xx → `available: false`). Cache in-memory `useRef<Map>` TTL 60s no frontend para evitar polling.

**GET aggregate por id específico (X7 Fix C):**
`GET /api/v1/companies/{companyId}/cars/{carId}/market-aggregate?aggregate_id=N`
Query param `aggregate_id` opcional. Quando presente, devolve o aggregate específico (com verificação de `car_id` para isolamento). Sem o param, devolve `latestOfMany` (comportamento legado, ainda usado em alguns paths). Usado pelo polling do MarketPositionCard com `aggregate_id` persistido em sessionStorage (chave `xplendor:mkt_agg:{carId}`, TTL 20 min).

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

**Estado actual do fetch duplicado:** mitigado em 2026-05-23 (H2a) com guard Redux (`if (existingId === Number(id)) return`) e endpoint dedicado leve para Ficha (`H2b`, `GET /cars/{id}/specs`). Falta TTL/invalidação por tempo — item 37 da dívida técnica. Layout component com `Outlet` (resolução estrutural completa) — item 38.

### Formulário de viatura (`CarCreate` / `CarUpdate`)

Suporta tipos: carro, moto, motorhome, caravana.

**Para motorhome e caravan:** 9 accordions de atributos de habitação (Fases B1 + B2 + M5):
1. Dimensões e Pesos (B1)
2. Cozinha (B1)
3. Casa de Banho (B1)
4. Energia e Aquecimento (B2)
5. Exterior (B2)
6. Segurança e Fechaduras (B2)
7. Chassis e Estrutura (B2)
8. Mobiliário Interior (B2)
9. Sala (M5)

Accordions 4-9 vivem em `web/src/pages/Cars/Car/components/vehicleAttributes/` como sub-componentes próprios. Accordions 1-3 estão inline em `CarVehicleDetailsDataFields.tsx` (dívida técnica registada).

### Velzon — como tratar

- Velzon é tema Bootstrap 5 comercial. As classes (`fs-11`, `fw-semibold`, `bg-light-subtle`, `text-primary`) **são do tema, não do nosso design system**
- **Não criar mais `sectionStyle` inline.** Já está duplicado em vários ficheiros — extrair para componente `Section` ou `Card` próprio
- Não fazer upgrade major do Velzon sem análise de impacto (risco alto)
- Médio prazo: extrair tokens (cores, espaçamentos, border-radius) para CSS variables próprias

### Padrões mobile (Fase Y3, 2026-05-26)

**Hook `useIsMobile(breakpoint=768)`** em `web/src/hooks/useIsMobile.ts`. Substituiu `useState(window.innerWidth < N)` em vários sítios (que não actualizava em resize). Breakpoints recomendados: 680px para listas/tabelas, 768px para sidebars/headers, 1200px para grids complexos.

**Off-canvas para filtros em mobile** (CarList, Y3.b): abaixo de 768px, a sidebar de filtros vira `Offcanvas` (Reactstrap) com botão "Filtros (N)" na toolbar. `activeFilterCount` exclui defaults (ex: `statusFilter === "active"`). Pattern aplicável a qualquer lista com sidebar de filtros.

**Prop `renderMobileCard` no XTanStackTable** (Y3.d.1): quando `mobileMode=true` e a prop está presente, substitui o bloco genérico label/valor por render customizado. Retro-compatível — tabelas sem a prop mantêm o comportamento anterior. Usado em CarList e LeadList.

**Tap-no-card vs botões filhos** (Y3.d.2): cards mobile com acção principal e botões secundários precisam de `stopPropagation` nos botões para evitar duplo trigger. Pattern: navegação dominante no card inteiro, acções específicas nos botões com `e.stopPropagation()`.

### Aprendizagens CSS (Y3.d, 2026-05-26)

Dois bugs descobertos no mesmo dia através de debugging empírico no browser. Documentar aqui para evitar repetir.

**Flex containers + min-content em Bootstrap (Y3.d.5):** num flex container com `flexWrap: nowrap` e filhos com `flexShrink: 0`, o `min-content width` do container propaga-se para o `Col` pai (que tem `min-width: auto` por defeito), inflando o `scrollWidth` da página. **Solução:** `minWidth: 0` no container flex resolve a propagação. Aplicado em Y3.d.5 ao `CarPageNav` (regressão introduzida pelo Y1.2).

**Bootstrap `.row.g-3` margin negativa (Y3.d.6):** `<Row className="g-3">` tem `margin-x: -8px` cada lado (-16px total) para compensar padding das colunas. Em viewports estreitos, esse bleed pode exceder o padding do pai e causar overflow horizontal. **Solução:** `overflow: hidden` no wrapper directo da Row. Aplicado em Y3.d.6 ao `CarAnalytics` (KPI row).

**Debug de overflow horizontal:** análise estática não basta — testar empiricamente no Console do DevTools com `findOverflow()`:

```javascript
(function(){const v=innerWidth;const r=[];function w(e){
  if(e.scrollWidth>v+1)r.push({tag:e.tagName,sw:e.scrollWidth,
    ow:e.offsetWidth,class:(e.className?.toString()||'').slice(0,60)});
  for(const c of e.children)w(c)}w(document.body);
  console.table(r);return r;})();
```

Lê todos os elementos cuja `scrollWidth > viewport`. O primeiro na ordem DOM é a causa-raiz. Validar fix com `document.documentElement.scrollWidth === window.innerWidth`.

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

## 14. Dívida técnica conhecida

Esta secção foi reorganizada na revisão 1.8 (DOCS, 2026-05-26): itens activos no topo, itens resolvidos compactados em tabela no fim, aprendizagens de processo em sub-secção própria. **Para detalhe histórico de cada item resolvido, ver a secção 15 onde a sub-fase correspondente está documentada.**

### 14.1 Itens activos

#### 🔴 Alta prioridade — atacar no próximo trimestre

1. **TypeScript `any` generalizado** — bloqueia refactor seguro. Criar `web/src/types/api.ts` progressivamente. Tipos derivam das API Resources do Laravel.

2. **Sem testes para lógica de negócio crítica** — `CarAiAnalysesService::enforceCampaignDiagnosisRules()`, `DashboardRepository::groupCarsByPersona()`, `ScraperService::normalizeFilters()`, `AggregateCarPerformanceMetricsJob`.

3. **Duas fontes de verdade paralelas para "posição no mercado"** — `CarMarketIntelligenceService` (legacy, lê de `car_market_snapshots`) vs `CarMarketAggregate` (E2, tabela própria). Pipeline IA ainda usa o legacy. Migrar numa sub-fase dedicada após capítulo E estabilizar (3-5 dias em produção). Envolve:
   - Substituir leitura de `car_market_snapshots` por `latest_market_aggregate` nos 5 services dependentes
   - Re-treinar prompts da IA que assumem `market_intelligence.market_position`
   - Validar que `CarDecisionEngineService` continua a produzir scoring consistente
   - Eventualmente eliminar `CarMarketIntelligenceService.php`

4. **Limpeza de scores zombie em `car_sale_potential_scores`** (baixa prioridade) — ~56k registos históricos com `triggered_by='price_change'` e `score=0`, criados pelo bug F.2/F.3 antes do fix de 2026-05-26. Bug resolvido. Carmine sem clientes activos. Limpar em sessão dedicada se tabela atingir tamanho problemático. Distribuição actual saudável (investigação F.1): 19% zeros, 66% no meio, 12% altos — IPS não bloqueia dashboard.

5. **Scheduler tem gaps inexplicados (alta prioridade)** — container ficou suspenso 11h30 entre 21:00 (2026-05-24) e 08:30 (2026-05-25). Loop `while true` que invoca `schedule:run` não morreu, apenas suspendeu. Detecção silenciosa. Hipótese: invocação síncrona de `schedule:run` bloqueou. **Fase E planeada** com logs visíveis desde D6. Plano:
   - Identificar último job antes do gap
   - Substituir loop por supervisor/cron robusto com timeout por invocação
   - Considerar `php artisan schedule:work` (built-in)
   - Health check externo (heartbeat file + Docker healthcheck)

6. **Bug histórico `whatsapp_clicks`** — métricas erradas 2026-03-06 a 2026-03-13. Remediação: `php artisan performance:aggregate --from=DATE --to=DATE --sync` para cada dia. Mitigado em parte: H3c passou a usar `car_interactions` (real-time) no campo `metrics.whatsapp_clicks` da Tráfego & Canais, evitando o agregado bug.

#### 🟡 Média prioridade

7. **Fetch duplicado em tabs de viatura — guards sem TTL** — H2a/H2b resolveram fetch repetido na mesma sessão. Mas se o utilizador deixa a tab aberta horas, vê dados stale. Adicionar TTL ou invalidação por evento quando relevante (item antigo 37).

8. **`CarPageLayout` com `Outlet` não implementado** — Opção A da H2a (nested routes) é o design correcto a longo prazo. Requer suporte a nested routes em `Routes/index.tsx`, refactor de `CarAnalyticsHeader` para ler do Redux, e `CarPageNav` para `useLocation()`. Atacar em sessão dedicada à página de viatura (item antigo 38).

9. **`CarSpecsResource` faz 2 queries extra no `toArray()`** — H2b optou por simplicidade (queries directas a `CarSalePotentialScore` e `CarAiAnalysis` dentro do Resource). Aceitável para escala actual (1 viatura por request). Optimizar para eager load quando houver bottleneck (item antigo 39).

10. **`CarAnalyticsHeader` recebe `car` como objecto plano sem tipo** — props definidas como `any`. Após H2b, a Ficha passa adapter `carForHeader` construído a partir de `CarSpecs`. Tipar com interface própria (item antigo 40).

11. **Toast de erros 422 só em CarCreate/CarUpdate** — M3.preview implementou em escopo mínimo via `showApiErrorToast` em `helpers/error_helper.ts`. Outros forms (Leads, Users, Companies, Blogs) continuam silenciosos perante 422. Alargar quando houver feedback de bug ou em sessão dedicada de UX.

12. **Backend gera `action.label`, `action.suggestion`, `problem` em `immediate_actions`** que o frontend já não renderiza (após F1c). Continuam a ser gerados (custo potencial de IA) mas não aparecem. Decidir destino quando voltarmos com lógica nova (item antigo 34).

13. **`sessionStorage` com dados de utilizador** — avaliar mover para HTTP-only cookie. Tem implicações de auth, não trivial.

14. **`laravel_helper.ts` a crescer sem organização** — partir em helpers por recurso.

15. **Sem lazy loading de rotas React** — implementar `React.lazy()` + `Suspense` em `allRoutes.tsx`.

16. **Sem rate limiting nas rotas de IA** — adicionar throttle por `company_id` para proteger custos OpenAI.

17. **`classifyPersona()` acoplado a strings raw de `segment`** — encapsular num enum/Value Object.

18. **Sem Error Boundary React global** — adicionar e ligar a logging.

19. **B1 accordions inline em `CarVehicleDetailsDataFields.tsx`** — Os 3 accordions da Fase B1 (Dimensões/Pesos, Cozinha, Casa de Banho) estão inline no parent. Os 5 da B2 foram extraídos para sub-componentes. Alinhar B1 com o padrão.

20. **`subsegment` NULL em todos os motorhomes** — campo existe no schema mas não está a ser gravado pelo UI. Investigar.

21. **Catch silencioso em `CarDescriptionDataFields.tsx`** — `catch {}` apenas mostra toast genérico. Propagar mensagem de erro real quando disponível.

22. **Validação min/max em campos numéricos do `vehicle_attributes`** — backend valida (min 0.1 m para `length_m`), mas car 57 histórico tem `length: -0.1745`. Limpar registo manualmente.

23. **Originais não eliminados em delete de imagem (Z2.a)** — `CarService::update()` elimina o ficheiro em `images/` mas não o correspondente em `originals/`. Orphaned originals acumulam. Corrigir quando houver limpeza de storage agendada.

24. **Re-crop não actualiza tile do FilePond (Z2.c)** — thumbnail da secção "Editar cortes" actualiza via `?v=timestamp`, mas o tile no FilePond pond mantém cached preview até refresh. Complexo dado o modelo de estado do react-filepond. Adiar para v2 do cropper.

#### 🟢 Baixa prioridade

25. `document.title` mutado directamente — substituir por hook `useDocumentTitle`.
26. Sem CI/CD — adicionar GitHub Actions para tests + lint em PRs.
27. Sem índices em `car_performance_metrics(car_id, period_start)`.
28. `ScraperExecution.company_id` nullable (legacy) — normalizar quando seguro.
29. `sectionStyle` inline duplicado — extrair `<Section>` / `<Card>` próprios.
30. **`lifestyle` campo zombie** no `cars` — sem cast, sem UI, nunca preenchido. Remover ou implementar.
31. **Queries em JSON sobre `vehicle_attributes.attributes` não usam índices.** Aceitável até ~5.000 viaturas por empresa. Adicionar índices funcionais (MariaDB) para `length_m`, `has_solar_panel`, slugs de `beds` se necessário.
32. **Divergência seeder vs DB nas `car_categories`** — seeder tem "Capucine", BD tem "Capucino"; seeder tem 7 categorias, BD tem 4. Alinhar seeder com realidade, sem alterar slugs em uso.
33. **`car_market_aggregates` não tem `scraped_at`** — `updated_at` é usado como proxy. Adicionar quando houver necessidade de distinguir "última actualização" de "último scrape" (refresh manual vs schedule periódico).
34. **`getAnalyticsDashboard` thunk alimenta Dashboard e /insights** — nome impreciso após F1a. Renomear para `getAnalyticsCompany` em mudança maior do Redux state.
35. **6 ficheiros frontend orphans eliminados na E3a** registados para evitar reintrodução acidental: `MarketIntelligenceCard`, `TabAnaliseIA`, `TabMetricas`, `TabOverview`, `TabPerformance`, `TabViatura`.
36. **Página `/insights` desactivada em 2026-05-23** — rota e item de menu removidos. Componentes (DashboardInsightsCard, StockIntelligenceDashboardCard, MarketingWorkspaceTabs, InsightsPage, visibilityHelpers + 12 testes) ficam no codebase para reuso futuro. Motivo: análise de marketplace em vez de gestão de stand. Recuperar quando houver decisão clara sobre que análise é útil (provavelmente reorientada para análise sobre o próprio stock).
37. **Item "Acções" oculto do sidebar em 2026-05-23** — rota mantida, item de menu removido. Página existe mas conteúdo não pronto. Reintroduzir quando houver acções accionáveis reais.
38. **Aba `/cars/:id/ads` eliminada em 2026-05-23 (H3b)** — frontend removido (822 linhas), backend Meta INTACTO (OAuth, jobs, endpoints) para reaproveitar em página futura de criação directa de campanhas. Candidatos a limpeza no payload `analyticsCar`: `smart_ads_recommendation`, `recommended_creative`, `ai_analysis.recommended_channel`.
39. **Aba `/cars/:id/intelligence` reduzida a MarketPositionCard em 2026-05-23 (H3d)** — backend intacto. 4 campos viram zombies no payload (`intent_analysis`, `lead_reality_gap`, `meta_ads_targeting_status`, `silent_buyers`) — continuam calculados para alimentar pipeline IA legacy (item 3). Limpeza apenas após migrar para `CarMarketAggregate`. Thunks órfãos não eliminados: `refreshCarMetaAds`, `regenerateCarAnalysis`.
40. **Campos órfãos em Tráfego & Canais (H3c)** — `metrics.views_24h`, `metrics.views_7d`, `metrics.interactions`, `metrics.interest_rate`, `performance.totals.*`. Componente `CarAnalyticsKpiStrip.tsx` mantido mas órfão (reutilizável).

41. **Consistência de enums com `none` nos outros 3 enums de habitação** — Após M1.c (shower_type sem 'none'), avaliar com Matilde se `fridge_type`, `water_heater_source`, `ambient_heating_source` também deviam perder `'none'` por consistência UX ("não marca = não tem"). Baixa prioridade — funciona como está.

### 14.2 Itens resolvidos (histórico compactado)

Resolvidos em 2026-05-22 a 2026-05-26. Para detalhe técnico ver secção 15.

| # | Descrição | Resolvido em | Sub-fase |
|---|---|---|---|
| ~~25~~ | `GenerateWeeklyMarketingIdeasJob` desactivado → eliminado completamente | 2026-05-23 | H3a (DROP TABLE `car_marketing_ideas`) |
| ~~36~~ | `CarAdsPage` chamadas API directas fora do Redux | 2026-05-23 | H3b (eliminação da aba) |
| ~~41~~ | `RecommendedCreative` type zombie | 2026-05-23 | H3b (componente eliminado) |
| ~~43~~ | KPIs em excesso em Tráfego & Canais | 2026-05-23 | H3c (reduzido a 4 essenciais) |
| ~~44~~ | Intelligence com componentes zombie | 2026-05-23 | H3d (reduzido a MarketPositionCard) |
| ~~45~~ | Bug `triggered_by` ENUM rejeitava `'promo_price_change'` | 2026-05-23 | X2 (ALTER ENUM) |
| ~~47~~ | `RecalculateAllCarScoresJob` cron parado | 2026-05-23 | Auto-resolvido (recreate de containers) |
| ~~48~~ | Polling MarketPositionCard sem timeout | 2026-05-23 + 25 | X1 (TimedOutState) + X7 (Fix C) |
| ~~49~~ | Race condition de navegação rápida | 2026-05-23 + 25 | X3 (mountedRef) + X7 (Fix C) |
| ~~50~~ Fix A | GET `useEffect.catch` produzia NeverRunState para qualquer erro | 2026-05-25 | X5 (NetworkErrorState) |
| ~~50~~ Fix B | Aggregates ficam pendentes durante janelas sem worker | 2026-05-25 | X6 (`MarkStaleAggregatesAsErrorJob`) |
| ~~50~~ Fix C | `latestOfMany` podia devolver aggregate errado | 2026-05-25 | X7 (sessionStorage + GET `?aggregate_id=N`) |
| ~~50~~ Fix C.1 | Bug histórico de mapeamento `res.data.data` no helper | 2026-05-25 | X7.1 (genérico corrigido) |
| ~~50~~ Fix D | Fila/cache em MariaDB em vez de Redis em prod | 2026-05-26 | D2/D3/D4/D6 |
| ~~52~~ | Comparação de mercado ignorava `promo_price_gross` | 2026-05-25 | Y2 (+ Y2.1 UX, Y2.2 search_url) |
| ~~P0~~ | Label 'Cilindradas' (name=`cylinders`) confundia utilizadora; corrigido para 'Cilindros' + oculto para motorhome/caravan | 2026-05-27 | P0 |

### 14.3 Aprendizagens de processo (descobertas em sessões recentes)

- **Análise estática mente** — para bugs CSS/layout, testar empiricamente no browser. Em 2026-05-26 foram precisas 3 rondas de debugging do Claude Code (todas com análises estáticas bem fundamentadas) para identificar as duas causas reais de overflow horizontal em `/cars/:id/analytics`. Só o snippet `findOverflow()` no Console do DevTools (documentado em 10.X) chegou à verdade.

- **Causas-raiz podem ter múltiplas camadas** — Y3.d.5 resolveu o `CarPageNav`; Y3.d.6 resolveu o KPI row (segundo culpado, independente). Parar no primeiro fix deixaria 50% do bug em produção. Lição: após aplicar fix, **revalidar empiricamente** que o sintoma desapareceu totalmente.

- **`config:cache` é necessário após mudança de `.env` em prod** (descoberto em D2). Restart de workers não chega — Laravel cacheia `config()` no boot e ignora `.env` actualizado.

- **`docker exec` num container reinicia variáveis** mas se o container já estava com config cacheada, o restart só chega quando se faz `restart` propriamente. `up -d --no-deps <service>` recria o container.

- **Falsos positivos em detectores de overflow** — o `findOverflow()` pode listar muitos elementos sem que haja overflow real no documento. A âncora de verdade é `document.documentElement.scrollWidth === window.innerWidth`. Tudo o resto pode ser overflow contido (`overflow: auto/hidden`).

- **Migração de queue/cache em prod precisa de validação imediata** — em D2/D3/D4 confirmámos com `redis-cli KEYS` que o Redis tinha as chaves esperadas (`xplendor-database-xplendor-cache-confirm-redis`). Sem isso, fica a dúvida se a config foi mesmo aplicada.

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

**Fix incompleto em Y1.2 — corrigido em Y3.d.5:** `flexWrap: "nowrap"` + `flexShrink: 0` sem `minWidth: 0` no container faziam o `min-content width` do div (soma de todas as tabs, ~500px) propagar-se pelo Col pai → Row → Container fluid, inflando o `scrollWidth` da página em viewports estreitos. Adicionado `minWidth: 0` ao outer div em Y3.d.5 — ver entrada abaixo.

**Y2 — Preço promocional na comparação de mercado (item 52)**
Migration `promo_price_gross` nullable em `car_market_aggregates`. Novo método `effectivePrice()` no model. `priceDifference()` e `priceSignal()` usam preço efectivo (promo se activo, senão gross). Resource emite `comparison.car_price_gross` apenas quando promo activa. UI: label "Preço promo" + linha "↑ PVP: €X" no MetricBox. 5 novos testes unitários (16/16). Item 52 ✅.

**Y2.1 — UX da lista de comparáveis + correcção do tipo MarketComparable**
`ComparablesList.tsx` reescrito. Chips de combustível/caixa/região/ano traduzidos para pt-PT (omitidos silenciosamente quando null). Diferença percentual `(price - effectivePrice) / effectivePrice` colorida (verde/vermelho). Substituída props `carPrice` por `effectivePrice` (usa `comparison.car_price` da Resource, não `car_price_gross`). Texto do contador alterado para "Análise baseada em N anúncios · Standvirtual" + subtítulo "A mostrar os N mais próximos da mediana".

**Bug histórico corrigido:** `MarketComparable` em `types/api.ts` declarava `brand`, `model`, `km` — campos que nunca existiram na resposta do backend (desde Fase E3a). O backend envia `title`, `fuel`, `gearbox`, `region`, `year | null`. Interface corrigida; frontend passa a consumir e renderizar todos estes campos.

**Y2.2 — Links mortos do Standvirtual: search_url + check-link + cache**
Migration `search_url` nullable text em `car_market_aggregates`. `MarketSnapshotService::buildSearchUrl()` pré-computa URL de pesquisa (marca/modelo/ano±1/combustível, mesmos query params do scraper Python) no momento do snapshot. `CarController::checkMarketLink()`: endpoint `GET /market-aggregate/check-link?url=` — proxies HEAD para Standvirtual, devolve `{ available: bool }`; SSRF-guardado a `https://www.standvirtual.com/`; fail-open (timeout/5xx → `available: false`). `CarMarketAggregateResource` inclui `search_url`. `checkMarketLink()` adicionado ao helper. `ComparablesList` recebe `companyId`, `carId`, `searchUrl`; clique em `↗` verifica link via endpoint com cache `useRef<Map<string, {available, expiresAt}>>` TTL 60s; link morto → abre `searchUrl` + toast informativo.

Detecção validada empiricamente (2026-05-25) com URLs reais da BD: listagem activa → HTTP 200; expirada/vendida → HTTP 410 Gone.

### ✅ Fases concluídas (sessão 2026-05-26)

#### Capítulo Z — Imagens e cropper

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

#### Capítulo D — Infra Redis em produção

**D1 — Investigação infra Redis/queue/cache**
Relatório completo de infra: `REDIS_HOST=127.0.0.1` inacessível dentro dos containers (devia ser nome do serviço Docker `redis`), fila e cache em MariaDB em vez de Redis, worker com `--max-time=3600`, scheduler com output suprimido em prod. Base para D2–D6.

**D2/D3/D4 — Redis correctamente configurado**
`REDIS_HOST=127.0.0.1` → `REDIS_HOST=redis` (D2). `QUEUE_CONNECTION=redis` (D3). `CACHE_STORE=redis` (D4). Fila e cache migradas de MariaDB para Redis. `.env.example` actualizado nos 3 commits correspondentes. Worker e scheduler reiniciados a seguir a cada mudança. Validado: `redis-cli PING` → `PONG` em ambas as connections; jobs processados em <1s; `Cache::put/get` OK.

**D6 — Output do scheduler visível em produção**
`>> /dev/null 2>&1` removido de `docker-compose.prod.yml` (linha 92). `docker logs xplendor-scheduler` passa a mostrar a saída de cada invocação de `schedule:run`. Dev nunca teve este redirect. Diagnóstico do item 51 (congelamentos) agora possível com visibilidade real dos logs.

#### Capítulo Y3 — Mobile UX e CSS fixes

**Y3.a — CarAnalyticsHeader trim mobile**
Abaixo de `md` (768px), ocultos via `d-none d-md-inline` / `d-none d-md-inline-flex`: data de publicação, matrícula, badge IPS (numérico), badge IPS (classificação), badge urgência IA, badge alerta de preço. Botão "Editar viatura" passa a mostrar apenas ícone em mobile (`d-none d-md-inline` no texto). `text-truncate` + `minWidth: 0` adicionados ao `h5` para marcas longas ("Mercedes-Benz Classe E") em viewports estreitos.

**Y3.b — CarList filtros off-canvas**
Abaixo de 768px, a sidebar de filtros (`Col xl={3} lg={4}`) é substituída por um Offcanvas (Reactstrap, desliza da esquerda, `scrollable`, ESC fecha, backdrop fecha). Botão "Filtros (N)" aparece na toolbar apenas quando `isFiltersMobile`. `activeFilterCount` conta filtros activos excluindo `statusFilter === "active"` (estado default). `filterPanelContent` extraído para const reutilizado em desktop e Offcanvas. `handleClearFilters` extraído de inline para função nomeada.

**Y3.c — Dashboard signal cards grid + SummaryDashboard border switch**
`ActionRequiredCarsDashboard`: signal cards passam de `d-flex flex-wrap` para `row g-2` com `col-6 col-lg-3` — grid 2×2 em mobile/tablet e 4×1 em desktop. `minWidth: 78` removido (Bootstrap gere a largura). `key` movido para o wrapper `col-6`. `SummaryDashboard`: `useIsMobile(1200)` + `isCompact` — abaixo de 1200px as separações passam de `borderRight` para `borderBottom`, eliminando linhas verticais órfãs no layout 2×2 (`col-md-6`).

**Y3.d.0 — Fix sobreposição desktop /cars**
`CarList` coluna "Carro": `flex-grow-1` recebe `style={{ minWidth: 0 }}` e `h5` recebe `text-truncate`. Sem `minWidth: 0`, o flex-grow não impede overflow do texto em viewports ~1024-1280px — títulos longos invadiam a coluna Preço.

**Y3.d.1 — Prop `renderMobileCard` no XTanStackTable**
Nova prop opcional `renderMobileCard?: (rowData: any) => React.ReactNode` no `IXTanStackTable`. Quando presente e `mobileMode=true`, substitui o bloco genérico de label/valor. Retro-compatível — tabelas existentes sem a prop mantêm o comportamento anterior.

**Y3.d.2 — Car mobile card em CarList**
`renderCarMobileCard` useCallback em `CarList`: 16:9 image (ou placeholder cinzento com `ri-car-line` + "Sem imagem"), title + attention badge, `CarPriceDisplay`, 3 chips (Views/Leads/Conversão), footer com data de publicação e botões de acção 44px (`/analytics` + `/edit`). Tap no card navega para `/cars/:id/analytics`; botões filhos têm `stopPropagation`.

**Y3.d.3 — Lead mobile card em LeadList**
`formatTimeDiff` extraído para helper de módulo (substitui cálculo inline na coluna Tempo). `renderLeadMobileCard` useCallback: avatar com inicial (círculo `#405189`), nome/telefone/email, thumbnail + nome do carro, status select (reutiliza `handleStatusChange`), footer com tempo+origem e botões 44px (tel/whatsapp/email). Sem tap-no-card.

**Y3.d.4 — UsersList migração useIsMobile**
`useState(window.innerWidth < 680)` substituído por `useIsMobile(680)` + import do hook. Fix de bug silencioso: o estado anterior não actualizava em resize de janela.

**Y3.d.5 — Fix overflow horizontal em CarPageNav (regressão Y1.2)**
`minWidth: 0` adicionado ao outer div do CarPageNav. Contexto: Y1.2 introduziu `flexWrap: "nowrap"` + `flexShrink: 0` nas tabs sem adicionar `minWidth: 0` ao container. O CSS default `min-width: auto` num flex item significa que o Col pai nunca comprime abaixo do `min-content` do CarPageNav (~500px = soma das tabs), inflando o `scrollWidth` da página e criando scroll horizontal em todas as rotas `/cars/:id/*` em viewports < ~500px. Com `minWidth: 0` no outer div, a propagação de min-content é cortada: o Col fica nos seus ~350px calculados pelo flex layout, `scrollWidth` da página ≤ viewport, sem scroll horizontal.

**Y3.d.6 — Fix overflow residual em CarAnalytics (KPI row)**
Margin negativa do Bootstrap `.row.g-3` nos KPIs excedia padding do pai em viewports mobile; `overflow: hidden` no d-grid wrapper resolve. Contexto: após Y3.d.5, persistia overflow residual de 16px em CarAnalytics a ~500px. Causa: `.row.g-3` tem `margin-x: -8px` em cada lado (total -16px); o wrapper d-grid não tinha `overflow: hidden` para absorver esse bleed. A ~504px, o row media 488px num container de 472px, inflando o `scrollWidth` da página em 16px. Fix: `overflow: "hidden"` no inner d-grid que envolve directamente o `row g-3` dos KPIs (linha 131 de `CarAnalytics.tsx`). As outras rotas (`/intelligence`, `/ficha`) não têm `.row.g-3` sem padding adequado, pelo que não precisam de fix equivalente.

#### Capítulo Y4 — UX de estado de lead

**Y4.a — LeadStatusBadge component**
Substituído `<select>` HTML nativo por `LeadStatusBadge` em `web/src/pages/Leads/components/LeadStatusBadge.tsx`. Dropdown Reactstrap (`UncontrolledDropdown`) com soft badges Bootstrap (`bg-{variant}-subtle text-{variant}`). 6 estados: `new` (primary), `contacted` (secondary), `qualified` (warning), `won` (success), `lost` (danger), `spam` (dark). Prop `size="sm"` para tabela desktop, `size="md"` para card mobile. Checkmark no item activo. Prop `disabled` mantém comportamento de `loadingUpdate`. Integrado em coluna "Estado" de `LeadList` (desktop) e `renderLeadMobileCard` (mobile, Y3.d.3). `leadStatuses` array removido de `LeadList` — não mais necessário. Sem alterações de backend.

**Y4.c — Fix scroll fantasma vertical (override Velzon)**
O Velzon define `min-height: 1400px` no `<html>` via `structure/_vertical.scss` quando `data-sidebar-size="sm"` e viewport ≥ 768px. Em displays com altura < 1400px (todo desktop ≤ 1080p), criava ~538px de scroll fantasma em todas as telas autenticadas. Criado `web/src/assets/scss/_xplendor-overrides.scss` como ficheiro dedicado para overrides futuros do tema (não mexer nos ficheiros Velzon directamente). Override aplica `min-height: 100vh !important` no mesmo selector, eliminando o scroll sem alterar a aparência visual. Import adicionado no fim de `themes.scss` para garantir precedência. Build CSS confirma compilação correcta.

**Y4.b — Login polish**
`web/src/pages/Authentication/Login.tsx` reescrito com: (1) todos os textos em pt-PT ("Entrar na XPLENDOR", "O seu email", "A sua palavra-passe", "Esqueceu-se da palavra-passe?", "Manter sessão iniciada"); (2) alert Reactstrap `color="danger"` com mensagem genérica "Email ou palavra-passe incorrectos." — não revela se é email ou password o erro (boa prática de segurança); (3) ícones `ri-mail-line` e `ri-lock-2-line` via `InputGroup` + `InputGroupText`; (4) botão de olho movido para dentro do `InputGroup` como elemento append (resolve interceptação de eventos do Bootstrap em position-absolute); (5) `isSubmitting` state + `Spinner size="sm"` no botão durante request; (6) `useSelector` sobre `state.Login.data.errorMsg` para detectar falha via Redux. Testimonial no carousel: textos já correctos em pt-PT, não há truncamento de conteúdo (overflow é do `react-responsive-carousel` em animação mid-slide, não layout issue).

#### Capítulo F — Calibração IPS e bug scores zombie (sessão 2026-05-26)

**F.1 — Diagnóstico IPS**
CLAUDE.md item 4 (anterior: "68% zeros — calibração suspeita") estava desactualizado. Distribuição real na Spacedrive: 19% zeros, 12% altos, 66% no meio — saudável. Bug real identificado: ~970 scores por viatura em 60 dias vs esperado ~60-120. Causa-raiz no sync Carmine.

**F.2 — Identificação da causa-raiz**
`SyncCarmineCarsJob` (hourly) → `CarmineConnectionService::syncCompanyCars()` fazia update incondicional em cada viatura Carmine. Dois mecanismos criavam diff fantasma: (1) `updated_at` forçado do Carmine em cada sync → Eloquent detectava mudança; (2) sem casts no `Car` model → `price_gross` da BD era string `"21900.00"` vs float `21900.0` do payload Carmine → `wasChanged('price_gross') = true` → `CarObserver` disparava `CalculateCarSalePotentialScoreJob`.

**F.3 — Fix aplicado**
Três camadas de defesa: (1) casts adicionados em `Car.php` (`price_gross: decimal:2`, `is_resume: boolean`, `is_metallic: boolean`, `mileage_km/power_hp/engine_capacity_cc/doors/seats/registration_year/registration_month: integer`) — normaliza tipos para comparação correcta; (2) `updated_at` removido do `mapCarmineToXplendor()` — Eloquent gere automaticamente; (3) comparação valor-a-valor antes de update em `syncCompanyCars()` — se nada mudou, `Car::find()` devolve existente sem chamar `save()`, sem disparar observer. Log `sem_mudancas` adicionado para observabilidade. Scores zombie deixam de ser criados em syncs futuros.

#### DOCS — Revisão integral do CLAUDE.md

**v1.8 — DOCS**
Revisão integral do documento após acumulação de 14 sub-fases em 2 dias. Schema actualizado (`car_market_aggregates`, `car_images`, `original_path`, `search_url`, `promo_price_gross` adicionados à secção 6). 3 endpoints internos recentes documentados em nova subsecção 8.4 (recrop Z2.c, check-link Y2.2, GET por `aggregate_id` X7). Padrões mobile (Fase Y3) e aprendizagens CSS (Y3.d) documentados em nova subsecção em 10. Secção 14 (dívida técnica) reestruturada: itens activos no topo (14.1), tabela compacta de resolvidos (14.2), aprendizagens de processo (14.3) — passou de ~448 linhas para ~120. Secção 15 organizada por capítulos (Z, D, Y3, DOCS) dentro de cada sessão. Histórico de versões com entrada 1.8.

#### Capítulo M — Feedback Matilde (sessão 2026-05-27)

**M1 — Bug fixes triviais**
Feedback de utilizadora real (Matilde, autocaravanas): label "Baterias celular" → "Baterias célula" (pt-PT correcto). Enum `shower_type` `'separate'` → `'independent'` (label "Independente" em vez de "Separado"). BD em prod vazia neste campo, sites externos não no ar — renomeação sem risco, sem migration necessária. 7 alterações em 6 ficheiros (CarRequest.php, RESOURCE_SHAPE.md, car.model.ts, vehicleAttributes.ts, CarVehicleDetailsDataFields.tsx, EnergyClimateAccordion.tsx + CLAUDE.md).

**M1.c — Fix shower_type: 3 tipos distintos**
Após M1, a Matilde clarificou que existem 3 tipos distintos de duche em autocaravanas:
- `separate` (label "Separado"): cabine de duche DENTRO do WC, com divisão própria
- `independent` (label "Independente"): cabine de duche FORA do WC, em espaço próprio
- `combined` (label "Combinado"): duche e WC partilham mesmo espaço, sem cabine
Removido `none` — ausência do campo (`null`) significa "sem duche". BD em prod vazia, sites externos não no ar, grep confirma que 'none' não tinha tratamento especial. 5 ficheiros de código + CLAUDE.md.

**M2 — Energia avançada**
Feedback Matilde: dois novos campos opcionais no accordion Energia e Aquecimento.
- Painel solar: `solar_panel_count` (integer, 1-10) — "Quantidade". Permite indicar nº de painéis sem ter de saber watts. Layout lado-a-lado com "Potência (W)" existente quando `has_solar_panel = true`.
- Inversor: `inverter_type` enum (`pure_sine`/`modified_sine`, labels "Onda Pura"/"Onda Modificada") — dropdown react-select. Layout lado-a-lado com "Potência inversor (W)" quando `has_inverter = true`.
Novo tipo TypeScript `InverterType` adicionado em `car.model.ts` e `vehicleAttributes.ts`. Validação backend em `CarRequest.php`. Sem migration (JSON). 5 ficheiros alterados + CLAUDE.md.

**M3.preview — Toast de erros 422 (escopo mínimo)**
Bug descoberto via Matilde: backend rejeitava save com HTTP 422 e frontend não mostrava mensagem — utilizadora ficava bloqueada sem perceber porque o save falhava. Novo helper genérico `showApiErrorToast` em `web/src/helpers/error_helper.ts`: detecta estrutura Laravel `{ errors: { campo: [msgs] } }`, mostra um toast por mensagem (autoClose 6s), fallback para `message` do servidor ou texto genérico. Integrado em `CarCreate.tsx` (save) e `CarUpdate.tsx` (save + venda). Outros forms (Leads, Users, Companies, Blogs) ficam como dívida técnica (item 11).

**P0 — Fix bug 'cylinders' label confuso**
Descoberto via feedback Matilde (M3.preview): `name="cylinders"` (número de cilindros, válido 1-16) tinha label "Cilindradas" (sugerindo cilindrada em cc). Utilizadora preencheu 2287 esperando cc, backend rejeitou com 422. SQL em prod confirmou 0/70 viaturas com cylinders preenchido. Fix: label corrigido para "Cilindros"; campo ocultado para motorhome/caravan via `isMotorhomeOrCaravan` condicional; `useEffect` limpa valor residual quando vehicle_type muda para motorhome/caravan. Backend, API pública e `CarSaleService` intactos. 1 ficheiro alterado (`CarAdditionalDataFields.tsx`).

**M5 — Sala (novo accordion + secção JSON)**
Feedback Matilde: nova secção `living_room` no JSON `vehicle_attributes.attributes`. Campos: `layout` (enum nullable: `face_to_face` | `l_shape` | `panoramic`, labels pt-PT "Frente a Frente" / "Sala em L" / "Salão Panorâmico") e `has_extending_table` (boolean nullable, label "Acrescento de mesa"). Novo `LivingRoomAccordion.tsx` extraído (padrão B2), accordionId=9, no fim. Sem renumeração dos existentes. Bancos rotativos (S5) não duplicado — reutiliza `interior_furniture.has_rotating_seats` existente. `normalizeShape()` actualizado em 2 sítios (emptyShape + detector new format). `LivingRoomLayout` e `VehicleAttributeLivingRoom` adicionados a `car.model.ts` e `vehicleAttributes.ts`. 3 regras de validação backend em `CarRequest.php`. Sem migration (JSON). Capítulo M completo.

**M4 — Cozinha: acrescento de banca**
Feedback Matilde: novo campo `has_extending_counter` (boolean, label "Acrescento de banca") na interface `VehicleAttributeKitchen`. Inserido como 3.º checkbox na mesma `<Row>` de Fogão/Forno (após `has_oven`, antes de Micro-ondas) — Row passa de 5 para 6 `<Col lg={2}>` (12 colunas exactas). Validação backend em `CarRequest.php`. Sem migration (JSON).

**M3 — Exterior: Escada exterior + Garagem**
Feedback Matilde: campo `has_external_ladder` (boolean, label "Escada exterior") adicionado ao último `<Row>` do ExteriorAccordion (6.º de 6 cols `lg={2}`). Novo objecto aninhado `garage` em `exterior` com 4 booleanos: `has_garage` (gate principal, label "Garagem"), `has_double_opening` ("Abertura dos dois lados"), `is_spacious` ("Espaçosa"), `has_height_adjuster` ("Altura ajustável"). Secção "Garagem" separada por `<hr/>` + `<h6>`. Sub-campos condicionais (só aparecem com `garage.has_garage === true`). Novo tipo TypeScript `VehicleAttributeGarage` em `car.model.ts` e `vehicleAttributes.ts`. 5 regras de validação backend em `CarRequest.php`. Sem migration (JSON).

#### Capítulo API Pública — status de viatura (sessão 2026-05-28)

**API Pública — reserved + unificação de status**
Viaturas `reserved` passam a ser expostas publicamente (visíveis no site do stand, tal como `active`/`sold`/`available_soon`). Corrigida duplicação entre `CarPublicRepository` (constante `ACTIVE_STATUSES` sem reserved, usada por `index` e `filters`) e `CarController::show()` (array hardcoded `['active', 'sold', 'available_soon']`) — unificados numa fonte única `CarPublicRepository::PUBLIC_STATUSES` (`public const`, agora com reserved). Constante renomeada `ACTIVE_STATUSES` → `PUBLIC_STATUSES` (nome antigo enganador — incluía `sold`). Resource continua a emitir `status` cru; o site externo decide o rendering. `RESOURCE_SHAPE.md` e secção 8.1 actualizados (estavam desactualizados face ao código, que já incluía `sold`). `RefreshStaleMarketAggregatesJob` (lógica interna de market aggregates) **não alterado** — decisão separada fora do escopo. Sem migration, sem alterações de frontend interno.

### 🚧 Próximo

**Curto prazo (próximas 2-3 sessões)**
- **Fase E** — investigar congelamento do scheduler (item 5 de 14.1) com logs visíveis desde D6
- **Fase F concluída** — bug de scores zombie no sync Carmine resolvido (F.2/F.3). IPS com distribuição saudável, não bloqueia dashboard.
- Lista de **ideias do utilizador** (a receber em sessão dedicada) — classificar como atacar curto/médio prazo, validar com Paulo/Matilde, ou descartar
- Verificação 24-48h pós-push nocturno de 2026-05-26: confirmar que Redis em prod, mobile UX e crop de imagens funcionam com utilizadores reais

**Médio prazo (3-6 meses)**
- `types/api.ts` e fim do `any` (item 1)
- Migrar pipeline IA de `CarMarketIntelligenceService` para `CarMarketAggregate` (item 3 — bloqueado por estabilidade do Capítulo E)
- Layout component `CarPageLayout` com `Outlet` (item 8) — resolução estrutural da página de viatura
- Rate limiting OpenAI por `company_id` (item 16)
- Error Boundary React global (item 18)
- Lazy loading de rotas (item 15)
- B1 accordions extraídos do parent (item 19)

**Longo prazo / backlog (sem urgência clara)**
- 2FA
- WebSockets / real-time notifications
- Smoke tests do scraper Python
- GitHub Actions CI (lint + tests em PRs)
- Tokens Velzon → CSS variables próprias
- WhatsApp Business API (faz parte da fase de Agência)

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
- **Aggregate de mercado** — registo em `car_market_aggregates` com mediana, top 5 comparáveis, confidence e `price_signal`. Substitui parcialmente os `car_market_snapshots` (Fase E2). Item 3 da dívida técnica documenta a migração da pipeline IA pendente.
- **Off-canvas** — pattern Reactstrap (`<Offcanvas>`) para sidebars móveis. Aplicado em CarList (Y3.b) para os filtros em viewports mobile. Pattern reutilizável.
- **`useIsMobile(breakpoint)`** — hook em `web/src/hooks/useIsMobile.ts`. Substitui `useState(window.innerWidth < N)` (que não actualiza em resize). Padrão estabelecido na Fase Y1/Y3.

---

## 20. Documentos relacionados

- `XPLENDOR-Manual-Reposicionamento.md` — manual comercial da agência (pricing, contrato-tipo, scripts de venda)
- `app/Http/Resources/Public/RESOURCE_SHAPE.md` — contrato da API pública (formato de resposta, breaking changes)
- Auditoria estrutural completa (2026-05-22) — relatório base que deu origem a este documento

---

*Fim do documento. Manter actualizado.*