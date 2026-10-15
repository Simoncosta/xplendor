"""
XPLENDOR — Fatia 7b: documentos de stock no PingWin (Contagem 1106, Desperdício 1100), a lista
dos documentos, os artigos contáveis e o relatório de Movimentos (838630403295).

Protocolo dos HAR de 15/10 (contagem, desperdicio, inventario-anulado), na porta da API (a da
sessão principal). O ObjectID vem no cabeçalho da resposta do NEW e reenvia-se em todos os passos;
nada persiste sem SAVE; CANCEL descarta (sempre num finally, quando não houve SAVE).

Estados: 8001 Aberto (não mexe no stock), 8002 Fechado (mexe), 8003 Anulado, 8004 Edição (nunca).
Nunca o Inventário 1105 (redefine também o preço). As transferências não entram aqui.

⚠️ Escritas: uma de cada vez, iniciadas por uma pessoa; a guarda (allowed_codes: só estes códigos
de artigo; ["0"] = nenhum; vazio = produção) é verificada aqui, antes de qualquer pedido.
"""
from __future__ import annotations

import base64
import json
import logging
import re
from typing import Any, Dict, List

import requests

log = logging.getLogger("mycloudpie")


def _num(v: Any) -> float:
    try:
        return float(v)
    except (TypeError, ValueError):
        return 0.0


def _qty_text(v: Any) -> str:
    """A quantidade como o ecrã do PingWin a envia: texto com 3 casas ("8.000")."""
    return f"{float(v):.3f}"


class StockDocsMixin:
    COUNT_DOCCONFIG = "1106"
    WASTE_DOCCONFIG = "1100"
    COUNT_BROWSER = "1099511639270"
    DOCS_BROWSER = "1099511639269"
    MOVEMENTS_REPORT = "838630403295"
    MOVEMENT_DOCCONFIGS = (
        "1002,1031,1410,1300,1106,1100,1225,1213,1202,1013,1212,1200,584955579139630867,1006,62590524561076436,1104,1005,1030,"
        "1209,1016,1014,1024,1020,1027,1018,584955579139752236,1022,1025,1011,1107,1102,1219,1220,1012,1217,1218,1108,1214,"
        "1223,1224,1008,1203,1221,1222,1007,1208,1215,1216,1105,1211,1009,1205,1021,1026,1029,1010,1206,1032,1028,1109,1015,"
        "1110,1902,1901,1201,1903,1033,1207,585727986578670852,585727986578670856,1103,1500,99098"
    )
    _COUNT_GET = ("productlist,inventorysetup,details,detailproduct,docconfig,docreason,docserie,docstatus,documentimport,"
                  "documenttransform,lk_docconfig,header,store,emplolyee,warehouse")
    _WASTE_GET = ("header,footer,details,store,docserie,docstatus,docreason,extra,entity,doclocal,currency,lkcurrency,paycondition,"
                  "shipping_method,docreference,paymethod,entity_address.address,expedition_address.address,delivery_address.address,"
                  "accounts,doctaxes.taxscenario,taxroundmode,appendix.maindataset,appendix.lkappendixtype,warehouse,doctaxes.taxgroup,"
                  "lk_docconfig,docconfig,detailsource")
    _WASTE_ADDR = "entity_address.address,expedition_address.address,delivery_address.address"
    # Só estas combinações caminho+ação passam ({dc} = 1106 ou 1100).
    _SD_ACTIONS = {
        "{dc}": {"NEW", "SAVE", "CLOSE", "CANCEL"},
        "1106/" + _COUNT_GET: {"GET,INFO"},
        "1106/header/warehouse,docserie/": {"EDIT,GET"},
        "1106/header": {"EDIT"},
        "1106/details": {"NEW", "EDIT", "GET,INFO"},
        "1106/productlist": {"GET,INFO"},
        "1106/header,details": {"MERGE", "GET,INFO"},
        "1100/" + _WASTE_GET: {"GET,INFO"},
        "1100/header": {"EDIT", "GET,INFO"},
        "1100/details": {"NEW", "EDIT"},
        "1100/details,header": {"GET,INFO"},
        "1100/fiscalrules.verifydoc": {"GET,INFO"},
        "1100/validation": {"GET,INFO"},
        "1100/" + _WASTE_ADDR: {"MERGE", "GET,INFO"},
        "1100/fiscalrules.verifydoc,fiscalrules.alternativedocs": {"GET,INFO"},
        "address/*/maindataset": {"OPEN,GET,CLOSE"},
        # Fatia 7c: transferências e encomendas ({doc} = 1102, 1104, 1007 e as encomendas; {id} = documento de origem).
        "{doc}": {"NEW", "SAVE", "CLOSE", "CANCEL"},
        "{doc}/{id}": {"NEW"},
        "{doc}/" + _WASTE_GET: {"GET,INFO"},
        "{doc}/header": {"EDIT", "GET,INFO"},
        "{doc}/details": {"NEW", "EDIT"},
        "{doc}/details,header": {"GET,INFO"},
        "{doc}/header,doctaxes.taxheader,details": {"GET,INFO"},
        "{doc}/entitysearch": {"GET,INFO"},
        "{doc}/fiscalrules.verifydoc": {"GET,INFO"},
        "{doc}/validation": {"GET,INFO"},
        "{doc}/" + _WASTE_ADDR: {"MERGE", "GET,INFO"},
        "{doc}/fiscalrules.verifydoc,fiscalrules.alternativedocs": {"GET,INFO"},
    }
    GA_DOCCONFIG = "1102"
    GT_DOCCONFIG = "1007"
    EA_DOCCONFIG = "1104"
    ORDER_DRINKS = "584955579139630867"
    ORDER_PRODUCTION = "62590524561076436"
    DOC_FAMILY = ("1102", "1104", "1007", "584955579139630867", "62590524561076436")

    # ── pedidos ─────────────────────────────────────────────────────────────────
    def _sd(self, path: str, action: str, oid: str | None = None, body: Any = None) -> requests.Response:
        key = re.sub(r"^(1106|1100)$", "{dc}", path)
        head, _, rest = path.partition("/")
        if head in self.DOC_FAMILY:
            key = "{doc}" + ("/" + ("{id}" if rest.isdigit() else rest) if rest else "")
        if action not in self._SD_ACTIONS.get(key, set()):
            raise RuntimeError(f"documento de stock: '{action}' em '{path}' recusado (fora do protocolo).")
        h = self._paycond_headers(action, oid)
        if body is None:
            h.pop("Content-Type", None)
            return self.session.post(f"{self.api_url}/service/{path}", data=b"", headers=h)
        return self.session.post(f"{self.api_url}/service/{path}", data=json.dumps(body), headers=h)

    @staticmethod
    def _sd_rows(r: requests.Response, dc: str, block: str, required: bool = True) -> List[Dict[str, Any]]:
        if r.status_code not in (200, 206):
            raise RuntimeError(f"{dc}/{block}: HTTP {r.status_code} — {r.text[:300]}")
        data = r.json()
        if isinstance(data, dict) and data.get("code") not in (None, 0, "0") and data.get("message"):
            raise RuntimeError(f"{dc}/{block}: {data.get('message')}")
        inner = data.get(dc, data) if isinstance(data, dict) else {}
        rows = inner.get(block) if isinstance(inner, dict) else None
        if required and (not isinstance(rows, list) or not rows):
            raise RuntimeError(f"{dc}/{block}: resposta sem linhas — {r.text[:300]}")
        return rows or []

    @staticmethod
    def _sd_message(r: requests.Response) -> str:
        try:
            j = r.json()
            return str(j.get("message") or r.text)[:500]
        except Exception:  # noqa: BLE001
            return (r.text or "")[:500]

    @staticmethod
    def _sd_guard(codes: List[str], allowed_codes: List[str] | None) -> None:
        allowed = [str(a).strip() for a in (allowed_codes or []) if str(a).strip()]
        if not allowed:
            return
        bad = [c for c in codes if str(c) not in allowed]
        if bad or allowed == ["0"]:
            raise RuntimeError(f"documento de stock: escrita recusada (artigos fora da guarda: {', '.join(bad) or 'todos'}).")

    def _sd_new(self, dc: str) -> str:
        r = self._sd(dc, "NEW")
        if r.status_code not in (200, 206):
            raise RuntimeError(f"{dc} NEW falhou (HTTP {r.status_code}) — {self._sd_message(r)}")
        oid = r.headers.get("ObjectID") or r.headers.get("Objectid")
        if not oid:
            raise RuntimeError(f"{dc}: header 'ObjectID' ausente no NEW.")
        return oid

    def _sd_discard(self, dc: str, oid: str, saved: bool) -> None:
        try:
            self._sd(dc, "CLOSE" if saved else "CANCEL", oid)
        except Exception as exc:  # noqa: BLE001
            log.warning("%s %s falhou (ignorado): %s", dc, "CLOSE" if saved else "CANCEL", type(exc).__name__)

    # ── leituras ────────────────────────────────────────────────────────────────
    def fetch_countable_products(self, search: str = "") -> List[Dict[str, Any]]:
        """SÓ LEITURA: os artigos que a Contagem aceita (NEW 1106 → GET,INFO → productlist → CANCEL)."""
        oid = self._sd_new(self.COUNT_DOCCONFIG)
        try:
            g = self._sd(f"1106/{self._COUNT_GET}", "GET,INFO", oid)
            if g.status_code not in (200, 206):
                raise RuntimeError(f"1106 GET,INFO falhou (HTTP {g.status_code}).")
            params = {"SEARCHKEY": search} if search else {}
            rows = self._sd_rows(self._sd("1106/productlist", "GET,INFO", oid, {"params": params}), "1106", "productlist", required=False)
        finally:
            self._sd_discard(self.COUNT_DOCCONFIG, oid, saved=False)
        return [{"id": str(r.get("id") or ""), "code": str(r.get("code") or ""), "description": r.get("description"),
                 "family": r.get("family_description")} for r in rows if r.get("id")]

    def list_stock_documents(self, start: str, end: str, which: str = "count", doctype_like: str = "") -> List[Dict[str, Any]]:
        """SÓ LEITURA: documentos de stock entre duas datas (AAAA-MM-DD). which="count" → lista das
        contagens e inventários; "docs" → lista geral (o desperdício), com filtro pelo tipo."""
        params = {"DOCTYPE": "", "DOCCONFIG_ID": "", "STORE_ID": "", "DOCSTATUS_ID": "", "DOCNUMBER": "", "REFDOC_NUMBER": "",
                  "ATM_REFERENCE": "", "SEARCHTEXT": "", "HIDE_CONV_DOCS": "0", "DOCUMENT_ID": "",
                  "START_DATE": start.replace("-", "") + "T00:00:00", "END_DATE": end.replace("-", "") + "T00:00:00",
                  "FILTER_DATE_BY": "0", "TAX_NUMBER": ""}
        if which == "count":
            params["ENTITY_ID"] = ""
            return self.fetch_browserdataset(self.COUNT_BROWSER, {"params": params})
        body: Dict[str, Any] = {"params": params}
        if doctype_like:
            body["filter"] = {"doctype": {"op": "like", "value": f"%{doctype_like}%"}}
        return self.fetch_browserdataset(self.DOCS_BROWSER, body)

    def fetch_movements(self, start: str, end: str, code_from: str, code_to: str) -> List[Dict[str, Any]]:
        """SÓ LEITURA: o relatório Movimentos de um intervalo de códigos (uma chamada)."""
        row = self.run_report(self.MOVEMENTS_REPORT, {
            "START_DATE": start.replace("-", "") + "T00:00:00", "END_DATE": end.replace("-", "") + "T00:00:00",
            "Family_level": "-1", "ProductGroups": "", "Documents": self.MOVEMENT_DOCCONFIGS, "CostCalculate": 0, "ShowRelation": 0,
            "BaseUnit": 0, "StockUnit": 0, "ProductIniNr": str(code_from), "ProductEndNr": str(code_to),
        }, "Movimentos")
        payload = json.loads(base64.b64decode(row["reportdata"]).decode("utf-8"), strict=False)  # tem \r: parse tolerante
        rows = ((payload.get("dados_grid") or payload.get("data") or {}).get("data")) or []
        return [self.parse_movement(r) for r in rows if isinstance(r, dict)]

    @staticmethod
    def parse_movement(r: Dict[str, Any]) -> Dict[str, Any]:
        keep = ("store", "doctype", "docconfig_id", "docheader_id", "document", "doc_date", "entity_name", "product_code", "isbom",
                "bom_code", "bom_description", "unit_description", "qnt", "docstatus", "stk_signal", "warehouse_id", "warehouse")
        out = {k: r.get(k) for k in keep}
        out["qnt"] = _num(out["qnt"])
        try:
            out["stk_signal"] = int(_num(out["stk_signal"]))
        except (TypeError, ValueError):
            out["stk_signal"] = 0
        d = str(out.get("doc_date") or "")
        out["doc_date"] = f"{d[0:4]}-{d[4:6]}-{d[6:8]}" if len(d) >= 8 and d[:8].isdigit() else (d[:10] or None)
        for k in ("docconfig_id", "docheader_id", "warehouse_id", "product_code"):
            out[k] = str(out[k]) if out[k] not in (None, "") else None
        return out

    # ── Contagem (1106) ─────────────────────────────────────────────────────────
    def write_count(self, store_id: str, warehouse_id: str, inventory_date: str, docstatus: str,
                    lines: List[Dict[str, Any]], allowed_codes: List[str] | None = None) -> Dict[str, Any]:
        """⚠️ ESCRITA: UMA Contagem. lines = [{product_id, code, description, physical_qnt, unit_id?}] (physical_qnt
        na unidade da linha do PingWin; unit_id = a unidade em que a XPLENDOR tem o total: se o PingWin
        devolver outra, pára ANTES de gravar). inventory_date AAAA-MM-DD. docstatus 8001 ou 8002."""
        if str(docstatus) not in ("8001", "8002"):
            return {"ok": False, "saved": False, "error": "Estado não permitido (só 8001 ou 8002)."}
        if not lines:
            return {"ok": False, "saved": False, "error": "Sem linhas."}
        self._sd_guard([str(l.get("code") or "") for l in lines], allowed_codes)
        dc = self.COUNT_DOCCONFIG
        steps: List[str] = []
        oid = self._sd_new(dc)
        saved = False
        try:
            g = self._sd(f"1106/{self._COUNT_GET}", "GET,INFO", oid)
            if g.status_code not in (200, 206):
                raise RuntimeError(f"1106 GET,INFO falhou (HTTP {g.status_code}).")
            w = self._sd("1106/header/warehouse,docserie/", "EDIT,GET", oid, [{"key": "00000000", "store_id": str(store_id)}])
            if w.status_code not in (200, 206):
                raise RuntimeError(f"1106 loja: HTTP {w.status_code} — {self._sd_message(w)}")
            wb = w.json().get(dc, {})
            visible = [str(x.get("id")) for x in wb.get("warehouse") or []]
            if str(warehouse_id) not in visible:
                return {"ok": False, "saved": False, "error": f"O armazém {warehouse_id} não aparece na Contagem para esta loja.", "warehouses": visible}
            self._sd_rows(self._sd("1106/header", "EDIT", oid, [{"key": "00000000", "warehouse_id": str(warehouse_id)}]), dc, "header")
            date = inventory_date.replace("-", "") + "T00:00:00"
            header = self._sd_rows(self._sd("1106/header", "EDIT", oid, [{"key": "00000000", "inventory_date": date, "docstatus_id": str(docstatus)}]), dc, "header")[0]
            if str(header.get("inventory_date")) != date or str(header.get("docstatus_id")) != str(docstatus) or str(header.get("warehouse_id")) != str(warehouse_id):
                raise RuntimeError(f"1106: o cabeçalho não aceitou a data, o estado ou o armazém (veio {header.get('inventory_date')}, {header.get('docstatus_id')}, {header.get('warehouse_id')}).")
            steps.append("cabecalho")

            # Linhas: todas num só NEW; se vierem menos, uma a uma.
            first = [{"product_id": str(l["product_id"]), "code": str(l.get("code") or ""), "description": str(l.get("description") or "")} for l in lines]
            r = self._sd("1106/details", "NEW", oid, first)
            created = self._sd_rows(r, dc, "details", required=False)
            got = {str(x.get("product_id")) for x in created}
            steps.append(f"novas_linhas_num_pedido={len(created)}")
            for l in first:
                if l["product_id"] not in got:
                    one = self._sd_rows(self._sd("1106/details", "NEW", oid, [l]), dc, "details")
                    got |= {str(x.get("product_id")) for x in one}
            details = self._sd_rows(self._sd("1106/details", "GET,INFO", oid), dc, "details")
            by_pid = {str(d.get("product_id")): d for d in details}
            missing = [l["product_id"] for l in lines if str(l["product_id"]) not in by_pid]
            if missing:
                raise RuntimeError(f"1106: o PingWin não criou as linhas de {', '.join(missing)}.")
            for l in lines:
                d = by_pid[str(l["product_id"])]
                if l.get("unit_id") and str(d.get("unit_id")) != str(l["unit_id"]):
                    return {"ok": False, "saved": False, "unit_mismatch": True, "product_id": str(l["product_id"]), "code": l.get("code"),
                            "error": f"O PingWin conta {l.get('code')} em «{d.get('unit_description')}» ({d.get('unit_id')}) e a XPLENDOR tem o total noutra unidade ({l['unit_id']}). Nada foi gravado."}
                e = self._sd_rows(self._sd("1106/details", "EDIT", oid, [{"key": d["key"], "physical_qnt": _qty_text(l["physical_qnt"])}]), dc, "details")[0]
                by_pid[str(l["product_id"])] = e
            steps.append("quantidades")
            details = self._sd_rows(self._sd("1106/details", "GET,INFO", oid), dc, "details")
            m = self._sd("1106/header,details", "MERGE", oid, {"header": [header], "details": details})
            if m.status_code not in (200, 206):
                return {"ok": False, "saved": False, "error": f"MERGE falhou: {self._sd_message(m)}", "steps": steps}
            s = self._sd(dc, "SAVE", oid)
            body = {}
            try:
                body = s.json()
            except Exception:  # noqa: BLE001
                pass
            if s.status_code not in (200, 206) or (isinstance(body, dict) and body.get("code") not in (None, 0, "0")):
                return {"ok": False, "saved": False, "pingwin_message": self._sd_message(s), "steps": steps,
                        "error": f"{self._sd_message(s)} O documento não foi gravado no PingWin."}
            saved = True
        finally:
            self._sd_discard(dc, oid, saved)
        return {"ok": True, "saved": True, "steps": steps, "doc_id": str(header.get("id")),
                "doc_number": f"{header.get('doc_prefix')}/{header.get('doc_number')}", "header": header,
                "lines": [{"product_id": str(d.get("product_id")), "code": d.get("product_code"), "unit_id": str(d.get("unit_id") or ""),
                           "unit_description": d.get("unit_description"), "theoretical_qnt": _num(d.get("theoretical_qnt")),
                           "physical_qnt": _num(d.get("physical_qnt")), "unit_cost": _num(d.get("unit_cost")),
                           "qnt_difference": _num(d.get("qnt_difference")), "price_difference": _num(d.get("price_difference"))} for d in details]}

    # ── Desperdício (1100) ──────────────────────────────────────────────────────
    def write_waste(self, warehouse_id: str, lines: List[Dict[str, Any]], header_obs: str = "", docstatus: str = "8002",
                    allowed_codes: List[str] | None = None) -> Dict[str, Any]:
        """⚠️ ESCRITA: UM documento de Desperdício num armazém. lines = [{product_id, code, qnt, obs}]
        (qnt na unidade de stock). O armazém escolhe-se pela entidade do cabeçalho (o armazém é a
        entidade do documento); confirma-se em cada linha (warehouse_id) antes de gravar."""
        if str(docstatus) not in ("8001", "8002"):
            return {"ok": False, "saved": False, "error": "Estado não permitido (só 8001 ou 8002)."}
        if not lines:
            return {"ok": False, "saved": False, "error": "Sem linhas."}
        self._sd_guard([str(l.get("code") or "") for l in lines], allowed_codes)
        dc = self.WASTE_DOCCONFIG
        steps: List[str] = []
        oid = self._sd_new(dc)
        saved = False
        header: Dict[str, Any] = {}
        try:
            g = self._sd(f"1100/{self._WASTE_GET}", "GET,INFO", oid)
            if g.status_code not in (200, 206):
                raise RuntimeError(f"1100 GET,INFO falhou (HTTP {g.status_code}).")
            gb = g.json().get(dc, {})
            header = (gb.get("header") or [{}])[0]
            hkey = header.get("key")
            entities = [str(e.get("id")) for e in gb.get("entity") or []]
            if str(warehouse_id) not in entities:
                return {"ok": False, "saved": False, "error": f"O armazém {warehouse_id} não aparece como entidade do Desperdício.", "entities": entities}
            if str(header.get("entity_id")) != str(warehouse_id):
                header = self._sd_rows(self._sd("1100/header", "EDIT", oid, [{"key": hkey, "entity_id": str(warehouse_id)}]), dc, "header")[0]
                steps.append("entidade")
            if str(header.get("entity_id")) != str(warehouse_id):
                raise RuntimeError(f"1100: o cabeçalho não aceitou o armazém {warehouse_id} (ficou {header.get('entity_id')}).")
            self._sd("address/*/maindataset", "OPEN,GET,CLOSE", None, {"params": {"querystring": f"entity_id = {warehouse_id} AND deleted = 0"}})

            for l in lines:
                d = self._sd_rows(self._sd("1100/details", "NEW", oid, [{"product_id": str(l["product_id"])}]), dc, "details")[-1]
                if str(d.get("warehouse_id")) != str(warehouse_id):
                    d = self._sd_rows(self._sd("1100/details", "EDIT", oid, [{"key": d["key"], "warehouse_id": str(warehouse_id)}]), dc, "details")[-1]
                    steps.append("armazem_na_linha")
                if str(d.get("warehouse_id")) != str(warehouse_id):
                    raise RuntimeError(f"1100: a linha de {l.get('code')} ficou no armazém {d.get('warehouse_id')}, não em {warehouse_id}.")
                if l.get("unit_id") and str(d.get("unit_id")) != str(l["unit_id"]):
                    return {"ok": False, "saved": False, "unit_mismatch": True, "product_id": str(l["product_id"]), "code": l.get("code"),
                            "error": f"O PingWin regista {l.get('code')} em «{d.get('unit_desc')}» ({d.get('unit_id')}) e a XPLENDOR tem a quantidade noutra unidade ({l['unit_id']}). Nada foi gravado."}
                edit = {"key": d["key"], "qnt": _qty_text(l["qnt"])}
                if l.get("obs"):
                    edit["obs"] = str(l["obs"])[:200]
                self._sd_rows(self._sd("1100/details", "EDIT", oid, [edit]), dc, "details")
            steps.append("linhas")
            dh = self._sd("1100/details,header", "GET,INFO", oid)
            details = self._sd_rows(dh, dc, "details")
            header = self._sd_rows(dh, dc, "header")[0]
            wrong = [str(d.get("product_code")) for d in details if str(d.get("warehouse_id")) != str(warehouse_id)]
            if wrong:
                raise RuntimeError(f"1100: linhas noutro armazém: {', '.join(wrong)}.")
            self._sd("1100/fiscalrules.verifydoc", "GET,INFO", oid, {"params": {}})
            self._sd("1100/validation", "GET,INFO", oid, {"params": {}})
            addr = self._sd(f"1100/{self._WASTE_ADDR}", "GET,INFO", oid)
            ab = addr.json().get(dc, {}) if addr.status_code in (200, 206) else {}
            echo = {k: ab.get(k) or [] for k in ("entity_address.address", "expedition_address.address", "delivery_address.address")}
            if any(echo.values()):
                self._sd(f"1100/{self._WASTE_ADDR}", "MERGE", oid, echo)
            h = dict(header)
            for k in ("total_paid", "total", "paid", "entity_address_id", "expedition_address_id", "delivery_address_id"):
                h.pop(k, None)  # o ecrã do PingWin não os reenvia no EDIT do cabeçalho (HAR 15/10)
            h["obs"] = str(header_obs or "")[:250]
            h["docstatus_id"] = str(docstatus)
            header = self._sd_rows(self._sd("1100/header", "EDIT", oid, [h]), dc, "header")[0]
            v = self._sd("1100/fiscalrules.verifydoc,fiscalrules.alternativedocs", "GET,INFO", oid, {"params": {}})
            ver = (self._sd_rows(v, dc, "fiscalrules.verifydoc", required=False) or [{}])[0]
            if int(_num(ver.get("code"))) != 0:
                return {"ok": False, "saved": False, "error": f"O PingWin recusou o documento: {ver.get('msg')}", "steps": steps}
            s = self._sd(dc, "SAVE", oid)
            body = {}
            try:
                body = s.json()
            except Exception:  # noqa: BLE001
                pass
            if s.status_code not in (200, 206) or (isinstance(body, dict) and body.get("code") not in (None, 0, "0")):
                return {"ok": False, "saved": False, "pingwin_message": self._sd_message(s), "steps": steps,
                        "error": f"{self._sd_message(s)} O documento não foi gravado no PingWin."}
            saved = True
        finally:
            self._sd_discard(dc, oid, saved)
        return {"ok": True, "saved": True, "steps": steps, "doc_id": str(header.get("id")),
                "doc_number": f"{header.get('doc_prefix')}/{header.get('doc_number')}", "total": _num(header.get("total")),
                "lines": [{"product_id": str(d.get("product_id")), "code": d.get("product_code"), "qnt": _num(d.get("qnt")),
                           "warehouse_id": str(d.get("warehouse_id")), "unit_id": str(d.get("unit_id") or ""),
                           "price": _num(d.get("price")), "obs": d.get("obs")} for d in details]}

    # ── Transferências e encomendas (Fatia 7c): P1 a P5 ─────────────────────────
    @staticmethod
    def _addr_fill(row: Dict[str, Any], a: Dict[str, Any] | None) -> Dict[str, Any]:
        out = dict(row)
        if a:
            for k in ("address", "postalcode", "postalcode_description", "city"):
                if k in a and a[k] is not None:
                    out[k] = str(a[k])[:120]
        return out

    def write_doc(self, dc: str, entity_id: str, lines: List[Dict[str, Any]], source_id: str | None = None,
                  store_id: str | None = None, entity_search: str | None = None, expected_warehouse: str | None = None,
                  addresses: Dict[str, Dict[str, Any]] | None = None, header_obs: str = "", docstatus: str = "8002",
                  allowed_codes: List[str] | None = None, unit_change: bool = True) -> Dict[str, Any]:
        """⚠️ ESCRITA: UM documento da família das transferências (Encomenda, Guia 1102/1007, Entrada 1104).
        source_id = conversão (NEW /service/<dc>/<id> com keepprices/keepref); senão documento novo.
        lines = [{product_id, code, unit_id?, qnt, price?}] (qnt e price na unidade da linha). Na conversão,
        as linhas casam pelo artigo. O armazém é a entidade do cabeçalho; expected_warehouse confirma-o em
        cada linha antes de gravar. addresses = {"expedition": {...}, "delivery": {...}}: só estas moradas se
        preenchem; a entity_address vai em eco. Devolve {ok, saved, doc_id, doc_number, lines, trace}."""
        if str(dc) not in self.DOC_FAMILY:
            return {"ok": False, "saved": False, "error": f"Documento {dc} não permitido."}
        if str(docstatus) not in ("8001", "8002"):
            return {"ok": False, "saved": False, "error": "Estado não permitido (só 8001 ou 8002)."}
        if not lines:
            return {"ok": False, "saved": False, "error": "Sem linhas."}
        self._sd_guard([str(l.get("code") or "") for l in lines], allowed_codes)
        trace: Dict[str, Any] = {"steps": []}
        if source_id:
            r = self._sd(f"{dc}/{source_id}", "NEW", None, {"params": {"keepprices": True, "keepref": True}})
        else:
            r = self._sd(dc, "NEW")
        if r.status_code not in (200, 206):
            return {"ok": False, "saved": False, "error": f"{self._sd_message(r)}", "trace": trace, "new_failed": True}
        oid = r.headers.get("ObjectID") or r.headers.get("Objectid")
        if not oid:
            return {"ok": False, "saved": False, "error": f"{dc}: o PingWin não abriu o documento. {self._sd_message(r)}", "trace": trace, "new_failed": True}
        saved = False
        header: Dict[str, Any] = {}
        try:
            g = self._sd(f"{dc}/{self._WASTE_GET}", "GET,INFO", oid)
            if g.status_code not in (200, 206):
                raise RuntimeError(f"{dc} GET,INFO falhou: {self._sd_message(g)}")
            gb = g.json().get(dc, {})
            if isinstance(gb, dict) is False or not gb.get("header"):
                raise RuntimeError(f"{dc} GET,INFO sem cabeçalho: {self._sd_message(g)}")
            header = gb["header"][0]
            trace["opened"] = {k: header.get(k) for k in ("store_id", "entity_id", "doc_prefix", "docstatus_id")}
            if store_id and str(header.get("store_id")) != str(store_id):
                header = self._sd_rows(self._sd(f"{dc}/header", "EDIT", oid, [{"key": header["key"], "store_id": str(store_id)}]), dc, "header")[0]
                g = self._sd(f"{dc}/{self._WASTE_GET}", "GET,INFO", oid)
                gb = g.json().get(dc, {}) if g.status_code in (200, 206) else gb
                header = (gb.get("header") or [header])[0]
                trace["steps"].append("loja")
            entities = [str(e.get("id")) for e in gb.get("entity") or []]
            if str(entity_id) not in entities and entity_search:
                es = self._sd(f"{dc}/entitysearch", "GET,INFO", oid, {"params": {"DESCRIPTION": entity_search}})
                trace["entitysearch"] = es.status_code
            if str(header.get("entity_id")) != str(entity_id):
                header = self._sd_rows(self._sd(f"{dc}/header", "EDIT", oid, [{"key": header["key"], "entity_id": str(entity_id)}]), dc, "header")[0]
                trace["steps"].append("entidade")
            if str(header.get("entity_id")) != str(entity_id):
                raise RuntimeError(f"{dc}: o cabeçalho não aceitou a entidade {entity_id} (ficou {header.get('entity_id')}).")
            if expected_warehouse:
                self._sd("address/*/maindataset", "OPEN,GET,CLOSE", None, {"params": {"querystring": f"entity_id = {expected_warehouse} AND deleted = 0"}})

            dh = self._sd(f"{dc}/details,header", "GET,INFO", oid)
            existing = self._sd_rows(dh, dc, "details", required=False)
            trace["lines_after_entity"] = [{"product_id": str(d.get("product_id")), "warehouse_id": str(d.get("warehouse_id")), "unit_id": str(d.get("unit_id")),
                                            "qnt": _num(d.get("qnt")), "price": _num(d.get("price"))} for d in existing]
            by_pid = {str(d.get("product_id")): d for d in existing}
            for l in lines:
                pid = str(l["product_id"])
                d = by_pid.get(pid)
                if d is None:
                    if source_id:
                        raise RuntimeError(f"{dc}: a linha de {l.get('code')} não veio na conversão.")
                    body = {"product_id": pid}
                    if l.get("unit_id"):
                        body["unit_id"] = str(l["unit_id"])
                    d = self._sd_rows(self._sd(f"{dc}/details", "NEW", oid, [body]), dc, "details")[-1]
                use = l
                if l.get("unit_id") and str(d.get("unit_id")) != str(l["unit_id"]):
                    refused = None
                    if unit_change:
                        try:
                            d = self._sd_rows(self._sd(f"{dc}/details", "EDIT", oid, [{"key": d["key"], "unit_id": str(l["unit_id"])}]), dc, "details")[-1]
                        except RuntimeError as exc:
                            refused = str(exc)[:200]
                    if str(d.get("unit_id")) != str(l["unit_id"]):
                        trace.setdefault("unit_refused", []).append({"code": l.get("code"), "kept": str(d.get("unit_id")), "error": refused})
                        fb = l.get("fallback") or {}
                        if str(fb.get("unit_id") or "") != str(d.get("unit_id")):
                            return {"ok": False, "saved": False, "unit_mismatch": True, "code": l.get("code"), "trace": trace,
                                    "error": f"O PingWin manteve {l.get('code')} em «{d.get('unit_desc')}» e a XPLENDOR tem a quantidade noutra unidade. Nada foi gravado."}
                        use = {**l, **fb}
                edit = {"key": d["key"], "qnt": _qty_text(use["qnt"])}
                if use.get("price") is not None:
                    edit["price"] = f"{float(use['price']):.6f}"
                d = self._sd_rows(self._sd(f"{dc}/details", "EDIT", oid, [edit]), dc, "details")[-1]
                by_pid[pid] = d
            # As linhas da conversão que não se enviam ficam a 0.
            if source_id:
                wanted = {str(l["product_id"]) for l in lines}
                for pid, d in list(by_pid.items()):
                    if pid not in wanted and _num(d.get("qnt")) != 0:
                        by_pid[pid] = self._sd_rows(self._sd(f"{dc}/details", "EDIT", oid, [{"key": d["key"], "qnt": "0.000"}]), dc, "details")[-1]
            trace["steps"].append("linhas")
            dh = self._sd(f"{dc}/details,header", "GET,INFO", oid)
            details = self._sd_rows(dh, dc, "details")
            header = self._sd_rows(dh, dc, "header")[0]
            if expected_warehouse:
                wrong = [str(d.get("product_code")) for d in details if _num(d.get("qnt")) != 0 and str(d.get("warehouse_id")) != str(expected_warehouse)]
                if wrong:
                    return {"ok": False, "saved": False, "error": f"As linhas de {', '.join(wrong)} não ficaram no armazém {expected_warehouse}. Nada foi gravado.",
                            "trace": trace, "lines_wrong_warehouse": True}
            self._sd(f"{dc}/fiscalrules.verifydoc", "GET,INFO", oid, {"params": {}})
            self._sd(f"{dc}/validation", "GET,INFO", oid, {"params": {}})
            addr = self._sd(f"{dc}/{self._WASTE_ADDR}", "GET,INFO", oid)
            ab = addr.json().get(dc, {}) if addr.status_code in (200, 206) else {}
            echo = {k: [dict(x) for x in ab.get(k) or []] for k in ("entity_address.address", "expedition_address.address", "delivery_address.address")}
            trace["addresses_before"] = {k: [{f: x.get(f) for f in ("id", "entity_id", "address", "postalcode", "city")} for x in v] for k, v in echo.items()}
            if addresses:
                if addresses.get("expedition") and echo["expedition_address.address"]:
                    echo["expedition_address.address"][0] = self._addr_fill(echo["expedition_address.address"][0], addresses["expedition"])
                if addresses.get("delivery") and echo["delivery_address.address"]:
                    echo["delivery_address.address"][0] = self._addr_fill(echo["delivery_address.address"][0], addresses["delivery"])
            if any(echo.values()):
                self._sd(f"{dc}/{self._WASTE_ADDR}", "MERGE", oid, echo)
            h = dict(header)
            for k in ("total_paid", "total", "paid", "entity_address_id", "expedition_address_id", "delivery_address_id"):
                h.pop(k, None)
            if header_obs:
                h["obs"] = str(header_obs)[:250]
            h["docstatus_id"] = str(docstatus)
            header = self._sd_rows(self._sd(f"{dc}/header", "EDIT", oid, [h]), dc, "header")[0]
            v = self._sd(f"{dc}/fiscalrules.verifydoc,fiscalrules.alternativedocs", "GET,INFO", oid, {"params": {}})
            ver = (self._sd_rows(v, dc, "fiscalrules.verifydoc", required=False) or [{}])[0]
            trace["verifydoc"] = {"code": ver.get("code"), "msg": ver.get("msg")}
            if int(_num(ver.get("code"))) != 0:
                return {"ok": False, "saved": False, "error": f"O PingWin recusou o documento: {ver.get('msg')}", "trace": trace, "verify_code": ver.get("code")}
            sresp = self._sd(dc, "SAVE", oid)
            body = {}
            try:
                body = sresp.json()
            except Exception:  # noqa: BLE001
                pass
            if sresp.status_code not in (200, 206) or (isinstance(body, dict) and body.get("code") not in (None, 0, "0")):
                return {"ok": False, "saved": False, "pingwin_message": self._sd_message(sresp), "trace": trace,
                        "error": f"{self._sd_message(sresp)} O documento não foi gravado no PingWin."}
            saved = True
        finally:
            self._sd_discard(dc, oid, saved)
        return {"ok": True, "saved": True, "doc_id": str(header.get("id")), "doc_number": f"{header.get('doc_prefix')}/{header.get('doc_number')}",
                "store_id": str(header.get("store_id")), "entity_id": str(header.get("entity_id")), "total": _num(header.get("total")), "trace": trace,
                "lines": [{"product_id": str(d.get("product_id")), "code": d.get("product_code"), "qnt": _num(d.get("qnt")), "qnt_base": _num(d.get("qnt_base")),
                           "unit_id": str(d.get("unit_id") or ""), "unit_value": _num(d.get("unit_value")), "unit_desc": d.get("unit_desc"),
                           "price": _num(d.get("price")), "warehouse_id": str(d.get("warehouse_id")), "src_docmovdetail_id": d.get("src_docmovdetail_id"),
                           "qnt_delivered": _num(d.get("qnt_delivered"))} for d in details]}

    def list_documents_of(self, start: str, end: str, docconfig_id: str, hide_converted: bool = False) -> List[Dict[str, Any]]:
        """SÓ LEITURA (P7): os documentos de um tipo (DOCCONFIG_ID) entre duas datas (AAAA-MM-DD)."""
        params = {"DOCTYPE": "", "DOCCONFIG_ID": str(docconfig_id), "STORE_ID": "", "DOCSTATUS_ID": "", "DOCNUMBER": "", "REFDOC_NUMBER": "",
                  "ATM_REFERENCE": "", "SEARCHTEXT": "", "HIDE_CONV_DOCS": "1" if hide_converted else "0", "DOCUMENT_ID": "",
                  "START_DATE": start.replace("-", "") + "T00:00:00", "END_DATE": end.replace("-", "") + "T00:00:00",
                  "FILTER_DATE_BY": "0", "TAX_NUMBER": ""}
        return self.fetch_browserdataset(self.DOCS_BROWSER, {"params": params})

    def read_document(self, docconfig_id: str, doc_id: str) -> Dict[str, Any]:
        """SÓ LEITURA: cabeçalho e linhas de um documento (o leitor da F4: OPEN → GET → CLOSE)."""
        return self._doc_read_once(str(docconfig_id), str(doc_id))

    # ── Estado (fechar ou anular) ───────────────────────────────────────────────
    def change_stock_document_status(self, doc_id: str, status: str) -> Dict[str, Any]:
        """⚠️ ESCRITA: /service/document/<id>/header OPEN,EDIT,SAVE,CLOSE com o estado (8002 ou 8003)."""
        res = self.set_document_status(str(doc_id), str(status))
        if res.get("ok") and str(res.get("docstatus_id")) != str(status):
            return {"ok": False, "error": f"O PingWin não mudou o estado (ficou {res.get('docstatus_id')}).", **{k: v for k, v in res.items() if k != "ok"}}
        return res
