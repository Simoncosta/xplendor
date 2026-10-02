"""
Cliente para o sistema POS (MyCloudPie / PBO SOA — GrupoPIE).

CÓPIA VENDORIZADA de yukotavern/winrest/mycloudpie.py (relatório §3, §7). A única
diferença é a remoção dos defaults hardcoded da Yuko no construtor (§7/§10): os
IDs por-instalação (report_id, stores, frontend_url, database, app_version) são
agora OBRIGATÓRIOS e vêm da config do tenant. O protocolo é idêntico:

  - LegacySSLAdapter (SECLEVEL=1, TLS 1.0/1.1)            — §3.2
  - custom_pbkdf2 (1000× MD5 com XOR; NÃO é pbkdf2_hmac)  — §3.4  ← copiar tal e qual
  - login challenge-response, inversão nonce/salt          — §3.3
  - download via sessionid na querystring (não header)     — §3.6
  - parse .xls via xlrd 1.x                                 — §3.7

XPLENDOR — Incremento 1 (2026-09): protocolo TAL E QUAL o original. Só se
ACRESCENTOU (sem tocar no protocolo): _TimeoutSession (timeout em todos os
pedidos), logout() (best-effort) e __enter__/__exit__ (context manager →
LOGOUT GARANTIDO ao sair do `with`, mesmo com erro a meio).
"""
import base64
import hashlib
import logging
import os
import ssl
import sys
import time
from contextlib import contextmanager
from datetime import datetime
from typing import Any, Dict, List
from urllib.parse import quote, urlparse

import pandas as pd
import requests
import xlrd
from requests.adapters import HTTPAdapter
from urllib3.util.ssl_ import create_urllib3_context

log = logging.getLogger("mycloudpie")

# (connect, read) segundos. NENHUM pedido pode pendurar a sessão indefinidamente.
DEFAULT_TIMEOUT = (10, 60)


class _TimeoutSession(requests.Session):
    """
    ACRESCENTO XPLENDOR (não é do protocolo). requests.Session com timeout por
    defeito em TODOS os pedidos. O mycloudpie.py original chamava session.post/get
    SEM timeout — um upstream pendurado segurava a sessão para sempre (crítico:
    sessão presa estraga o restaurante). Injeta o timeout aqui, sem tocar em
    nenhuma chamada do protocolo (que fica byte-a-byte).
    """
    def request(self, *args, **kwargs):
        kwargs.setdefault("timeout", DEFAULT_TIMEOUT)
        return super().request(*args, **kwargs)


class LegacySSLAdapter(HTTPAdapter):
    """
    HTTPAdapter que aceita cifras "legacy" exigidas pelo servidor PBO SOA.
    Necessário porque o OpenSSL recente rejeita por defeito as cifras antigas
    que este servidor oferece. (Relatório §3.2.)
    """
    def __init__(self, *args, **kwargs):
        ctx = create_urllib3_context(ciphers="DEFAULT@SECLEVEL=1")
        ctx.options &= ~ssl.OP_NO_TLSv1
        ctx.options &= ~ssl.OP_NO_TLSv1_1
        self._legacy_ssl_context = ctx
        super().__init__(*args, **kwargs)

    def init_poolmanager(self, *args, **kwargs):
        kwargs["ssl_context"] = self._legacy_ssl_context
        return super().init_poolmanager(*args, **kwargs)

    def proxy_manager_for(self, *args, **kwargs):
        kwargs["ssl_context"] = self._legacy_ssl_context
        return super().proxy_manager_for(*args, **kwargs)


def parse_xls_to_dataframe(content: bytes) -> pd.DataFrame:
    """
    Lê ficheiros .xls (Excel 97-2003) usando xlrd 1.2.0 diretamente.
    Necessário porque pandas 2.x exige xlrd>=2.0, que já não suporta .xls. (§3.7)
    """
    # ⚠️ O xlrd escreve avisos no seu `logfile`, que por defeito é sys.stdout — o
    # aviso INOFENSIVO "file size ... not ... multiple of sector size" iria poluir
    # o STDOUT (onde o run.py imprime o JSON do resultado) e partir o parse. Envia
    # o logfile do xlrd para STDERR (informativo), deixando o STDOUT só com o JSON.
    book = xlrd.open_workbook(file_contents=content, logfile=sys.stderr)
    sheet = book.sheet_by_index(0)

    if sheet.nrows == 0:
        return pd.DataFrame()

    headers = [sheet.cell_value(0, c) for c in range(sheet.ncols)]
    rows = [
        [sheet.cell_value(r, c) for c in range(sheet.ncols)]
        for r in range(1, sheet.nrows)
    ]

    return pd.DataFrame(rows, columns=headers)


def custom_pbkdf2(password: str, salt_hex: str) -> str:
    """
    PBKDF2 customizado do PingWin BO (GrupoPIE): 1000 iterações de MD5 com XOR.
    NÃO substituir por hashlib.pbkdf2_hmac — o algoritmo é propositadamente diferente. (§3.4)

    Iteração 0 : digest = MD5(pwd + salt_bytes); d_xor = digest
    Iterações 1-999 : digest = MD5(pwd + digest_anterior); d_xor = d_xor XOR digest
    Devolve d_xor como string hex de 32 chars.
    """
    pwd = password.encode("utf-8")
    digest = hashlib.md5(pwd + bytes.fromhex(salt_hex)).digest()
    d_xor = digest
    for _ in range(999):
        digest = hashlib.md5(pwd + digest).digest()
        d_xor = bytes(a ^ b for a, b in zip(d_xor, digest))
    return d_xor.hex()


class MyCloudPieClient:
    def __init__(
        self,
        auth_url: str,
        api_url: str,
        username: str,
        password: str,
        report_id: str,
        stores: str,
        frontend_url: str,
        database: str,
        app_version: str,
        application: str = "pbo_soa_2026.0",
        app_grupopie: str = "PBOWEB",
        units_url: str = "",
        units_port: str = "8138",
        product_url: str = "",
        product_port: str = "8134",
        paycond_url: str = "",
        paycond_port: str = "",
    ):
        self.auth_url = auth_url.rstrip("/")
        self.api_url = api_url.rstrip("/")
        self.frontend_url = frontend_url.rstrip("/")
        # ⚠️ As UNIDADES vivem numa PORTA DIFERENTE do SOA GrupoPIE (8138, não a
        # 8136 do browser/relatórios). O SOA reparte áreas por porta. Aceita um
        # override explícito (units_url); senão deriva do api_url trocando a porta.
        self.units_url = units_url.rstrip("/") if units_url else ""
        self.units_port = str(units_port or "8138")
        # ⚠️ A porta do FORM/ESCRITA do artigo NÃO é universal (vimos 8134 e 8136 em
        # sessões diferentes). Default 8134 (confirmado no Yuko), overridable por config
        # (product_url/product_port). O invariante crítico: TODO o fluxo de criar corre
        # numa ÚNICA sessão/porta (o mesmo mecanismo por-porta das Unidades).
        self.product_url = product_url.rstrip("/") if product_url else ""
        self.product_port = str(product_port or "8134")
        # ⚠️ CONDIÇÕES DE PAGAMENTO (paycond, Fatia 1 READ-ONLY). Default: MESMA porta
        # da sessão principal (api_url = 8136, igual ao browserdataset de stores/
        # catálogo/fornecedores — confirmado: auth_url e api_url são :8136). Overridable
        # (paycond_url/paycond_port) caso uma instalação sirva o paycond noutra porta.
        self.paycond_url = paycond_url.rstrip("/") if paycond_url else ""
        self.paycond_port = str(paycond_port or "")
        self.username = username
        self.password = password
        self.report_id = report_id
        self.stores = stores
        self.database = database
        self.app_version = app_version
        self.application = application
        self.app_grupopie = app_grupopie

        self.session_id: str | None = None
        self.signature: str | None = None
        self.request_id: str | None = None

        # ACRESCENTO XPLENDOR: _TimeoutSession (em vez de requests.Session) para
        # garantir timeout em todos os pedidos. O resto é igual ao original.
        self.session = _TimeoutSession()
        # Servidor PBO SOA usa cifras "legacy" — montar adapter compatível
        self.session.mount("https://", LegacySSLAdapter())
        self.session.headers.update({
            "Accept": "application/json, text/plain, */*",
            "Content-Type": "application/json",
            "User-Agent": "Mozilla/5.0 (cron-job)",
        })

    # ---------------------------------------------------- CONTEXT MANAGER
    # ACRESCENTO XPLENDOR: LOGOUT GARANTIDO. `with build_client(...) as c:` faz
    # login à entrada e logout SEMPRE à saída (mesmo com exceção/timeout a meio).
    def __enter__(self) -> "MyCloudPieClient":
        self.login()
        return self

    def __exit__(self, exc_type, exc, tb) -> bool:
        self.logout()   # best-effort, nunca levanta
        return False    # NÃO engole a exceção original da consulta

    # ------------------------------------------------ AUTENTICAÇÃO (por-porta)
    # ⚠️ LIÇÃO (401 na 8138): a sessão do login numa porta NÃO é válida noutra
    # porta/área do SOA GrupoPIE — CADA porta exige o SEU login challenge-response.
    # A autenticação é agora genérica (session + base_url) para servir qualquer
    # porta (8136 relatórios/browser, 8138 unidades, futuras áreas).
    def _authenticate(self, session: requests.Session, base_url: str) -> tuple[str, str, str]:
        """
        Executa o challenge-response (§3.3) contra `base_url`, numa `session`
        qualquer. NÃO mexe no estado do cliente — devolve (session_id, signature,
        request_id) para quem chama gerir. Reutiliza o custom_pbkdf2.
        """
        request_id = base64.b64encode(os.urandom(16)).decode()
        common_headers = {
            "Origin":        self.frontend_url,
            "Referer":       self.frontend_url + "/",
            "RequestID":     request_id,
            "X-Database":    self.database,
            "X-AppGrupoPie": self.app_grupopie,
        }

        # --- Passo 1: obter challenge ---
        r1 = session.post(
            f"{base_url}/service/login",
            data=b"",
            headers={
                **common_headers,
                "UserName":       self.username,
                "X-Application":  self.application,
                "Version":        self.app_version,
                "AcceptRedirect": "true",
                "Content-Length": "0",
            },
        )
        if r1.status_code != 200:
            raise RuntimeError(f"Passo 1 (/service/login @ {base_url}) falhou: HTTP {r1.status_code} — {r1.text}")
        challenge = r1.headers.get("Challenge")
        if not challenge:
            raise RuntimeError(f"Header 'Challenge' ausente (/service/login @ {base_url}). Headers: {dict(r1.headers)}")

        parts = challenge.split(":")
        nonce = parts[1]   # 32 hex chars
        salt  = parts[2]   # 16 hex chars

        auth_hash = custom_pbkdf2(self.password, salt)
        md51           = hashlib.md5(f"{nonce}:{auth_hash}".encode()).hexdigest()
        md52           = hashlib.md5(f"{md51}:{nonce}".encode()).hexdigest()
        authentication = f"1:{quote(self.username, safe='')}:{nonce}:{md52}"
        timestamp_ms   = int(time.time() * 1000)
        signature      = hashlib.md5(f"{auth_hash}{timestamp_ms}".encode()).hexdigest()

        # --- Passo 2: autenticar e obter session ---
        r2 = session.post(
            f"{base_url}/service/authenticate",
            data=b"",
            headers={
                **common_headers,
                "Authentication": authentication,
                "signature":      signature,
                "application":    "boweb",
                "Content-Length": "0",
            },
        )
        if r2.status_code != 200:
            raise RuntimeError(f"Passo 2 (/service/authenticate @ {base_url}) falhou: HTTP {r2.status_code} — {r2.text}")
        session_id = r2.headers.get("Sessionid")
        if not session_id:
            raise RuntimeError(f"Header 'Sessionid' ausente (/service/authenticate @ {base_url}). Headers: {dict(r2.headers)}")

        return session_id, signature, request_id

    def _logout_session(self, session: requests.Session, base_url: str, session_id: str, signature: str, request_id: str) -> None:
        """Logout genérico (best-effort, nunca levanta) de UMA sessão numa porta.
        Usado tanto para a sessão principal (8136) como para as por-porta (8138…).
        Crítico: sem logout a sessão trava no PingWin — agora em qualquer porta."""
        if not session_id:
            return
        try:
            r = session.post(
                f"{base_url}/service/logout",
                data=b"",
                headers={
                    "Sessionid":     session_id,
                    "Signature":     signature or "",
                    "X-AppGrupoPie": self.app_grupopie,
                    "X-Database":    self.database,
                    "RequestID":     request_id or "",
                    "Content-Length": "0",
                },
            )
            if r.status_code == 200:
                log.info("Logout OK @ %s — %s", base_url, (r.text or "").strip()[:60])
            else:
                log.warning("Logout @ %s devolveu HTTP %s (ignorado)", base_url, r.status_code)
        except Exception as exc:  # noqa: BLE001 — best-effort; não mascara o erro real
            log.warning("logout @ %s falhou (ignorado): %s", base_url, type(exc).__name__)

    @contextmanager
    def _port_session(self, base_url: str):
        """
        Context manager para uma ÁREA NOUTRA PORTA (ex.: unidades @ 8138). Faz um
        login PRÓPRIO nessa porta (a sessão da 8136 não é aceite lá — HTTP 401
        "Session not found"), numa session SEPARADA (não mexe na principal), e
        GARANTE o logout dessa porta à saída (mesmo com erro). READ-ONLY.
        """
        session = _TimeoutSession()
        session.mount("https://", LegacySSLAdapter())
        session.headers.update({
            "Accept": "application/json, text/plain, */*",
            "Content-Type": "application/json",
            "User-Agent": "Mozilla/5.0 (cron-job)",
        })
        log.info("A autenticar em %s (login por-porta)…", base_url)
        session_id, signature, request_id = self._authenticate(session, base_url)
        session.headers.update({
            "Sessionid":     session_id,
            "Signature":     signature,
            "X-AppGrupoPie": self.app_grupopie,
        })
        log.info("Login OK @ %s — session=%s…", base_url, session_id[:8])
        try:
            yield session
        finally:
            self._logout_session(session, base_url, session_id, signature, request_id)

    # ---------------------------------------------------------------- LOGOUT
    def logout(self) -> None:
        """Encerra a sessão PRINCIPAL (8136). Best-effort, sempre tentado."""
        if not self.session_id:
            return
        self._logout_session(self.session, self.api_url, self.session_id, self.signature or "", self.request_id or "")
        self.session_id = None  # marca encerrada localmente (evita reuso)

    # ---------------------------------------------------------------- LOGIN
    def login(self) -> None:
        """
        Autenticação challenge-response do PingWin BO (GrupoPIE Portugal) na porta
        PRINCIPAL (auth_url/8136). (§3.3) Delega no _authenticate genérico.
        """
        log.info("A autenticar no MyCloudPie...")
        self.session_id, self.signature, self.request_id = self._authenticate(self.session, self.auth_url)
        self.session.headers.update({
            "Sessionid":     self.session_id,
            "Signature":     self.signature,
            "X-AppGrupoPie": self.app_grupopie,
        })
        log.info(f"Login OK — session={self.session_id[:8]}…")

    # ----------------------------------------------------- GERAR RELATÓRIO
    def trigger_report(self, target_date: datetime) -> str:
        """Pede ao servidor para gerar o .xls (Resumo de Vendas). Devolve o id do ficheiro."""
        date_str = target_date.strftime("%Y%m%dT00:00:00")
        payload = {
            "params": {
                "querystring": f"report.id = '{self.report_id}'",
                "Stores": self.stores,
                "START_DATE": date_str,
                "END_DATE": date_str,
                "Groupby": "1",
                "Family_level": "-1",
                "GROUPBY_LOCAL": 0,
                "sendmail": 0,
                "mimetype": "application/vnd.ms-excel",
                "reportaction": "execute",
            }
        }
        url = f"{self.api_url}/service/report/*/report"
        headers = {
            "Action":       "OPEN,GET,CLOSE",
            "Content-Type": "application/json;charset=UTF-8",
        }
        r = self.session.post(url, json=payload, headers=headers)

        if r.status_code != 200:
            log.error(f"trigger_report falhou: HTTP {r.status_code}")
            log.error(f"Response body (primeiros 2000 chars): {r.text[:2000]}")
            r.raise_for_status()

        data = r.json()
        report_file = data["report"]["report"][0]["reportfile"]
        log.info(f"Relatório gerado: {report_file}")
        return report_file

    # ------------------------------------------- GERAR RELATÓRIO (genérico)
    def trigger_report_custom(
        self,
        report_id: str,
        start_date: "datetime",
        end_date: "datetime",
        store_ids: "list[str] | None" = None,
        extra_params: "dict | None" = None,
    ) -> str:
        """Versão parametrizável de trigger_report para relatórios arbitrários.

        Mantém trigger_report() intacto (Resumo de Vendas usa-o directamente).
        Devolve o reportfile ID (mesmo formato que trigger_report).
        """
        extra_params = extra_params or {}
        stores = ",".join(store_ids) if store_ids else self.stores
        payload = {
            "params": {
                "querystring": f"report.id = '{report_id}'",
                "START_DATE":    start_date.strftime("%Y%m%dT00:00:00"),
                "END_DATE":      end_date.strftime("%Y%m%dT00:00:00"),
                "Stores":        stores,
                "mimetype":      "application/vnd.ms-excel",
                "reportaction":  "execute",
                **extra_params,
            }
        }
        url = f"{self.api_url}/service/report/*/report"
        headers = {
            "Action":       "OPEN,GET,CLOSE",
            "Content-Type": "application/json;charset=UTF-8",
        }
        r = self.session.post(url, json=payload, headers=headers)
        if r.status_code != 200:
            log.error(f"trigger_report_custom falhou: HTTP {r.status_code}")
            log.error(f"Response body: {r.text[:2000]}")
            r.raise_for_status()
        data = r.json()
        report_file = data["report"]["report"][0]["reportfile"]
        log.info(f"Relatório gerado: {report_file}")
        return report_file

    # ----------------------------------------------- RELATÓRIO DE MARGENS
    def trigger_report_margens(
        self,
        report_id: str,
        target_date,
        store_id: str,
        tax_selection: str,
        taxscenario: str,
        saletables: str,
    ) -> bytes:
        """Dispara o relatório 'Margem de artigos' e devolve o XLS em bytes.

        Payload estruturalmente diferente do Sales by product (STORE singular, sem
        END_DATE, com TAX_SELECTION/TAXSCENARIO/SALETABLES — §3.6). Estes três são
        IDs por-instalação (§10), por isso vêm da config do tenant (sem defaults).
        """
        payload = {
            "params": {
                "querystring":   f"report.id = '{report_id}'",
                "START_DATE":    target_date.strftime("%Y%m%dT00:00:00"),
                "TAX_SELECTION": tax_selection,
                "STORE":         store_id,
                "TAXSCENARIO":   taxscenario,
                "SALETABLES":    saletables,
                "ProductGroups": "",
                "mimetype":      "application/vnd.ms-excel",
                "reportaction":  "execute",
            }
        }
        url = f"{self.api_url}/service/report/*/report"
        headers = {
            "Action":       "OPEN,GET,CLOSE",
            "Content-Type": "application/json;charset=UTF-8",
        }
        r = self.session.post(url, json=payload, headers=headers)
        if r.status_code != 200:
            log.error(f"trigger_report_margens falhou: HTTP {r.status_code}")
            log.error(f"Response body: {r.text[:2000]}")
            r.raise_for_status()
        data = r.json()
        report_file = data["report"]["report"][0]["reportfile"]
        log.info(f"Relatório margens gerado: {report_file}")
        return self.download_report(report_file)

    # ------------------------------------------------------- DOWNLOAD .XLS
    def download_report(self, report_file: str) -> bytes:
        """
        Descarrega o .xls gerado. Autenticação por QUERY STRING (sessionid=...),
        não por header — é assim que o JS original faz com window.open. (§3.6)
        """
        ext = report_file.rsplit(".", 1)[-1] if "." in report_file else "xls"
        url = f"{self.api_url}/download/{report_file}"
        params = {
            "filename":  f"report.{ext}",
            "sessionid": self.session_id,
        }

        r = self.session.get(url, params=params)

        if r.status_code != 200:
            log.error(f"download_report falhou: HTTP {r.status_code}")
            log.error(f"Response headers: {dict(r.headers)}")
            r.raise_for_status()

        content_type = r.headers.get("Content-Type", "")
        log.info(f"Excel descarregado: {len(r.content)} bytes (Content-Type: {content_type})")
        return r.content

    # ------------------------------------------ BROWSER: HELPER PAGINADO
    def fetch_browserdataset(
        self,
        dataset_id: str,
        body: Dict[str, Any],
        page_size: int = 1000,
        max_pages: int = 30,
    ) -> List[Dict[str, Any]]:
        """
        Helper ÚNICO de leitura paginada de um browserdataset (mesmo endpoint do
        fetch_stores/fetch_document_configs/fetch_catalog). ITERA o header Range
        (items=0-999, 1000-1999, …), ACUMULA os itens e PARA quando a página vem
        curta (len < page_size). `max_pages` é a trava §8.2 (nunca paginar sem fim).
        Aceita HTTP 200 E 206 (Partial Content). Lê resposta["browser"]["browserdataset"].
        READ-ONLY (Action OPEN,GET,INFO,CLOSE — não escreve). Requer login feito.

        `body` é o corpo COMPLETO do POST (muda só entre datasets: os documentos
        levam {"filter":{},"params":{…}}, o catálogo {"orderby":"code","params":{…}}).
        """
        url = f"{self.api_url}/service/browser/{dataset_id}/browserdataset"
        out: List[Dict[str, Any]] = []
        pages = 0
        for page in range(max_pages):
            pages = page + 1
            start = page * page_size
            headers = {
                "Action": "OPEN,GET,INFO,CLOSE",
                "Content-Type": "application/json;charset=UTF-8",
                "Range": f"items={start}-{start + page_size - 1}",
            }
            r = self.session.post(url, json=body, headers=headers)
            if r.status_code not in (200, 206):
                log.error(f"fetch_browserdataset({dataset_id}) falhou: HTTP {r.status_code} body={r.text[:1000]}")
                r.raise_for_status()
            items = r.json().get("browser", {}).get("browserdataset", [])
            out.extend(items)
            if len(items) < page_size:  # página curta → última página; para
                break
        log.info(f"browserdataset {dataset_id}: {len(out)} item(s) em {pages} página(s)")
        return out

    # ----------------------------------------------------- BROWSER: LOJAS
    def fetch_stores(self, dataset_id: str) -> List[Dict[str, Any]]:
        """
        Lista de lojas via browserdataset (auto-descoberta). Requer login feito.

        Captado do DevTools: este browserdataset responde na MESMA porta dos
        relatórios (api_url, 8136) — não precisa da 8134. Cada item traz id, code,
        description, is_hq_store, deleted, city, address, ...
        """
        url = f"{self.api_url}/service/browser/{dataset_id}/browserdataset"
        headers = {
            "Action":       "OPEN,GET,INFO,CLOSE",
            "Content-Type": "application/json;charset=UTF-8",
            "Range":        "items=0-1000",
        }
        body = {"params": {"CODE": "", "DESCRIPTION": "", "COMPANY_ID": "", "STATE": "0"}}
        r = self.session.post(url, json=body, headers=headers)
        if r.status_code not in (200, 206):
            log.error(f"fetch_stores falhou: HTTP {r.status_code}")
            log.error(f"Response body: {r.text[:2000]}")
            r.raise_for_status()
        data = r.json()
        stores = data.get("browser", {}).get("browserdataset", [])
        log.info(f"Lojas no browserdataset: {len(stores)}")
        return stores

    # ------------------------------------------- BROWSER: TIPOS DE DOCUMENTO
    def fetch_document_configs(self, dataset_id: str = "1099511639239") -> List[Dict[str, Any]]:
        """
        Lista de TIPOS DE DOCUMENTO (Definições→Documentos) via browserdataset.
        USA o helper paginado fetch_browserdataset — MESMO padrão do catálogo, só
        muda o dataset_id (1099511639239) e os params. Os documentos hoje cabem
        numa página, mas paginam por consistência (e caso cresçam). READ-ONLY.

        Cada item traz: id, code, description, entitytype, fiscaltype,
        fiscaltype_description, deleted, ...
        """
        body = {"filter": {}, "params": {"CODE": "", "DESCRIPTION": "", "ENTITYTYPE_ID": "", "STATE": "0"}}
        docs = self.fetch_browserdataset(dataset_id, body)
        log.info(f"Tipos de documento: {len(docs)}")
        return docs

    # ============================ DOCUMENTCONFIG — LEITURA RICA (Fase D0) ============================
    # READ-ONLY. Lista (browserdataset, igual ao fetch_document_configs) + abre cada documento
    # por id (OPEN,GET,INFO → CLOSE) trazendo numa só chamada: maindataset (~50 campos),
    # OPTIONS dos selects, 14 TABELAS FILHAS e additionalfields. Molde: _paycond_open (leitura).
    # Reusa o transporte (porta 8136, _paycond_base/_paycond_headers). NÃO abre sessão de
    # escrita (o passo additionalfields.storedataset que gera ObjectID de escrita é da D1).
    _DOCCONFIG_RICH_DATASETS = (
        "maindataset,docfiscaltype,doctype,stock_signal,productgroup,taxscenario,tax_round_mode,"
        "contacttype,report,printzone,docseries,entitytype_docconfig,docconfig_detailstatus,"
        "default_detailstatus,docconfig_docmovreason,docconfig_docstatus,default_docsatatus,"
        "docconfig_docaccount,docconfig_local,docconfig_import,docconfig_paymethod,"
        "docconfig_docreference,docconfig_paycond,store_docconfig,userrole_docconfig,"
        "additionalfields.fieldsinfo,additionalfields.maindataset"
    )
    # Selects de valor único cujas OPTIONS vêm no GET (id + description por linha).
    _DOCCONFIG_OPTION_KEYS = ["docfiscaltype", "doctype", "stock_signal", "productgroup", "taxscenario",
                              "tax_round_mode", "contacttype", "report", "printzone", "docseries"]
    # 14 tabelas filhas (cada linha com deleted 0/1; docconfig_docaccount também com credit/debit).
    _DOCCONFIG_CHILD_KEYS = ["entitytype_docconfig", "docconfig_detailstatus", "default_detailstatus",
                             "docconfig_docmovreason", "docconfig_docstatus", "default_docsatatus",
                             "docconfig_docaccount", "docconfig_local", "docconfig_import",
                             "docconfig_paymethod", "docconfig_docreference", "docconfig_paycond",
                             "userrole_docconfig", "store_docconfig"]

    def _docconfig_base(self) -> str:
        """Mesma porta/sessão do paycond (api_url, 8136). Reusa a lógica de base."""
        return self._paycond_base()

    def _docconfig_list(self, dataset_id: str, state: str) -> List[Dict[str, Any]]:
        body = {"filter": {}, "params": {"CODE": "", "DESCRIPTION": "", "ENTITYTYPE_ID": "", "STATE": str(state)}}
        return self.fetch_browserdataset(dataset_id, body)

    def _docconfig_open_read(self, session: requests.Session, doc_id: str) -> Dict[str, Any]:
        """Abre um documento por id (OPEN,GET,INFO), tenta ler a storedataset (passo próprio,
        read-only com o MESMO ObjectID), e FECHA (CLOSE). Devolve o corpo + _storedataset."""
        base = self._docconfig_base()
        url = f"{base}/service/documentconfig/{doc_id}/{self._DOCCONFIG_RICH_DATASETS}"
        r = session.post(url, data=b"", headers=self._paycond_headers("OPEN,GET,INFO"))
        if r.status_code not in (200, 206):
            raise RuntimeError(f"documentconfig OPEN by id falhou: HTTP {r.status_code} — {r.text[:500]}")
        object_id = r.headers.get("ObjectID") or r.headers.get("Objectid")
        data = r.json()
        body = data.get("documentconfig") or data

        # storedataset (additionalfields por loja) vem num passo PRÓPRIO. Em D0 tentamos LER
        # (GET,INFO) com o ObjectID da leitura — best-effort; se não vier, fica [] e reporta-se.
        storedataset: List[Dict[str, Any]] = []
        if object_id:
            try:
                rs = session.post(f"{base}/service/documentconfig/additionalfields.storedataset",
                                  data=b"", headers=self._paycond_headers("GET,INFO", object_id))
                if rs.status_code in (200, 206):
                    sb = rs.json()
                    sb = sb.get("documentconfig") or sb
                    storedataset = sb.get("additionalfields.storedataset") or sb.get("storedataset") or []
            except Exception as exc:  # noqa: BLE001
                log.warning("documentconfig storedataset (read) falhou (ignorado): %s", type(exc).__name__)
            try:  # CLOSE (leitura; descarta sem gravar)
                session.post(f"{base}/service/documentconfig", data=b"",
                             headers=self._paycond_headers("CLOSE", object_id))
            except Exception as exc:  # noqa: BLE001
                log.warning("documentconfig CLOSE falhou (ignorado): %s", type(exc).__name__)
        body["_storedataset"] = storedataset
        return body

    @staticmethod
    def _docconfig_id(row: Dict[str, Any]) -> str:
        for k in ("id", "documentconfig_id", "code"):
            v = row.get(k)
            if v not in (None, ""):
                return str(v)
        return ""

    def _shape_docconfig(self, body: Dict[str, Any], row: Dict[str, Any]) -> Dict[str, Any]:
        """Dá forma ao documento rico: maindataset, options, 14 filhas, additionalfields, raw."""
        main0 = (body.get("maindataset") or [{}])[0] if body.get("maindataset") else {}
        item: Dict[str, Any] = {
            "id": self._docconfig_id(row),
            "code": main0.get("code") if main0.get("code") is not None else row.get("code"),
            "description": main0.get("description") if main0.get("description") is not None else row.get("description"),
            "entitytype": row.get("entitytype"),
            "fiscaltype": row.get("fiscaltype"),
            "fiscaltype_description": row.get("fiscaltype_description"),
            "deleted": bool(int((main0.get("deleted") if main0.get("deleted") is not None else row.get("deleted")) or 0)),
            "maindataset": main0,
            "options": {k: (body.get(k) or []) for k in self._DOCCONFIG_OPTION_KEYS},
            "children": {k: (body.get(k) or []) for k in self._DOCCONFIG_CHILD_KEYS},
            "additionalfields": {
                "fieldsinfo":  body.get("additionalfields.fieldsinfo") or [],
                "maindataset": body.get("additionalfields.maindataset") or [],
                "storedataset": body.get("_storedataset") or [],
            },
            # raw = SÓ o maindataset (~50 campos, incl. os _descr). As options/14 filhas/
            # additionalfields já vão em chaves próprias — NÃO duplicar o corpo inteiro aqui.
            "raw": main0,
        }
        return item

    def fetch_document_configs_rich(self, dataset_id: str = "1099511639239") -> List[Dict[str, Any]]:
        """
        LEITURA RICA (D0): lista os documentos (STATE 0 ativos + STATE 1 anulados) e
        ENRIQUECE cada ATIVO abrindo-o por id (maindataset + options + 14 filhas +
        additionalfields). Os anulados ficam só com os campos da lista (deleted=True).
        READ-ONLY. Devolve uma lista de dicts ricos (ver _shape_docconfig).
        """
        actives = self._docconfig_list(dataset_id, "0")
        anulados = self._docconfig_list(dataset_id, "1")
        log.info("documentconfig: %d ativo(s), %d anulado(s)", len(actives), len(anulados))

        base = self._docconfig_base()
        use_main = (base == self.api_url)
        out: List[Dict[str, Any]] = []
        seen: set = set()

        def _enrich(session: requests.Session, row: Dict[str, Any]) -> None:
            did = self._docconfig_id(row)
            if did in seen:
                return
            seen.add(did)
            if not did:
                return
            try:
                body = self._docconfig_open_read(session, did)
                out.append(self._shape_docconfig(body, row))
            except Exception as exc:  # noqa: BLE001 — degrada p/ só-lista, não parte o sync
                log.warning("documentconfig detalhe id=%s falhou (fica só lista): %s", did, type(exc).__name__)
                out.append({"id": did, "code": row.get("code"), "description": row.get("description"),
                            "entitytype": row.get("entitytype"), "deleted": bool(int(row.get("deleted") or 0))})

        if use_main:
            for row in actives:
                _enrich(self.session, row)
        else:
            with self._port_session(base) as session:
                for row in actives:
                    _enrich(session, row)

        for row in anulados:
            did = self._docconfig_id(row)
            if did in seen:
                continue
            seen.add(did)
            out.append({"id": did, "code": row.get("code"), "description": row.get("description"),
                        "entitytype": row.get("entitytype"), "deleted": True})

        log.info("documentconfig: %d documento(s) no total (ativos enriquecidos + anulados)", len(out))
        return out

    def probe_documentconfig(self, doc_id: str) -> Dict[str, Any]:
        """PROBE (só leitura) de UM documento por id: devolve um RESUMO estrutural
        (chaves do maindataset, contagens das options e das 14 filhas, additionalfields)
        para validar o terreno da D0 sem gravar nada."""
        base = self._docconfig_base()
        session = self.session if base == self.api_url else None
        owns = False
        if session is None:
            ctx = self._port_session(base)
            session = ctx.__enter__()
            owns = True
        try:
            body = self._docconfig_open_read(session, str(doc_id))
            main0 = (body.get("maindataset") or [{}])[0] if body.get("maindataset") else {}
            return {
                "id": str(doc_id),
                "maindataset_keys": sorted(main0.keys()),
                "maindataset_sample": {k: main0.get(k) for k in (
                    "code", "description", "shortname", "taxscenario_id", "taxscenario_id_descr",
                    "doctype_id", "docfiscaltype_id", "default_paycond_id", "stock_signal",
                    "docseries_id", "deleted") if k in main0},
                "options_counts": {k: len(body.get(k) or []) for k in self._DOCCONFIG_OPTION_KEYS},
                "children_counts": {k: len(body.get(k) or []) for k in self._DOCCONFIG_CHILD_KEYS},
                "additionalfields_maindataset": body.get("additionalfields.maindataset") or [],
                "storedataset_count": len(body.get("_storedataset") or []),
                "docconfig_paycond": body.get("docconfig_paycond") or [],
                "docconfig_docaccount_sample": (body.get("docconfig_docaccount") or [])[:2],
                "top_keys": sorted(body.keys()),
            }
        finally:
            if owns:
                ctx.__exit__(None, None, None)

    # ============================ DOCUMENTCONFIG — EDITAR MAINDATASET (Fase D1) ============================
    # ⚠️ ESCRITA. Bloco PARALELO novo (NÃO reusa _paycond_*). Sequência (HAR real):
    #   1. GET rico por id (OPEN,GET,INFO) → MATRIZ VIVA (maindataset + 14 filhas + addfields).
    #   2. POST /service/documentconfig/additionalfields.storedataset VAZIO → ABRE A SESSÃO
    #      DE ESCRITA e gera o ObjectID (próprio do documentconfig; o paycond NÃO tinha).
    #   3. WRITE GRANDE: POST /service/documentconfig/<WRITE_DATASETS> com maindataset editado
    #      + 13 filhas + 2 additionalfields REENVIADOS TAL E QUAL da matriz viva. Mesmo ObjectID.
    #   4. + 5. POST /service/documentconfig VAZIO ×2 (commit). Mesmo ObjectID.
    # Só o maindataset se edita (D1); as filhas preservam-se. Confirma por releitura.
    # NÃO replicar o POST /service/sessionsettings (ruído da UI, ObjectID vazio).
    _DOCCONFIG_WRITE_DATASETS = (
        "maindataset,entitytype_docconfig,docconfig_detailstatus,default_detailstatus,"
        "docconfig_docmovreason,docconfig_docstatus,default_docsatatus,docconfig_docaccount,"
        "docconfig_local,docconfig_import,docconfig_paymethod,docconfig_docreference,"
        "docconfig_paycond,userrole_docconfig,additionalfields.maindataset,additionalfields.storedataset"
    )
    # Filhas reenviadas no WRITE (nota: store_docconfig é só de leitura — NÃO entra no write).
    _DOCCONFIG_WRITE_CHILD_KEYS = [
        "entitytype_docconfig", "docconfig_detailstatus", "default_detailstatus",
        "docconfig_docmovreason", "docconfig_docstatus", "default_docsatatus",
        "docconfig_docaccount", "docconfig_local", "docconfig_import",
        "docconfig_paymethod", "docconfig_docreference", "docconfig_paycond", "userrole_docconfig",
    ]
    _DOCCONFIG_ACT_OPEN   = "OPEN,GET,INFO"   # passo 1 (abrir existente)
    _DOCCONFIG_ACT_STORE  = "GET,INFO"        # passo 2 (abre sessão de escrita + gera ObjectID)
    _DOCCONFIG_ACT_WRITE  = "MERGE"           # passo 3 (write grande)
    _DOCCONFIG_ACT_COMMIT = "SAVE"            # passo 4 (commit)
    _DOCCONFIG_ACT_CLOSE  = "CLOSE"           # passo 5 (close)
    _DOCCONFIG_ACT_VOID   = "DELETE"          # anular (soft-delete, 1 POST sem body)

    # D2a — filhas de MARCAÇÃO SIMPLES (só deleted) editáveis + a sua chave identificadora
    # PRÓPRIA (confirmada no GET vivo). userrole_docconfig NÃO entra (tem field_value e chave
    # composta — tratamento próprio noutra fatia); fica preservada verbatim como docaccount/defaults.
    _DOCCONFIG_EDITABLE_CHILD_IDKEY = {
        "entitytype_docconfig":   "entitytype_id",
        "docconfig_detailstatus": "detailstatus_id",
        "docconfig_docmovreason": "docmovreason_id",
        "docconfig_docstatus":    "docstatus_id",
        "docconfig_local":        "local_id",
        "docconfig_import":       "importdocconfig_id",
        "docconfig_paymethod":    "paymethod_id",
        "docconfig_docreference": "docreference_id",
        "docconfig_paycond":      "paycond_id",
    }

    def update_document_config(self, doc_id: str, fields: Dict[str, Any],
                               children_changes: Dict[str, List[Dict[str, Any]]] | None = None,
                               docaccount_changes: List[Dict[str, Any]] | None = None) -> Dict[str, Any]:
        """⚠️ ESCRITA (D1/D2a/D2b): edita o MAINDATASET (`fields`), o `deleted` das 9 filhas de
        marcação (`children_changes` = {filha: [{id, deleted}]}) e/ou o docaccount
        (`docaccount_changes` = [{docaccount_id, deleted, credit, debit}]). As restantes
        filhas + additionalfields reenviam-se PRESERVADAS da releitura viva. O diff aplica-se
        sobre a MATRIZ VIVA (passo 1). Só persisted se a releitura confirmar."""
        base = self._docconfig_base()
        if base == self.api_url:
            return self._update_docconfig_on(self.session, str(doc_id), fields or {}, children_changes or {}, docaccount_changes or [])
        with self._port_session(base) as session:
            return self._update_docconfig_on(session, str(doc_id), fields or {}, children_changes or {}, docaccount_changes or [])

    def _update_docconfig_on(self, session: requests.Session, doc_id: str, fields: Dict[str, Any],
                             children_changes: Dict[str, List[Dict[str, Any]]],
                             docaccount_changes: List[Dict[str, Any]]) -> Dict[str, Any]:
        base = self._docconfig_base()

        # 1. GET rico (OPEN,GET,INFO) → matriz VIVA.
        url1 = f"{base}/service/documentconfig/{doc_id}/{self._DOCCONFIG_RICH_DATASETS}"
        r1 = session.post(url1, data=b"", headers=self._paycond_headers(self._DOCCONFIG_ACT_OPEN))
        if r1.status_code not in (200, 206):
            raise RuntimeError(f"documentconfig GET (open-for-write) falhou: HTTP {r1.status_code} — {r1.text[:400]}")
        oid1 = r1.headers.get("ObjectID") or r1.headers.get("Objectid")
        body = r1.json()
        body = body.get("documentconfig") or body
        main_live = (body.get("maindataset") or [{}])[0] if body.get("maindataset") else {}
        if not main_live:
            raise RuntimeError("documentconfig: GET sem maindataset.")
        if int(main_live.get("deleted") or 0) != 0:
            raise RuntimeError("documentconfig ABORTADO: só documentos ATIVOS são editáveis (deleted!=0).")

        # 2. Abre a SESSÃO DE ESCRITA (storedataset vazio) → gera o ObjectID.
        r2 = session.post(f"{base}/service/documentconfig/additionalfields.storedataset", data=b"",
                          headers=self._paycond_headers(self._DOCCONFIG_ACT_STORE, oid1))
        if r2.status_code not in (200, 206):
            raise RuntimeError(f"documentconfig passo-2 (abre escrita) falhou: HTTP {r2.status_code} — {r2.text[:400]}")
        oid = r2.headers.get("ObjectID") or r2.headers.get("Objectid") or oid1
        if not oid:
            raise RuntimeError("documentconfig: passo 2 não gerou ObjectID de escrita.")
        try:
            sb = r2.json()
            sb = sb.get("documentconfig") or sb
            store_live = sb.get("additionalfields.storedataset") or sb.get("storedataset") or body.get("_storedataset") or []
        except Exception:  # noqa: BLE001
            store_live = body.get("_storedataset") or []

        # maindataset editado: parte do VIVO (verbatim) e sobrepõe os campos recebidos.
        # Grava os _id (nunca mexe nos _descr — o servidor deriva-os do _id). code read-only.
        row = dict(main_live)
        protected = {"code", "id", "key"}
        for k, v in (fields or {}).items():
            if k in protected or k.endswith("_descr"):
                continue
            row[k] = v
        row["deleted"] = 0

        # WRITE body: maindataset editado + 13 filhas + 2 additionalfields, TAL E QUAL do vivo.
        # Nas 9 filhas editáveis (D2a) aplica-se SÓ o diff de `deleted` sobre a matriz VIVA;
        # as linhas não mexidas mantêm o deleted do vivo. As restantes filhas: verbatim.
        write_body: Dict[str, Any] = {"maindataset": [row]}
        counts: Dict[str, int] = {}
        child_edits: Dict[str, Any] = {}   # para o capture: aplicados/não-casados por filha
        for ck in self._DOCCONFIG_WRITE_CHILD_KEYS:
            if ck not in body:
                raise RuntimeError(f"documentconfig ABORTADO: bloco '{ck}' ausente na releitura viva.")
            live_rows = body.get(ck) or []
            changes = children_changes.get(ck) or []
            if changes and ck in self._DOCCONFIG_EDITABLE_CHILD_IDKEY:
                id_key = self._DOCCONFIG_EDITABLE_CHILD_IDKEY[ck]
                want = {str(c.get("id")): (1 if int(c.get("deleted") or 0) == 1 else 0)
                        for c in changes if c.get("id") not in (None, "")}
                applied, matched = 0, set()
                out_rows = []
                for r in live_rows:
                    nr = dict(r)
                    rid = str(nr.get(id_key))
                    if rid in want:
                        nr["deleted"] = want[rid]
                        matched.add(rid)
                        applied += 1
                    out_rows.append(nr)
                write_body[ck] = out_rows
                child_edits[ck] = {"id_key": id_key, "applied": applied,
                                   "unmatched": sorted(set(want.keys()) - matched)}
            elif ck == "docconfig_docaccount" and docaccount_changes:
                # D2b: aplica os 3 flags (deleted/credit/debit) por conta, casando por
                # docaccount_id. As contas NÃO mexidas reenviam-se TAL E QUAL (preserva
                # qualquer combinação pré-existente, incl. um eventual ambos=1).
                want_da = {str(c.get("docaccount_id")): c for c in docaccount_changes
                           if c.get("docaccount_id") not in (None, "")}
                applied, matched = 0, set()
                out_rows = []
                for r in live_rows:
                    nr = dict(r)
                    rid = str(nr.get("docaccount_id"))
                    if rid in want_da:
                        c = want_da[rid]
                        nr["deleted"] = 1 if int(c.get("deleted") or 0) == 1 else 0
                        nr["credit"] = 1 if int(c.get("credit") or 0) == 1 else 0
                        nr["debit"] = 1 if int(c.get("debit") or 0) == 1 else 0
                        matched.add(rid)
                        applied += 1
                    out_rows.append(nr)
                write_body[ck] = out_rows
                child_edits[ck] = {"id_key": "docaccount_id", "applied": applied,
                                   "unmatched": sorted(set(want_da.keys()) - matched)}
            else:
                write_body[ck] = live_rows   # verbatim (não editável ou sem mudanças)
            counts[ck] = len(write_body[ck])
        write_body["additionalfields.maindataset"] = body.get("additionalfields.maindataset") or []
        write_body["additionalfields.storedataset"] = store_live
        counts["additionalfields.maindataset"] = len(write_body["additionalfields.maindataset"])
        counts["additionalfields.storedataset"] = len(write_body["additionalfields.storedataset"])

        # ⚠️ TRAVA ANTI-ENCOLHIMENTO POR BLOCO: cada bloco reenvia o nº de linhas do vivo.
        # (Reenvio verbatim → iguais por construção; a trava protege contra bloco vazio
        #  nos críticos, que teriam dados.)
        for ck in ("docconfig_docstatus", "docconfig_docaccount", "docconfig_paycond"):
            if counts.get(ck, 0) == 0:
                raise RuntimeError(f"documentconfig ABORTADO: bloco crítico '{ck}' veio vazio do vivo (anti-encolhimento).")

        # 3. WRITE GRANDE (MERGE), mesmo ObjectID.
        url3 = f"{base}/service/documentconfig/{self._DOCCONFIG_WRITE_DATASETS}"
        r3 = session.post(url3, json=write_body, headers=self._paycond_headers(self._DOCCONFIG_ACT_WRITE, oid))
        capture = {
            "oid1": oid1, "oid_write": oid, "write_http": r3.status_code,
            "counts_sent": counts, "fields_applied": {k: row.get(k) for k in (fields or {}) if k not in ("code", "id", "key")},
            "child_edits": child_edits,
            # docaccount VIVO (passo 1) — para auditar a preservação das contas não mexidas.
            "docaccount_live": [{"docaccount_id": str(r.get("docaccount_id")), "deleted": int(r.get("deleted") or 0),
                                 "credit": int(r.get("credit") or 0), "debit": int(r.get("debit") or 0)}
                                for r in (body.get("docconfig_docaccount") or [])],
        }
        if r3.status_code not in (200, 206):
            capture["write_body_err"] = r3.text[:600]
            return {"ok": False, "persisted": False, "aborted_before_commit": True, "capture": capture,
                    "error": f"WRITE (MERGE) falhou (HTTP {r3.status_code}) — abortado antes do commit."}

        # 4. + 5. commits (vazios), mesmo ObjectID.
        rc1 = session.post(f"{base}/service/documentconfig", data=b"",
                           headers=self._paycond_headers(self._DOCCONFIG_ACT_COMMIT, oid))
        if rc1.status_code not in (200, 206):
            raise RuntimeError(f"documentconfig SAVE falhou: HTTP {rc1.status_code} — {rc1.text[:400]}")
        rc2 = session.post(f"{base}/service/documentconfig", data=b"",
                           headers=self._paycond_headers(self._DOCCONFIG_ACT_CLOSE, oid))
        if rc2.status_code not in (200, 206):
            log.warning("documentconfig commit#2 devolveu HTTP %s (ignorado)", rc2.status_code)

        # Confirm-by-reread: campo(s) novo(s) + 14 filhas/addfields intactas (contagem).
        reread = self._docconfig_open_read(session, doc_id)
        rmain = (reread.get("maindataset") or [{}])[0] if reread.get("maindataset") else {}
        counts_after = {ck: len(reread.get(ck) or []) for ck in self._DOCCONFIG_CHILD_KEYS}
        counts_after["additionalfields.maindataset"] = len(reread.get("additionalfields.maindataset") or [])
        counts_after["additionalfields.storedataset"] = len(reread.get("_storedataset") or [])
        confirm = {
            "maindataset": {k: rmain.get(k) for k in (fields or {}) if k not in ("code", "id", "key")},
            "code": rmain.get("code"),
            "deleted": int(rmain.get("deleted") or 0),
            "children_counts": counts_after,
        }
        # persisted se os campos editados batem na releitura.
        fields_ok = all(str(rmain.get(k)) == str(v) for k, v in (fields or {}).items() if k not in ("code", "id", "key") and not k.endswith("_descr"))

        # confirma as mudanças de filhas: cada id mexido tem o `deleted` pedido na releitura.
        children_ok = True
        children_confirm: Dict[str, Any] = {}
        for ck, changes in (children_changes or {}).items():
            if ck not in self._DOCCONFIG_EDITABLE_CHILD_IDKEY or not changes:
                continue
            id_key = self._DOCCONFIG_EDITABLE_CHILD_IDKEY[ck]
            by_id = {str(r.get(id_key)): int(r.get("deleted") or 0) for r in (reread.get(ck) or [])}
            want = {str(c.get("id")): (1 if int(c.get("deleted") or 0) == 1 else 0) for c in changes if c.get("id") not in (None, "")}
            ok = all(by_id.get(rid) == dv for rid, dv in want.items())
            children_ok = children_ok and ok
            children_confirm[ck] = {
                "ok": ok,
                "linked_after": sorted(rid for rid, dv in by_id.items() if dv == 0),
            }
        confirm["children"] = children_confirm

        # D2b — confirma o docaccount: cada conta mexida tem os 3 flags pedidos na releitura.
        docaccount_ok = True
        if docaccount_changes:
            da_after = {str(r.get("docaccount_id")): r for r in (reread.get("docconfig_docaccount") or [])}
            da_conf = []
            for c in docaccount_changes:
                rid = str(c.get("docaccount_id"))
                r = da_after.get(rid) or {}
                ok = (int(r.get("deleted") or 0) == (1 if int(c.get("deleted") or 0) == 1 else 0)
                      and int(r.get("credit") or 0) == (1 if int(c.get("credit") or 0) == 1 else 0)
                      and int(r.get("debit") or 0) == (1 if int(c.get("debit") or 0) == 1 else 0))
                docaccount_ok = docaccount_ok and ok
                da_conf.append({"docaccount_id": rid, "ok": ok,
                                "deleted": r.get("deleted"), "credit": r.get("credit"), "debit": r.get("debit")})
            confirm["docaccount"] = da_conf

        persisted = bool(fields_ok and children_ok and docaccount_ok and int(confirm["deleted"] or 0) == 0)
        return {
            "ok": persisted, "persisted": persisted, "pingwin_id": doc_id,
            "capture": capture, "confirm": confirm, "raw": reread,
        }

    # ============================ DOCUMENTCONFIG — CRIAR (Fase D3) ============================
    # ⚠️ ESCRITA. Padrão paycond 2a + protocolo documentconfig. Sequência:
    #   1. form-novo: POST /service/documentconfig/*/<RICH> (NEW,GET,INFO) → id PROVISÓRIO
    #      (IGNORAR), code sugerido, 53 campos em branco, 14 filhas no estado-TEMPLATE + options.
    #   2. POST /service/documentconfig/additionalfields.storedataset VAZIO → gera ObjectID.
    #   3. WRITE: maindataset PREENCHIDO + 13 filhas template + 2 additionalfields. A RESPOSTA
    #      traz maindataset[0].id = o ID FINAL REAL (≠ provisório). LER daqui.
    #   4.+5. 2 commits vazios. Confirm-by-reread pelo id FINAL (NÃO exigir code igual — o
    #      servidor TRUNCA o code em silêncio; confiar no id).
    def probe_new_documentconfig(self) -> Dict[str, Any]:
        """PROBE (só leitura) do form-novo: id provisório, code sugerido, tamanho do campo
        code (datasetinfo), contagens das filhas template. NÃO faz commit (não persiste)."""
        base = self._docconfig_base()
        session = self.session if base == self.api_url else None
        owns = False
        if session is None:
            ctx = self._port_session(base)
            session = ctx.__enter__()
            owns = True
        try:
            url = f"{base}/service/documentconfig/*/{self._DOCCONFIG_RICH_DATASETS}"
            r = session.post(url, data=b"", headers=self._paycond_headers("NEW,GET,INFO"))
            data = r.json() if r.status_code in (200, 206) else {}
            body = data.get("documentconfig") or data
            main0 = (body.get("maindataset") or [{}])[0] if body.get("maindataset") else {}
            dsinfo = body.get("service.datasetinfo") or {}
            # procurar o tamanho do campo 'code' no datasetinfo (estrutura varia).
            code_info = None
            for v in (dsinfo.values() if isinstance(dsinfo, dict) else []):
                if isinstance(v, dict) and "code" in v and isinstance(v["code"], dict):
                    code_info = v["code"]
                    break
            if code_info is None and isinstance(dsinfo.get("code"), dict):
                code_info = dsinfo.get("code")
            return {
                "http": r.status_code,
                "provisional_id": main0.get("id"),
                "suggested_code": main0.get("code"),
                "code_info": code_info,
                "children_counts": {k: len(body.get(k) or []) for k in self._DOCCONFIG_CHILD_KEYS},
                "dsinfo_keys": sorted(dsinfo.keys()) if isinstance(dsinfo, dict) else None,
            }
        finally:
            if owns:
                ctx.__exit__(None, None, None)

    def create_document_config(self, fields: Dict[str, Any]) -> Dict[str, Any]:
        """⚠️ ESCRITA (D3): cria um documento novo. `fields` = campos do maindataset a preencher
        (os _id/texto; o PHP já filtra o whitelist e limita o code). As 14 filhas vão no
        estado-TEMPLATE do form-novo (configuram-se depois por edição). Lê o id FINAL da
        resposta do write; confirma por releitura (sem exigir code igual — truncagem)."""
        base = self._docconfig_base()
        if base == self.api_url:
            return self._create_docconfig_on(self.session, fields or {})
        with self._port_session(base) as session:
            return self._create_docconfig_on(session, fields or {})

    def _create_docconfig_on(self, session: requests.Session, fields: Dict[str, Any]) -> Dict[str, Any]:
        base = self._docconfig_base()
        # 1. form-novo (NEW,GET,INFO) → template + provisório + options.
        url1 = f"{base}/service/documentconfig/*/{self._DOCCONFIG_RICH_DATASETS}"
        r1 = session.post(url1, data=b"", headers=self._paycond_headers("NEW,GET,INFO"))
        if r1.status_code not in (200, 206):
            raise RuntimeError(f"documentconfig form-novo falhou: HTTP {r1.status_code} — {r1.text[:400]}")
        oid1 = r1.headers.get("ObjectID") or r1.headers.get("Objectid")
        body = r1.json()
        body = body.get("documentconfig") or body
        tmpl = (body.get("maindataset") or [{}])[0] if body.get("maindataset") else {}
        if not tmpl:
            raise RuntimeError("documentconfig form-novo sem maindataset.")
        suggested_code = tmpl.get("code")
        provisional_id = tmpl.get("id")

        # 2. abre sessão de escrita (storedataset vazio) → ObjectID.
        r2 = session.post(f"{base}/service/documentconfig/additionalfields.storedataset", data=b"",
                          headers=self._paycond_headers(self._DOCCONFIG_ACT_STORE, oid1))
        if r2.status_code not in (200, 206):
            raise RuntimeError(f"documentconfig passo-2 (abre escrita) falhou: HTTP {r2.status_code} — {r2.text[:400]}")
        oid = r2.headers.get("ObjectID") or r2.headers.get("Objectid") or oid1
        if not oid:
            raise RuntimeError("documentconfig: passo 2 não gerou ObjectID de escrita.")
        try:
            sb = r2.json()
            sb = sb.get("documentconfig") or sb
            store_live = sb.get("additionalfields.storedataset") or sb.get("storedataset") or body.get("_storedataset") or []
        except Exception:  # noqa: BLE001
            store_live = body.get("_storedataset") or []

        # maindataset PREENCHIDO: parte do template e sobrepõe os campos do utilizador.
        row = dict(tmpl)
        code_sent = str(fields.get("code") if fields.get("code") not in (None, "") else (suggested_code or ""))
        for k, v in (fields or {}).items():
            if k in ("id", "key") or k.endswith("_descr"):
                continue
            row[k] = v
        row["code"] = code_sent         # o utilizador define o code (servidor pode truncar)
        row["id"] = str(provisional_id or "")   # provisório; o servidor devolve o final
        row["deleted"] = 0

        # WRITE body: maindataset + 13 filhas TEMPLATE + 2 additionalfields (tudo do form-novo).
        write_body: Dict[str, Any] = {"maindataset": [row]}
        counts: Dict[str, int] = {}
        for ck in self._DOCCONFIG_WRITE_CHILD_KEYS:
            if ck not in body:
                raise RuntimeError(f"documentconfig CRIAR ABORTADO: bloco '{ck}' ausente no form-novo.")
            write_body[ck] = body.get(ck) or []
            counts[ck] = len(write_body[ck])
        write_body["additionalfields.maindataset"] = body.get("additionalfields.maindataset") or []
        write_body["additionalfields.storedataset"] = store_live
        for ck in ("docconfig_docstatus", "docconfig_docaccount", "docconfig_paycond"):
            if counts.get(ck, 0) == 0:
                raise RuntimeError(f"documentconfig CRIAR ABORTADO: bloco '{ck}' vazio no form-novo (anti-encolhimento).")

        # 3. WRITE (MERGE) → id FINAL na resposta.
        url3 = f"{base}/service/documentconfig/{self._DOCCONFIG_WRITE_DATASETS}"
        r3 = session.post(url3, json=write_body, headers=self._paycond_headers(self._DOCCONFIG_ACT_WRITE, oid))
        capture = {"provisional_id": provisional_id, "suggested_code": suggested_code,
                   "code_sent": code_sent, "oid_write": oid, "write_http": r3.status_code, "counts_sent": counts}
        if r3.status_code not in (200, 206):
            capture["write_body_err"] = r3.text[:600]
            return {"ok": False, "persisted": False, "aborted_before_commit": True, "capture": capture,
                    "error": f"WRITE (MERGE) falhou (HTTP {r3.status_code}) — abortado antes do commit."}
        wmain0 = ((r3.json().get("documentconfig") or r3.json()).get("maindataset") or [{}])[0]
        final_id = str(wmain0.get("id") or "")
        capture["final_id"] = final_id
        capture["code_returned"] = wmain0.get("code")
        capture["final_id_differs_from_provisional"] = (final_id != str(provisional_id or ""))
        if not final_id:
            return {"ok": False, "persisted": False, "aborted_before_commit": True, "capture": capture,
                    "error": "WRITE não devolveu maindataset[0].id (id final). Abortado antes do commit."}

        # 4.+5. commits.
        rc1 = session.post(f"{base}/service/documentconfig", data=b"",
                           headers=self._paycond_headers(self._DOCCONFIG_ACT_COMMIT, oid))
        if rc1.status_code not in (200, 206):
            raise RuntimeError(f"documentconfig CRIAR SAVE falhou: HTTP {rc1.status_code} — {rc1.text[:400]}")
        rc2 = session.post(f"{base}/service/documentconfig", data=b"",
                           headers=self._paycond_headers(self._DOCCONFIG_ACT_CLOSE, oid))
        if rc2.status_code not in (200, 206):
            log.warning("documentconfig CRIAR commit#2 devolveu HTTP %s (ignorado)", rc2.status_code)

        # Confirm-by-reread pelo id FINAL. ⚠️ NÃO exigir code igual (servidor trunca).
        reread = self._docconfig_open_read(session, final_id)
        rmain0 = (reread.get("maindataset") or [{}])[0] if reread.get("maindataset") else {}
        confirm = {
            "id": str(rmain0.get("id") or ""), "code": rmain0.get("code"),
            "description": rmain0.get("description"), "deleted": int(rmain0.get("deleted") or 0),
            "code_truncated": (str(rmain0.get("code") or "") != code_sent),
        }
        persisted = bool(confirm["id"] == final_id and int(confirm["deleted"] or 0) == 0)
        return {
            "ok": persisted, "persisted": persisted, "pingwin_id": final_id,
            "capture": capture, "confirm": confirm, "raw": reread,
        }

    # ⚠️⚠️ ESCRITA NO PINGWIN — ANULAR DOCUMENTO (Fase D4) ⚠️⚠️
    # SOFT-DELETE (confirmado por HAR e já usado na limpeza da D1/D3): UM POST sem body a
    # /service/documentconfig/{id} (Action DELETE) → o documento sai do STATE 0 e vai para
    # o STATE 1 (anulados), deleted=1. Resposta {"documentconfig":{}}. Só anula se ATIVO.
    def void_document_config(self, doc_id: str, dataset_id: str = "1099511639239") -> Dict[str, Any]:
        """⚠️ ESCRITA (D4): anula (soft-delete) um documento ATIVO. Devolve {ok,
        voided_confirmed, void_http, in_state0_ativos, in_state1_anulados, pingwin_id}.
        Só voided_confirmed=True se a releitura mostrar: saiu do STATE 0 E está no STATE 1."""
        base = self._docconfig_base()
        if base == self.api_url:
            return self._void_docconfig_on(self.session, str(doc_id), dataset_id)
        with self._port_session(base) as session:
            return self._void_docconfig_on(session, str(doc_id), dataset_id)

    def _void_docconfig_on(self, session: requests.Session, pid: str, dataset_id: str) -> Dict[str, Any]:
        base = self._docconfig_base()
        # Só anula se estiver ATIVO (STATE 0); se já inativo, recusa (sem POST).
        active_before = any(str(x.get("id")) == pid for x in self._docconfig_list(dataset_id, "0"))
        if not active_before:
            return {"ok": False, "voided_confirmed": False,
                    "error": "Documento não está ativo (STATE 0) — anular recusado."}

        r = session.post(f"{base}/service/documentconfig/{pid}", data=b"",
                         headers=self._paycond_headers(self._DOCCONFIG_ACT_VOID))
        if r.status_code not in (200, 206):
            return {"ok": False, "voided_confirmed": False, "void_http": r.status_code,
                    "error": f"Anular falhou (HTTP {r.status_code}) — {r.text[:300]}"}

        # Confirm-by-reread: tem de SAIR do STATE 0 e ESTAR no STATE 1. ("200" ≠ gravado.)
        in0 = any(str(x.get("id")) == pid for x in self._docconfig_list(dataset_id, "0"))
        in1 = any(str(x.get("id")) == pid for x in self._docconfig_list(dataset_id, "1"))
        voided = (not in0) and in1
        if not voided:
            log.error("void_documentconfig: id=%s NÃO confirmado (in_state0=%s in_state1=%s).", pid, in0, in1)
        return {
            "ok": voided, "voided_confirmed": voided, "void_http": r.status_code,
            "in_state0_ativos": in0, "in_state1_anulados": in1, "pingwin_id": pid,
        }

    # -------------------------------------------------- BROWSER: CATÁLOGO
    def fetch_catalog(self, dataset_id: str, page_size: int = 1000, max_pages: int = 30) -> List[Dict[str, Any]]:
        """
        Catálogo COMPLETO de artigos via browserdataset (porta 8136, como fetch_stores).
        São centenas/milhares → USA o helper paginado fetch_browserdataset (itera o
        Range até a página vir curta, com a trava max_pages). Requer login.
        """
        body = {
            "orderby": "code",  # ordem estável p/ paginação consistente
            "params": {
                "CODE": "", "DESCRIPTION": "", "BARCODE": "", "FAMILY_ID": "",
                "PART_PRODUCT_ID": "", "STORE_ID": "", "SHOWATTR": "0", "STATE": "0",
            },
        }
        items = self.fetch_browserdataset(dataset_id, body, page_size=page_size, max_pages=max_pages)
        log.info(f"Catálogo: {len(items)} artigos")
        return items

    def fetch_catalog_deleted_ids(self, dataset_id: str, page_size: int = 1000, max_pages: int = 30) -> List[str]:
        """
        IDs dos artigos ANULADOS (STATE:1) — mesmo browserdataset/paginação do catálogo,
        só muda STATE:"1". O "anular" no PingWin é SOFT-DELETE: o artigo continua a existir
        mas move-se para os Anulados (deleted:1). Devolve os pingwin_id (precedência
        id→product_id→code, IGUAL à do sync dos ativos) dos que têm deleted:1. READ-ONLY.
        ⚠️ deleted (anulado) ≠ status (Ativo/Inativo/Descontinuado): usa-se o deleted:1.
        """
        body = {
            "orderby": "code",
            "params": {
                "CODE": "", "DESCRIPTION": "", "BARCODE": "", "FAMILY_ID": "",
                "PART_PRODUCT_ID": "", "STORE_ID": "", "SHOWATTR": "0", "STATE": "1",
            },
        }
        items = self.fetch_browserdataset(dataset_id, body, page_size=page_size, max_pages=max_pages)
        ids: List[str] = []
        for it in items:
            if int(it.get("deleted") or 0) != 1:
                continue
            pid = it.get("id") or it.get("product_id") or it.get("code")
            if pid not in (None, ""):
                ids.append(str(pid))
        log.info(f"Catálogo anulados (STATE:1, deleted:1): {len(ids)} id(s)")
        return ids

    # ------------------------------------------------- BROWSER: FORNECEDORES
    def fetch_suppliers(self, dataset_id: str, page_size: int = 1000, max_pages: int = 30) -> List[Dict[str, Any]]:
        """
        Lista de FORNECEDORES via browserdataset — MESMO helper paginado do catálogo,
        só muda o dataset_id (por-instalação, do HAR → PINGWIN_SUPPLIERS_DATASET_ID) e
        os params. READ-ONLY (Action OPEN,GET,INFO,CLOSE). Requer login.

        Params com filtros vazios → devolve todos. Se a captura (HAR) mostrar nomes de
        campo diferentes, ajustar só este body — o mapeamento a montante (PHP) já é
        tolerante a aliases. Cada item traz tipicamente: id, code, name/description,
        taxnumber (NIF), address, phone, email, deleted, ...
        """
        # Params confirmados no HAR (fornecedores.har, dataset 1099511639252).
        body = {
            "orderby": "code",  # ordem estável p/ paginação consistente
            "params": {"CODE": "", "DESCRIPTION": "", "TAX_NUMBER": "", "CONTACT": "", "STORE_ID": "", "SHOWATTR": "0", "STATE": "0"},
        }
        items = self.fetch_browserdataset(dataset_id, body, page_size=page_size, max_pages=max_pages)
        log.info(f"Fornecedores: {len(items)}")
        return items

    # --------------------------------------------------- BROWSER: FAMÍLIAS
    def fetch_families(self) -> List[Dict[str, Any]]:
        """
        Árvore de famílias via GET /family (maindataset). Cada nó traz id, parent_id,
        description, deleted, parent_id_descr, … — a hierarquia REAL (não inferida da
        string do artigo). Um só pedido traz todas. Requer login.
        """
        url = f"{self.api_url}/family"
        r = self.session.get(url, headers={"Action": "OPEN,GET,INFO,CLOSE"})
        if r.status_code != 200:
            log.error(f"fetch_families falhou: HTTP {r.status_code} body={r.text[:1000]}")
            r.raise_for_status()
        fams = r.json().get("family", {}).get("maindataset", [])
        log.info(f"Famílias: {len(fams)}")
        return fams

    # ============================ CONDIÇÕES DE PAGAMENTO (paycond) ============================
    # Fatia 1 — READ-ONLY. Bloco PARALELO (como units/BOM); NÃO reusa o fluxo product.
    # LISTA via browserdataset (porta 8136, sessão principal — igual a stores/catálogo/
    # fornecedores). DETALHE por id (OPEN,GET,INFO → CLOSE best-effort) para trazer
    # discount/days/tbdocs/additionalfields, que a lista NÃO traz. NUNCA MERGE/SAVE.
    _PAYCOND_DETAIL_DATASETS = (
        "maindataset,tbdocs,additionalfields.fieldsinfo,additionalfields.maindataset"
    )

    def _paycond_base(self) -> str:
        """Base URL do serviço paycond. Default = api_url (8136, a MESMA porta/sessão do
        browserdataset). Overridable (paycond_url/paycond_port) caso uma instalação sirva
        o paycond noutra porta — aí faz-se login PRÓPRIO nessa porta (_port_session)."""
        if self.paycond_url:
            return self.paycond_url
        if self.paycond_port:
            parsed = urlparse(self.api_url)
            host = parsed.hostname or ""
            netloc = f"{host}:{self.paycond_port}" if host else parsed.netloc
            return f"{parsed.scheme}://{netloc}"
        return self.api_url

    def _paycond_headers(self, action: str, object_id: str | None = None) -> Dict[str, str]:
        h = {
            "Action":        action,
            "X-Database":    self.database,
            "X-AppGrupoPie": self.app_grupopie,
            "Content-Type":  "application/json;charset=UTF-8",
        }
        if object_id:
            h["ObjectID"] = object_id  # o MESMO handle em todos os passos
        return h

    def _paycond_list(self, dataset_id: str, state: str) -> List[Dict[str, Any]]:
        """Lista (browserdataset, 8136 sessão principal) p/ um STATE (0=ativos, 1=anulados).
        Body confirmado no HAR: {"params":{"CODE":"","DESCRIPTION":"","STATE":state}}."""
        body = {"params": {"CODE": "", "DESCRIPTION": "", "STATE": str(state)}}
        return self.fetch_browserdataset(dataset_id, body)

    def _paycond_open(self, session: requests.Session, paycond_id: str) -> Dict[str, Any]:
        """Abre UM registo pelo id (OPEN,GET,INFO) → maindataset + tbdocs + additionalfields.
        Devolve o corpo (dict). CLOSE best-effort (leitura; sem SAVE nada persiste)."""
        base = self._paycond_base()
        url = f"{base}/service/paycond/{paycond_id}/{self._PAYCOND_DETAIL_DATASETS}"
        r = session.post(url, data=b"", headers=self._paycond_headers("OPEN,GET,INFO"))
        if r.status_code not in (200, 206):
            raise RuntimeError(f"paycond OPEN by id falhou: HTTP {r.status_code} — {r.text[:600]}")
        object_id = r.headers.get("ObjectID") or r.headers.get("Objectid")
        try:
            data = r.json()
        except Exception:  # noqa: BLE001
            data = {}
        body = data.get("paycond") or data
        if object_id:  # descarta o staging de leitura (CLOSE), sem SAVE
            try:
                session.post(f"{base}/service/paycond", data=b"",
                             headers=self._paycond_headers("CLOSE", object_id))
            except Exception as exc:  # noqa: BLE001
                log.warning("paycond CLOSE falhou (ignorado): %s", type(exc).__name__)
        return body

    @staticmethod
    def _paycond_id(row: Dict[str, Any]) -> str:
        for k in ("id", "paycond_id", "dbid", "code"):
            v = row.get(k)
            if v not in (None, ""):
                return str(v)
        return ""

    def fetch_payment_conditions(self, dataset_id: str) -> List[Dict[str, Any]]:
        """
        CONDIÇÕES DE PAGAMENTO (READ-ONLY). Lista ativos (STATE 0) e anulados (STATE 1)
        via browserdataset e ENRIQUECE cada ATIVO com o detalhe (discount/days/tbdocs/
        additionalfields) abrindo o registo pelo id. Os anulados ficam só com os campos
        da lista (deleted=True) — não se abre o detalhe de um registo anulado.

        Cada item: id, code, description, deleted, discount, days, tbdocs[],
        additional_fields{}, raw{}. As conversões (discount decimal, days int) são
        feitas A MONTANTE no PHP — aqui repassa-se tal e qual (transporte "burro").
        """
        actives = self._paycond_list(dataset_id, "0")
        anulados = self._paycond_list(dataset_id, "1")
        log.info("paycond: %d ativo(s), %d anulado(s)", len(actives), len(anulados))

        base = self._paycond_base()
        use_main = (base == self.api_url)  # default: sessão principal (8136) já autenticada

        out: List[Dict[str, Any]] = []
        seen: set = set()

        def _enrich(session: requests.Session, row: Dict[str, Any]) -> Dict[str, Any]:
            pid = self._paycond_id(row)
            item: Dict[str, Any] = {
                "id": pid,
                "code": row.get("code"),
                "description": row.get("description"),
                "deleted": bool(int(row.get("deleted") or 0)),
            }
            if pid:
                try:
                    body = self._paycond_open(session, pid)
                    main = body.get("maindataset") or []
                    main0 = main[0] if main else {}
                    item["discount"] = main0.get("discount")
                    item["days"] = main0.get("days")
                    item["tbdocs"] = body.get("tbdocs") or []
                    item["additional_fields"] = {
                        "fieldsinfo":  body.get("additionalfields.fieldsinfo") or [],
                        "maindataset": body.get("additionalfields.maindataset") or [],
                    }
                    item["raw"] = body
                    if main0.get("code") is not None:
                        item["code"] = main0.get("code")
                    if main0.get("description") is not None:
                        item["description"] = main0.get("description")
                    if main0.get("deleted") is not None:
                        item["deleted"] = bool(int(main0.get("deleted") or 0))
                except Exception as exc:  # noqa: BLE001 — degrada p/ só-lista, não parte o sync
                    log.warning("paycond detalhe id=%s falhou (fica só lista): %s", pid, type(exc).__name__)
            return item

        def _consume_actives(session: requests.Session) -> None:
            for row in actives:
                pid = self._paycond_id(row)
                if pid in seen:
                    continue
                seen.add(pid)
                out.append(_enrich(session, row))

        if use_main:
            _consume_actives(self.session)
        else:
            # paycond noutra porta → login PRÓPRIO nessa porta, logout garantido.
            with self._port_session(base) as session:
                _consume_actives(session)

        # Anulados: só os campos da lista (não se abre o detalhe), marcados deleted.
        for row in anulados:
            pid = self._paycond_id(row)
            if pid in seen:
                continue
            seen.add(pid)
            out.append({
                "id": pid,
                "code": row.get("code"),
                "description": row.get("description"),
                "deleted": True,
            })

        log.info("paycond: %d condição(ões) no total (ativos enriquecidos + anulados)", len(out))
        return out

    # ⚠️⚠️ ESCRITA NO PINGWIN — CRIAR CONDIÇÃO DE PAGAMENTO (Fatia 2a) ⚠️⚠️
    # Bloco PARALELO novo (NÃO reusa create_product). Protocolo mais curto, confirmado
    # por HAR + probe ao vivo (form-novo = NEW,GET,INFO; discount é NÚMERO; template
    # 63× deleted:0; code sugerido pelo servidor). Sequência, mesma sessão/porta (8136)
    # e MESMO ObjectID em todos os passos:
    #   1. NEW,GET,INFO em /service/paycond/*/maindataset,tbdocs,additionalfields.*
    #      → ObjectID (header) + maindataset[0] (id PROVISÓRIO a ignorar, code sugerido)
    #        + tbdocs (63 linhas, todas deleted:0 = matriz-template).
    #   2. MERGE em /service/paycond/maindataset,tbdocs com o body completo
    #      (maindataset[1] + as 63 linhas tbdocs). A RESPOSTA traz maindataset[0].id =
    #      o ID FINAL REAL (≠ provisório). ⚠️ discount vai NÚMERO, days int.
    #   3. SAVE (corpo vazio) + CLOSE (corpo vazio) = commit/close (os 2 POSTs vazios).
    #   4. Confirmação por releitura (OPEN,GET,INFO pelo id FINAL): code/description/
    #      discount/days + os deleted das linhas tbdocs. "Sucesso do SAVE" ≠ gravado.
    # ⚠️ SALVAGUARDA: se o passo 2 não devolver id final (HTTP != 200 ou sem id), ABORTA
    # ANTES do commit (não envia SAVE/CLOSE) → nada persiste. TRAVA ANTI-ENCOLHIMENTO no
    # tbdocs (nº enviado == nº do form-novo, senão aborta sem gravar).
    _PAYCOND_ACT_OPEN        = "NEW,GET,INFO"    # passo 1 (form-NOVO, inserir)
    _PAYCOND_ACT_OPEN_EXIST  = "OPEN,GET,INFO"   # passo 1 (abrir EXISTENTE por id, editar)
    _PAYCOND_ACT_WRITE       = "MERGE"           # passo 2 (escrita do body)
    _PAYCOND_ACT_COMMIT      = "SAVE"            # passo 3a (commit)
    _PAYCOND_ACT_CLOSE       = "CLOSE"           # passo 3b (close)
    _PAYCOND_ACT_VOID        = "DELETE"          # anular (soft-delete, 1 POST sem body)

    def _paycond_open_form(self, session: requests.Session, paycond_id: str | None = None) -> tuple[str, Dict[str, Any]]:
        """Passo 1 da ESCRITA: abre o form e devolve (object_id, body) SEM FECHAR (o
        commit são os 2 POSTs vazios). `paycond_id` None → form-NOVO (`*`, NEW,GET,INFO);
        caso contrário → abre o EXISTENTE por id (OPEN,GET,INFO) p/ obter a matriz VIVA.
        ⚠️ Distinto do _paycond_open (de leitura, que FECHA) — não misturar."""
        base = self._paycond_base()
        target = paycond_id if paycond_id else "*"
        action = self._PAYCOND_ACT_OPEN_EXIST if paycond_id else self._PAYCOND_ACT_OPEN
        url = f"{base}/service/paycond/{target}/{self._PAYCOND_DETAIL_DATASETS}"
        r = session.post(url, data=b"", headers=self._paycond_headers(action))
        if r.status_code not in (200, 206):
            raise RuntimeError(f"paycond form-open falhou: HTTP {r.status_code} — {r.text[:600]}")
        object_id = r.headers.get("ObjectID") or r.headers.get("Objectid")
        if not object_id:
            raise RuntimeError(f"paycond form-open: header 'ObjectID' ausente. Headers: {dict(r.headers)}")
        data = r.json()
        return object_id, (data.get("paycond") or data)

    @staticmethod
    def _paycond_num(value: Any) -> float | int:
        """discount como NÚMERO (não string). Inteiro quando for inteiro (0, 2 → 0, 2),
        float caso contrário (2.5). Espelha o formato que o servidor devolve."""
        try:
            f = float(value if value not in (None, "") else 0)
        except (TypeError, ValueError):
            f = 0.0
        return int(f) if f == int(f) else f

    def _merge_tbdocs_template(self, template: List[Dict[str, Any]], unlinked: set) -> List[Dict[str, Any]]:
        """INSERIR: aplica os desmarcados sobre a matriz-template do form-novo: deleted:1
        nos docconfig_id em `unlinked`, o resto fica como veio (deleted:0 por omissão)."""
        out: List[Dict[str, Any]] = []
        for d in template:
            nd = dict(d)
            nd["deleted"] = 1 if str(nd.get("docconfig_id")) in unlinked else int(nd.get("deleted") or 0)
            out.append(nd)
        return out

    def _apply_tbdocs_changes(self, live: List[Dict[str, Any]], changes_map: Dict[str, int]) -> List[Dict[str, Any]]:
        """EDITAR (preservação de vínculos — R1): parte da matriz VIVA e altera SÓ o
        `deleted` das linhas cujo docconfig_id está em `changes_map`; TODAS as outras
        ficam com o deleted REAL que vinham do PingWin (preservadas). Reenvia as 63."""
        out: List[Dict[str, Any]] = []
        for d in live:
            nd = dict(d)
            did = str(nd.get("docconfig_id"))
            nd["deleted"] = changes_map[did] if did in changes_map else int(nd.get("deleted") or 0)
            out.append(nd)
        return out

    def _paycond_commit(self, session: requests.Session, object_id: str, row: Dict[str, Any],
                        docs: List[Dict[str, Any]], n_expected: int, capture: Dict[str, Any]) -> Dict[str, Any]:
        """Passos 2-4 COMUNS a inserir e editar: TRAVA anti-encolhimento → MERGE (lê id
        final; aborta ANTES do commit se falhar/sem id) → SAVE+CLOSE → confirmação por
        releitura. Devolve {ok, persisted, pingwin_id, code, capture, confirm, raw}."""
        base = self._paycond_base()
        # ⚠️ TRAVA ANTI-ENCOLHIMENTO: nº enviado tem de bater com o da matriz do passo 1.
        if len(docs) != n_expected:
            raise RuntimeError(f"paycond ABORTADO: tbdocs {len(docs)} != matriz {n_expected} (anti-encolhimento).")

        # 2. MERGE — body completo. A RESPOSTA traz o id (final no inserir; o mesmo no editar).
        write_url = f"{base}/service/paycond/maindataset,tbdocs"
        rw = session.post(write_url, json={"maindataset": [row], "tbdocs": docs},
                          headers=self._paycond_headers(self._PAYCOND_ACT_WRITE, object_id))
        capture["tbdocs_sent"] = len(docs)
        capture["write_http"] = rw.status_code
        if rw.status_code not in (200, 206):
            capture["write_body"] = rw.text[:800]
            return {"ok": False, "persisted": False, "aborted_before_commit": True, "capture": capture,
                    "error": f"MERGE falhou (HTTP {rw.status_code}) — abortado antes do commit."}
        wmain0 = ((rw.json().get("paycond") or rw.json()).get("maindataset") or [{}])[0]
        final_id = str(wmain0.get("id") or "")
        capture["discount_returned"] = wmain0.get("discount")
        capture["days_returned"] = wmain0.get("days")
        capture["final_id"] = final_id
        if not final_id:
            return {"ok": False, "persisted": False, "aborted_before_commit": True, "capture": capture,
                    "error": "MERGE não devolveu maindataset[0].id. Abortado antes do commit — contradiz o protocolo."}

        # 3. SAVE (vazio) + CLOSE (vazio) = commit/close, mesmo ObjectID.
        rs = session.post(f"{base}/service/paycond", data=b"",
                          headers=self._paycond_headers(self._PAYCOND_ACT_COMMIT, object_id))
        if rs.status_code not in (200, 206):
            raise RuntimeError(f"paycond SAVE falhou: HTTP {rs.status_code} — {rs.text[:600]}")
        rc = session.post(f"{base}/service/paycond", data=b"",
                          headers=self._paycond_headers(self._PAYCOND_ACT_CLOSE, object_id))
        if rc.status_code not in (200, 206):
            log.warning("paycond CLOSE devolveu HTTP %s (ignorado)", rc.status_code)

        # 4. CONFIRMAÇÃO por releitura pelo id (OPEN,GET,INFO → CLOSE, STATE 0).
        reread = self._paycond_open(session, final_id)
        rmain0 = ((reread.get("maindataset") or [{}])[0])
        rtbdocs = reread.get("tbdocs") or []
        linked_after = sorted(str(d.get("docconfig_id")) for d in rtbdocs if int(d.get("deleted") or 0) == 0)
        confirm = {
            "id":           str(rmain0.get("id") or ""),
            "code":         rmain0.get("code"),
            "description":  rmain0.get("description"),
            "discount":     rmain0.get("discount"),
            "days":         rmain0.get("days"),
            "deleted":      int(rmain0.get("deleted") or 0),
            "tbdocs_total": len(rtbdocs),
            "tbdocs_linked": linked_after,
        }
        persisted = (confirm["id"] == final_id and int(confirm["deleted"] or 0) == 0)
        return {
            "ok": persisted, "persisted": persisted, "pingwin_id": final_id,
            "code": str(rmain0.get("code") or row.get("code") or ""),
            "capture": capture, "confirm": confirm, "raw": reread,
        }

    def create_payment_condition(self, fields: Dict[str, Any], tbdocs_unlinked: List[Any] | None = None) -> Dict[str, Any]:
        """⚠️ ESCRITA (INSERIR): cria UMA condição. `fields` = {code?, description,
        discount(number), days(int)}. `tbdocs_unlinked` = docconfig_id a DESMARCAR."""
        base = self._paycond_base()
        if base == self.api_url:
            return self._create_paycond_on(self.session, fields, tbdocs_unlinked or [])
        with self._port_session(base) as session:
            return self._create_paycond_on(session, fields, tbdocs_unlinked or [])

    def _create_paycond_on(self, session: requests.Session, fields: Dict[str, Any], tbdocs_unlinked: List[Any]) -> Dict[str, Any]:
        # 1. NEW,GET,INFO (form-novo): ObjectID + provisório + template (63 deleted:0).
        object_id, body = self._paycond_open_form(session, None)
        template = body.get("tbdocs") or []
        prov0 = (body.get("maindataset") or [{}])[0]
        n_template = len(template)
        if n_template == 0:
            raise RuntimeError("create_paycond ABORTADO: form-novo sem tbdocs (matriz-template vazia).")

        # code: usa o fornecido; senão o SUGERIDO pelo servidor (nunca inventado às cegas).
        code = str(fields.get("code") if fields.get("code") not in (None, "") else prov0.get("code") or "")
        row = {
            "key":         "00000000",
            "id":          str(prov0.get("id") or ""),   # provisório (o servidor devolve o final no MERGE)
            "code":        code,
            "description": str(fields.get("description") or ""),
            "discount":    self._paycond_num(fields.get("discount")),  # ⚠️ NÚMERO
            "days":        int(fields.get("days") or 0),
            "deleted":     0,
        }
        unlinked = set(str(x) for x in (tbdocs_unlinked or []))
        docs = self._merge_tbdocs_template(template, unlinked)
        capture = {
            "op": "create", "provisional_id": row["id"], "code_suggested": prov0.get("code"),
            "code_sent": code, "discount_sent": row["discount"], "days_sent": row["days"],
            "tbdocs_unlinked": sorted(unlinked),
        }
        return self._paycond_commit(session, object_id, row, docs, n_template, capture)

    def update_payment_condition(self, paycond_id: str, fields: Dict[str, Any],
                                 tbdocs_changes: List[Dict[str, Any]] | None = None) -> Dict[str, Any]:
        """⚠️ ESCRITA (EDITAR condição ATIVA existente). Passo 1 abre o VIVO por id
        (matriz tbdocs REAL). `fields` = {description, discount(number), days(int)} —
        o code NÃO muda (read-only). `tbdocs_changes` = [{docconfig_id, deleted}] (SÓ as
        mudanças); as linhas não mexidas ficam com o deleted VIVO (preservação R1)."""
        base = self._paycond_base()
        if base == self.api_url:
            return self._update_paycond_on(self.session, paycond_id, fields, tbdocs_changes or [])
        with self._port_session(base) as session:
            return self._update_paycond_on(session, paycond_id, fields, tbdocs_changes or [])

    def _update_paycond_on(self, session: requests.Session, paycond_id: str, fields: Dict[str, Any],
                           tbdocs_changes: List[Dict[str, Any]]) -> Dict[str, Any]:
        # 1. OPEN,GET,INFO pelo id EXISTENTE → matriz tbdocs VIVA (deleted real) + valores atuais.
        object_id, body = self._paycond_open_form(session, str(paycond_id))
        live = body.get("tbdocs") or []
        cur0 = (body.get("maindataset") or [{}])[0]
        n_live = len(live)
        if n_live == 0:
            raise RuntimeError("update_paycond ABORTADO: open por id sem tbdocs (matriz viva vazia).")
        if int(cur0.get("deleted") or 0) != 0:
            # 2b é só para ATIVAS; reativar anulado é pós-2c.
            raise RuntimeError("update_paycond ABORTADO: condição não está ativa (deleted!=0).")

        # code INALTERADO (read-only no editar) — vem da releitura viva, não do input.
        row = {
            "key":         str(cur0.get("key") or "00000000"),
            "id":          str(cur0.get("id") or paycond_id),   # id EXISTENTE
            "code":        str(cur0.get("code") or ""),          # ⚠️ inalterado
            "description": str(fields.get("description") if fields.get("description") is not None else (cur0.get("description") or "")),
            "discount":    self._paycond_num(fields.get("discount") if fields.get("discount") is not None else cur0.get("discount")),
            "days":        int(fields.get("days") if fields.get("days") is not None else (cur0.get("days") or 0)),
            "deleted":     0,
        }
        # merge: aplica SÓ as mudanças sobre a matriz viva; preserva o resto (R1).
        changes_map = {str(c.get("docconfig_id")): int(c.get("deleted") or 0) for c in (tbdocs_changes or []) if c.get("docconfig_id") not in (None, "")}
        docs = self._apply_tbdocs_changes(live, changes_map)
        capture = {
            "op": "update", "paycond_id": str(paycond_id), "code_kept": row["code"],
            "discount_sent": row["discount"], "days_sent": row["days"],
            "tbdocs_changes": changes_map, "tbdocs_live": n_live,
            "linked_before": sorted(str(d.get("docconfig_id")) for d in live if int(d.get("deleted") or 0) == 0),
        }
        return self._paycond_commit(session, object_id, row, docs, n_live, capture)

    # ⚠️⚠️ ESCRITA NO PINGWIN — ANULAR CONDIÇÃO DE PAGAMENTO (Fatia 2c) ⚠️⚠️
    # SOFT-DELETE (confirmado 2× nas limpezas das fatias 2a/2b): UM único POST sem body a
    # /service/paycond/{id} com Action DELETE → o registo sai do STATE 0 (ativos) e entra
    # no STATE 1 (anulados), deleted:1. NÃO é o hard-delete de 3 passos do product — bloco
    # próprio, simples. Só anula se estiver ATIVA; confirma por releitura (STATE 0/1).
    def void_payment_condition(self, paycond_id: str, dataset_id: str) -> Dict[str, Any]:
        """⚠️ ESCRITA: anula (soft-delete) UMA condição ATIVA. Devolve {ok,
        voided_confirmed, void_http, in_state0_ativos, in_state1_anulados, pingwin_id}.
        Só voided_confirmed=True se a releitura mostrar: saiu do STATE 0 E está no STATE 1."""
        base = self._paycond_base()
        if base == self.api_url:
            return self._void_paycond_on(self.session, str(paycond_id), dataset_id)
        with self._port_session(base) as session:
            return self._void_paycond_on(session, str(paycond_id), dataset_id)

    def _void_paycond_on(self, session: requests.Session, pid: str, dataset_id: str) -> Dict[str, Any]:
        base = self._paycond_base()
        # Só anula se estiver ATIVA (STATE 0); se já estiver inativa, recusa (sem POST).
        active_before = any(str(x.get("id")) == pid for x in self._paycond_list(dataset_id, "0"))
        if not active_before:
            return {"ok": False, "voided_confirmed": False,
                    "error": "Condição não está ativa (STATE 0) — anular recusado."}

        r = session.post(f"{base}/service/paycond/{pid}", data=b"",
                         headers=self._paycond_headers(self._PAYCOND_ACT_VOID))
        if r.status_code not in (200, 206):
            return {"ok": False, "voided_confirmed": False, "void_http": r.status_code,
                    "error": f"Anular falhou (HTTP {r.status_code}) — {r.text[:300]}"}

        # Confirm-by-reread: tem de SAIR do STATE 0 e ESTAR no STATE 1. ("http 200" ≠ gravado.)
        in0 = any(str(x.get("id")) == pid for x in self._paycond_list(dataset_id, "0"))
        in1 = any(str(x.get("id")) == pid for x in self._paycond_list(dataset_id, "1"))
        voided = (not in0) and in1
        if not voided:
            log.error("void_paycond: id=%s NÃO confirmado (in_state0=%s in_state1=%s).", pid, in0, in1)
        return {
            "ok": voided, "voided_confirmed": voided, "void_http": r.status_code,
            "in_state0_ativos": in0, "in_state1_anulados": in1, "pingwin_id": pid,
        }

    # ---- PROBE SEGURO (SÓ LEITURA) do form-novo: passo 1 do inserir, SEM commit ----
    # Abre o form-novo (POST /service/paycond/*/...), LÊ (id provisório, code sugerido,
    # matriz-template tbdocs) e ABANDONA (NÃO envia os 2 POSTs vazios → NÃO persiste).
    # Serve para validar o Action do passo 1 e capturar a template antes de construir a
    # escrita. Tenta NEW,GET,INFO (aloca o form novo); reporta o que recebeu.
    def probe_new_paycond_form(self, action: str = "NEW,GET,INFO") -> Dict[str, Any]:
        base = self._paycond_base()
        url = f"{base}/service/paycond/*/{self._PAYCOND_DETAIL_DATASETS}"
        session = self.session if base == self.api_url else None
        owns = False
        if session is None:
            ctx = self._port_session(base)
            session = ctx.__enter__()
            owns = True
        try:
            r = session.post(url, data=b"", headers=self._paycond_headers(action))
            status = r.status_code
            object_id = r.headers.get("ObjectID") or r.headers.get("Objectid")
            try:
                data = r.json()
            except Exception:  # noqa: BLE001
                data = {}
            body = data.get("paycond") or data
            main = body.get("maindataset") or []
            main0 = main[0] if main else {}
            tbdocs = body.get("tbdocs") or []
            # ⚠️ NÃO enviar os POSTs vazios → sem commit, não persiste. Abandona a sessão.
            return {
                "action_used": action,
                "http_status": status,
                "object_id_present": bool(object_id),
                "maindataset0": main0,
                "maindataset0_keys": list(main0.keys()),
                "tbdocs_count": len(tbdocs),
                "tbdocs_sample": tbdocs[:3],
                "body_keys": list(body.keys()),
            }
        finally:
            if owns:
                ctx.__exit__(None, None, None)

    # ------------------------------------------------------- UNIDADES (porta 8138)
    def _units_base(self) -> str:
        """Base URL das unidades. Usa units_url se dado; senão deriva do api_url
        trocando a porta para units_port (8138). O SOA GrupoPIE reparte áreas por
        porta (8136 browser/relatórios, 8138 unidades)."""
        if self.units_url:
            return self.units_url
        parsed = urlparse(self.api_url)
        host = parsed.hostname or ""
        netloc = f"{host}:{self.units_port}" if host else parsed.netloc
        return f"{parsed.scheme}://{netloc}"

    def fetch_units(self) -> List[Dict[str, Any]]:
        """
        Lista de UNIDADES (base de conversão) na PORTA 8138. READ-ONLY
        (Action OPEN,GET,INFO).

        ⚠️ A sessão da 8136 NÃO é válida na 8138 (HTTP 401 "Session not found") —
        a 8138 exige o SEU PRÓPRIO login. Por isso usamos _port_session(8138), que
        faz login próprio nessa porta e GARANTE o logout dela à saída (mesmo com
        erro). A sessão principal (8136) fica intacta.

        A resposta traz units.maindataset (as ~48 unidades REAIS) e units.baseunit
        (centenas de "radio conv." = LIXO interno). USA-SE SÓ o maindataset.

        Cada item (maindataset): id, product_id (''=global; preenchido=específica de
        artigo), description, shortname, purchase/sale/stock, net_weight, frac_unit,
        external_measure, parent_id (unidade-base p/ conversão), parent_qnt/unit_value
        (fator de conversão, ex: Barril 50lt → parent_id=Litro, unit_value=50), deleted.
        """
        base = self._units_base()
        url = f"{base}/service/units/*/maindataset,baseunit,additionalfields.fieldsinfo,additionalfields.maindataset"
        headers = {"Action": "OPEN,GET,INFO", "Content-Type": "application/json;charset=UTF-8"}
        # Login PRÓPRIO na 8138 → usa a sessão dela → LOGOUT garantido na 8138.
        with self._port_session(base) as session:
            r = session.post(url, data=b"", headers=headers)
            if r.status_code not in (200, 206):
                log.error(f"fetch_units falhou: HTTP {r.status_code} body={r.text[:1000]}")
                r.raise_for_status()
            # SÓ o maindataset (as reais). O baseunit ("radio conv.") é ignorado.
            units = r.json().get("units", {}).get("maindataset", [])
        log.info(f"Unidades (maindataset): {len(units)}")
        return units

    # ⚠️⚠️ ESCRITA NO PINGWIN — CRIAR / EDITAR / ANULAR UNIDADE ⚠️⚠️
    # CADEIA REAL E COMPLETA (do HAR do criar-que-funciona). TUDO na MESMA sessão
    # (8136) e os pedidos partilham o MESMO ObjectID (o handle da grelha, obtido no
    # OPEN e reenviado em todos — no HAR é sempre o mesmo, ex.: afe643c7…):
    #   1. OPEN : POST .../units/*/maindataset,baseunit,…  Action:OPEN,GET,INFO
    #             → abre a grelha; o ObjectID vem no HEADER da resposta.
    #   2. NEW  : POST .../units/maindataset  Action:NEW  corpo [{só a unidade nova}]
    #             → adiciona a nova à grelha (staging, NÃO persiste).
    #   3. GET,INFO : Action:GET,INFO → LISTA COMPLETA já com a nova incluída.
    #   4. ⚠️ EDIT,SAVE (O COMMIT): Action:EDIT,SAVE corpo = A LISTA COMPLETA do
    #             passo 3, TAL E QUAL (sem reconstruir/reordenar/alterar). ISTO PERSISTE.
    #   5. GET,INFO : confirma.
    # ⚠️ RISCO ALTO: o EDIT,SAVE reenvia a TABELA TODA — se a lista vier incompleta e
    # a enviássemos, apagava unidades. Salvaguardas: usar a lista EXATA do servidor
    # (nunca a nossa BD), e ABORTAR o SAVE se a lista vier com menos do que o esperado
    # ou sem a nova. Sem o commit, o NEW "cria em memória" e não persiste ("criar mentiu").
    _UNITS_URL_PATH = "/service/units/maindataset"
    _UNITS_OPEN_PATH = "/service/units/*/maindataset,baseunit,additionalfields.fieldsinfo,additionalfields.maindataset"

    def _units_headers(self, action: str, object_id: str | None = None) -> Dict[str, str]:
        h = {
            "Action":        action,
            "X-Database":    self.database,
            "X-AppGrupoPie": self.app_grupopie,
            "Content-Type":  "application/json;charset=UTF-8",
        }
        if object_id:
            h["ObjectID"] = object_id  # o MESMO handle da grelha em todos os passos
        return h

    def _units_open(self) -> str | None:
        """Passo 1: abre a grelha (Action OPEN,GET,INFO, o mesmo pedido do fetch_units),
        na sessão da escrita (8136). Devolve o ObjectID do HEADER (reutilizado nos
        passos seguintes); None se o servidor não o der (aí segue-se só pela sessão)."""
        url = f"{self.api_url}{self._UNITS_OPEN_PATH}"
        r = self.session.post(url, data=b"", headers=self._units_headers("OPEN,GET,INFO"))
        if r.status_code not in (200, 206):
            raise RuntimeError(f"open_units (OPEN,GET,INFO) falhou: HTTP {r.status_code} — {r.text[:800]}")
        return r.headers.get("ObjectID") or r.headers.get("Objectid")

    def _units_getinfo(self, object_id: str | None) -> List[Dict[str, Any]]:
        """Action:GET,INFO → a LISTA COMPLETA das unidades (maindataset), tal e qual
        o servidor a devolve (é esta que se reenvia no EDIT,SAVE)."""
        url = f"{self.api_url}{self._UNITS_URL_PATH}"
        r = self.session.post(url, data=b"", headers=self._units_headers("GET,INFO", object_id))
        if r.status_code not in (200, 206):
            raise RuntimeError(f"units GET,INFO falhou: HTTP {r.status_code} — {r.text[:800]}")
        return r.json().get("units", {}).get("maindataset", [])

    def create_unit(self, unit: Dict[str, Any]) -> Dict[str, Any]:
        """Cria uma unidade: OPEN → (baseline) GET,INFO → NEW → GET,INFO(lista c/ nova)
        → ⚠️ EDIT,SAVE dessa lista EXATA (commit) → GET,INFO(confirma). Tudo na mesma
        sessão/ObjectID. Salvaguardas contra apagar a tabela. Erro REAL exposto."""
        url = f"{self.api_url}{self._UNITS_URL_PATH}"
        allowed = ["description", "shortname", "parent_qnt", "parent_id", "net_weight",
                   "external_measure", "frac_unit", "warn_maxsale_qnt"]
        row = {k: unit[k] for k in allowed if k in unit and unit[k] not in (None, "")}
        if "parent_qnt" in row:
            row["parent_qnt"] = str(row["parent_qnt"])  # HAR envia como string

        object_id = self._units_open()                       # 1. abrir grelha (ObjectID)
        baseline = self._units_getinfo(object_id)            # baseline (mesmo endpoint do check)
        n0 = len(baseline)

        # 2. NEW — adiciona a nova à grelha (staging).
        r = self.session.post(url, json=[row], headers=self._units_headers("NEW", object_id))
        if r.status_code != 200:
            raise RuntimeError(f"create_unit NEW falhou: HTTP {r.status_code} — {r.text[:800]}")
        created = r.json().get("units", {}).get("maindataset", [])
        if not created:
            raise RuntimeError(f"create_unit: resposta NEW sem unidade — {r.text[:800]}")
        new_unit = created[0]
        new_id = str(new_unit.get("id") or "")
        if not new_id:
            raise RuntimeError(f"create_unit: unidade criada sem id — {r.text[:800]}")
        # o NEW pode devolver um ObjectID no header — se vier, passa a ser o handle.
        object_id = r.headers.get("ObjectID") or r.headers.get("Objectid") or object_id
        log.info("Unidade NEW (staging): id=%s desc=%s", new_id, new_unit.get("description"))

        # 3. GET,INFO → LISTA COMPLETA já com a nova.
        full = self._units_getinfo(object_id)

        # ⚠️⚠️ SALVAGUARDAS antes do commit (o EDIT,SAVE reenvia a tabela toda) ⚠️⚠️
        present = any(str(u.get("id")) == new_id for u in full)
        if not present:
            raise RuntimeError("create_unit ABORTADO: a nova unidade não aparece na lista do GET,INFO (algo falhou no NEW). NÃO gravei.")
        if len(full) < n0 + 1:
            raise RuntimeError(
                f"create_unit ABORTADO: lista incompleta (antes={n0}, agora={len(full)}); "
                "gravar apagaria unidades. NÃO gravei."
            )

        # 4. EDIT,SAVE — commit da lista EXATA do passo 3 (tal e qual, sem alterar).
        rs = self.session.post(url, json=full, headers=self._units_headers("EDIT,SAVE", object_id))
        if rs.status_code != 200:
            raise RuntimeError(f"create_unit EDIT,SAVE (commit) falhou: HTTP {rs.status_code} — {rs.text[:800]}")

        # 5. GET,INFO — confirma que a nova persiste.
        committed = False
        final_n = len(full)
        try:
            final = self._units_getinfo(object_id)
            final_n = len(final)
            committed = any(str(u.get("id")) == new_id for u in final)
        except Exception as exc:  # noqa: BLE001 — confirmação secundária
            log.warning("create_unit: GET,INFO final falhou (ignorado): %s", type(exc).__name__)

        # Reportar humildemente (o _confirmed já mentiu 2x). O Simon verifica no PingWin.
        new_unit["_committed_in_getinfo"] = committed
        new_unit["_units_before"] = n0
        new_unit["_units_after"] = final_n
        return new_unit

    def save_unit(self, unit: Dict[str, Any]) -> Dict[str, Any]:
        """⚠️ ESCRITA (editar/anular): OPEN → GET,INFO(lista completa do servidor) →
        sobrepõe SÓ a linha alvo (por id) com os campos de `unit` → ⚠️ EDIT,SAVE dessa
        lista COMPLETA (as outras linhas ficam TAL E QUAL as do servidor) → GET,INFO.
        Editar=deleted:0, anular=deleted:1. Tudo na mesma sessão/ObjectID. Erro REAL
        exposto. Salvaguarda: a lista vem do SERVIDOR (nunca da nossa BD) e o alvo tem
        de existir nela."""
        uid = str(unit.get("id") or "")
        if not uid:
            raise RuntimeError("save_unit: falta o id da unidade a gravar.")
        url = f"{self.api_url}{self._UNITS_URL_PATH}"

        object_id = self._units_open()                 # 1. abrir grelha (ObjectID)
        full = self._units_getinfo(object_id)          # 2. LISTA COMPLETA do servidor
        n0 = len(full)
        if n0 == 0:
            raise RuntimeError("save_unit ABORTADO: GET,INFO devolveu lista vazia. NÃO gravei.")

        # 3. Sobrepor SÓ a linha alvo (na lista do servidor, in-place). As outras
        #    ficam intactas — nunca reconstruímos a partir da nossa BD.
        target = next((u for u in full if str(u.get("id")) == uid), None)
        if target is None:
            raise RuntimeError(f"save_unit ABORTADO: a unidade {uid} não está na lista do servidor. NÃO gravei.")
        for k, v in unit.items():
            if k == "id":
                continue
            target[k] = v  # description/shortname/parent_id/parent_qnt/unit_value/deleted/…

        # ⚠️ Salvaguarda: não encolher a tabela.
        if len(full) < n0:
            raise RuntimeError("save_unit ABORTADO: lista encolheu inesperadamente. NÃO gravei.")

        # 4. EDIT,SAVE — commit da lista completa (com o alvo alterado).
        rs = self.session.post(url, json=full, headers=self._units_headers("EDIT,SAVE", object_id))
        if rs.status_code != 200:
            raise RuntimeError(f"save_unit EDIT,SAVE falhou: HTTP {rs.status_code} — {rs.text[:800]}")
        log.info("Unidade EDIT,SAVE (commit): id=%s deleted=%s", uid, unit.get("deleted"))

        # 5. GET,INFO — estado real confirmado.
        saved = target
        try:
            final = self._units_getinfo(object_id)
            match = next((u for u in final if str(u.get("id")) == uid), None)
            if match:
                saved = match
        except Exception as exc:  # noqa: BLE001 — confirmação secundária
            log.warning("save_unit: GET,INFO final falhou (ignorado): %s", type(exc).__name__)
        return saved

    # ════════════════════════════════ FICHA TÉCNICA (BOM / productbom) ════════════
    # SÓ LEITURA. Sequência descoberta ao vivo (read-only — nunca grava no PingWin):
    #   OPEN  → ObjectID (no HEADER da resposta)
    #   maindataset (Action GET) — carrega a ficha na sessão do ObjectID
    #   lkwarehouses (GET,INFO) — armazéns disponíveis
    #   prodbomdetail (GET,INFO, warehouse_id) — linhas (composição + custo do armazém)
    #   CLOSE
    # NOTA: maindataset TEM de ser chamado antes de prodbomdetail no mesmo ObjectID.
    # NOTA: o armazém id="" (default da sessão) é NÃO-DETERMINÍSTICO — ignorar; usar
    #       só os armazéns nomeados (custo estável e reproduzível).
    def _bom_headers(self, action: str, object_id: str | None = None) -> Dict[str, str]:
        h = {
            "Action": action,
            "X-AppGrupoPie": self.app_grupopie,
            "Content-Type": "application/json;charset=UTF-8",
        }
        if object_id:
            h["ObjectID"] = object_id
        return h

    def open_bom(self, product_id: str) -> str:
        """Abre a ficha técnica do artigo (Action OPEN). Devolve o ObjectID (header).
        Read-only: abrir+fechar sem gravar não escreve nada no PingWin."""
        url = f"{self.api_url}/service/product/{product_id}/productbom"
        r = self.session.post(url, json={"params": {}}, headers=self._bom_headers("OPEN"))
        if r.status_code != 200:
            log.error("open_bom %s falhou: HTTP %s body=%s", product_id, r.status_code, r.text[:1000])
            r.raise_for_status()
        object_id = r.headers.get("ObjectID") or r.headers.get("Objectid")
        if not object_id:
            raise RuntimeError(
                f"open_bom {product_id}: header 'ObjectID' ausente. Headers: {dict(r.headers)}"
            )
        return object_id

    def fetch_bom_meta(self, object_id: str) -> Dict[str, Any]:
        """maindataset (Action GET, read-only) — {id (productbom_id), product_id,
        cashbox_date, product_id_descr, deleted}. Carrega a ficha na sessão."""
        url = (
            f"{self.api_url}/service/product/productbom.maindataset/"
            f"productbom.storerelation.storedata,productbom.storerelation.storegroup"
        )
        r = self.session.post(url, json=[{"key": "00000000"}], headers=self._bom_headers("GET", object_id))
        if r.status_code != 200:
            log.error("fetch_bom_meta falhou: HTTP %s body=%s", r.status_code, r.text[:1000])
            r.raise_for_status()
        prod = r.json().get("product", {})
        md = prod.get("productbom.maindataset", [])
        meta = dict(md[0]) if md else {}
        # storerelation (quais lojas a ficha cobre) — guarda o que vier (pode vir vazio).
        storerel = {k: v for k, v in prod.items() if k.startswith("productbom.storerelation")}
        if storerel:
            meta["_storerelation"] = storerel
        return meta

    def fetch_bom_warehouses(self, object_id: str) -> List[Dict[str, Any]]:
        """lkwarehouses (GET,INFO) — [{id, description}]. Inclui o default id="" (a
        ignorar a montante por ser não-determinístico)."""
        url = f"{self.api_url}/service/product/productbom.lkwarehouses"
        r = self.session.post(url, json={"params": {}}, headers=self._bom_headers("GET,INFO", object_id))
        if r.status_code != 200:
            log.error("fetch_bom_warehouses falhou: HTTP %s body=%s", r.status_code, r.text[:1000])
            r.raise_for_status()
        return r.json().get("product", {}).get("productbom.lkwarehouses", [])

    def fetch_bom_ingredients(self, object_id: str, warehouse_id: str = "") -> List[Dict[str, Any]]:
        """prodbomdetail (GET,INFO) para um armazém — linhas com composição
        (qnt/qnt_base/waste/recovery/unit) e custo (unit_cost/cost) + isbom."""
        url = (
            f"{self.api_url}/service/product/"
            f"productbom.prodbomdetail,productbom.lkunits,productbom.lkprodunits"
        )
        body = {"params": {"warehouse_id": warehouse_id}}
        r = self.session.post(url, json=body, headers=self._bom_headers("GET,INFO", object_id))
        if r.status_code != 200:
            log.error("fetch_bom_ingredients wh=%s falhou: HTTP %s body=%s", warehouse_id, r.status_code, r.text[:1000])
            r.raise_for_status()
        return r.json().get("product", {}).get("productbom.prodbomdetail", [])

    def close_bom(self, product_id: str, object_id: str) -> None:
        """Fecha a sessão da ficha (Action CLOSE). Sem SAVE → nada é gravado."""
        url = f"{self.api_url}/service/product/{product_id}/productbom"
        try:
            self.session.post(url, json={"params": {}}, headers=self._bom_headers("CLOSE", object_id))
        except Exception as exc:  # noqa: BLE001 — fechar é best-effort; não rebenta o sync
            log.warning("close_bom %s falhou (ignorado): %s", product_id, type(exc).__name__)

    # ═══════════════════════ FORM DO ARTIGO (form-lookups) — porta 8134 ═══════════
    # ⚠️ SÓ LEITURA (Caminho 1/3). O form do artigo NÃO abre com OPEN puro: abre com
    # Action NEW,GET,INFO (Content-Length:0) na PORTA 8134 (nem 8136 nem 8138). A
    # resposta traz o ObjectID no HEADER e, no corpo (chave "product"), o registo em
    # branco com o CODE novo já calculado + TODOS os lookups. Exercemos o NEW UMA vez
    # para LER, fechamos logo (CLOSE) e PROVAMOS POR LEITURA (browserdataset) que nada
    # persistiu. NUNCA MERGE nem EDIT,SAVE aqui — isso é a escrita (Etapa 1b).
    # 19 datasets, EXATAMENTE nesta ordem (confirmado por HAR).
    _PRODUCT_FORM_DATASETS = (
        "maindataset,movementtype,lkfamily_id,lkmodelgridtype,product_type,lkstatus,"
        "productunits.baseunit,productunits.lkunitsale,productunits.lkunitpurchase,"
        "lktaxgroup,tbtaxtable,lk_stockconfig,productgroup,lkproductdiscount,prices,"
        "productbom.maindataset,additionalfields.lk_service_tax_type,"
        "additionalfields.fieldsinfo,additionalfields.maindataset"
    )
    # OPEN MÍNIMO (só o suficiente para o ObjectID vir no header) — usado no ANULAR,
    # onde não precisamos dos 136 KB de lookups. A etapa 2 (ler/editar) reutiliza o
    # helper _product_open_by_id com a string COMPLETA (_PRODUCT_FORM_DATASETS).
    _PRODUCT_MIN_DATASETS = "maindataset"

    def _product_base(self) -> str:
        """Base URL do form/escrita de artigo. Usa product_url se dado; senão deriva do
        api_url trocando a porta para product_port (default 8134, overridable). Todo o
        fluxo de criar corre nesta base, numa só sessão (o mesmo SessionID em todos os passos)."""
        if self.product_url:
            return self.product_url
        parsed = urlparse(self.api_url)
        host = parsed.hostname or ""
        netloc = f"{host}:{self.product_port}" if host else parsed.netloc
        return f"{parsed.scheme}://{netloc}"

    def _product_headers(self, action: str, object_id: str | None = None) -> Dict[str, str]:
        h = {
            "Action":        action,
            "X-Database":    self.database,
            "X-AppGrupoPie": self.app_grupopie,
            "Content-Type":  "application/json;charset=UTF-8",
        }
        if object_id:
            h["ObjectID"] = object_id  # o MESMO handle do form em todos os passos
        return h

    def _product_open(self, session: requests.Session) -> tuple[str, Dict[str, Any]]:
        """Passo 1: NEW,GET,INFO (Content-Length:0) na 8134 → abre o form em STAGING.
        Devolve (object_id do HEADER, corpo 'product'). Staging de LEITURA: gera o
        registo em branco (code novo) + lookups; NÃO persiste sem EDIT,SAVE."""
        url = f"{self._product_base()}/service/product/*/{self._PRODUCT_FORM_DATASETS}"
        r = session.post(url, data=b"", headers=self._product_headers("NEW,GET,INFO"))
        if r.status_code not in (200, 206):
            raise RuntimeError(f"product NEW,GET,INFO falhou: HTTP {r.status_code} — {r.text[:800]}")
        object_id = r.headers.get("ObjectID") or r.headers.get("Objectid")
        if not object_id:
            raise RuntimeError(f"product form: header 'ObjectID' ausente. Headers: {dict(r.headers)}")
        return object_id, r.json().get("product", {})

    def _product_open_by_id(self, session: requests.Session, product_id: str, datasets: str) -> tuple[str | None, Dict[str, Any]]:
        """OPEN,GET,INFO de um artigo EXISTENTE pelo id. `datasets` parametriza o que vem:
        - ANULAR: _PRODUCT_MIN_DATASETS (só o ObjectID importa);
        - LER/EDITAR (etapa 2): _PRODUCT_FORM_DATASETS (artigo completo + lookups do form).
        Devolve (object_id do header, corpo 'product'). Read-only (não persiste)."""
        url = f"{self._product_base()}/service/product/{product_id}/{datasets}"
        r = session.post(url, data=b"", headers=self._product_headers("OPEN,GET,INFO"))
        if r.status_code not in (200, 206):
            raise RuntimeError(f"product OPEN by id falhou: HTTP {r.status_code} — {r.text[:600]}")
        object_id = r.headers.get("ObjectID") or r.headers.get("Objectid")
        return object_id, r.json().get("product", {})

    def _product_close(self, session: requests.Session, object_id: str) -> None:
        """Passo 2: CLOSE (Content-Length:0, mesmo ObjectID) → descarta o staging de
        forma limpa. Sem SAVE → nada é gravado. Best-effort (não mascara o erro real)."""
        url = f"{self._product_base()}/service/product"
        try:
            session.post(url, data=b"", headers=self._product_headers("CLOSE", object_id))
        except Exception as exc:  # noqa: BLE001
            log.warning("product CLOSE falhou (ignorado): %s", type(exc).__name__)

    @staticmethod
    def _filter_radio_conv(units: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
        """Filtra o lixo interno "radio conv." (aparece às centenas no baseunit)."""
        out = []
        for u in units or []:
            label = f"{u.get('description', '')} {u.get('shortname', '')}".lower()
            if "radio conv" in label:
                continue
            out.append(u)
        return out

    def product_form_lookups(self, catalog_dataset_id: str = "") -> Dict[str, Any]:
        """
        SÓ LEITURA do form de artigo (Caminho 1/3): NEW,GET,INFO na 8134 → CLOSE →
        salvaguarda por browserdataset (a contagem de artigos NÃO pode subir).
        Devolve um shape limpo para o frontend (code novo + lookups). NUNCA grava.
        """
        base = self._product_base()

        # Baseline ANTES (browserdataset, 8136 — a mesma leitura do sync de catálogo).
        count_before = len(self.fetch_catalog(catalog_dataset_id)) if catalog_dataset_id else None

        # Login PRÓPRIO na 8134 (a sessão da 8136 não vale lá) → logout garantido.
        with self._port_session(base) as session:
            object_id, product = self._product_open(session)   # NEW,GET,INFO (staging leitura)
            try:
                main = product.get("maindataset") or []
                form: Dict[str, Any] = {
                    "next_code":      str(main[0].get("code")) if main and main[0].get("code") is not None else None,
                    "product_type":   product.get("product_type") or [],
                    "lkstatus":       product.get("lkstatus") or [],
                    "lktaxgroup":     product.get("lktaxgroup") or [],
                    "lk_stockconfig": product.get("lk_stockconfig") or [],
                    "families":       product.get("lkfamily_id") or [],   # árvore (parent_id/node_level)
                    "units": {
                        "base":     self._filter_radio_conv(product.get("productunits.baseunit") or []),
                        "sale":     product.get("productunits.lkunitsale") or [],
                        "purchase": product.get("productunits.lkunitpurchase") or [],
                    },
                    "prices": ((product.get("prices") or [{}])[0]) if product.get("prices") else {},
                    "additional_fields": {
                        "fieldsinfo":       product.get("additionalfields.fieldsinfo") or [],
                        "service_tax_type": product.get("additionalfields.lk_service_tax_type") or [],
                    },
                }
            finally:
                self._product_close(session, object_id)         # descarta staging (CLOSE), sem SAVE

        # Salvaguarda DEPOIS: reler e confirmar que NADA persistiu.
        count_after = len(self.fetch_catalog(catalog_dataset_id)) if catalog_dataset_id else None
        persisted = (count_before is not None and count_after is not None and count_after > count_before)
        form["_guard"] = {"count_before": count_before, "count_after": count_after, "persisted": persisted}
        if persisted:
            log.error("product_form_lookups: CONTAGEM SUBIU (antes=%s, depois=%s) — o NEW persistiu!",
                      count_before, count_after)
        return form

    # ⚠️⚠️ ESCRITA NO PINGWIN — CRIAR ARTIGO ⚠️⚠️
    # Toda a sequência corre numa ÚNICA sessão/porta (mecanismo por-porta das Unidades);
    # MESMO ObjectID (do header do NEW) + MESMO SessionID em todos os passos:
    #   1. NEW,GET,INFO → ObjectID (header) + registo em branco (code) + lookups/listas.
    #   2. CARREGAR LOJAS (GET,INFO) → storerelation.storedata/storegroup. ⚠️ Salvaguarda A:
    #      abortar se 0 lojas (MERGE sem lojas criaria o artigo sem lojas).
    #   3. (opcional) EDIT /prices → [{saleprice/purchaseprice}] (decimal string, ponto).
    #   4. MERGE maindataset(1) + as LOJAS do passo 2 + productgroup+tbtaxtable+tbstock+
    #      additionalfields.* — reenviando as listas tal como o servidor as deu (não inventar).
    #   5. SAVE (Content-Length:0)  ·  6. CLOSE (Content-Length:0)
    #   7. ⚠️ Salvaguarda B: CONFIRMAR por browserdataset filtrado pelo code (o SAVE responde
    #      {"product":{}} vazio → NÃO prova nada). Só persisted=True se o code aparecer.
    # NOTA: o aggregate é SÓ deste ObjectID (o MERGE leva só ESTE produto), logo NÃO há
    # risco de apagar outros artigos; a salvaguarda "abortar se a grelha encolher" (unidades)
    # não se aplica — aqui as salvaguardas são A (lojas) e B (releitura).
    def _product_load_stores(self, session: requests.Session, object_id: str) -> tuple[List[Dict[str, Any]], List[Dict[str, Any]]]:
        """Passo 2: carrega as LOJAS da empresa (Action GET,INFO, mesmo ObjectID) —
        POST /service/product/storerelation.storedata,storerelation.storegroup. É
        LEITURA (não escreve). Devolve (storedata, storegroup) para reenviar no MERGE."""
        url = f"{self._product_base()}/service/product/storerelation.storedata,storerelation.storegroup"
        r = session.post(url, data=b"", headers=self._product_headers("GET,INFO", object_id))
        if r.status_code not in (200, 206):
            raise RuntimeError(f"create_product carregar-lojas falhou: HTTP {r.status_code} — {r.text[:600]}")
        prod = r.json().get("product", {})
        storedata = prod.get("storerelation.storedata") or []
        storegroup = prod.get("storerelation.storegroup") or []
        return storedata, storegroup

    def _product_load_supplier_prices(self, session: requests.Session, object_id: str) -> List[Dict[str, Any]]:
        """SÓ LEITURA (tab Compras): carrega as linhas de fornecedor do artigo aberto —
        POST /service/product/tbsupprice com Action GET,INFO + o MESMO ObjectID do OPEN
        (não é pedido isolado). Mesmo padrão das lojas (_product_load_stores). NÃO escreve.
        Devolve a lista de linhas tbsupprice (row-dicts crus) ou [] se falhar."""
        url = f"{self._product_base()}/service/product/tbsupprice"
        r = session.post(url, data=b"", headers=self._product_headers("GET,INFO", object_id))
        if r.status_code not in (200, 206):
            raise RuntimeError(f"read_product carregar-tbsupprice falhou: HTTP {r.status_code} — {r.text[:600]}")
        return r.json().get("product", {}).get("tbsupprice") or []

    def _product_load_supplier_tables(self, session: requests.Session, object_id: str) -> List[Dict[str, Any]]:
        """SÓ LEITURA (cascata da tab Compras): tabelas-por-fornecedor (lksuppliertable) —
        POST /service/product/lksuppliertable com Action GET,INFO + o MESMO ObjectID.
        Igual ao tbsupprice/lojas. NÃO escreve. Lookup (fornecedor→tabela→datas/moeda)."""
        url = f"{self._product_base()}/service/product/lksuppliertable"
        r = session.post(url, data=b"", headers=self._product_headers("GET,INFO", object_id))
        if r.status_code not in (200, 206):
            raise RuntimeError(f"read_product carregar-lksuppliertable falhou: HTTP {r.status_code} — {r.text[:600]}")
        return r.json().get("product", {}).get("lksuppliertable") or []

    def _product_browser_by_code(self, session: requests.Session, dataset_id: str, code: str) -> Dict[str, Any] | None:
        """Passo 6: relê o browserdataset (na 8134) filtrado pelo code e devolve a
        linha EXATA (code igual) ou None. É a prova de que o artigo persistiu."""
        url = f"{self._product_base()}/service/browser/{dataset_id}/browserdataset"
        body = {
            "filter": {"code": {"op": "like", "value": f"%{code}%"}},
            "params": {"CODE": "", "DESCRIPTION": "", "BARCODE": "", "FAMILY_ID": "",
                       "PART_PRODUCT_ID": "", "STORE_ID": "", "SHOWATTR": "0", "STATE": "0"},
        }
        headers = self._product_headers("OPEN,GET,INFO,CLOSE")
        headers["Range"] = "items=0-50"
        r = session.post(url, json=body, headers=headers)
        if r.status_code not in (200, 206):
            raise RuntimeError(f"create_product confirmação (browserdataset) falhou: HTTP {r.status_code} — {r.text[:600]}")
        rows = r.json().get("browser", {}).get("browserdataset", [])
        for it in rows:
            if str(it.get("code")) == str(code):
                return it
        return None

    def create_product(self, product: Dict[str, Any],
                       saleprice: str | None = None,
                       purchaseprice: str | None = None,
                       catalog_dataset_id: str = "") -> Dict[str, Any]:
        """
        ⚠️ ESCRITA: cria UM artigo no PingWin (porta 8134). Devolve
        {ok, persisted, code, pingwin_id, raw}. Só persisted=True se o passo 6
        (releitura) encontrar o code. Erros estruturados (aborta) se: NEW sem
        ObjectID/code; SAVE falhar; ou o code não confirmar.
        """
        base = self._product_base()
        with self._port_session(base) as session:
            # 1. NEW,GET,INFO — abre o form (ObjectID + registo em branco com code + listas).
            object_id, body = self._product_open(session)
            main = body.get("maindataset") or []
            if not main:
                raise RuntimeError("create_product: NEW sem maindataset (sem registo em branco).")
            code = str(main[0].get("code") or "")
            if not code:
                raise RuntimeError("create_product: NEW não devolveu code novo.")

            # Registo: parte do BRANCO do servidor e sobrepõe os nossos campos; code do servidor.
            row = dict(main[0])
            row.update(product)
            row["code"] = code
            # ⚠️ PONTO 1 (bug do preço): o maindataset do MERGE vai SEM chaves de preço.
            # A UI não manda saleprice no maindataset (AUSENTE, não vazio). O registo-branco
            # traz saleprice:"" — se o reenviássemos, o servidor via a chave e punha o preço
            # a vazio, sobrepondo o EDIT /prices. REMOVER as CHAVES (não esvaziar) → o servidor
            # NÃO toca no preço posto pelo EDIT /prices. O preço vive só no ciclo /prices.
            for _pk in ("saleprice", "purchaseprice", "markup"):
                row.pop(_pk, None)

            # 2. CARREGAR LOJAS (GET,INFO, mesmo ObjectID) → reenviar no MERGE.
            # Estas são as lojas que EXISTEM na empresa (infra) — nº variável entre
            # empresas e não fixo (Yuko tem 3, outra terá outras ids). NÃO hard-codar.
            storedata, storegroup = self._product_load_stores(session, object_id)
            stores_count = len(storedata)
            # ⚠️ SALVAGUARDA A: exigir > 0 (0 = sessão partida / servidor sem resposta);
            # nunca fazer MERGE com lojas vazias → criaria o artigo sem lojas.
            if stores_count == 0:
                raise RuntimeError("create_product ABORTADO: passo 2 devolveu 0 lojas — não gravei (MERGE sem lojas criaria o artigo sem lojas).")
            # CRIAR básico: reenviar TODAS as lojas que vieram, tal e qual. A ESCOLHA de
            # quais marcar (0..N) é da futura tab "Loja" — aí o MERGE levará só as marcadas.

            # 3. PREÇOS (opcional, ANTES do MERGE) — decimal string com ponto; o servidor calcula margem/IVA.
            price_row: Dict[str, str] = {}
            if saleprice not in (None, ""):
                price_row["saleprice"] = str(saleprice)
            if purchaseprice not in (None, ""):
                price_row["purchaseprice"] = str(purchaseprice)
            price_url = f"{base}/service/product/prices"

            # 4. MERGE — maindataset(1) + as LOJAS do passo 2 (tal como vieram) + listas do NEW.
            merge_url = (f"{base}/service/product/"
                         "maindataset,storerelation.storedata,productgroup,tbtaxtable,"
                         "tbstock,additionalfields.maindataset,additionalfields.storedataset")
            merge_body = {
                "maindataset": [row],
                "storerelation.storedata": storedata,   # ⚠️ do passo 2 (nunca vazio — Salvaguarda A)
                "productgroup": body.get("productgroup") or [],
                "tbtaxtable": body.get("tbtaxtable") or [],
                "tbstock": body.get("tbstock") or [],
                "additionalfields.maindataset": body.get("additionalfields.maindataset") or [],
                "additionalfields.storedataset": body.get("additionalfields.storedataset") or [],
            }

            if price_row:
                rp = session.post(price_url, json=[price_row], headers=self._product_headers("EDIT", object_id))
                if rp.status_code not in (200, 206):
                    raise RuntimeError(f"create_product EDIT prices falhou: HTTP {rp.status_code} — {rp.text[:600]}")

            rm = session.post(merge_url, json=merge_body, headers=self._product_headers("MERGE", object_id))
            if rm.status_code not in (200, 206):
                raise RuntimeError(f"create_product MERGE falhou: HTTP {rm.status_code} — {rm.text[:600]}")

            # 5. SAVE (corpo vazio; a resposta {"product":{}} NÃO prova nada — daí o passo 7).
            rs = session.post(f"{base}/service/product", data=b"", headers=self._product_headers("SAVE", object_id))
            if rs.status_code not in (200, 206):
                raise RuntimeError(f"create_product SAVE falhou: HTTP {rs.status_code} — {rs.text[:600]}")

            # 6. CLOSE (best-effort).
            self._product_close(session, object_id)

            # 7. ⚠️ SALVAGUARDA B: confirmação por releitura (browserdataset pelo code), MESMA sessão/porta.
            found = self._product_browser_by_code(session, catalog_dataset_id, code) if catalog_dataset_id else None

        persisted = bool(found)
        if not persisted:
            log.error("create_product: code=%s NÃO confirmado no browserdataset — NÃO persistiu.", code)
        return {
            "ok": persisted,
            "persisted": persisted,
            "code": code,
            "pingwin_id": str(found.get("id")) if found and found.get("id") is not None else None,
            "stores_count": stores_count,
            "raw": found or {},
        }

    def read_product(self, product_id: str) -> Dict[str, Any]:
        """SÓ LEITURA (etapa 2): abre o artigo pelo id (OPEN,GET,INFO com os 19 datasets),
        lê o registo + prices[0] + lojas ligadas + lookups, e CLOSE limpo. NÃO faz
        NEW/EDIT/MERGE/SAVE/DELETE. Logout garantido (_port_session try/finally)."""
        base = self._product_base()
        with self._port_session(base) as session:
            object_id, product = self._product_open_by_id(session, product_id, self._PRODUCT_FORM_DATASETS)
            try:
                main = product.get("maindataset") or []
                prices = product.get("prices") or []
                stores: List[Dict[str, Any]] = []
                supplier_prices: List[Dict[str, Any]] = []
                supplier_tables: List[Dict[str, Any]] = []
                if object_id:
                    try:
                        stores, _ = self._product_load_stores(session, object_id)
                    except Exception as exc:  # noqa: BLE001 — lojas são secundárias à leitura
                        log.warning("read_product: storerelation falhou (ignorado): %s", type(exc).__name__)
                    try:
                        supplier_prices = self._product_load_supplier_prices(session, object_id)
                    except Exception as exc:  # noqa: BLE001 — linhas de fornecedor secundárias à leitura
                        log.warning("read_product: tbsupprice falhou (ignorado): %s", type(exc).__name__)
                    try:
                        supplier_tables = self._product_load_supplier_tables(session, object_id)
                    except Exception as exc:  # noqa: BLE001 — cascata secundária à leitura
                        log.warning("read_product: lksuppliertable falhou (ignorado): %s", type(exc).__name__)
                out = {
                    "found": bool(main),
                    "maindataset": main[0] if main else {},
                    "prices": prices[0] if prices else {},
                    "stores": stores,
                    "supplier_prices": supplier_prices,
                    "supplier_tables": supplier_tables,
                    "lookups": {
                        "product_type":   product.get("product_type") or [],
                        "lkstatus":       product.get("lkstatus") or [],
                        "lktaxgroup":     product.get("lktaxgroup") or [],
                        "lk_stockconfig": product.get("lk_stockconfig") or [],
                        "families":       product.get("lkfamily_id") or [],
                        "units": {
                            "base":     self._filter_radio_conv(product.get("productunits.baseunit") or []),
                            "sale":     product.get("productunits.lkunitsale") or [],
                            "purchase": product.get("productunits.lkunitpurchase") or [],
                        },
                    },
                    "additional_fields": {
                        "fieldsinfo":       product.get("additionalfields.fieldsinfo") or [],
                        "maindataset":      product.get("additionalfields.maindataset") or [],
                        "service_tax_type": product.get("additionalfields.lk_service_tax_type") or [],
                    },
                }
            finally:
                if object_id:
                    self._product_close(session, object_id)
        return out

    # ⚠️⚠️ ESCRITA NO PINGWIN — EDITAR ARTIGO ⚠️⚠️
    # Sequência (HAR save-edit), na porta da sessão, MESMO ObjectID; logout try/finally:
    #   1. OPEN,GET,INFO pelo id (19 datasets) → ObjectID + registo EXISTENTE.
    #   2. (se o preço mudou) EDIT /service/product/prices [{"saleprice":"X"}] ANTES do MERGE.
    #   3. MERGE em maindataset,productgroup,tbtaxtable,additionalfields.maindataset (path
    #      CURTO — as relações/lojas já existem). O maindataset = registo do OPEN + campos
    #      alterados, com as CHAVES DE PREÇO REMOVIDAS (saleprice/purchaseprice/markup) —
    #      senão sobrepõe o preço (o bug do 101246, agora também evitado no editar).
    #   4. SAVE → CLOSE.
    #   5. CONFIRMAÇÃO in-session: re-OPEN pelo id → lê status + prices reais (autoritativo).
    def update_product(self, product_id: str, changes: Dict[str, Any],
                       saleprice: str | None = None,
                       purchaseprice: str | None = None,
                       supplier_prices_changes: Dict[str, Any] | None = None) -> Dict[str, Any]:
        """⚠️ ESCRITA: edita um artigo existente. `changes` = campos do maindataset a
        sobrepor (ex.: {"status": 3}). `supplier_prices_changes` (opcional) = linhas de
        fornecedor a criar/editar/apagar (tab Compras, C2) — se vazio/None, o editar é
        IDÊNTICO a antes. Devolve {ok, product_id, code, confirm{...}, supplier_prices[...]}
        com a leitura AUTORITATIVA pós-gravação."""
        base = self._product_base()
        with self._port_session(base) as session:
            # 1. OPEN,GET,INFO pelo id → ObjectID + registo existente.
            object_id, product = self._product_open_by_id(session, product_id, self._PRODUCT_FORM_DATASETS)
            if not object_id:
                raise RuntimeError("update_product: OPEN não devolveu ObjectID.")
            main = product.get("maindataset") or []
            if not main:
                raise RuntimeError(f"update_product: artigo {product_id} não encontrado (maindataset vazio).")
            row = dict(main[0])
            row.update(changes)   # sobrepõe os campos alterados (ex.: status)

            # 2. PREÇOS (só se mudou) — EDIT /prices ANTES do MERGE.
            price_row: Dict[str, str] = {}
            if saleprice not in (None, ""):
                price_row["saleprice"] = str(saleprice)
            if purchaseprice not in (None, ""):
                price_row["purchaseprice"] = str(purchaseprice)
            if price_row:
                rp = session.post(f"{base}/service/product/prices",
                                  json=[price_row], headers=self._product_headers("EDIT", object_id))
                if rp.status_code not in (200, 206):
                    raise RuntimeError(f"update_product EDIT prices falhou: HTTP {rp.status_code} — {rp.text[:600]}")

            # 2b. LINHAS DE FORNECEDOR (C2) — staging na MESMA sessão, ANTES do MERGE. Só se
            #     vieram mudanças; senão NÃO toca em nada (o editar normal fica idêntico).
            if supplier_prices_changes:
                self._apply_supplier_price_changes(session, object_id, supplier_prices_changes)

            # 3. MERGE (path CURTO) — maindataset SEM chaves de preço (não sobrepor o preço).
            for _pk in ("saleprice", "purchaseprice", "markup"):
                row.pop(_pk, None)
            merge_url = (f"{base}/service/product/"
                         "maindataset,productgroup,tbtaxtable,additionalfields.maindataset")
            merge_body = {
                "maindataset": [row],
                "productgroup": product.get("productgroup") or [],
                "tbtaxtable": product.get("tbtaxtable") or [],
                "additionalfields.maindataset": product.get("additionalfields.maindataset") or [],
            }
            rm = session.post(merge_url, json=merge_body, headers=self._product_headers("MERGE", object_id))
            if rm.status_code not in (200, 206):
                raise RuntimeError(f"update_product MERGE falhou: HTTP {rm.status_code} — {rm.text[:600]}")

            # 4. SAVE → CLOSE.
            rs = session.post(f"{base}/service/product", data=b"", headers=self._product_headers("SAVE", object_id))
            if rs.status_code not in (200, 206):
                raise RuntimeError(f"update_product SAVE falhou: HTTP {rs.status_code} — {rs.text[:600]}")
            self._product_close(session, object_id)

            # 5. CONFIRMAÇÃO in-session — re-OPEN pelo id (leitura autoritativa).
            oid2, prod2 = self._product_open_by_id(session, product_id, self._PRODUCT_FORM_DATASETS)
            m2 = (prod2.get("maindataset") or [{}])[0]
            p2 = (prod2.get("prices") or [{}])[0]
            confirm = {
                "code":        m2.get("code"),
                "description": m2.get("description"),
                "status":      m2.get("status"),
                "saleprice":   p2.get("saleprice"),
                "purchaseprice": p2.get("purchaseprice"),
            }
            # Confirmação das linhas de fornecedor (autoritativo; sucesso ≠ enviado).
            supplier_prices: List[Dict[str, Any]] = []
            if oid2:
                try:
                    supplier_prices = self._product_load_supplier_prices(session, oid2)
                except Exception as exc:  # noqa: BLE001 — secundário à confirmação
                    log.warning("update_product: reler tbsupprice falhou (ignorado): %s", type(exc).__name__)
                self._product_close(session, oid2)

        return {"ok": True, "product_id": str(product_id), "code": str(row.get("code") or ""),
                "confirm": confirm, "supplier_prices": supplier_prices}

    # ⚠️⚠️ ESCRITA NO PINGWIN — LINHAS DE FORNECEDOR (tab Compras, C2) ⚠️⚠️
    # Staging na sessão do artigo (MESMO ObjectID), efetivado no SAVE do artigo. HAR:
    #   DELETE linha → DELETE tbsupprice [linha inteira atual].
    #   EDITAR linha → LOCATE tbsupprice [linha inteira] → EDIT tbsupprice [linha + alterações].
    #   INSERIR linha → NEW tbsupprice [{"supprice_header_id"}] → EDIT tbsupprice [linha nova].
    # Falha em qualquer passo → raise ANTES do SAVE → o logout (finally) descarta o staging
    # → o artigo NÃO fica meio-gravado. Python é "burro": recebe já no formato PingWin.
    def _apply_supplier_price_changes(self, session: requests.Session, object_id: str, changes: Dict[str, Any]) -> None:
        url = f"{self._product_base()}/service/product/tbsupprice"

        # Linhas atuais → mapa por id (reenviar a linha TAL COMO o servidor a deu).
        current: Dict[str, Dict[str, Any]] = {}
        for r in self._product_load_supplier_prices(session, object_id):
            rid = str(r.get("id") or "")
            if rid:
                current[rid] = r

        def _post(action: str, body: List[Dict[str, Any]]) -> None:
            resp = session.post(url, json=body, headers=self._product_headers(action, object_id))
            if resp.status_code not in (200, 206):
                raise RuntimeError(f"tbsupprice {action} falhou: HTTP {resp.status_code} — {resp.text[:400]}")

        # 1. DELETE (linha inteira atual). Se já não existe, ignora.
        for line_id in (changes.get("delete") or []):
            row = current.get(str(line_id))
            if row:
                _post("DELETE", [row])

        # 2. UPDATE (LOCATE linha inteira → EDIT linha + alterações).
        for upd in (changes.get("update") or []):
            line_id = str(upd.get("id") or "")
            row = current.get(line_id)
            if not row:
                raise RuntimeError(f"tbsupprice UPDATE: linha {line_id} não existe no artigo.")
            _post("LOCATE", [row])                      # HAR: LOCATE envia a linha inteira
            merged = dict(row)
            merged.update(upd.get("fields") or {})
            _post("EDIT", [merged])

        # 3. CREATE (NEW {supprice_header_id} → EDIT linha nova completa).
        #    ⚠️ O NEW DEVOLVE a linha nova COM o id atribuído pelo servidor. O EDIT tem de
        #    reenviar ESSA linha (com o id) + os campos — senão, havendo outras linhas, o
        #    servidor não sabe qual editar e dá 404 ("Record not found").
        for cre in (changes.get("create") or []):
            header_id = cre.get("supprice_header_id")
            if header_id in (None, ""):
                raise RuntimeError("tbsupprice CREATE: supprice_header_id em falta.")
            new_resp = session.post(url, json=[{"supprice_header_id": header_id}], headers=self._product_headers("NEW", object_id))
            if new_resp.status_code not in (200, 206):
                raise RuntimeError(f"tbsupprice NEW falhou: HTTP {new_resp.status_code} — {new_resp.text[:400]}")
            new_rows = new_resp.json().get("product", {}).get("tbsupprice") or []
            # A linha nova é a que ainda não estava em 'current' (id novo); fallback: a última.
            newrow = next((r for r in new_rows if str(r.get("id") or "") not in current), None) or (new_rows[-1] if new_rows else None)
            if not newrow:
                raise RuntimeError("tbsupprice NEW não devolveu a linha nova.")
            merged = dict(newrow)
            merged.update(cre.get("line") or {})
            _post("EDIT", [merged])
            current[str(newrow.get("id") or "")] = merged  # p/ creates múltiplos na mesma sessão

    # ⚠️⚠️ ESCRITA NO PINGWIN — APAGAR ARTIGO (DELETE definitivo) ⚠️⚠️
    # DELETE = apagar A SÉRIO (não é "descontinuar"/mudar Estado — isso é EDITAR o status).
    # Após o DELETE, o browserdataset filtrado pelo code vem VAZIO; NÃO é reactivável.
    # Sequência FIEL à UI (HAR), na porta da sessão, MESMO SessionID; logout garantido
    # (try/finally do _port_session, corre mesmo que rebente a meio):
    #   1. OPEN,GET,INFO do artigo pelo id → devolve o ObjectID no header (abre a edição).
    #   2. CANCEL,CLOSE em /service/product/<id> COM esse ObjectID → fecha a sessão de edição.
    #   3. DELETE em /service/product/<id> SEM ObjectID (só SessionID) → apaga. {"product":{}} (NÃO prova).
    #   4. ⚠️ SALVAGUARDA (inverte a do criar): reler browserdataset pelo code → tem de vir
    #      VAZIO. Se o artigo AINDA aparecer → o DELETE falhou; deleted_confirmed=False.
    def delete_product(self, product_id: str, code: str = "", catalog_dataset_id: str = "") -> Dict[str, Any]:
        """⚠️ ESCRITA: apaga DEFINITIVAMENTE um artigo. Devolve {ok, deleted_confirmed,
        code, product_id, still_present}. Só deleted_confirmed=True se a releitura pelo
        code vier VAZIA. Logout garantido pelo _port_session (try/finally)."""
        base = self._product_base()
        url = f"{base}/service/product/{product_id}"
        with self._port_session(base) as session:
            # 1. OPEN,GET,INFO do artigo pelo id → ObjectID (header). OPEN MÍNIMO (só o
            #    ObjectID importa para apagar). Abre a sessão de edição.
            object_id, _ = self._product_open_by_id(session, product_id, self._PRODUCT_MIN_DATASETS)
            if not object_id:
                raise RuntimeError("delete_product: OPEN não devolveu ObjectID (necessário para o CANCEL,CLOSE).")

            # 2. CANCEL,CLOSE COM ObjectID — fecha a sessão de edição aberta pelo OPEN.
            rc = session.post(url, data=b"", headers=self._product_headers("CANCEL,CLOSE", object_id))
            if rc.status_code not in (200, 206):
                raise RuntimeError(f"delete_product CANCEL,CLOSE falhou: HTTP {rc.status_code} — {rc.text[:600]}")

            # 3. DELETE SEM ObjectID (só SessionID; id no path). {"product":{}} vazio NÃO prova.
            rd = session.post(url, data=b"", headers=self._product_headers("DELETE"))
            if rd.status_code not in (200, 206):
                raise RuntimeError(f"delete_product DELETE falhou: HTTP {rd.status_code} — {rd.text[:600]}")

            # 4. ⚠️ SALVAGUARDA: releitura pelo code TEM de vir VAZIA (o artigo desapareceu).
            still = self._product_browser_by_code(session, catalog_dataset_id, code) if (catalog_dataset_id and code) else None

        deleted_confirmed = (still is None)
        if not deleted_confirmed:
            log.error("delete_product: code=%s AINDA aparece no browserdataset — DELETE NÃO confirmado.", code)
        return {
            "ok": deleted_confirmed,
            "deleted_confirmed": deleted_confirmed,
            "code": code,
            "product_id": str(product_id),
            "still_present": (still is not None),
        }

    # ------------------------------------------------------------- PARSE
    def fetch_sales_report(self, target_date: datetime) -> pd.DataFrame:
        report_file = self.trigger_report(target_date)
        excel_bytes = self.download_report(report_file)
        df = parse_xls_to_dataframe(excel_bytes)
        log.info(f"Excel parsed: {len(df)} linhas | colunas: {list(df.columns)}")
        return df

    @staticmethod
    def extract_store_data(df: pd.DataFrame) -> List[Dict[str, Any]]:
        """
        Devolve uma lista de dicts (um por loja), excluindo a linha de totais.
        Inclui todas as métricas relevantes do XLS.
        """
        required = ["Loja", "Vendas líquidas"]
        for col in required:
            if col not in df.columns:
                raise ValueError(
                    f"Coluna '{col}' em falta. Disponíveis: {list(df.columns)}"
                )

        def _f(row, col, default=0.0):
            try:
                return float(row.get(col, default) or default)
            except (ValueError, TypeError):
                return default

        def _i(row, col, default=0):
            try:
                return int(float(row.get(col, default) or default))
            except (ValueError, TypeError):
                return default

        results = []
        for _, row in df.iterrows():
            loja = str(row["Loja"]).strip()
            if not loja or loja.lower() == "nan":
                continue
            results.append({
                "loja":            loja,
                "vendas_brutas":   _f(row, "Vendas brutas"),
                "notas_credito":   _f(row, "Notas de crédito"),
                "descontos":       _f(row, "Descontos"),
                "vendas_liquidas": _f(row, "Vendas líquidas"),
                "impostos":        _f(row, "Impostos"),
                "valor_faturado":  _f(row, "Valor faturado"),
                "num_tickets":     _i(row, "Nº tickets"),
                "pos_num_pessoas": _i(row, "Nº pessoas"),
            })

        log.info(f"Lojas extraídas: {len(results)}")
        return results


def build_client(**kwargs) -> MyCloudPieClient:
    """Factory: `with build_client(auth_url=..., ...) as c:` → login/logout garantidos."""
    return MyCloudPieClient(**kwargs)
