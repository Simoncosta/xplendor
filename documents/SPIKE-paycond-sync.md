# SPIKE — Sincronização de Condições de Pagamento (paycond) do PingWin

> **Natureza:** relatório de reconhecimento (spike). **Sem código de implementação, sem deploy, sem migrations.** Nada foi registado em memória. `build/tsc EXIT=0` não se aplica.
> **Data:** 2026-10-01 · **Pré-requisito de:** funcionalidade de Faturas.
> **Objetivo:** mapear o terreno para sincronizar a tabela `paycond` (`id, code, description, discount, days, deleted` + filho `tbdocs` que vincula a condição a documentos via `docconfig_id`).

## TL;DR

- **`paycond` é greenfield.** Não existe nenhuma tabela/migration/model/mode para condições de pagamento em todo o repo (PHP, Python, TS). Grep confirmou zero ocorrências.
- **O padrão a copiar já existe e está maduro:** o fluxo read-only de `pingwin_document_configs` (lookup mais simples) e, para escrita, o fluxo de artigos (`product`). A arquitetura de duas camadas (PHP orquestra → Python fala o protocolo) é reutilizável.
- **A camada de transporte/sessão é genérica e reaproveitável** (`invoke`/`buildPayload` em PHP; `_authenticate`/`_port_session`/ObjectID em Python). **O protocolo de escrita está hardcoded ao `product`** — `paycond` precisa de um bloco paralelo novo (tal como units e BOM já têm os seus).
- **Conversões (`discount` decimal, `days` int) ficam no PHP.** O Python é "burro" — recebe já formatado e no máximo faz `str()` para casar o formato do HAR. Isto está escrito em comentários no próprio código.
- **A maior incógnita técnica:** em que **porta/base** vive `/service/paycond` (8134? 8136? 8138? outra?). Tem de ser capturado por HAR, não adivinhado.
- **`docconfig`/`tbdocs` ainda não têm schema de ligação.** Existe `pingwin_document_configs` (chave `external_id`), que é o ponto de junção natural para o futuro `docconfig_id` (1209 = "Fatura de fornecedor" viveria em `external_id`, como **dado por empresa**, não constante).

---

## 1. Espelho / schema atual

### 1.1 Condições de pagamento (paycond) — **NÃO EXISTE**

Não há tabela, migration nem model. Grep case-insensitive por `paycond`, `payterm`, `payment_condition`, `condicao/condicoes de pagamento` em todo o repo → **zero**. É greenfield.

O único `payment`-ish é ruído não relacionado: `monthly_payment` em [create_cars_table.php](server/database/migrations/2025_12_22_202725_create_cars_table.php) (financiamento de carros).

### 1.2 Config de documentos (docconfig) — **EXISTE como `pingwin_document_configs`**

É o lookup read-only dos **tipos de documento** do PingWin (Definições → Documentos). **Atenção à nomenclatura:** usa `external_id`, **não** `docconfig_id`; **não** há coluna `tbdocs`; **não** há mapa `1209`.

[2026_09_23_000000_create_pingwin_document_configs.php](server/database/migrations/2026_09_23_000000_create_pingwin_document_configs.php):
```php
Schema::create('pingwin_document_configs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->cascadeOnDelete();
    $table->string('external_id');                 // id do documento no PingWin (o slot onde 1209 viveria)
    $table->string('code')->nullable();
    $table->string('description')->nullable();
    $table->string('entitytype')->nullable();      // Cliente/Fornecedor/Armazém…
    $table->string('fiscaltype')->nullable();
    $table->string('fiscaltype_description')->nullable();
    $table->boolean('deleted')->default(false);
    $table->timestamp('synced_at')->nullable();
    $table->timestamps();
    $table->unique(['company_id', 'external_id'], 'pw_doc_cfg_company_ext_unique');
});
```

Model [PingwinDocumentConfig.php](server/app/Models/PingwinDocumentConfig.php): `$fillable` espelha as colunas; `casts` `deleted=>boolean`, `synced_at=>datetime`; `belongsTo(Company)`. Note que, ao contrário dos artigos/fornecedores, este lookup mantém um booleano **`deleted`** explícito (e não `is_active`).

### 1.3 Referência — ARTIGOS (`pingwin_catalog_items`)

O modelo mais rico, e o que mais se aproxima da escrita que o paycond vai precisar. [create_pingwin_catalog_items.php](server/database/migrations/2026_09_24_000000_create_pingwin_catalog_items.php) + [add_write_fields_to_pingwin_catalog_items.php](server/database/migrations/2026_10_11_000000_add_write_fields_to_pingwin_catalog_items.php) / model [PingwinCatalogItem.php](server/app/Models/PingwinCatalogItem.php). Pontos a reter para o padrão:

- **id do PingWin → `pingwin_id` (string)**; UNIQUE `(company_id, pingwin_id)` dá o `upsert` idempotente.
- **Sem coluna `deleted`** — usa `is_active` (bool, default true) = "NÃO anulado". O STATE do PingWin é lido como `product_status`.
- Preços em **cêntimos inteiros** (`saleprice_cents`); coluna **`raw` (json)** guarda o registo exato do servidor como fonte de verdade para edição futura.

### 1.4 Referência — FORNECEDORES (tabela unificada `suppliers` com `source`)

Dois models partilham **uma** tabela física `suppliers`, distinguidos por `source` (`manual` | `pingwin`), cada um com um global scope — [unify_suppliers_with_source.php](server/database/migrations/2026_10_10_000000_unify_suppliers_with_source.php), models [Supplier.php](server/app/Models/Supplier.php) (scope `manual`) e [PingwinSupplier.php](server/app/Models/PingwinSupplier.php) (`protected $table='suppliers'`, scope `pingwin`). Mesmo padrão de chave (`pingwin_id`) e `is_active = !deleted`.

### 1.5 Padrão de lookup read-only estabelecido

Todos os lookups "Definições" do PingWin seguem a mesma forma: scoped por `company_id`, UPSERT idempotente numa chave do PingWin, flag de anulado. **Um `pingwin_payment_conditions` deve nascer calcado no template de `pingwin_document_configs`** (é o lookup mais simples e o mais próximo em forma).

---

## 2. PingwinService e protocolo

### 2.1 Arquitetura de duas camadas

| Camada | Ficheiro | Papel |
|---|---|---|
| **PHP orquestrador** | [PingwinService.php](server/app/Services/PingwinService.php) (~1494 linhas) | **Não fala o protocolo.** Monta JSON (`buildPayload`) e faz `docker exec … python run.py`, creds por STDIN. Cada método público só define um `mode` e faz o parse do resultado. |
| **Python cliente** | [mycloudpie.py](scraper/sources/pingwin/mycloudpie.py) (~1550 linhas, `MyCloudPieClient`) | **Aqui vive o protocolo real GrupoPIE/SOA:** ObjectID, sessão por porta, login/logout, `maindataset` POSTs, MERGE/SAVE/CLOSE, confirmação por releitura. |

> **Conclusão central:** o ObjectID, a porta de sessão, o login/logout em try/finally e a confirmação por releitura **estão todos na camada Python**, não no `PingwinService`.

### 2.2 `invoke()` — transporte (PHP)

Transporte = `docker exec` de um script Python, creds por **STDIN** (nunca argv — argv aparece no `ps`). [PingwinService.php:36](server/app/Services/PingwinService.php#L36):

```php
private const ENTRYPOINT = '/scraper/sources/pingwin/run.py';

protected function invoke(array $payload): array
{
    $command = ['docker','exec','-i', env('SCRAPER_CONTAINER','xplendor-scraper'),
                'python', self::ENTRYPOINT];
    $process = new Process($command);
    $process->setTimeout(180);
    $process->setInput(json_encode($payload));   // creds por STDIN
    $process->run();
    // ... sucesso decidido por $data['ok'] (NÃO pelo stderr); json_decode do stdout
    return $data;
}
```

`buildPayload` ([:1484](server/app/Services/PingwinService.php#L1484)) funde globais do `.env` + 3 chaves por empresa (`username`, `database`, senha cifrada) + `frontend_url` derivado + o `$extra` que carrega o `mode`. **Cada entidade é só um `mode` diferente.**

### 2.3 ObjectID, porta de sessão, login/logout, confirm-by-reread (Python)

- **ObjectID** = handle do form devolvido no **header da resposta** do OPEN/NEW, e **reecoado no header `ObjectID` de todos os passos seguintes** da mesma sessão — `_product_headers` ([mycloudpie.py:969](scraper/sources/pingwin/mycloudpie.py#L969)). Há mecanismos paralelos independentes para Units (`:708`) e BOM (`:861`).
- **Sessão por porta** — o SOA divide áreas por porta (**8136** main/reports/browser, **8138** units, **8134** product) e **um login numa porta não é válido noutra** (401 "Session not found"). `_port_session(base_url)` ([:285](scraper/sources/pingwin/mycloudpie.py#L285)) é um `@contextmanager` que faz login próprio nessa porta e **garante logout num `finally`**.
- **Login/logout** em try/finally: além do `_port_session`, o próprio cliente é context manager (`__enter__`→login / `__exit__`→logout best-effort) usado por `run.py` em `with build_client(...) as client:`.
- **Confirmação por releitura** (o SAVE devolve `{"product":{}}` vazio, que não prova nada):
  - **Create/Delete** → re-leitura por `browserdataset` filtrado por `code` (`_product_browser_by_code`, [:1120](scraper/sources/pingwin/mycloudpie.py#L1120)); no delete tem de voltar **vazio**.
  - **Update** → re-OPEN pelo id (leitura autoritativa), [:1364](scraper/sources/pingwin/mycloudpie.py#L1364).

### 2.4 Protocolo de escrita de artigos (sequência)

Tudo é `POST` em porta 8134; o "verbo" vai no header `Action`; corpo vazio = `data=b""`.

`create_product` ([:1140](scraper/sources/pingwin/mycloudpie.py#L1140)): **NEW,GET,INFO** (→ ObjectID) → carregar stores → (opcional) EDIT `/prices` → **MERGE** (`maindataset,…`) → **SAVE** (POST vazio) → **CLOSE** (POST vazio) → confirmar por `browserdataset`.
`delete_product` ([:1457](scraper/sources/pingwin/mycloudpie.py#L1457)): OPEN,GET,INFO → **CANCEL,CLOSE** (com ObjectID) → **DELETE** (sem ObjectID) → releitura tem de vir vazia.

> ⚠️ **Nota para o paycond:** o protocolo que me passaste para o paycond — `POST /service/paycond/maindataset,tbdocs` seguido de **dois POST `/service/paycond` vazios** (commit/close) e delete por **`POST /service/paycond/{id}` sem body (sem o par CANCEL,CLOSE)** — é **mais simples** que o do product. É compatível com as primitivas existentes (ObjectID repetido, corpo vazio `data=b""`), mas **não é o mesmo encadeamento**, logo não se reusa `create_product`/`delete_product` tal e qual.

### 2.5 Reutilizável vs. acoplado aos artigos

**Genérico / reaproveitável tal e qual (transporte + sessão):**
- PHP: `invoke()`, `buildPayload()`, `globalConfig()`, `pickConfig()`, `resolveFrontendUrl()`.
- Python: `_authenticate(session, base_url)`, `_logout_session(...)`, `_port_session(base_url)`, `__enter__/__exit__`, `check_hosts_allowed`, `scrub`, `LegacySSLAdapter`, `_TimeoutSession`, `custom_pbkdf2`.
- O *padrão* OPEN→MERGE→SAVE→CLOSE + confirm-by-reread + ObjectID é uma receita reutilizável — mas **não está parametrizada**.

**Acoplado ao `product` (precisa de código novo):**
- PHP: métodos públicos (`syncCatalog`, `createProduct`, …) com `mode` hardcoded e mapeamento para `PingwinCatalogItem`/`PingwinSupplierPrice` via `pick($art, [...])` com nomes de coluna de artigos.
- Python: dispatch `if mode == …` em `run.py`; constantes `_PRODUCT_FORM_DATASETS` (19 datasets), paths literais `/service/product/...`, `_product_base()/_product_headers()/_product_open()/…`, `product_port=8134`.
- **Evidência decisiva:** Units e BOM já existem como **blocos paralelos copiados-e-adaptados** (cada um com o seu header helper, constantes de dataset, porta, open/merge/save/close). Adicionar `paycond` é escrever **mais um bloco paralelo**, reusando só `_port_session`/`_authenticate`/`invoke`/`buildPayload`.

---

## 3. Camada Python (run.py / mycloudpie.py)

### 3.1 Como recebe operação e params

[run.py](scraper/sources/pingwin/run.py) lê **toda a config num JSON por STDIN** (não há HTTP server nem argv para o PingWin). **A operação é `cfg["mode"]`; os params são chaves-irmãs do mesmo dict** (`product`, `product_id`, `catalog_dataset_id`, `date`, …). Dispatch é uma **cadeia linear `if mode == "…"`** dentro de `run(cfg)` ([:96](scraper/sources/pingwin/run.py#L96)), **não** um dict/registry. Modes atuais: `validate`, `documents`, `catalog`, `families`, `suppliers`, `units`, `product_form_lookups`, `create_product`, `read_product`, `update_product`, `delete_product`, `create_unit`, `save_unit`, `sync` (default, relatório de vendas).

### 3.2 O que teria de mudar para suportar paycond

1. **`run.py`:** acrescentar branches `if mode == "paycond" / "create_paycond" / "read_paycond" / "delete_paycond": …` que puxam params do `cfg` e chamam os novos métodos. (Não há registry a atualizar — é if-chain manual.)
2. **`mycloudpie.py`:** um bloco `_paycond_*` paralelo ao do product:
   - `_paycond_base()` — **qual a porta de `/service/paycond`?** (incógnita → capturar do HAR).
   - `_paycond_headers(action, object_id)` — cópia de `_product_headers`.
   - *create/merge:* `POST /service/paycond/maindataset,tbdocs` → **2× POST `/service/paycond` vazios** com ObjectID repetido (as primitivas "mesmo ObjectID em todos os passos" e corpo vazio `data=b""` já existem).
   - *read:* `POST /service/paycond/{id}/maindataset,tbdocs,additionalfields…` com `OPEN,GET,INFO` (análogo a `_product_open_by_id`).
   - *delete:* `POST /service/paycond/{id}` sem body.
3. **Config:** provavelmente uma nova env `PINGWIN_PAYCOND_DATASET_ID` (para a leitura via `browserdataset`, como `catalog_dataset_id`/`suppliers_dataset_id` em [services.php:85-86](server/config/services.php#L85)) e, se aplicável, nova porta.

As primitivas de transporte (SSL legacy, auth, sessão por porta, ObjectID, POST de corpo vazio, dataset-no-path) **já existem todas** — nada novo na camada HTTP.

### 3.3 Conversões ficam no PHP — confirmado

O Python é transporte "burro". Está escrito no código: `# Python é "burro": recebe já no formato PingWin.` ([mycloudpie.py:1393](scraper/sources/pingwin/mycloudpie.py#L1393)) e `"Preço já chega como decimal string"` (create_product). As únicas manipulações do lado Python são **estruturais**, não de negócio: `str()` em valores que o HAR envia como string (`parent_qnt`, `saleprice`), allowlist de campos e `pop()` de chaves de preço. **Logo:** `discount` (decimal string) e `days` (int, ou string conforme o HAR dite) convertem-se **no PHP**; o Python repassa verbatim, no máximo embrulhado em `str()` para casar o payload capturado.

---

## 4. Padrão de sync existente

### 4.1 Scheduler (Laravel 12 — sem `Console/Kernel.php`)

Agendamento em [routes/console.php](server/routes/console.php) via facade `Schedule`. A tarefa das 05:00 ([:83](server/routes/console.php#L83)):

```php
Schedule::job(new ScheduledRestaurantSyncJob())
    ->dailyAt('05:00')->timezone('Europe/Lisbon')
    ->name('restaurant-daily-sync')->withoutOverlapping()
    ->onFailure(fn () => Log::error('[Restaurant Daily Sync] Job falhou no scheduler'));
```

> ⚠️ **Nuance importante:** a tarefa das 05:00 (`ScheduledRestaurantSyncJob`) só sincroniza **vendas/reservas do dia anterior** (fan-out por empresa). As syncs de **master-data (catálogo/fornecedores/famílias/unidades) NÃO estão agendadas** — são disparadas **manualmente** pelo controller. Portanto uma sync diária de paycond será uma **entrada nova** no scheduler, não algo que o job das 05:00 já cobre.

### 4.2 Jobs finos → métodos do PingwinService

Não há artisan Commands para sync PingWin — o padrão é **Job → método do PingwinService**. Jobs em [app/Jobs/](server/app/Jobs/): `SyncPingwinCatalogJob`, `SyncPingwinSuppliersJob`, `SyncPingwinFamiliesJob`, `SyncPingwinUnitsJob`, `SyncPingwinDocumentsJob`. Forma partilhada: `$tries=1`, `$timeout` 240-300, `WithoutOverlapping("pingwin-sync:{companyId}")`, DI de `PingwinService`+`AlertService`, `createSystemAlert` de sucesso/falha com `detailPath`, e hook `failed()`.

### 4.3 Reconciliação de anulados (STATE)

Vive no **PingwinService**, não nos jobs, e há duas estratégias:

- **Artigos — lista explícita de ids anulados** ([PingwinService.php:277](server/app/Services/PingwinService.php#L277)): o Python devolve `deleted_ids` (STATE:1 / deleted:1 — ver `fetch_catalog_deleted_ids` em [mycloudpie.py:582](scraper/sources/pingwin/mycloudpie.py#L582)); o serviço faz upsert dos ativos (`is_active = !deleted`) e depois marca `is_active=false` **só** nos ids que (a) existem, (b) estão ativos, (c) constam da lista. Cruzamento por `pingwin_id`.
- **Supplier-prices — por ausência** ([:659](server/app/Services/PingwinService.php#L659)): linhas ativas que não vieram na leitura → `is_active=false`.
- **Fornecedores/Famílias/Unidades** não fazem passo de anulados — só `is_active = !deleted` por linha.

### 4.4 Padrão a seguir para o sync de paycond

Calcado em fornecedores/famílias (read-only), ficheiros a criar **por analogia** (nomes, sem os escrever):
1. Migration `…_create_pingwin_payment_conditions.php` (colunas: `company_id, pingwin_id, code, description, discount, days, is_active, synced_at, timestamps` + UNIQUE `(company_id, pingwin_id)`; se guardares o filho, `raw` json com o `tbdocs`).
2. Model `PingwinPaymentCondition.php`.
3. `PingwinService::syncPaymentConditions(int $companyId): int` — guarda de integração → `invoke(buildPayload($config,$pwd,['mode'=>'paycond']))` → `ok` → map com `pick()` + `is_active=!deleted` → `upsert` em chunks. **Decisão:** se o PingWin faz soft-delete de condições, replicar a reconciliação estilo-artigos via `deleted_ids`; senão, `is_active=!deleted` como fornecedores.
4. Job `SyncPingwinPaymentConditionsJob.php` (clone do de fornecedores; `detailPath` ex. `/restauracao/condicoes-pagamento`).
5. Dispatch manual no [CompanyPingwinController.php](server/app/Http/Controllers/Api/V1/CompanyPingwinController.php) (ao lado de catálogo/fornecedores) + endpoint de listagem.
6. **Scheduler (encaixe diário):** o mais limpo é criar um **orquestrador fan-out de master-data** (análogo a `ScheduledRestaurantSyncJob`) que percorre as empresas com integração ativa e despacha os jobs de master-data — incluindo paycond — com `->dailyAt(...)->timezone('Europe/Lisbon')->name(...)->withoutOverlapping()`. (Hoje não existe esse orquestrador de master-data; é uma lacuna a decidir à parte.)

---

## 5. Ligação a documentos (tbdocs → docconfig)

### 5.1 Estado atual

- **`tbdocs` e `docconfig_id` estão ausentes** do código (grep: zero em `server/app|database|routes|config` e `scraper`). Os únicos hits de `1209`/`tbdocs` são ruído em fixtures HTML de testes (`scraper/tests/fixtures/custojusto/`).
- **Não existe mapa/enum/constante** de ids de docconfig. Não há `1209 = "Fatura de fornecedor"` em lado nenhum como constante.
- **Documentos hoje:** o PingWin **lê** (só **tipos de documento**, read-only — `syncDocuments` → `fetch_document_configs`, dataset `1099511639239`, [mycloudpie.py:549](scraper/sources/pingwin/mycloudpie.py#L549)) e **não escreve** nada (nem documentos nem faturas). As faturas de fornecedor existem só via **OCR** ([OcrInvoice.php](server/app/Models/OcrInvoice.php)), explicitamente isolado do PingWin (`synced_to_pingwin` fica false "até à Fase B").

### 5.2 Ponto de junção natural para o futuro `docconfig_id`

- **`pingwin_document_configs` é a tabela de referência canónica.** O `docconfig_id` do `tbdocs` (ex. 1209) é, por empresa, um valor que vive em `pingwin_document_configs.external_id`. O cruzamento futuro será **`paycond.tbdocs.docconfig_id` → `pingwin_document_configs.external_id`** (scoped por `company_id`). Hoje `1209` só apareceria se a sync de documentos dessa empresa o tivesse devolvido — é **dado**, não constante.
- **`OcrInvoice` é o candidato a ganhar o `docconfig_id`** quando as Faturas passarem a escrever no PingWin ("Fase B"): já faz `belongsTo(PingwinSupplier)`, tem linhas/sumário filhos e o flag `synced_to_pingwin` como gancho.
- **Pré-requisito de dados:** para o cruzamento funcionar é preciso garantir que `pingwin_document_configs` dessa empresa está sincronizada **e** contém a linha 1209 ("Fatura de fornecedor") antes de qualquer escrita que a referencie.

---

## 6. Riscos, pontos sensíveis e fatiamento

### 6.1 Riscos / pontos sensíveis

| # | Risco | Mitigação |
|---|---|---|
| R1 | **Porta/base de `/service/paycond` desconhecida** (8134/8136/8138/outra). Login é por-porta; porta errada → 401. | Capturar HAR real de criação/leitura/apagar antes de escrever código. Não adivinhar. |
| R2 | **Dataset_id do `browserdataset` de paycond** para a leitura/sync (como `catalog_dataset_id`). | Capturar do HAR; parametrizar em `.env` (`PINGWIN_PAYCOND_DATASET_ID`). |
| R3 | **Protocolo de escrita difere do product** (2 POSTs vazios de commit/close; delete sem CANCEL,CLOSE). Reusar `create_product`/`delete_product` levaria a sequência errada. | Escrever bloco `_paycond_*` dedicado; só reusar as primitivas (ObjectID, `data=b""`, `_port_session`). |
| R4 | **SAVE devolve vazio** — não prova persistência. | Replicar confirm-by-reread (POST `/service/paycond/{id}/maindataset,tbdocs,additionalfields…` e comparar discount/days). |
| R5 | **Reconciliação de anulados**: desconhecido se o PingWin faz soft-delete de condições e se há leitura STATE:1. | Investigar na captura; se sim, replicar o padrão `deleted_ids` dos artigos; se não, `is_active=!deleted`. |
| R6 | **`tbdocs`/`docconfig` sem schema de ligação** e sem garantia de que 1209 existe no mirror. | Tratar como fatia posterior, dependente do pré-requisito docconfig; validar presença de 1209 em `pingwin_document_configs` antes de escrever. |
| R7 | **SSL legacy / allowlist de hosts** (SECLEVEL=1, TLS 1.0/1.1). | Já tratado pelas primitivas (`LegacySSLAdapter`, `check_hosts_allowed`); garantir host do paycond na allowlist. |
| R8 | **Blindagem sqlite ativa** — não tocar/consultar a BD de produção fora do fluxo seguro. | Spike é só leitura/relatório; na implementação, testar em registo descartável (ver 6.3). |
| R9 | **Conversão de tipos** (`discount` decimal, `days` int) no sítio errado. | Fazer no PHP; Python repassa verbatim (`str()` só para formato). |

### 6.2 Proposta de fatiamento

- **Fatia 1 — SYNC READ-ONLY (baixo risco, primeiro):** migration `pingwin_payment_conditions` + model + `syncPaymentConditions()` (mode `paycond` por `browserdataset`) + `SyncPingwinPaymentConditionsJob` + dispatch manual no controller + endpoint/UI de listagem. **Sem escrita.** Entrega valor (espelho) e exercita todo o caminho PHP↔Python↔DB sem risco de mutação no PingWin.
- **Fatia 2 — ESCRITA em registo descartável:** implementar o bloco `_paycond_*` (create/update) + confirm-by-reread, testando **exclusivamente** no registo de teste `id 584955579139782582` ("XPLENDOR Teste"). Validar o encadeamento maindataset,tbdocs → 2× POST vazio.
- **Fatia 3 — DELETE + reconciliação de anulados:** `POST /service/paycond/{id}` sem body + (se aplicável) leitura STATE:1 e reconciliação `deleted_ids`.
- **Fatia 4 — scheduler diário:** adicionar paycond ao orquestrador de master-data (a criar) ou entrada dedicada em `routes/console.php`.
- **Fatia 5 (dependente do pré-requisito docconfig) — cruzamento `tbdocs`/`docconfig_id`:** só depois de docconfig modelado e de 1209 garantido no mirror.

### 6.3 Como testar no registo descartável

Usar o `paycond` de teste **`584955579139782582` ("XPLENDOR Teste")** como alvo único das fatias 2-3:
1. **Ler primeiro** (`read_paycond` do id) e guardar o `raw` como baseline.
2. **Escrever** um `discount`/`days` conhecidos (via PHP → mode paycond).
3. **Reler** e comparar (confirm-by-reread) — só considerar `ok` se os valores bateram.
4. **Apagar** (`POST /service/paycond/{id}` sem body) e confirmar que a releitura vem vazia.
5. Nunca tocar em condições reais até as fatias 1-3 estarem verdes no registo de teste.

---

## Anexo — ficheiros-chave

**PHP (server):**
- [PingwinService.php](server/app/Services/PingwinService.php) — orquestrador: `invoke` (:36), `buildPayload` (:1484), `syncCatalog` (:208, reconciliação :277), `syncDocuments` (:153), `syncSuppliers` (:817), `syncFamilies` (:1110).
- [routes/console.php](server/routes/console.php) — scheduler (05:00 em :83).
- [CompanyPingwinController.php](server/app/Http/Controllers/Api/V1/CompanyPingwinController.php) — dispatch manual + endpoints.
- Jobs: [SyncPingwinCatalogJob.php](server/app/Jobs/SyncPingwinCatalogJob.php), [SyncPingwinSuppliersJob.php](server/app/Jobs/SyncPingwinSuppliersJob.php), [SyncPingwinDocumentsJob.php](server/app/Jobs/SyncPingwinDocumentsJob.php).
- Models: [PingwinDocumentConfig.php](server/app/Models/PingwinDocumentConfig.php), [PingwinCatalogItem.php](server/app/Models/PingwinCatalogItem.php), [PingwinSupplier.php](server/app/Models/PingwinSupplier.php).
- [config/services.php:72-91](server/config/services.php#L72) — config pingwin (globais vs. por empresa, datasets, portas).

**Python (scraper):**
- [run.py](scraper/sources/pingwin/run.py) — entrypoint / dispatch de `mode` (:96).
- [mycloudpie.py](scraper/sources/pingwin/mycloudpie.py) — protocolo: `_authenticate` (:194), `_port_session` (:285), `_product_headers`/ObjectID (:969), `create_product` (:1140), `delete_product` (:1457), `fetch_document_configs` (:549), `fetch_catalog_deleted_ids` (:582).
