"""
XPLENDOR — Entrypoint PingWin (Incremento 1). Invocado pelo Laravel:

    docker exec xplendor-scraper python /scraper/sources/pingwin/run.py

As credenciais chegam por STDIN (JSON) — NUNCA por argv (argv aparece no `ps`).
Devolve JSON por STDOUT. Logs vão para STDERR (nunca poluem o STDOUT do resultado).

Ciclo (LOGOUT GARANTIDO pelo `with`):
  · mode="validate" → login + logout (teste de ligação antes de gravar credenciais);
  · mode="sync"     → login → descobrir lojas → resumo de vendas por loja → logout.

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
OPTIONAL_CLIENT = ["application", "app_grupopie", "units_url", "units_port"]

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

        if mode == "catalog":
            # READ-ONLY: catálogo de ARTIGOS (produtos) via browserdataset paginado.
            # O dataset_id do catálogo é por-instalação (capturado) → vem da config
            # (PINGWIN_CATALOG_DATASET_ID), como o stores_dataset_id.
            dataset_id = cfg.get("catalog_dataset_id")
            if not dataset_id:
                return {"ok": False, "error": "catalog_dataset_id em falta (definir PINGWIN_CATALOG_DATASET_ID)."}
            articles = client.fetch_catalog(dataset_id)
            return {"ok": True, "mode": "catalog", "articles": articles}

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

        if mode == "units":
            # READ-ONLY: UNIDADES (base de conversão) na PORTA 8138. Usa só o
            # maindataset (ignora o baseunit "radio conv." = lixo). Login PRÓPRIO
            # na 8138 (a sessão da 8136 não é aceite lá).
            units = client.fetch_units()
            return {"ok": True, "mode": "units", "units": units}

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
