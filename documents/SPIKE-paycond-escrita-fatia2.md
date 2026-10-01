# SPIKE — Fatia 2: ESCRITA das Condições de Pagamento (paycond) do PingWin

> **Natureza:** relatório de reconhecimento (spike). **Sem código de implementação, sem deploy, sem migrations.** Nada registado em memória. Blindagem sqlite ativa. `build/tsc EXIT=0` não se aplica.
> **Data:** 2026-10-01 · **Depende de:** Fatia 1 (leitura) — já feita e verificada.
> **Âmbito:** inserir + editar + anular condições de pagamento. Mapeia **o que falta**; não repete a Fatia 1.

## TL;DR

- **O protocolo indicado bate certo com o código; nada contradiz.** Confirmei por leitura de código e por uma leitura ao vivo do espelho do Yuko. Há **2 pontos que só se confirmam a escrever** (id/code pré-alocados no form novo; anular = soft-delete) — confirmar com o registo descartável, nunca nas 3 reais.
- **Transporte 100% reutilizável:** `_port_session` (login/logout em try/finally por-porta), gestão de `ObjectID` por header, POST de corpo vazio como commit, e os helpers de leitura `_paycond_open`/`_paycond_list` (confirm-by-reread sai de graça). **Falta** um form-open de escrita que **NÃO feche** o registo (o `_paycond_open` atual fecha — é de leitura).
- **A matriz tbdocs (63 linhas) está ÍNTEGRA no espelho** — verifiquei: `raw` e a coluna `tbdocs` têm as 63 linhas. A regra de ouro da escrita: **reler a matriz viva por id mesmo antes de gravar, alternar só o `deleted` das linhas que o utilizador mexeu, reenviar as 63 tal e qual** (como o `EDIT,SAVE` das Unidades reenvia a tabela toda, com trava anti-encolhimento).
- **A escrita é ASSÍNCRONA** (job no worker + linha de tracking + polling), como artigos/unidades — porque o php-fpm **não tem** o socket Docker. Não dá para ser síncrona no controller.
- **Anular do paycond ≠ apagar do product.** O product faz HARD delete em 3 passos (OPEN→CANCEL,CLOSE→DELETE). O paycond é **1 POST sem body = SOFT-delete** (`deleted=1`), confirmado por reaparecer em STATE 1. Bloco paralelo novo, não reuso de `delete_product`.
- **Inserir e editar = mesma sequência de 3 POSTs**, diferindo só no id. Bloco paralelo novo (protocolo mais curto que o do product: sem NEW/MERGE/EDIT/CANCEL).

---

## 1. Reuso do transporte (Python)

Tudo o que a escrita precisa ao nível de transporte **já existe** em [mycloudpie.py](scraper/sources/pingwin/mycloudpie.py):

| Peça | Onde | Reuso na escrita paycond |
|---|---|---|
| Login/logout por-porta em try/finally | `_port_session` ([:294](scraper/sources/pingwin/mycloudpie.py#L294)) | ✅ direto — envolve os 3 POSTs numa sessão com logout garantido |
| `ObjectID` por header (mesmo handle em todos os passos) | `_paycond_headers(action, object_id)` ([:675](scraper/sources/pingwin/mycloudpie.py#L675)) | ✅ direto — captura no form-open, reenvia nos 3 POSTs |
| POST de corpo vazio como commit | padrão `session.post(url, data=b"", ...)` (ex.: SAVE do product [:1377](scraper/sources/pingwin/mycloudpie.py#L1377)) | ✅ direto — os 2 POSTs vazios = commit/close |
| Base/porta do paycond (default 8136, overridable) | `_paycond_base` ([:662](scraper/sources/pingwin/mycloudpie.py#L662)) | ✅ direto |
| Abrir registo por id (OPEN,GET,INFO) | `_paycond_open` ([:692](scraper/sources/pingwin/mycloudpie.py#L692)) | ✅ para **confirm-by-reread** e para o **editar** (ler matriz viva) |
| Listar por STATE | `_paycond_list(dataset_id, state)` ([:686](scraper/sources/pingwin/mycloudpie.py#L686)) | ✅ para confirmar o **anular** (reaparece em STATE 1) |

**O que falta (helpers de escrita a criar), bloco paralelo:**

1. **`_paycond_open_for_write(session, paycond_id=None)`** — variante do `_paycond_open` que **NÃO fecha**: faz `OPEN,GET,INFO` e **devolve `(object_id, body)`** para os passos seguintes. ⚠️ O `_paycond_open` atual faz um `CLOSE` best-effort no fim ([:706-712](scraper/sources/pingwin/mycloudpie.py#L706)) — ótimo para leitura, **errado para escrita** (fecharia o form antes do commit). Na escrita, quem fecha são os 2 POSTs vazios.
   - **editar:** abre pelo id existente → `POST /service/paycond/{id}/{_PAYCOND_DETAIL_DATASETS}`.
   - **inserir:** abre o form novo → `POST /service/paycond/*/maindataset,tbdocs,additionalfields.fieldsinfo,additionalfields.maindataset` (o `*` = registo novo). A resposta traz o **id/code PRÉ-ALOCADOS** + a matriz tbdocs-base.
2. **`create_payment_condition(fields, tbdocs_changes)`** e **`update_payment_condition(paycond_id, fields, tbdocs_changes)`** — a sequência de 3 POSTs (abaixo, §4).
3. **`void_payment_condition(paycond_id)`** — 1 POST sem body + confirmação por STATE 1 (§4/§5).

---

## 2. Matriz tbdocs na escrita (o ponto mais sensível)

### a) A matriz-base de 63 linhas está íntegra no espelho? **SIM** (verificado ao vivo)

Leitura do Yuko (condição "Fim do mês", `pingwin_id=16010`):
```
raw keys: maindataset, tbdocs, additionalfields.fieldsinfo, additionalfields.maindataset, service.datasetinfo
raw.tbdocs count = 63   |   coluna tbdocs count = 63
```
Cada linha tem `key, paycond_id, docconfig_id, description, entitytype, deleted` (0=vinculada, 1=não). Logo a matriz completa está disponível **sem ir ao PingWin** — tanto na coluna `tbdocs` como em `raw['tbdocs']` do model [PingwinPaymentCondition](server/app/Models/PingwinPaymentCondition.php).

### b) Como garantir que a escrita só muda o `deleted` das linhas mexidas e preserva as outras

**Regra de ouro (precedente no código — Unidades):** o `save_unit` reenvia a **tabela COMPLETA do servidor, tal e qual**, nunca reconstruída da BD, e **ABORTA** se a lista vier mais curta do que o esperado (comentário em [mycloudpie.py:701-704](scraper/sources/pingwin/mycloudpie.py#L701)). O mesmo invariante aplica-se às 63 linhas do tbdocs: **mandar incompleto = apagar vínculos**.

Procedimento seguro para o editar:
1. **Reler a matriz VIVA por id** imediatamente antes de gravar (via `_paycond_open_for_write`), **não** confiar só no espelho (pode estar desatualizado face a mexidas feitas direto no PingWin).
2. Indexar as 63 linhas por `docconfig_id` (ou `key`).
3. Aplicar **apenas** os toggles do utilizador (lista de `{docconfig_id, deleted}`) sobre a cópia viva — alterar **só** o campo `deleted` dessas linhas.
4. **Reenviar as 63 linhas** no body (as não mexidas vão idênticas).
5. **Trava anti-encolhimento:** abortar se o nº de linhas a enviar < nº lido (igual à salvaguarda das unidades).

➡️ A conversão "toggles do utilizador → matriz completa" deve viver **no PHP** (o Python é transporte "burro"): o PHP lê a matriz viva (ou recebe-a do form-open via Python) e devolve as 63 linhas já montadas. Alternativa (mais simples e robusta): o **Python** faz o merge (abre o form → tem a matriz viva → aplica os toggles recebidos do PHP → envia), porque é quem tem a matriz viva na mão no momento da sessão. **Recomendação:** o Python recebe do PHP só `tbdocs_changes = [{docconfig_id, deleted}]` e aplica sobre a matriz que o próprio form-open devolveu — minimiza o risco de enviar uma matriz obsoleta.

### c) O inserir começa com que matriz tbdocs?

Com a **matriz-base que o form novo devolve** (`POST /service/paycond/*/...`): o PingWin devolve as 63 linhas-template já com os `deleted` por defeito dessa instalação. O inserir aplica os toggles do utilizador sobre **essa** matriz (não sobre a de outra condição) e envia as 63. Nunca inventar linhas localmente.

---

## 3. Orquestração PHP

### Como o product escreve hoje (padrão a espelhar) — [PingwinService.php](server/app/Services/PingwinService.php)

- `createProduct` ([:354](server/app/Services/PingwinService.php#L354)), `updateProduct` ([:393](server/app/Services/PingwinService.php#L393)), `deleteProduct` ([:743](server/app/Services/PingwinService.php#L743)), `readProduct` ([:523](server/app/Services/PingwinService.php#L523)): cada um resolve a integração, decifra a senha, monta `$extra` com o `mode` e invoca o Python; devolve o `result`. **Nenhum destes toca no espelho** — isso é o Job, e **só após confirmação** (persisted/confirmed).
- **A escrita é ASSÍNCRONA** (crítico): o controller `createArticle` ([CompanyPingwinController.php:360](server/app/Http/Controllers/Api/V1/CompanyPingwinController.php#L360)) **não** chama `createProduct` em linha — cria uma linha de tracking `PingwinCatalogWrite` (status `a_criar`) e **despacha** `CreatePingwinCatalogJob`, devolvendo `creation_id` para o frontend fazer **polling** (`articles/creation/{creationId}`). Porquê: o `invoke()` faz `docker exec` ao scraper e **só o worker tem o socket** (o php-fpm não) — o síncrono rebentaria.
- Conversões na fronteira (no PHP): preços decimal↔cêntimos (`decimalToCents` [:341](server/app/Services/PingwinService.php#L341)); o Python recebe já decimal string.

### O que nasce de paralelo para o paycond (NÃO tocar no product)

1. **`PingwinService::createPaymentCondition(int $companyId, array $fields, array $tbdocsChanges): array`** — `mode => 'create_paycond'`, body = campos do maindataset + toggles tbdocs; devolve `{ok, persisted, pingwin_id, code, confirm}`.
2. **`PingwinService::updatePaymentCondition(int $companyId, string $paycondId, array $fields, array $tbdocsChanges): array`** — `mode => 'update_paycond'`.
3. **`PingwinService::voidPaymentCondition(int $companyId, string $paycondId): array`** — `mode => 'void_paycond'`; devolve `{ok, voided_confirmed}`.
4. **Conversões na fronteira (PHP):** `discount` — a tela usa **%** (ex.: 2.5); o PingWin espera o `discount` do maindataset como decimal (na leitura veio `discount: 0` numérico). ⚠️ **Confirmar na captura** se o write quer `"2.5"` string decimal ou `2.5` número (a leitura devolve número; o product manda preços como **string** decimal). Default seguro: enviar string decimal com ponto, 2 casas, e confirmar por releitura. `days` — int tal e qual.
5. **Tracking + espelho:** uma linha de tracking (tabela nova `pingwin_paycond_writes` análoga a `pingwin_catalog_writes`, ou reaproveitar um modelo genérico) com `action` (`criar`/`editar`/`anular`), `payload`, `status`. O espelho (`pingwin_payment_conditions`) só é atualizado **pelo job, após confirm-by-reread** — idealmente reusando `syncPaymentConditions` (ou um upsert pontual da condição relida).

---

## 4. Dispatch Python (bloco paralelo novo)

Em [run.py](scraper/sources/pingwin/run.py), novos branches (a par do `paycond` de leitura [:111](scraper/sources/pingwin/run.py#L111)):

```python
if mode == "create_paycond":
    # ⚠️ ESCRITA: abre form novo (id/code pré-alocados) → monta body → 3 POSTs → confirma.
    fields = cfg.get("paycond") or {}
    tbdocs_changes = cfg.get("tbdocs_changes") or []
    result = client.create_payment_condition(fields, tbdocs_changes)
    return {"ok": bool(result.get("persisted")), "mode": "create_paycond", "result": result}

if mode == "update_paycond":
    paycond_id = str(cfg.get("paycond_id") or "")
    fields = cfg.get("paycond") or {}
    tbdocs_changes = cfg.get("tbdocs_changes") or []
    result = client.update_payment_condition(paycond_id, fields, tbdocs_changes)
    return {"ok": bool(result.get("ok")), "mode": "update_paycond", "result": result}

if mode == "void_paycond":
    paycond_id = str(cfg.get("paycond_id") or "")
    dataset_id = cfg.get("paycond_dataset_id")   # p/ confirmar por STATE 1
    result = client.void_payment_condition(paycond_id, dataset_id)
    return {"ok": bool(result.get("voided_confirmed")), "mode": "void_paycond", "result": result}
```

Sequência dos métodos de escrita (mais curta que o product — **sem** NEW/MERGE/EDIT/CANCEL):

```
create/update_payment_condition:
  with _port_session(_paycond_base()) as session:
    object_id, body = _paycond_open_for_write(session, paycond_id or None)   # '*' no inserir
    main0 = body["maindataset"][0]            # traz id/code pré-alocados (inserir) ou atuais (editar)
    tbdocs = body["tbdocs"]                   # as 63 linhas VIVAS
    # montar maindataset (1 registo) com id/code do servidor + discount/days/description do PHP
    # aplicar tbdocs_changes (só o 'deleted' das linhas mexidas); TRAVA anti-encolhimento (len==63)
    # 1) POST /service/paycond/maindataset,tbdocs   body={maindataset:[row], tbdocs:tbdocs}   (ObjectID)
    # 2) POST /service/paycond   data=b""   (ObjectID)   ← commit
    # 3) POST /service/paycond   data=b""   (ObjectID)   ← close
    # confirm-by-reread (§5)
```
```
void_payment_condition:
  with _port_session(_paycond_base()) as session:
    # 1) POST /service/paycond/{id}   data=b""   → soft-delete
    # confirmar por _paycond_list(STATE=1) (aparece) e _paycond_list(STATE=0) (saiu) — §5
```

**Confirmação:** é um **bloco paralelo novo** — não reutiliza `create_product`/`update_product`/`delete_product` (o protocolo do paycond é 3-POSTs sem MERGE/SAVE/CANCEL; o anular é soft-delete de 1 POST vs. o hard-delete de 3 passos do product em [:1617](scraper/sources/pingwin/mycloudpie.py#L1617)).

---

## 5. Confirm-by-reread ("sucesso" ≠ gravado)

Precedente no product:
- **Criar:** relê o `browserdataset` pelo `code` (STATE 0) — tem de **encontrar** (`_product_browser_by_code` [:1280](scraper/sources/pingwin/mycloudpie.py#L1280); `persisted = bool(found)`).
- **Editar:** re-OPEN pelo id (leitura autoritativa) e compara os campos ([:1364 do spike anterior]).
- **Apagar:** relê pelo code — tem de vir **VAZIO** (`deleted_confirmed = (still is None)`, [:1643](scraper/sources/pingwin/mycloudpie.py#L1643)).

Réplica para o paycond (reusa os helpers de leitura da Fatia 1):
- **Inserir/editar:** depois dos 3 POSTs, **`_paycond_open(session, id)`** e comparar: `maindataset[0].discount/days/description` == o enviado **e** os `deleted` das linhas tbdocs mexidas == o pedido. Só então `persisted=True`.
- **Anular:** `_paycond_list(dataset, "1")` → o id **aparece** nos anulados **e** `_paycond_list(dataset, "0")` → o id **não aparece** nos ativos. Só então `voided_confirmed=True`.
- O job só mexe no espelho (`is_active`, `discount`, `days`, `tbdocs`, `raw`) **depois** desta confirmação — nunca com base na resposta `{...}` do SAVE.

---

## 6. Registo de teste

Estado atual (lido ao vivo): a condição **`584955579139782582` ("XPLENDOR Teste UP") está ANULADA** (`is_active=false`, STATE 1). As 3 reais do Yuko são **code 0/1/3** (`Fim do mes`, `30 dias`, `Pronto Pagamento`) — **NUNCA escrever nelas**.

**Estratégia recomendada (por sub-fatia):**
- **2a (inserir):** criar um **registo novo descartável de cada vez** (o form novo pré-aloca id/code). É o mais seguro: não toca em nada existente e exercita o caminho completo do inserir. No fim do teste, **anular** o que se criou (fecha o ciclo e deixa o ambiente limpo).
- **2b (editar + tbdocs):** editar **o registo descartável criado em 2a** (ou **reativar** o `584955579139782582` com um editar que ponha `deleted=0` — isto também testa "editar muda o estado"). Assim o editar nunca toca nas 3 reais.
- **2c (anular):** anular **o registo descartável**.

➡️ Preferir **criar-descartável** a reutilizar um fixo: evita colisões de estado entre execuções e prova o inserir. Guardar o id criado para limpar no fim.

---

## 7. Riscos e fatiamento

### Riscos

| # | Risco | Mitigação |
|---|---|---|
| R1 | **tbdocs incompleto apaga vínculos** (mandar < 63 linhas) | Reler matriz viva, alterar só `deleted` das mexidas, reenviar 63; **trava anti-encolhimento** (precedente: unidades). |
| R2 | **Matriz do espelho desatualizada** vs. PingWin | Reler por id **imediatamente antes** de gravar (não confiar só no mirror). |
| R3 | **id/code pré-alocados** — assumir a origem errada | **Confirmar** que vêm em `maindataset[0].id/.code` do form-open (não inventar); testar no descartável. |
| R4 | **Form-open do inserir pode PERSISTIR** um registo em branco (como o NEW do product podia) | Salvaguarda de não-persistência: se o inserir for abandonado, confirmar que nada ficou (contagem STATE 0 antes/depois), como o `product_form_lookups` [:777](server/app/Services/PingwinService.php#L777). |
| R5 | **`_paycond_open` fecha o registo** (é de leitura) | Criar `_paycond_open_for_write` que **não** fecha (o commit são os 2 POSTs vazios). |
| R6 | **Escrita síncrona no php-fpm rebenta** (sem socket Docker) | Obrigatório job no **worker** + tracking row + polling (padrão artigos/unidades). |
| R7 | **Anular ≠ apagar** — reusar `delete_product` faria o protocolo errado (hard delete 3 passos) | Bloco novo: 1 POST sem body (soft-delete), confirmar por STATE 1. |
| R8 | **Formato do `discount`** (string decimal vs número; % vs fração) | Confirmar na captura; default string decimal com ponto; confirmar por releitura o valor gravado. |
| R9 | **Concorrência** (2 escritas à mesma condição) | `WithoutOverlapping("pingwin-sync:{companyId}")` já serializa tudo por empresa — reusar nos jobs de escrita. |
| R10 | **Impersonation** em ações destrutivas | Rota de anular com `block_when_impersonating` (como `deleteArticle` [api.php:214](server/routes/api.php#L214)); inserir/editar conforme política. |

### Sub-fatias propostas (cada uma testada em tela antes da seguinte)

- **2a — INSERIR:** `_paycond_open_for_write` + `create_payment_condition` + `mode create_paycond` + `PingwinService::createPaymentCondition` + job + tracking + endpoint + polling. UI: botão **"Nova condição"** → form (code read-only pré-alocado, description, discount %, days) + matriz tbdocs (checkboxes por documento). Testar criando um descartável e confirmando por releitura.
- **2b — EDITAR (incl. tbdocs):** `update_payment_condition` + `mode update_paycond` + `updatePaymentCondition` + job/endpoint. UI: botão **"Editar"** na linha → mesmo form pré-preenchido (lê a matriz viva). Testar no descartável/`XPLENDOR Teste`.
- **2c — ANULAR:** `void_payment_condition` + `mode void_paycond` + `voidPaymentCondition` + job/endpoint (com `block_when_impersonating`). UI: botão **"Anular"** com confirmação. Testar no descartável; confirmar STATE 1.

### Como a UI read-only passa a ter botões

A tela atual [CondicoesPagamentoPage.tsx](web/src/pages/Restauracao/CondicoesPagamentoPage.tsx) é só leitura. Padrão a seguir (igual aos artigos): os helpers de escrita vão para [laravel_helper.ts](web/src/helpers/laravel_helper.ts) (ex.: `createPingwinPaymentCondition`, `updatePingwinPaymentCondition`, `voidPingwinPaymentCondition`, + `getPingwinPaymentConditionCreation` para polling), as rotas de escrita entram no grupo `ensure_module:pingwin` de [api.php](server/routes/api.php) (POST criar, PUT/PATCH editar, POST/DELETE anular com `block_when_impersonating`), e a UI ganha:
- botão **"Nova condição"** no cabeçalho (como o "Sincronizar"),
- botões **"Editar"/"Anular"** por linha,
- um **form/modal** com a matriz tbdocs em checkboxes (agrupada por `entitytype`: Cliente/Fornecedor/Armazém/Empregado),
- feedback **assíncrono** (toast "a criar…" + polling do `creation_id`, como os artigos).

O **cross-docconfig** (ligar a config de documentos ao 1209, etc.) continua a ser **fatia posterior** — a Fatia 2 só manipula o `deleted` da matriz que já existe.

---

## Anexo — ficheiros-chave

**Python** — [mycloudpie.py](scraper/sources/pingwin/mycloudpie.py): `_port_session` (:294), `_paycond_base` (:662), `_paycond_headers` (:675), `_paycond_list` (:686), `_paycond_open` (:692, **fecha — é de leitura**), `fetch_payment_conditions` (:722); referência product: `create_product` (:1300), `delete_product` (:1617), `_product_browser_by_code` (:1280). [run.py](scraper/sources/pingwin/run.py): dispatch (:96).

**PHP** — [PingwinService.php](server/app/Services/PingwinService.php): `createProduct` (:354), `updateProduct` (:393), `readProduct` (:523), `deleteProduct` (:743), `decimalToCents` (:341), `syncPaymentConditions` (Fatia 1). [CompanyPingwinController.php](server/app/Http/Controllers/Api/V1/CompanyPingwinController.php): `createArticle` (:360, **padrão async + tracking + polling**). [api.php](server/routes/api.php): rotas de escrita de artigos (:207-214).

**Frontend** — [CondicoesPagamentoPage.tsx](web/src/pages/Restauracao/CondicoesPagamentoPage.tsx), [laravel_helper.ts](web/src/helpers/laravel_helper.ts), [pingwin.model.ts](web/src/common/models/pingwin.model.ts).
