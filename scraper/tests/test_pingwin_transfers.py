"""
XPLENDOR — Transferências (Fatia 7c). Prova, sem rede, as sequências provadas no passo 0 (15/10):
  · P1 Encomenda nova: NEW → GET,INFO → loja → entitysearch → entidade → linha (NEW, unidade) → quantidade
    → verifydoc, validation → moradas (eco) → cabeçalho (eco sem os campos que o ecrã não reenvia) →
    verifydoc,alternativedocs → SAVE → CLOSE;
  · P2 Guia pela conversão da encomenda (keepprices/keepref), na loja do Economato, a entidade (o armazém de
    origem) que as linhas seguem, a troca para a unidade de stock e o preço; as moradas: só a de carga e a
    de descarga se preenchem, a do armazém vai em eco;
  · a unidade recusada usa a alternativa (ou pára sem gravar); linhas noutro armazém param antes do SAVE;
    o verifydoc com código ≠ 0 (morada de carga em falta) dá CANCEL;
  · P4 Entrada pela conversão da Guia (linhas que não se enviam ficam a 0); P5 Entrada nova (sementes);
  · a guarda (códigos de artigo) e a família de documentos recusam antes de qualquer pedido.
"""
import copy
import json
import os
import sys

import pytest
import requests

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

import sources.pingwin.run as runner  # noqa: E402
from sources.pingwin.mycloudpie import MyCloudPieClient  # noqa: E402

BASE = "https://srv-soa.example:8136"
BO_STORE, ORIGINAL = "1099511639284", "584955579139649880"
BO_WH, YUKO_WH = "584955579139620864", "584955579139649883"
A = "584955579139803444"
UNIDADE, CAIXA6 = "11001", "584955579139625325"
ADDR_ROW = {"key": "K", "id": "584955579139620865", "entity_id": BO_WH, "address": "", "postalcode": "", "postalcode_description": "", "city": "",
            "country_id": "30000", "country_code": "PT", "state_province": "", "obs": ""}


class FakeResp:
    def __init__(self, status=200, payload=None, headers=None):
        self.status_code = status
        self._payload = payload if payload is not None else {}
        self.headers = headers or {}
        self.text = json.dumps(self._payload)

    def json(self):
        return self._payload

    def raise_for_status(self):
        if self.status_code >= 400:
            raise requests.HTTPError(f"HTTP {self.status_code}", response=self)


class FakeSession:
    def __init__(self, responses):
        self.responses = list(responses)
        self.calls = []

    def post(self, url, data=None, json=None, headers=None, **kw):
        body = json if json is not None else (None if not data else __import__("json").loads(data))
        self.calls.append({"path": url.replace(BASE + "/service/", ""), "action": (headers or {}).get("Action"), "oid": (headers or {}).get("ObjectID"), "body": body})
        r = self.responses.pop(0)
        if isinstance(r, Exception):
            raise r
        return r


def client(session):
    c = MyCloudPieClient.__new__(MyCloudPieClient)
    c.api_url = BASE
    c.session = session
    c.app_grupopie = "grp"
    c.database = "db"
    return c


def ok(payload):
    return FakeResp(200, copy.deepcopy(payload))


def new():
    return FakeResp(200, {}, {"ObjectID": "OID"})


def header(dc, **over):
    h = {"key": "H1", "id": "DOC1", "store_id": BO_STORE, "entity_id": YUKO_WH, "doc_prefix": "GA BOGA", "doc_number": 6, "docstatus_id": "8002",
         "total": 6, "paid": 0, "total_paid": 0, "entity_address_id": "X", "expedition_address_id": "X", "delivery_address_id": "X", "obs": ""}
    h.update(over)
    return {dc: {"header": [h]}}


def get_info(dc, entities=(BO_WH, YUKO_WH), **over):
    body = header(dc, **over)
    body[dc]["entity"] = [{"id": e} for e in entities]
    return ok(body)


def line(dc, **over):
    d = {"key": "D1", "product_id": A, "product_code": "101343", "qnt": 2, "qnt_base": 2, "unit_id": CAIXA6, "unit_value": 1, "unit_desc": "Caixa 6 Unidades",
         "price": 0.5, "warehouse_id": BO_WH, "src_docmovdetail_id": "S1", "qnt_delivered": 0}
    d.update(over)
    return d


def details(dc, *rows, **hdr):
    body = header(dc, **hdr)
    body[dc]["details"] = list(rows)
    return ok(body)


def addresses(dc):
    return ok({dc: {k: [dict(ADDR_ROW, key=f"K{i}")] for i, k in enumerate(("entity_address.address", "expedition_address.address", "delivery_address.address"))}})


def verify(dc, code=0, msg="OK"):
    return ok({dc: {"fiscalrules.verifydoc": [{"code": code, "msg": msg}]}})


def seq(s):
    return [(c["path"].split("/")[0] if c["path"].startswith("address") else c["path"].replace("1102", "{dc}").replace("1104", "{dc}").replace("62590524561076436", "{dc}"), c["action"])
            for c in s.calls]


def tail(dc, final_details):
    """verifydoc, validation, moradas (GET e MERGE), cabeçalho, verifydoc final, SAVE, CLOSE."""
    return [final_details, verify(dc), ok({}), addresses(dc), ok({}), ok(header(dc)), verify(dc), ok({dc: {}}), ok({})]


LOAD = {"address": "Rua de Teste, 1 (carga)", "postalcode": "4000-001", "postalcode_description": "Porto", "city": "Porto"}
UNLOAD = {"address": "Rua de Teste, 2 (descarga)", "postalcode": "4000-002", "postalcode_description": "Porto", "city": "Porto"}


def test_guard_and_family_refuse_before_any_request():
    s = FakeSession([])
    with pytest.raises(RuntimeError, match="escrita recusada"):
        client(s).write_doc("1102", BO_WH, [{"product_id": A, "code": "100415", "qnt": 1}], allowed_codes=["101343", "101344"])
    assert client(s).write_doc("1100", BO_WH, [{"product_id": A, "code": "101343", "qnt": 1}])["error"] == "Documento 1100 não permitido."
    assert client(s).write_doc("1102", BO_WH, [{"product_id": A, "code": "101343", "qnt": 1}], docstatus="8003")["saved"] is False
    assert s.calls == []


def test_p1_order_new_with_store_entity_search_unit_and_quantity():
    dc = "62590524561076436"
    s = FakeSession([
        new(), get_info(dc, entities=(), store_id="1099845342604", entity_id=""), ok(header(dc, store_id=ORIGINAL, entity_id="")), get_info(dc, entities=(), store_id=ORIGINAL, entity_id=""),
        ok({}), ok(header(dc, store_id=ORIGINAL, entity_id="62590524561076057")),
        details(dc), ok({dc: {"details": [line(dc, unit_id=CAIXA6, qnt=1)]}}), ok({dc: {"details": [line(dc, unit_id=CAIXA6, qnt=2)]}}),
        *tail(dc, details(dc, line(dc, warehouse_id=YUKO_WH), store_id=ORIGINAL, entity_id="62590524561076057")),
    ])
    res = client(s).write_doc(dc, "62590524561076057", [{"product_id": A, "code": "101343", "unit_id": CAIXA6, "qnt": 2}], store_id=ORIGINAL,
                              entity_search="Armazem", allowed_codes=["101343"])
    assert res["saved"] is True and res["doc_number"] == "GA BOGA/6"
    assert seq(s) == [
        ("{dc}", "NEW"), ("{dc}/" + MyCloudPieClient._WASTE_GET, "GET,INFO"), ("{dc}/header", "EDIT"), ("{dc}/" + MyCloudPieClient._WASTE_GET, "GET,INFO"),
        ("{dc}/entitysearch", "GET,INFO"), ("{dc}/header", "EDIT"), ("{dc}/details,header", "GET,INFO"), ("{dc}/details", "NEW"), ("{dc}/details", "EDIT"),
        ("{dc}/details,header", "GET,INFO"), ("{dc}/fiscalrules.verifydoc", "GET,INFO"), ("{dc}/validation", "GET,INFO"), ("{dc}/" + MyCloudPieClient._WASTE_ADDR, "GET,INFO"),
        ("{dc}/" + MyCloudPieClient._WASTE_ADDR, "MERGE"), ("{dc}/header", "EDIT"), ("{dc}/fiscalrules.verifydoc,fiscalrules.alternativedocs", "GET,INFO"),
        ("{dc}", "SAVE"), ("{dc}", "CLOSE"),
    ]
    assert s.calls[2]["body"] == [{"key": "H1", "store_id": ORIGINAL}]
    assert s.calls[4]["body"] == {"params": {"DESCRIPTION": "Armazem"}}
    assert s.calls[7]["body"] == [{"product_id": A, "unit_id": CAIXA6}]
    assert s.calls[8]["body"] == [{"key": "D1", "qnt": "2.000"}]
    hdr = s.calls[14]["body"][0]
    assert hdr["docstatus_id"] == "8002" and not {"total", "paid", "total_paid", "entity_address_id", "expedition_address_id", "delivery_address_id"} & set(hdr)
    assert all(c["oid"] == "OID" for c in s.calls[1:])


def test_p2_guide_by_conversion_switches_to_stock_unit_and_fills_only_load_and_unload_addresses():
    dc = "1102"
    s = FakeSession([
        new(), get_info(dc, store_id=ORIGINAL), ok(header(dc)), get_info(dc),
        ok(header(dc, entity_id=BO_WH)),
        ok({}),  # morada do armazém (OPEN,GET,CLOSE)
        details(dc, line(dc)),
        ok({dc: {"details": [line(dc, unit_id=UNIDADE, unit_value=1, unit_desc="Unidade")]}}),
        ok({dc: {"details": [line(dc, unit_id=UNIDADE, qnt=12, qnt_base=12, unit_desc="Unidade")]}}),
        *tail(dc, details(dc, line(dc, unit_id=UNIDADE, qnt=12, qnt_base=12, unit_desc="Unidade"), entity_id=BO_WH)),
    ])
    res = client(s).write_doc(dc, BO_WH, [{"product_id": A, "code": "101343", "unit_id": UNIDADE, "qnt": 12, "price": 0.5}], source_id="584955579139804038", store_id=BO_STORE,
                              expected_warehouse=BO_WH, addresses={"expedition": LOAD, "delivery": UNLOAD}, allowed_codes=["101343"])
    assert res["saved"] is True
    assert (s.calls[0]["path"], s.calls[0]["body"]) == ("1102/584955579139804038", {"params": {"keepprices": True, "keepref": True}})
    assert s.calls[4]["body"] == [{"key": "H1", "entity_id": BO_WH}]
    assert s.calls[5]["body"] == {"params": {"querystring": f"entity_id = {BO_WH} AND deleted = 0"}}
    assert s.calls[7]["body"] == [{"key": "D1", "unit_id": UNIDADE}]
    assert s.calls[8]["body"] == [{"key": "D1", "qnt": "12.000", "price": "0.500000"}]
    merge = next(c for c in s.calls if c["action"] == "MERGE")["body"]
    assert merge["entity_address.address"][0] == dict(ADDR_ROW, key="K0")          # a morada da ficha do armazém vai em eco
    assert merge["expedition_address.address"][0]["address"] == LOAD["address"]
    assert merge["delivery_address.address"][0]["postalcode"] == "4000-002"
    assert res["trace"]["lines_after_entity"][0]["warehouse_id"] == BO_WH
    assert res["lines"][0]["qnt_base"] == 12 and res["lines"][0]["warehouse_id"] == BO_WH


def test_unit_refused_uses_the_fallback_or_stops_without_saving():
    dc = "1102"
    refused = FakeResp(500, {"code": 500, "message": "Unidade não permitida"})
    s = FakeSession([
        new(), get_info(dc, entity_id=BO_WH), ok({}), details(dc, line(dc)), refused,
        ok({dc: {"details": [line(dc, qnt=2, price=3)]}}),
        *tail(dc, details(dc, line(dc, qnt=2, price=3))),
    ])
    res = client(s).write_doc(dc, BO_WH, [{"product_id": A, "code": "101343", "unit_id": UNIDADE, "qnt": 12, "price": 0.5,
                                           "fallback": {"unit_id": CAIXA6, "qnt": 2, "price": 3.0}}], source_id="584955579139804038", expected_warehouse=BO_WH)
    assert res["saved"] is True and res["trace"]["unit_refused"][0]["kept"] == CAIXA6
    assert s.calls[5]["body"] == [{"key": "D1", "qnt": "2.000", "price": "3.000000"}]

    s = FakeSession([new(), get_info(dc, entity_id=BO_WH), ok({}), details(dc, line(dc)), refused, ok({})])
    res = client(s).write_doc(dc, BO_WH, [{"product_id": A, "code": "101343", "unit_id": UNIDADE, "qnt": 12}], source_id="584955579139804038", expected_warehouse=BO_WH)
    assert res["saved"] is False and res["unit_mismatch"] is True and "Nada foi gravado" in res["error"]
    assert s.calls[-1]["action"] == "CANCEL"


def test_lines_in_another_warehouse_or_verify_refusal_cancel_without_saving():
    dc = "1102"
    s = FakeSession([new(), get_info(dc, entity_id=BO_WH), ok({}), details(dc, line(dc, unit_id=UNIDADE)),
                     ok({dc: {"details": [line(dc, unit_id=UNIDADE, qnt=12)]}}), details(dc, line(dc, unit_id=UNIDADE, qnt=12, warehouse_id=YUKO_WH)), ok({})])
    res = client(s).write_doc(dc, BO_WH, [{"product_id": A, "code": "101343", "unit_id": UNIDADE, "qnt": 12}], source_id="584955579139804038", expected_warehouse=BO_WH)
    assert res["saved"] is False and res["lines_wrong_warehouse"] is True
    assert ("1102", "CANCEL") == (s.calls[-1]["path"], s.calls[-1]["action"]) and not any(c["action"] == "SAVE" for c in s.calls)

    s = FakeSession([new(), get_info(dc, entity_id=BO_WH), ok({}), details(dc, line(dc, unit_id=UNIDADE)), ok({dc: {"details": [line(dc, unit_id=UNIDADE, qnt=12)]}}),
                     details(dc, line(dc, unit_id=UNIDADE, qnt=12)), verify(dc), ok({}), addresses(dc), ok({}), ok(header(dc)),
                     verify(dc, 3, "Morada do local de carga em falta"), ok({})])
    res = client(s).write_doc(dc, BO_WH, [{"product_id": A, "code": "101343", "unit_id": UNIDADE, "qnt": 12}], source_id="584955579139804038", expected_warehouse=BO_WH)
    assert res["saved"] is False and res["error"] == "O PingWin recusou o documento: Morada do local de carga em falta"
    assert s.calls[-1]["action"] == "CANCEL"


def test_p4_entry_by_conversion_zeroes_lines_not_sent_and_p5_new_entry():
    dc = "1104"
    other = line(dc, key="D2", product_id="B", product_code="101344", unit_id=UNIDADE, qnt=5)
    s = FakeSession([
        new(), get_info(dc, entity_id=YUKO_WH), ok({}),
        details(dc, line(dc, unit_id=UNIDADE, qnt=12, warehouse_id=YUKO_WH), dict(other, warehouse_id=YUKO_WH)),
        ok({dc: {"details": [line(dc, unit_id=UNIDADE, qnt=12, warehouse_id=YUKO_WH)]}}),
        ok({dc: {"details": [dict(other, qnt=0, warehouse_id=YUKO_WH)]}}),
        *tail(dc, details(dc, line(dc, unit_id=UNIDADE, qnt=12, warehouse_id=YUKO_WH), dict(other, qnt=0, warehouse_id=YUKO_WH))),
    ])
    res = client(s).write_doc(dc, YUKO_WH, [{"product_id": A, "code": "101343", "unit_id": UNIDADE, "qnt": 12, "price": 0.5}], source_id="584955579139804090", expected_warehouse=YUKO_WH)
    assert res["saved"] is True
    assert s.calls[0]["path"] == "1104/584955579139804090"
    assert s.calls[5]["body"] == [{"key": "D2", "qnt": "0.000"}]

    s = FakeSession([
        new(), get_info(dc, entity_id=YUKO_WH), ok(header(dc, entity_id=BO_WH)), ok({}), details(dc),
        ok({dc: {"details": [line(dc, unit_id=UNIDADE, qnt=1)]}}), ok({dc: {"details": [line(dc, unit_id=UNIDADE, qnt=60, price=0.5)]}}),
        *tail(dc, details(dc, line(dc, unit_id=UNIDADE, qnt=60, price=0.5), entity_id=BO_WH)),
    ])
    res = client(s).write_doc(dc, BO_WH, [{"product_id": A, "code": "101343", "unit_id": UNIDADE, "qnt": 60, "price": 0.5}], expected_warehouse=BO_WH)
    assert res["saved"] is True and s.calls[0]["path"] == "1104"
    assert s.calls[5]["body"] == [{"product_id": A, "unit_id": UNIDADE}]
    assert s.calls[6]["body"] == [{"key": "D1", "qnt": "60.000", "price": "0.500000"}]


def test_only_the_protocol_paths_pass():
    c = client(FakeSession([]))
    with pytest.raises(RuntimeError, match="fora do protocolo"):
        c._sd("1102/header", "DELETE")
    with pytest.raises(RuntimeError, match="fora do protocolo"):
        c._sd("1105", "NEW")   # nunca o Inventário


def test_run_modes_route_to_the_client(monkeypatch):
    seen = {}

    class FakeClient:
        def __enter__(self):
            return self

        def __exit__(self, *a):
            return False

        def write_doc(self, dc, entity, lines, **kw):
            seen["write"] = (dc, entity, len(lines), kw)
            return {"ok": True, "saved": True, "doc_id": "D"}

        def list_documents_of(self, start, end, dc, hide):
            seen.setdefault("list", []).append((dc, hide))
            return [{"id": f"X{dc}", "document": "Y"}]

        def read_document(self, dc, did):
            return {"header": {"id": did}, "details": []}

    monkeypatch.setattr(runner, "build_client", lambda **kw: FakeClient())
    out = runner.run({"mode": "doc_write", "docconfig_id": "1102", "entity_id": BO_WH, "lines": [{"product_id": A, "code": "101343", "qnt": 1}],
                      "source_id": "ENC35", "allowed_codes": ["101343"], "addresses": {"expedition": LOAD}})
    assert out["ok"] is True
    dc, entity, n, kw = seen["write"]
    assert (dc, entity, n, kw["source_id"], kw["allowed_codes"], kw["store_id"], kw["docstatus"]) == ("1102", BO_WH, 1, "ENC35", ["101343"], None, "8002")
    out = runner.run({"mode": "doc_list", "start": "2026-08-16", "end": "2026-10-16", "docconfig_ids": ["62590524561076436", "1102"], "with_lines": True})
    assert [d["docconfig_id"] for d in out["documents"]] == ["62590524561076436", "1102"]
    assert out["documents"][0]["read"] == {"header": {"id": "X62590524561076436"}, "details": []}
    assert seen["list"] == [("62590524561076436", False), ("1102", False)]
