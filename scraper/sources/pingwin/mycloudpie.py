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
import ssl
import sys
import time
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
    ):
        self.auth_url = auth_url.rstrip("/")
        self.api_url = api_url.rstrip("/")
        self.frontend_url = frontend_url.rstrip("/")
        # ⚠️ As UNIDADES vivem numa PORTA DIFERENTE do SOA GrupoPIE (8138, não a
        # 8136 do browser/relatórios). O SOA reparte áreas por porta. Aceita um
        # override explícito (units_url); senão deriva do api_url trocando a porta.
        self.units_url = units_url.rstrip("/") if units_url else ""
        self.units_port = str(units_port or "8138")
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

    # ---------------------------------------------------------------- LOGOUT
    def logout(self) -> None:
        """
        ACRESCENTO XPLENDOR (capturado do PingWin real). Encerra a sessão:
        POST {api_url}/service/logout, corpo vazio (Content-Length 0), com os
        headers da sessão. Resposta 200 "Session terminated" = sucesso.

        BEST-EFFORT (como close_bom): apanha qualquer exceção e só faz
        log.warning — NUNCA levanta (não pode mascarar o erro real da consulta),
        mas é SEMPRE tentado (sem logout a sessão do PingWin trava e estraga o
        restaurante).
        """
        if not self.session_id:
            return
        try:
            r = self.session.post(
                f"{self.api_url}/service/logout",
                data=b"",
                headers={
                    "Sessionid":     self.session_id,
                    "Signature":     self.signature or "",
                    "X-AppGrupoPie": self.app_grupopie,
                    "X-Database":    self.database,
                    "RequestID":     self.request_id or "",
                    "Content-Length": "0",
                },
            )
            if r.status_code == 200:
                log.info("Logout OK — %s", (r.text or "").strip()[:60])
            else:
                log.warning("Logout devolveu HTTP %s (ignorado)", r.status_code)
        except Exception as exc:  # noqa: BLE001 — best-effort; não mascara o erro real
            log.warning("logout falhou (ignorado): %s", type(exc).__name__)
        finally:
            self.session_id = None  # marca encerrada localmente (evita reuso)

    # ---------------------------------------------------------------- LOGIN
    def login(self) -> None:
        """
        Autenticação challenge-response do PingWin BO (GrupoPIE Portugal). (§3.3)

        Passo 1 — POST /service/login (corpo vazio): servidor responde com header
          "Challenge" no formato "1:NONCE:SALT" (NONCE 32 hex, SALT 16 hex).
        Passo 2 — POST /service/authenticate (corpo vazio): envia Authentication e
          signature calculados do challenge. Servidor responde com header "Sessionid".
        """
        log.info("A autenticar no MyCloudPie...")

        import os
        self.request_id = base64.b64encode(os.urandom(16)).decode()

        common_headers = {
            "Origin":        self.frontend_url,
            "Referer":       self.frontend_url + "/",
            "RequestID":     self.request_id,
            "X-Database":    self.database,
            "X-AppGrupoPie": self.app_grupopie,
        }

        # --- Passo 1: obter challenge ---
        r1 = self.session.post(
            f"{self.auth_url}/service/login",
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
            raise RuntimeError(
                f"Passo 1 (/service/login) falhou: HTTP {r1.status_code} — {r1.text}"
            )
        challenge = r1.headers.get("Challenge")
        if not challenge:
            raise RuntimeError(
                f"Header 'Challenge' ausente na resposta de /service/login. "
                f"Headers recebidos: {dict(r1.headers)}"
            )

        # Atenção: nomenclatura invertida em relação ao habitual no JS original
        parts = challenge.split(":")
        nonce = parts[1]   # 32 hex chars
        salt  = parts[2]   # 16 hex chars

        # --- Cálculo do authHash (PBKDF2 customizado) e derivados ---
        auth_hash = custom_pbkdf2(self.password, salt)
        md51           = hashlib.md5(f"{nonce}:{auth_hash}".encode()).hexdigest()
        md52           = hashlib.md5(f"{md51}:{nonce}".encode()).hexdigest()
        authentication = f"1:{quote(self.username, safe='')}:{nonce}:{md52}"
        timestamp_ms   = int(time.time() * 1000)
        signature      = hashlib.md5(f"{auth_hash}{timestamp_ms}".encode()).hexdigest()
        self.signature = signature

        # --- Passo 2: autenticar e obter session ---
        r2 = self.session.post(
            f"{self.auth_url}/service/authenticate",
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
            raise RuntimeError(
                f"Passo 2 (/service/authenticate) falhou: HTTP {r2.status_code} — {r2.text}"
            )
        session_id = r2.headers.get("Sessionid")
        if not session_id:
            raise RuntimeError(
                f"Header 'Sessionid' ausente na resposta de /service/authenticate. "
                f"Headers recebidos: {dict(r2.headers)}"
            )

        self.session_id = session_id

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
        (Action OPEN,GET,INFO). A sessão obtida no login (8136) é aceite na 8138
        (mesmo Sessionid — confirmado no HAR). Requer login feito.

        ⚠️ A resposta traz units.maindataset (as ~48 unidades REAIS) e units.baseunit
        (centenas de "radio conv." = LIXO interno). USA-SE SÓ o maindataset.

        Cada item (maindataset): id, product_id (''=global; preenchido=específica de
        artigo), description, shortname, purchase/sale/stock, net_weight, frac_unit,
        external_measure, parent_id (unidade-base p/ conversão), parent_qnt/unit_value
        (fator de conversão, ex: Barril 50lt → parent_id=Litro, unit_value=50), deleted.
        """
        url = f"{self._units_base()}/service/units/*/maindataset,baseunit,additionalfields.fieldsinfo,additionalfields.maindataset"
        headers = {"Action": "OPEN,GET,INFO", "Content-Type": "application/json;charset=UTF-8"}
        r = self.session.post(url, data=b"", headers=headers)
        if r.status_code not in (200, 206):
            log.error(f"fetch_units falhou: HTTP {r.status_code} body={r.text[:1000]}")
            r.raise_for_status()
        # SÓ o maindataset (as reais). O baseunit ("radio conv.") é ignorado.
        units = r.json().get("units", {}).get("maindataset", [])
        log.info(f"Unidades (maindataset): {len(units)}")
        return units

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
