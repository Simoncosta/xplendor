"""
XPLENDOR — Entrypoint PingWin (Incremento 1). Invocado pelo Laravel:

    docker exec xplendor-scraper python /scraper/sources/pingwin/run.py

As credenciais chegam por STDIN (JSON) — NUNCA por argv (argv aparece no `ps`).
Devolve JSON por STDOUT. Logs vão para STDERR (nunca poluem o STDOUT do resultado).

Ciclo (LOGOUT GARANTIDO pelo `with`):
  · mode="validate" → login + logout (teste de ligação antes de gravar credenciais);
  · mode="sync"     → login → descobrir lojas → resumo de vendas por loja → logout;
  · mode="item_sales" → login → Vendas por artigo (até 7 dias) → logout;
  · mode="store_year" → login → acumulado mensal de uma loja num ano → logout;
  · mode="hourly_sales" → login → Vendas por hora (até 7 dias) → logout.

Segurança:
  · PINGWIN_ALLOWED_HOSTS (allowlist) — mitiga o SSL fraco apontar a outro host;
  · scrub — o sessionid vai na querystring do download; nunca o deixamos sair em
    STDOUT/erros.
"""
import json
import logging
import os
import re
import sys
from datetime import datetime, timedelta
from urllib.parse import urlparse

# Import robusto quer corra como módulo quer como script solto.
try:
    from .mycloudpie import build_client
except ImportError:  # execução direta: python /scraper/sources/pingwin/run.py
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    from mycloudpie import build_client  # type: ignore

# Logs para STDERR (o STDOUT é só o JSON do resultado).
logging.basicConfig(level=logging.INFO, stream=sys.stderr,
                    format="%(asctime)s %(levelname)s %(name)s: %(message)s")
log = logging.getLogger("pingwin.run")

# Campos obrigatórios para o LOGIN (globais do .env + os 3 por-empresa). O
# Laravel compõe o payload: globais (auth_url/api_url/frontend_url/app_version/…)
# + por-empresa (username/database/password). O report_id/stores só importam no
# sync e vão com default "" ao construtor.
REQUIRED = ["auth_url", "api_url", "frontend_url", "database", "app_version",
            "username", "password"]

# Chaves que o construtor do cliente aceita (report_id/stores default "").
CLIENT_KEYS = ["auth_url", "api_url", "frontend_url", "database", "app_version",
               "username", "password", "report_id", "stores"]

# Chaves opcionais que o construtor aceita (defaults no cliente).
OPTIONAL_CLIENT = ["application", "app_grupopie", "units_url", "units_port", "product_url", "product_port",
                   "paycond_url", "paycond_port"]

_SESSIONID_RE = re.compile(r"(sessionid=)[^&\s\"']+", re.IGNORECASE)


def scrub(text: str) -> str:
    """Remove qualquer sessionid de mensagens (vai na querystring do download)."""
    return _SESSIONID_RE.sub(r"\1[REDACTED]", str(text))


def _host_allowed(host: str, allowed: list) -> bool:
    """Um host é permitido se bate EXATO ou é subdomínio de uma entrada de
    domínio. Ex.: 'mycloudpie.com' na allowlist permite 'yuko.mycloudpie.com'
    (o frontend_url deriva do database → varia por restaurante)."""
    return any(host == a or host.endswith("." + a) for a in allowed)


def check_hosts_allowed(cfg: dict) -> None:
    """Allowlist de hosts (PINGWIN_ALLOWED_HOSTS, separada por vírgulas). Se
    definida, os hosts de auth/api/frontend TÊM de estar lá (exato ou subdomínio)
    — mitiga o SSL fraco apontar a um host malicioso. Vazia → não restringe (dev)."""
    # Allowlist vem do payload (Laravel .env, fonte única) ou, em fallback, do
    # ambiente do container. Vazia → não restringe (dev).
    raw = cfg.get("allowed_hosts") or os.environ.get("PINGWIN_ALLOWED_HOSTS", "")
    allowed = [h.strip().lower() for h in raw.split(",") if h.strip()]
    if not allowed:
        log.warning("PINGWIN_ALLOWED_HOSTS não definido — sem allowlist de hosts (dev).")
        return
    for key in ("auth_url", "api_url", "frontend_url"):
        url = cfg.get(key)
        if not url:
            continue
        host = (urlparse(url).hostname or "").lower()
        if not _host_allowed(host, allowed):
            raise RuntimeError(f"Host '{host}' ({key}) não está em PINGWIN_ALLOWED_HOSTS.")


def build_kwargs(cfg: dict) -> dict:
    # report_id/stores podem faltar no validate → default "" (o construtor exige-os).
    kwargs = {k: cfg.get(k, "") for k in CLIENT_KEYS}
    for k in OPTIONAL_CLIENT:
        if cfg.get(k):
            kwargs[k] = cfg[k]
    return kwargs


def run(cfg: dict) -> dict:
    mode = cfg.get("mode", "sync")
    check_hosts_allowed(cfg)

    # `with` → login à entrada, LOGOUT SEMPRE à saída (mesmo com exceção a meio).
    with build_client(**build_kwargs(cfg)) as client:
        if mode == "validate":
            # Só provar credenciais: o with já fez login; o with fará logout.
            return {"ok": True, "mode": "validate"}

        if mode == "documents":
            # READ-ONLY: lista de tipos de documento (Definições→Documentos).
            docs = client.fetch_document_configs()
            return {"ok": True, "mode": "documents", "documents": docs}

        if mode == "documentconfig_probe":
            # PROBE (só leitura, D0): resumo estrutural de UM documento por id.
            doc_id = str(cfg.get("doc_id") or "")
            if not doc_id:
                return {"ok": False, "error": "doc_id em falta (id do documento a sondar)."}
            form = client.probe_documentconfig(doc_id)
            return {"ok": True, "mode": "documentconfig_probe", "form": form}

        if mode == "void_documentconfig":
            # ⚠️ ESCRITA (D4): ANULAR documento ATIVO (soft-delete, 1 POST sem body).
            # Confirma por releitura (sai do STATE 0, entra no STATE 1). Só ativos.
            doc_id = str(cfg.get("doc_id") or "")
            if not doc_id:
                return {"ok": False, "error": "doc_id em falta (id do documento a anular)."}
            dataset_id = cfg.get("docs_dataset_id") or "1099511639239"
            result = client.void_document_config(doc_id, dataset_id)
            return {"ok": bool(result.get("voided_confirmed")), "mode": "void_documentconfig", "result": result}

        if mode == "documentconfig_probe_new":
            # PROBE (só leitura): form-novo do documento — id provisório, code sugerido,
            # tamanho do code, filhas template. NÃO grava.
            return {"ok": True, "mode": "documentconfig_probe_new", "form": client.probe_new_documentconfig()}

        if mode == "create_documentconfig":
            # ⚠️ ESCRITA (D3): CRIAR documento novo. form-novo → abre escrita → write
            # (maindataset preenchido + filhas template) → 2 commits → id final → confirmar.
            fields = cfg.get("fields") or {}
            result = client.create_document_config(fields)
            return {"ok": bool(result.get("persisted")), "mode": "create_documentconfig", "result": result}

        if mode == "update_documentconfig":
            # ⚠️ ESCRITA (D1): editar o maindataset de um documento ATIVO. GET vivo →
            # abre escrita (storedataset) → write grande (filhas preservadas) → 2 commits
            # → confirmar por releitura. doc_id + fields (campos do maindataset a sobrepor).
            doc_id = str(cfg.get("doc_id") or "")
            if not doc_id:
                return {"ok": False, "error": "doc_id em falta (id do documento a editar)."}
            fields = cfg.get("fields") or {}
            children_changes = cfg.get("children_changes") or {}
            docaccount_changes = cfg.get("docaccount_changes") or []
            result = client.update_document_config(doc_id, fields, children_changes, docaccount_changes)
            return {"ok": bool(result.get("persisted")), "mode": "update_documentconfig", "result": result}

        if mode == "documents_rich":
            # READ-ONLY (D0): config COMPLETA de cada documento (maindataset + options +
            # 14 filhas + additionalfields). dataset_id da lista = PINGWIN_DOCS_DATASET_ID
            # (default 1099511639239, o mesmo da lista básica).
            dataset_id = cfg.get("docs_dataset_id") or "1099511639239"
            documents = client.fetch_document_configs_rich(dataset_id)
            return {"ok": True, "mode": "documents_rich", "documents": documents}

        if mode == "catalog":
            # READ-ONLY: catálogo de ARTIGOS (produtos) via browserdataset paginado.
            # O dataset_id do catálogo é por-instalação (capturado) → vem da config
            # (PINGWIN_CATALOG_DATASET_ID), como o stores_dataset_id.
            dataset_id = cfg.get("catalog_dataset_id")
            if not dataset_id:
                return {"ok": False, "error": "catalog_dataset_id em falta (definir PINGWIN_CATALOG_DATASET_ID)."}
            # F1-2: catalog_complete (só com o interruptor da empresa ligado) usa a leitura
            # completa, que não para numa página curta. Sem ele, a leitura é a de sempre.
            if cfg.get("catalog_complete"):
                articles = client.fetch_catalog_complete(dataset_id)
            else:
                articles = client.fetch_catalog(dataset_id)
            # Também os ANULADOS (STATE:1) → para marcar is_active=false no espelho.
            # Se ESTA leitura falhar, o raise propaga e o run devolve ok:false → o PHP
            # NÃO aplica nada (nem os ativos): melhor não corrigir do que meio-corrigir.
            deleted_ids = (client.fetch_catalog_deleted_ids_complete(dataset_id) if cfg.get("catalog_complete")
                           else client.fetch_catalog_deleted_ids(dataset_id))
            return {"ok": True, "mode": "catalog", "articles": articles, "deleted_ids": deleted_ids,
                    "diagnostics": getattr(client, "last_catalog_diag", None)}

        if mode == "paycond":
            # READ-ONLY (Fatia 1): CONDIÇÕES DE PAGAMENTO. Lista (browserdataset, 8136) +
            # detalhe por id (discount/days/tbdocs/additionalfields). O dataset_id é
            # por-instalação (do HAR) → vem da config (PINGWIN_PAYCOND_DATASET_ID).
            dataset_id = cfg.get("paycond_dataset_id")
            if not dataset_id:
                return {"ok": False, "error": "paycond_dataset_id em falta (definir PINGWIN_PAYCOND_DATASET_ID)."}
            conditions = client.fetch_payment_conditions(dataset_id)
            return {"ok": True, "mode": "paycond", "payment_conditions": conditions}

        if mode == "create_paycond":
            # ⚠️ ESCRITA (Fatia 2a): CRIAR condição de pagamento. NEW→MERGE→SAVE→CLOSE→
            # confirmar por releitura. Ação deliberada (confirmada a montante). discount
            # vai NÚMERO, days int. Aborta antes do commit se o passo 2 não der id final.
            fields = cfg.get("paycond") or {}
            tbdocs_unlinked = cfg.get("tbdocs_unlinked") or []
            result = client.create_payment_condition(fields, tbdocs_unlinked)
            return {"ok": bool(result.get("persisted")), "mode": "create_paycond", "result": result}

        if mode == "update_paycond":
            # ⚠️ ESCRITA (Fatia 2b): EDITAR condição ATIVA. Passo 1 abre o VIVO por id
            # (matriz tbdocs real) → merge só das mudanças → MERGE→SAVE→CLOSE→confirmar.
            # code NÃO muda; discount NÚMERO, days int. tbdocs_changes = [{docconfig_id, deleted}].
            paycond_id = str(cfg.get("paycond_id") or "")
            if not paycond_id:
                return {"ok": False, "error": "paycond_id em falta (id da condição a editar)."}
            fields = cfg.get("paycond") or {}
            tbdocs_changes = cfg.get("tbdocs_changes") or []
            result = client.update_payment_condition(paycond_id, fields, tbdocs_changes)
            return {"ok": bool(result.get("persisted")), "mode": "update_paycond", "result": result}

        if mode == "void_paycond":
            # ⚠️ ESCRITA (Fatia 2c): ANULAR condição ATIVA (soft-delete, 1 POST sem body).
            # Confirma por releitura (sai do STATE 0, entra no STATE 1). Só ativas.
            paycond_id = str(cfg.get("paycond_id") or "")
            if not paycond_id:
                return {"ok": False, "error": "paycond_id em falta (id da condição a anular)."}
            dataset_id = cfg.get("paycond_dataset_id")
            if not dataset_id:
                return {"ok": False, "error": "paycond_dataset_id em falta (confirmação por releitura)."}
            result = client.void_payment_condition(paycond_id, dataset_id)
            return {"ok": bool(result.get("voided_confirmed")), "mode": "void_paycond", "result": result}

        if mode == "paycond_probe_new":
            # PROBE SEGURO (só leitura): abre o form-novo do paycond e LÊ (sem commit).
            # Para validar o Action do passo 1 e capturar a matriz-template. NÃO grava.
            action = cfg.get("probe_action") or "NEW,GET,INFO"
            form = client.probe_new_paycond_form(action)
            return {"ok": True, "mode": "paycond_probe_new", "form": form}

        if mode == "families":
            # READ-ONLY: árvore de FAMÍLIAS (GET /family → family.maindataset). Um só
            # pedido traz todas (flat, com parent_id). A árvore monta-se na exibição.
            families = client.fetch_families()
            return {"ok": True, "mode": "families", "families": families}

        if mode == "suppliers":
            # READ-ONLY: FORNECEDORES via browserdataset paginado. O dataset_id é
            # por-instalação (capturado no HAR) → vem da config
            # (PINGWIN_SUPPLIERS_DATASET_ID), como o catalog_dataset_id.
            dataset_id = cfg.get("suppliers_dataset_id")
            if not dataset_id:
                return {"ok": False, "error": "suppliers_dataset_id em falta (definir PINGWIN_SUPPLIERS_DATASET_ID)."}
            suppliers = client.fetch_suppliers(dataset_id)
            return {"ok": True, "mode": "suppliers", "suppliers": suppliers}

        # ── FORNECEDORES — ESCRITA (FN). Confirmação por releitura no cliente. ──
        if mode == "read_supplier":
            sid = str(cfg.get("supplier_id") or "")
            if not sid:
                return {"ok": False, "error": "supplier_id em falta."}
            return {"ok": True, "mode": "read_supplier", "supplier": client.read_supplier(sid)}

        if mode == "find_supplier_by_nif":
            dataset_id = cfg.get("suppliers_dataset_id")
            nif = str(cfg.get("nif") or "").strip()
            if not dataset_id or not nif:
                return {"ok": False, "error": "suppliers_dataset_id e nif obrigatórios."}
            return {"ok": True, "mode": "find_supplier_by_nif", "nif": nif, **client.find_suppliers_by_nif(dataset_id, nif)}

        if mode == "create_supplier":
            # ⚠️ ESCRITA: NEW → GET,INFO → MERGE → SAVE → CLOSE (sempre) → releitura.
            res = client.create_supplier(cfg.get("supplier") or {}, bool(cfg.get("allow_duplicate_nif")))
            return {"mode": "create_supplier", **res, "ok": bool(res.get("ok"))}

        if mode == "update_supplier":
            # ⚠️ ESCRITA: OPEN → GET,INFO (vivo) → MERGE → SAVE → CLOSE (sempre) → releitura.
            sid = str(cfg.get("supplier_id") or "")
            if not sid:
                return {"ok": False, "error": "supplier_id em falta."}
            res = client.update_supplier(sid, cfg.get("supplier") or {}, bool(cfg.get("allow_duplicate_nif")))
            return {"mode": "update_supplier", **res, "ok": bool(res.get("ok"))}

        if mode == "void_supplier":
            # ⚠️ ESCRITA: /service/supplier/{id} DELETE,CLOSE → confirma STATE 0 → STATE 1.
            sid = str(cfg.get("supplier_id") or "")
            dataset_id = cfg.get("suppliers_dataset_id")
            if not sid or not dataset_id:
                return {"ok": False, "error": "supplier_id e suppliers_dataset_id obrigatórios."}
            res = client.void_supplier(sid, dataset_id)
            return {"mode": "void_supplier", **res, "ok": bool(res.get("ok"))}

        if mode == "supplier_cc":
            # READ-ONLY (S1): CONTA CORRENTE de uma lista de fornecedores (entity_ids =
            # pingwin_id). Documentos (período largo) + saldo por fornecedor; uma falha
            # num fornecedor vem como {entity_id, ok:false, error} sem abortar o lote.
            entity_ids = [str(e) for e in (cfg.get("entity_ids") or []) if str(e).strip()]
            if not entity_ids:
                return {"ok": False, "error": "entity_ids em falta (lista de pingwin_id de fornecedores)."}
            return {"ok": True, "mode": "supplier_cc", "suppliers": client.fetch_supplier_cc(entity_ids)}

        if mode == "launch_supplier_invoice":
            # ⚠️ ESCRITA (FB-1): cria UMA fatura de fornecedor em RASCUNHO (8001) e relê-a logo
            # (leitor da F4: OPEN → GET header,details → CLOSE) para a confirmação no PHP.
            doc = cfg.get("doc") or {}
            res = client.launch_supplier_invoice(doc)
            reread = None
            if res.get("saved") is True and res.get("docheader_id"):
                reread = client.fetch_supplier_document_lines([{"docconfig_id": res.get("docconfig_id") or "1209", "docheader_id": res["docheader_id"]}])[0]
            return {"ok": True, "mode": "launch_supplier_invoice", "result": res, "reread": reread}

        if mode == "document_status":
            # ⚠️ ESCRITA (FB-1): fecha (8002) ou anula (8003) UM documento e relê-o.
            dh = str(cfg.get("docheader_id") or "")
            if not dh:
                return {"ok": False, "error": "docheader_id em falta."}
            res = client.set_document_status(dh, str(cfg.get("docstatus_id") or ""))
            reread = client.fetch_supplier_document_lines([{"docconfig_id": str(cfg.get("docconfig_id") or "1209"), "docheader_id": dh}])[0]
            return {"ok": True, "mode": "document_status", "result": res, "reread": reread}

        if mode == "supplier_document_lines":
            # READ-ONLY (F4): header + LINHAS de uma lista de documentos de fornecedor
            # ({docconfig_id, docheader_id}). OPEN → GET header,details → CLOSE (sempre),
            # um documento de cada vez; uma falha vem {ok:false, error} sem abortar o lote.
            docs = [d for d in (cfg.get("docs") or []) if isinstance(d, dict)]
            if not docs:
                return {"ok": False, "error": "docs em falta (lista de {docconfig_id, docheader_id})."}
            return {"ok": True, "mode": "supplier_document_lines", "documents": client.fetch_supplier_document_lines(docs)}

        if mode == "supplier_documents":
            # READ-ONLY (F1): DOCUMENTOS DE FORNECEDOR da lista "Documentos" (DOCTYPE 2002),
            # de start a end (AAAA-MM-DD), em blocos de 7 dias divididos se baterem no teto.
            dataset_id = str(cfg.get("supplier_documents_dataset_id") or "")
            if not dataset_id:
                return {"ok": False, "error": "supplier_documents_dataset_id em falta (PINGWIN_SUPPLIER_DOCUMENTS_DATASET_ID)."}
            try:
                start = datetime.strptime(str(cfg.get("start") or ""), "%Y-%m-%d").date()
                end = datetime.strptime(str(cfg.get("end") or ""), "%Y-%m-%d").date()
            except ValueError:
                return {"ok": False, "error": "start/end em falta ou inválidos (AAAA-MM-DD)."}
            if end < start:
                return {"ok": False, "error": "end antes de start."}
            res = client.fetch_supplier_documents(dataset_id, start, end)
            return {"ok": True, "mode": "supplier_documents", "start": start.isoformat(), "end": end.isoformat(), **res}

        if mode == "units":
            # READ-ONLY: UNIDADES (base de conversão) na PORTA 8138. Usa só o
            # maindataset (ignora o baseunit "radio conv." = lixo). Login PRÓPRIO
            # na 8138 (a sessão da 8136 não é aceite lá).
            units = client.fetch_units()
            return {"ok": True, "mode": "units", "units": units}

        if mode == "product_form_lookups":
            # READ-ONLY (Caminho 1/3): NEW,GET,INFO na 8134 para LER o form do artigo
            # (code novo + lookups), CLOSE logo, e salvaguarda por browserdataset
            # (a contagem de artigos NÃO pode subir). NUNCA MERGE/SAVE — isso é a 1b.
            dataset_id = cfg.get("catalog_dataset_id")
            if not dataset_id:
                return {"ok": False, "error": "catalog_dataset_id em falta (salvaguarda de não-persistência)."}
            form = client.product_form_lookups(dataset_id)
            return {"ok": True, "mode": "product_form_lookups", "form": form}

        if mode == "create_product":
            # ⚠️ ESCRITA: CRIAR um artigo (porta 8134). Sequência NEW→(prices)→MERGE→
            # SAVE→CLOSE→confirmar por browserdataset. Ação deliberada (confirmada a
            # montante no Laravel/UI). Preço já chega como decimal string ("10.25").
            product = cfg.get("product") or {}
            saleprice = cfg.get("saleprice")
            purchaseprice = cfg.get("purchaseprice")
            dataset_id = cfg.get("catalog_dataset_id")
            if not dataset_id:
                return {"ok": False, "error": "catalog_dataset_id em falta (confirmação por releitura)."}
            result = client.create_product(product, saleprice, purchaseprice, dataset_id)
            return {"ok": bool(result.get("persisted")), "mode": "create_product", "result": result}

        if mode == "read_product":
            # SÓ LEITURA (etapa 2): lê o artigo completo pelo id (OPEN,GET,INFO → CLOSE).
            product_id = str(cfg.get("product_id") or "")
            if not product_id:
                return {"ok": False, "error": "product_id em falta (id do artigo a ler)."}
            product = client.read_product(product_id)
            return {"ok": True, "mode": "read_product", "product": product}

        if mode == "update_product":
            # ⚠️ ESCRITA: EDITAR um artigo (OPEN→(prices)→MERGE curto→SAVE→CLOSE→confirmar).
            product_id = str(cfg.get("product_id") or "")
            changes = cfg.get("changes") or {}
            saleprice = cfg.get("saleprice")
            purchaseprice = cfg.get("purchaseprice")
            supplier_prices_changes = cfg.get("supplier_prices_changes") or None
            if not product_id:
                return {"ok": False, "error": "product_id em falta (id do artigo a editar)."}
            result = client.update_product(product_id, changes, saleprice, purchaseprice, supplier_prices_changes)
            return {"ok": bool(result.get("ok")), "mode": "update_product", "result": result}

        if mode == "delete_product":
            # ⚠️ ESCRITA: APAGAR um artigo (DELETE definitivo). Ação deliberada (confirmada
            # a montante). CANCEL,CLOSE → DELETE → releitura pelo code (tem de vir VAZIA).
            product_id = str(cfg.get("product_id") or "")
            code = str(cfg.get("code") or "")
            dataset_id = cfg.get("catalog_dataset_id")
            if not product_id:
                return {"ok": False, "error": "product_id em falta (id do artigo a apagar)."}
            if not dataset_id:
                return {"ok": False, "error": "catalog_dataset_id em falta (confirmação por releitura)."}
            result = client.delete_product(product_id, code, dataset_id)
            return {"ok": bool(result.get("deleted_confirmed")), "mode": "delete_product", "result": result}

        if mode == "create_unit":
            # ⚠️ ESCRITA: CRIAR uma unidade (Action NEW) na PORTA 8136 (sessão principal).
            # Ação deliberada do utilizador (já confirmada a montante no Laravel/UI).
            unit = cfg.get("unit") or {}
            created = client.create_unit(unit)
            return {"ok": True, "mode": "create_unit", "unit": created}

        if mode == "save_unit":
            # ⚠️ ESCRITA: GRAVAR uma unidade existente (Action EDIT,SAVE) na PORTA 8136.
            # Editar (deleted=0) ou anular (deleted=1) — a flag no objeto decide.
            unit = cfg.get("unit") or {}
            saved = client.save_unit(unit)
            return {"ok": True, "mode": "save_unit", "unit": saved}

        if mode == "store_year":
            # READ-ONLY (F1-2): acumulado mês a mês de UMA loja num ano (relatório anual),
            # para detetar o primeiro mês com vendas. Locals vazio salvo indicação.
            report_id = str(cfg.get("annual_report_id") or "")
            store = str(cfg.get("store") or "")
            try:
                year = int(cfg.get("year"))
            except (TypeError, ValueError):
                return {"ok": False, "error": "year em falta ou inválido."}
            months = client.fetch_store_year(report_id, store, year, str(cfg.get("annual_locals") or ""))
            return {"ok": True, "mode": "store_year", "store": store, "year": year, "months": months}

        if mode == "hourly_sales":
            # READ-ONLY (F2): Vendas por hora, loja × dia × hora (sem IVA), até 7 dias por pedido.
            report_id = str(cfg.get("hourly_sales_report_id") or "")
            stores_csv = str(cfg.get("stores") or "")
            try:
                start = datetime.strptime(str(cfg.get("start") or ""), "%Y-%m-%d")
                end = datetime.strptime(str(cfg.get("end") or ""), "%Y-%m-%d")
            except ValueError:
                return {"ok": False, "error": "start/end em falta ou inválidos (AAAA-MM-DD)."}
            rows = client.fetch_hourly_sales(report_id, stores_csv, start, end)
            return {"ok": True, "mode": "hourly_sales", "start": start.strftime("%Y-%m-%d"),
                    "end": end.strftime("%Y-%m-%d"), "rows": rows}

        if mode == "item_sales":
            # READ-ONLY (F1): Vendas por artigo, loja × dia × artigo, até 7 dias por pedido.
            # Só saem os campos de ITEM_SALES_FIELDS (sem empresa, sem descontos).
            report_id = str(cfg.get("item_sales_report_id") or "")
            stores_csv = str(cfg.get("stores") or "")
            try:
                start = datetime.strptime(str(cfg.get("start") or ""), "%Y-%m-%d")
                end = datetime.strptime(str(cfg.get("end") or ""), "%Y-%m-%d")
            except ValueError:
                return {"ok": False, "error": "start/end em falta ou inválidos (AAAA-MM-DD)."}
            rows = client.fetch_item_sales(report_id, stores_csv, start, end)
            return {"ok": True, "mode": "item_sales", "start": start.strftime("%Y-%m-%d"),
                    "end": end.strftime("%Y-%m-%d"), "rows": rows}

        # sync — descoberta de lojas + resumo de vendas por loja.
        stores = []
        dataset_id = cfg.get("stores_dataset_id")
        if dataset_id:
            stores = client.fetch_stores(dataset_id)

        target = (
            datetime.strptime(cfg["date"], "%Y-%m-%d")
            if cfg.get("date")
            else datetime.now() - timedelta(days=1)  # ontem por defeito
        )
        df = client.fetch_sales_report(target)
        sales = client.extract_store_data(df)

        return {
            "ok": True,
            "mode": "sync",
            "date": target.strftime("%Y-%m-%d"),
            "stores": stores,
            "sales": sales,
        }


def main() -> int:
    try:
        raw = sys.stdin.read()
        cfg = json.loads(raw) if raw.strip() else {}
    except Exception as exc:  # noqa: BLE001
        print(json.dumps({"ok": False, "error": f"STDIN inválido: {type(exc).__name__}"}))
        return 2

    missing = [k for k in REQUIRED if not cfg.get(k)]
    if missing:
        print(json.dumps({"ok": False, "error": f"Config em falta: {', '.join(missing)}"}))
        return 2

    try:
        result = run(cfg)
        print(json.dumps(result, ensure_ascii=False))
        return 0
    except Exception as exc:  # noqa: BLE001 — o with já garantiu o logout
        print(json.dumps({"ok": False, "error": scrub(f"{type(exc).__name__}: {exc}")}, ensure_ascii=False))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
