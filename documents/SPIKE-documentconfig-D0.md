# SPIKE — D0 (leitura rica) da config de Documentos do PingWin (documentconfig)

> **Natureza:** reconhecimento (spike). **Sem código, sem deploy, sem migrations, nada em memória.** Blindagem sqlite ativa. `build/tsc` não se aplica.
> **Data:** 2026-10-01 · **Meio caminho para:** Faturas.
> **Âmbito:** mapear o que já existe para a sync de documentos antes de a estender para leitura rica. Plano global: **D0 ler-rico → D1 editar maindataset → D2 editar as 14 filhas → D3 criar → D4 anular.**

## TL;DR

- **Existe só o básico, por lista.** `pingwin_document_configs` + `PingwinDocumentConfig` + `PingwinService::syncDocuments()` + `SyncPingwinDocumentsJob` + `fetch_document_configs()` (Python) espelham os **TIPOS de documento** via **browserdataset** (dataset `1099511639239`): só `id/code/description/entitytype/fiscaltype/fiscaltype_description/deleted`. **Nenhum** dos ~50 campos do maindataset, **nenhuma** das 14 filhas, **nenhuma** option. A leitura rica é **nova**.
- **O `external_id` do espelho já é o id que se abre em rico** (`/service/documentconfig/{id}/...`) — a lista dá-nos os ids; D0 acrescenta o detalhe por id.
- **Transporte 100% reutilizável** (o mesmo do paycond: `_port_session`, ObjectID, POST vazio de commit, confirm-by-reread, `fetch_browserdataset`). O que é **paycond-específico** (`_paycond_*`, Actions, merge de 1 filha) serve de **receita** para um bloco paralelo `documentconfig`, não se reusa verbatim.
- **Recomendações do modelo de dados:** maindataset em **colunas dedicadas para os `_id` que as Faturas precisam + `raw` JSON para tudo**; as 14 filhas em **JSON** (como o tbdocs do paycond), com projeção indexada só para `docconfig_paycond` (a ligação às Condições de Pagamento); **options on-demand no editor** (via read-rico ao vivo), com snapshot em `raw` para a vista read-only.
- **Módulo + tela já existem** (`restauracao_documentos`, `DocumentosPage`) mas em **modo lista básica**; a vista/edição rica é nova e encaixa em *Cadastros › Documentos*.
- **Maior incógnita de protocolo:** o contexto fala de **"um passo `sessionsettings` no commit"** — isto **difere** do commit do paycond (MERGE→SAVE→CLOSE / 2 POSTs vazios). Confirmar na captura antes de D1/D3. Em D0 (leitura) não afeta.

---

## 1. Estado atual da sync de documentos

### a) Tabela/migration — EXISTE (só o básico)

[2026_09_23_000000_create_pingwin_document_configs.php](server/database/migrations/2026_09_23_000000_create_pingwin_document_configs.php):
```php
Schema::create('pingwin_document_configs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->cascadeOnDelete();
    $table->string('external_id');                 // id do documento no PingWin (= id do read-rico)
    $table->string('code')->nullable();
    $table->string('description')->nullable();
    $table->string('entitytype')->nullable();      // Cliente/Fornecedor/Armazém…
    $table->string('fiscaltype')->nullable();
    $table->string('fiscaltype_description')->nullable();
    $table->boolean('deleted')->default(false);
    $table->timestamp('synced_at')->nullable();
    $table->timestamps();
    $table->unique(['company_id', 'external_id'], 'pw_doc_cfg_company_ext_unique');
    $table->index(['company_id'], 'pw_doc_cfg_company_idx');
});
```
**Não há** `raw`, nem colunas de `_id` (taxscenario/doctype/…), nem colunas/tabelas para filhas, nem options.

### b) Model — EXISTE

[PingwinDocumentConfig.php](server/app/Models/PingwinDocumentConfig.php): `$fillable` = as colunas acima; casts `deleted=>boolean`, `synced_at=>datetime`; `belongsTo(Company)`. Sem relações filhas.

### c) Como é populado hoje

**Job → PingwinService → Python, por empresa** (mesmo padrão dos outros lookups):
- Controller: [CompanyPingwinController::syncDocuments](server/app/Http/Controllers/Api/V1/CompanyPingwinController.php) → `SyncPingwinDocumentsJob::dispatch($companyId)`.
- Job: [SyncPingwinDocumentsJob](server/app/Jobs/SyncPingwinDocumentsJob.php) (worker) → `PingwinService::syncDocuments($companyId)`.
- Serviço: [PingwinService::syncDocuments()](server/app/Services/PingwinService.php) → `invoke(buildPayload(..., ['mode'=>'documents']))` → `updateOrCreate(['company_id','external_id'], [...])`. **Não há comando artisan** dedicado.

### d) Que campos traz hoje

Só os da **lista** (browserdataset): mapeia `id→external_id`, `code`, `description`, `entitytype`, `fiscaltype`, `fiscaltype_description`, `deleted`. **Básico** — nada do maindataset rico.

### e) Guarda alguma das 14 filhas? **NÃO** (nenhuma).

### f) Guarda options dos selects? **NÃO** (nenhuma).

> **O que falta (tudo o que é "rico"):** read-rico por id; os ~50 campos do maindataset (em especial os `_id`); as 14 tabelas filhas; as options dos selects; o `raw`. É isso que a D0 introduz.

---

## 2. Como está a leitura no Python hoje

Só **lista** (browserdataset), não read-rico por id. [mycloudpie.py](scraper/sources/pingwin/mycloudpie.py) `fetch_document_configs()` (~linha 557):
```python
def fetch_document_configs(self, dataset_id: str = "1099511639239") -> List[Dict[str, Any]]:
    body = {"filter": {}, "params": {"CODE": "", "DESCRIPTION": "", "ENTITYTYPE_ID": "", "STATE": "0"}}
    docs = self.fetch_browserdataset(dataset_id, body)   # OPEN,GET,INFO,CLOSE paginado (8136)
    return docs
```
[run.py](scraper/sources/pingwin/run.py) (~linha 107): `mode == "documents"` → `fetch_document_configs()`. Traz `id/code/description/entitytype/fiscaltype/deleted` por linha. **Não abre `/service/documentconfig/{id}/...`**, não traz maindataset/filhas/options.

**Comparação com o padrão a seguir** — `fetch_payment_conditions()` (paycond, construído nas fatias 1/2): lista por STATE **e** abre cada registo por id (`_paycond_open`) para enriquecer com o detalhe (maindataset + tbdocs). **A leitura rica do documentconfig é exatamente este padrão, escalado** de 1 filha (tbdocs) para 14 filhas + options. **É nova.**

---

## 3. Reuso do que já temos (transporte vs. específico)

### Genérico / reutilizável TAL E QUAL (nada de paycond)
Em [mycloudpie.py](scraper/sources/pingwin/mycloudpie.py):
- `_port_session(base_url)` (login/logout por-porta em try/finally), `_authenticate`, `_logout_session`, `__enter__/__exit__`.
- `fetch_browserdataset(dataset_id, body)` (lista paginada por Range, 8136) — serve a lista de documentconfig tal e qual.
- `LegacySSLAdapter`, `_TimeoutSession`, `check_hosts_allowed`, `scrub`.
- Os **padrões** (não o código nomeado): ObjectID no header repetido, POST de corpo vazio como commit, confirm-by-reread.
- PHP: `invoke()`, `buildPayload()`, `globalConfig()`, `pickConfig()` — já passam qualquer `*_dataset_id` da config para o Python.

### Paycond-específico → **bloco paralelo novo** `documentconfig` (clonar a receita)
`_paycond_base/_paycond_headers/_paycond_open_form/_paycond_open/_paycond_commit/_paycond_list/_merge_tbdocs_template/_apply_tbdocs_changes/_void_paycond_on` e as constantes `_PAYCOND_ACT_*` estão todos **pathados para `/service/paycond`**. São o **molde**:
- `_paycond_open_form` (abre `*`/por-id com NEW,GET,INFO vs OPEN,GET,INFO, sem fechar) → clonar para `_documentconfig_open_form`.
- `_paycond_commit` (anti-encolhimento + MERGE→SAVE→CLOSE + confirm) → **generalizar**: o paycond tem **1** filha (tbdocs); o documentconfig tem **14** filhas no body, cada uma com o seu anti-encolhimento, e o commit pode ter o passo extra `sessionsettings` (ver §7).
- `_merge_tbdocs_template`/`_apply_tbdocs_changes` → precisam de versões por-filha (e uma que trate `credit/debit` do `docaccount`, não só `deleted`).

**Conclusão:** o transporte/sessão reusa-se 100%; a lógica de entidade é um bloco paralelo novo, mais rico que o do paycond (14 filhas + options + possível `sessionsettings`).

---

## 4. Modelo de dados proposto para a leitura rica (recomendação fundamentada)

### a) Os ~50 campos do maindataset → **HÍBRIDO: colunas dedicadas para os `_id`-chave + `raw` JSON para tudo**

- **Colunas dedicadas** (indexáveis, para a lógica das Faturas) para os `_id` que importam: `taxscenario_id`, `doctype_id`, `docfiscaltype_id`, `stock_signal`, `productgroup`, `tax_round_mode`, `contacttype`, `report_id`, `printzone_id`, `docseries_id`, `default_docstatus_id`, `default_detailstatus_id`, `default_paycond_id`. Guardar o **`_id`** (o valor), nunca o `_descr`.
- **`raw` (JSON)** com o maindataset COMPLETO (incl. os `_id_descr` para display e os campos menos usados) — fonte de verdade para a edição (como o `raw` do paycond/catálogo).
- **Prós:** as Faturas filtram/juntam por `taxscenario_id`/`doctype_id` sem desempacotar JSON; o `raw` preserva fidelidade total e alimenta a edição.
- **Contras:** duplicação (coluna + raw) e uma migration com várias colunas; mas é o padrão já usado no catálogo e paga-se em queries limpas.
- **Recomendação:** híbrido. Não meter os 50 todos em colunas (muitos nunca são consultados) nem tudo-em-JSON (as Faturas precisam de consultar os `_id`).

### b) As 14 tabelas filhas → **colunas JSON (uma por filha), NÃO tabelas filhas reais**

- Guardar cada filha numa coluna JSON (ex. `entitytype_docconfig`, `docconfig_docaccount`, `docconfig_paycond`, …) **ou** um único `children` JSON com as 14 chaves. Preferir **uma coluna JSON por filha** (mais legível/consultável).
- **Porquê JSON (e não 14 tabelas Laravel):**
  - A **edição (D2) parte da RELEITURA VIVA**, não do espelho (princípio provado no paycond 2b): o diff+merge aplica-se sobre a matriz viva que o GET devolve no momento, logo o espelho das filhas é **referência/display**, não a base da escrita. JSON chega e evita 14 migrations + 14 models + sync de 14 tabelas.
  - O `docconfig_docaccount` tem **`credit`/`debit` por linha** (além de `deleted`): JSON acomoda campos arbitrários por linha naturalmente; uma tabela real exigiria colunas próprias e um merge especial.
  - Fidelidade: reenviar a filha "tal e qual + só o que mudou" (anti-encolhimento) é trivial a partir de JSON.
- **Contras do JSON:** não dá para fazer queries SQL cruzadas às linhas das filhas (ex. "que documentos usam a condição X") sem desempacotar.
- **Mitigação pontual:** para `docconfig_paycond` (a ligação às Condições de Pagamento — ver §5), que **vai ser consultada** pelas Faturas, considerar uma **projeção indexada** leve (ex. uma tabela pivô `pingwin_documentconfig_paycond(company_id, documentconfig_id, paycond_id)` preenchida no sync a partir do JSON) **se e quando** as Faturas precisarem dessa query. Para já, JSON em todas as 14 é suficiente.
- **Recomendação:** JSON por filha agora; pivô indexado só para `docconfig_paycond` quando as Faturas o exigirem.

### c) Options dos selects → **on-demand no editor (read-rico ao vivo); snapshot em `raw` para a vista read-only**

- As options vêm no GET rico (docfiscaltype 53, doctype 14, printzone 14, …). São **do formulário vivo** e mudam raramente mas podem mudar.
- **Recomendação:** o **editor** (D1+) abre com um **read-rico ao vivo por id** (no worker) que já devolve as options **frescas** — coerente com o "editar a partir do vivo" (paycond 2b). **Não** manter as options como fonte na UI a partir de um espelho que pode ficar obsoleto.
- Para a **vista read-only** (D0), guardar um **snapshot das options dentro do `raw`** do sync (para mostrar labels sem ir ao vivo). Ou seja: `raw` serve o read-only; o editor relê o vivo.
- **Prós:** sem bloat de dezenas/centenas de linhas de options por documento no espelho relacional; dropdowns sempre corretos no momento de editar.
- **Contras:** abrir o editor exige um read assíncrono (worker + polling) — mas é o mesmo custo já aceite no paycond e necessário para a matriz viva das 14 filhas de qualquer forma.

---

## 5. Ligação às Condições de Pagamento (`docconfig_paycond`)

- A filha `docconfig_paycond` (3 linhas no exemplo) traz **`paycond_id`** — exatamente os ids que já temos em [pingwin_payment_conditions](server/app/Models/PingwinPaymentCondition.php) (chave `(company_id, pingwin_id)`).
- **Cruzamento na leitura rica:** guardar `docconfig_paycond` (JSON) com os `paycond_id` + `deleted`; na exibição/Faturas, **resolver o label** juntando por `pingwin_payment_conditions.pingwin_id == paycond_id` (scoped por `company_id`). As condições **anuladas** (is_active=false no espelho) devem aparecer marcadas/filtradas.
- **Porque importa (Faturas):** uma fatura precisa de saber, por documento, **o cenário fiscal** (`taxscenario_id` do maindataset — ex. 3101 "Compra") **e** **que condições de pagamento estão vinculadas** (`docconfig_paycond` com `deleted:0`). D0 traz os dois num só read → é literalmente o input das Faturas.
- É o candidato nº1 para a **projeção indexada** da §4b, se as Faturas quiserem perguntar "que documentos aceitam a condição X".

---

## 6. Module registry / tela

- **Módulo existe:** `restauracao_documentos` em [ModuleRegistry.php:102](server/app/Modules/ModuleRegistry.php) (depende de `pingwin`), já nos `RESTAURANT_SECTIONS` e no preset `restaurant`.
- **Tela existe, mas básica:** [DocumentosPage.tsx](web/src/pages/Restauracao/DocumentosPage.tsx), rota `/restauracao/documentos` ([allRoutes.tsx:132](web/src/Routes/allRoutes.tsx)), menu *Cadastros › Documentos* ([LayoutMenuData.tsx:239](web/src/Layouts/LayoutMenuData.tsx)). É a **lista read-only** dos tipos de documento (filtro por entitytype, badge de anulado).
- **A vista/edição RICA é nova:** encaixa na mesma secção (*Cadastros › Documentos*), estendendo a página atual com um **detalhe/editor por documento** (como os artigos têm `ArtigoFormPage`). Não precisa de módulo novo.

---

## 7. Riscos e fatiamento da D0

### Plano global — confirmado (com uma ressalva)

**D0 ler-rico → D1 editar maindataset (campos+selects, filhas preservadas da releitura viva) → D2 editar as 14 filhas (diff+merge+anti-encolhimento, com atenção ao `docaccount` credit/debit) → D3 criar → D4 anular.** Alinha com o que fizemos no paycond (1/2a/2b/2c). Ressalva: o **commit do documentconfig pode ter o passo `sessionsettings`** — confirmar em D1/D3 (não afeta D0).

### D0 em concreto (leitura rica, só leitura)
1. **Python:** `fetch_document_configs_rich()` (ou estender): lista por STATE (reusa `fetch_browserdataset`) → para cada id, `_documentconfig_open(id)` (OPEN,GET,INFO → CLOSE, como `_paycond_open`) devolvendo maindataset + 14 filhas + options + additionalfields; devolver lista enriquecida. Bloco paralelo novo; transporte reusado.
2. **PHP:** método de sync rico (`syncDocumentConfigsRich` ou estender `syncDocuments`) que mapeia os `_id`-chave para colunas e o resto para `raw`/JSON das filhas; UPSERT por `(company_id, external_id)`. Migration aditiva (colunas `_id` + colunas JSON das filhas + `raw` + options snapshot).
3. **Frontend:** estender a `DocumentosPage` com uma vista de detalhe read-only (campos legíveis via `_descr`, filhas em tabelas/contagens, condições de pagamento vinculadas resolvidas pelo espelho).

### Riscos

| # | Risco | Nota / mitigação |
|---|---|---|
| R1 | **`_id` vs `_id_descr`** | Gravar SEMPRE o `_id`; os `_descr` são só display. Em D0 guardar ambos (coluna `_id` + `raw` com `_descr`); em D1+ enviar só `_id`. Enganar-se aqui grava labels como valores. |
| R2 | **14 filhas no write (D2)** | Cada filha reenvia-se COMPLETA com anti-encolhimento PRÓPRIO (nº de linhas por filha). O paycond só tinha 1 (tbdocs); generalizar o commit para N arrays. |
| R3 | **`docconfig_docaccount` tem `credit`/`debit`** | Não é só marcar/desmarcar — o diff/merge tem de preservar/alterar `credit`, `debit` E `deleted` por linha. JSON acomoda; o merge precisa de tratar os 3 campos. |
| R4 | **Passo `sessionsettings` no commit** | Difere do commit do paycond (2 POSTs vazios). **Incógnita a confirmar por HAR antes de D1/D3.** Em D0 não entra (leitura). |
| R5 | **Volume do read rico** | 14 filhas + options (docfiscaltype 53, docconfig_import 103) por documento × N documentos = payloads grandes. Mitigar: read rico por id só quando necessário; a lista continua leve. Timeout do job generoso. |
| R6 | **`additionalfields` (fieldsinfo/maindataset/storedataset) + store/lojas** | Config por-loja (`store_docconfig`): o nº de lojas varia por empresa (Yuko tem 3). Não hard-codar; reenviar as que o GET der (lição do `_product_load_stores`). |
| R7 | **Options grandes no espelho** | Se se optar por guardar options relacionalmente, bloat/staleness. Recomendação (§4c): on-demand no editor + snapshot em `raw`. |
| R8 | **Ligação paycond desatualizada** | `docconfig_paycond` pode referir um `paycond_id` anulado; resolver label pelo espelho e marcar estado. |

### Registo de teste
Reusar o padrão: um **documento descartável** por operação nas fases de escrita (D3 cria; D4 anula), confirm-by-reread, **nunca** tocar nos documentos reais do Yuko. D0 é só leitura — sem risco de escrita.

---

## Anexo — ficheiros-chave

**Já existe (básico):**
- [pingwin_document_configs migration](server/database/migrations/2026_09_23_000000_create_pingwin_document_configs.php) · [PingwinDocumentConfig.php](server/app/Models/PingwinDocumentConfig.php)
- [PingwinService::syncDocuments()](server/app/Services/PingwinService.php) (~:158) · [SyncPingwinDocumentsJob.php](server/app/Jobs/SyncPingwinDocumentsJob.php)
- [CompanyPingwinController](server/app/Http/Controllers/Api/V1/CompanyPingwinController.php) `documents()`/`syncDocuments()`
- [mycloudpie.py](scraper/sources/pingwin/mycloudpie.py) `fetch_document_configs()` (~:557, browserdataset `1099511639239`) · [run.py](scraper/sources/pingwin/run.py) `mode=="documents"` (~:107)
- [DocumentosPage.tsx](web/src/pages/Restauracao/DocumentosPage.tsx) · [ModuleRegistry.php](server/app/Modules/ModuleRegistry.php) `restauracao_documentos`

**Molde a clonar (paycond, provado nas fatias 1/2a/2b/2c):**
- [mycloudpie.py](scraper/sources/pingwin/mycloudpie.py): `_port_session`, `_paycond_open_form`, `_paycond_commit`, `_paycond_open`, `_paycond_list`, `_merge_tbdocs_template`, `_apply_tbdocs_changes`, `_void_paycond_on`, `fetch_payment_conditions`.
- [PingwinService.php](server/app/Services/PingwinService.php): `syncPaymentConditions`, `createPaymentCondition`, `updatePaymentCondition`, `voidPaymentCondition`.
- Jobs/tracking: `PingwinPaycondWrite`, `CreatePingwinPaymentConditionJob`/`Update...`/`Void...`.
