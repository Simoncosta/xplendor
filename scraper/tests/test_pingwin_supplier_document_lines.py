"""
XPLENDOR — Linhas dos documentos de fornecedor (F4, SÓ LEITURA). Prova, sem rede:
  · OPEN /service/{dc}/{dh} (sem body) → GET /service/{dc}/header,details (Action GET,
    com o ObjectID da resposta do OPEN) → CLOSE /service/{dc} (com o ObjectID);
  · o CLOSE acontece SEMPRE — também quando o GET falha;
  · um CLOSE que falha não perde a leitura (closed=False);
  · parse do header e das linhas (campos úteis + raw), guarda do id do documento;
  · 5xx repete; 4xx não repete; uma falha não aborta o lote;
  · modo supplier_document_lines do run.py.
"""
import os
import sys

import pytest
import requests

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

import sources.pingwin.run as runner  # noqa: E402
from sources.pingwin.mycloudpie import MyCloudPieClient, parse_document_lines  # noqa: E402

BASE = "https://srv-soa.example:8136"

# Resposta real da VFT BOVFT/1020 (Passo 0), reduzida aos campos que interessam.
HEADER_1020 = {
    "id": "584955579139786478", "docconfig_id": "1209", "store_id": "1099511639284", "doc_prefix": "VFT BOVFT",
    "doc_number": 1020, "entity_id": "584955579139647570", "entity_name": "MARIA HELENA FERRADOR PIMENTA",
    "entity_taxnum": "143209884", "entity_code": "56", "doc_date": "20261005T00:00:00",
    "due_date": "20261104T00:00:00", "paycond_id": "584955579139752253", "docreference_id": "1649601156562",
    "docreference_number": "27731", "docreference_date": "20261005T00:00:00", "currency_id": "5001",
    "discount1": 0.0, "discount2_add": 0.0, "discount2_mul": 0.0, "shipping_value": 0.0, "adjustment": 0.0,
    "withholding": 0.0, "total_products": 951.3, "total_tax": 218.8, "total": 1170.1, "docstatus_id": "8002",
    "taxscenario_id": "3101", "tax_round_mode": 1, "taxincluded": 0, "paid": 0, "total_paid": 0.0,
    "employee_id": "584955579139627876", "hash": "xyz", "atcud": "0",
}
DETAIL_1020 = {
    "id": "584955579139786487", "line_number": 1, "product_id": "584955579139647596", "product_code": "100660",
    "entity_product_id": "", "description": "ALHEIRA", "qnt": 90.0, "unit_id": "11002", "unit_code": "KG",
    "unit_desc": "Quilograma", "price": 10.57, "discount1": 0.0, "discount_value": 0.0, "total": 951.3,
    "taxgroup_id": "1003001", "tax_description": "Normal", "tax_value": 218.8, "price_w_tax": 13.001111,
    "total_w_tax": 1170.1, "warehouse_id": "584955579139620864", "linetype": 1, "barcode": "",
}


class FakeResp:
    def __init__(self, status=200, payload=None, headers=None, text=""):
        self.status_code = status
        self._payload = payload
        self.headers = headers or {}
        self.text = text or str(payload)

    def json(self):
        return self._payload

    def raise_for_status(self):
        if self.status_code >= 400:
            raise requests.HTTPError(f"HTTP {self.status_code}", response=self)


class FakeSession:
    """Responde por ordem; cada pedido fica registado (url, Action, ObjectID, body)."""

    def __init__(self, responses):
        self.responses = list(responses)
        self.calls = []

    def post(self, url, data=None, json=None, headers=None, **kw):
        self.calls.append({"url": url, "action": (headers or {}).get("Action"),
                           "oid": (headers or {}).get("ObjectID"), "data": data, "json": json})
        r = self.responses.pop(0)
        if isinstance(r, Exception):
            raise r
        return r


def make_client(session):
    c = MyCloudPieClient.__new__(MyCloudPieClient)
    c.api_url = BASE
    c.session = session
    c.database = "db"
    c.app_grupopie = "grp"
    c.DOCLINES_RETRY_DELAY_S = 0
    return c


def opened(oid="OID-1"):
    return FakeResp(200, {"1209": {}}, headers={"ObjectID": oid})


def got(header=HEADER_1020, details=(DETAIL_1020,), dc="1209"):
    return FakeResp(200, {dc: {"header": [header], "details": list(details)}})


def closed():
    return FakeResp(200, {"1209": {}})


DOC = {"docconfig_id": "1209", "docheader_id": "584955579139786478"}


def test_open_get_close_protocol_and_parse():
    s = FakeSession([opened(), got(), closed()])
    out = make_client(s).fetch_supplier_document_lines([DOC])

    assert [c["url"] for c in s.calls] == [
        f"{BASE}/service/1209/584955579139786478",
        f"{BASE}/service/1209/header,details",
        f"{BASE}/service/1209",
    ]
    assert [c["action"] for c in s.calls] == ["OPEN", "GET", "CLOSE"]
    assert [c["oid"] for c in s.calls] == [None, "OID-1", "OID-1"]   # ObjectID do OPEN nos passos 2 e 3
    assert all(c["data"] == b"" and c["json"] is None for c in s.calls)  # sem body

    r = out[0]
    assert r["ok"] is True and r["closed"] is True and r["attempts"] == 1
    h = r["header"]
    assert (h["doc_prefix"], h["doc_number"], h["entity_taxnum"]) == ("VFT BOVFT", 1020, "143209884")
    assert (h["total_products"], h["total_tax"], h["total"]) == (951.3, 218.8, 1170.1)
    assert h["due_date"] == "20261104T00:00:00" and h["docreference_number"] == "27731"
    assert h["paycond_id"] == "584955579139752253"
    assert "hash" not in h  # só os campos úteis
    line = r["details"][0]
    assert (line["product_code"], line["description"], line["qnt"], line["price"]) == ("100660", "ALHEIRA", 90.0, 10.57)
    assert line["price_w_tax"] == 13.001111  # precisão total, sem arredondar
    assert line["raw"]["barcode"] == ""      # raw completo da linha


def test_close_happens_even_when_get_fails():
    s = FakeSession([opened(), FakeResp(400, text="bad"), closed()])
    out = make_client(s).fetch_supplier_document_lines([DOC])

    assert [c["action"] for c in s.calls] == ["OPEN", "GET", "CLOSE"]
    assert s.calls[2]["oid"] == "OID-1"
    assert out[0]["ok"] is False and "400" in out[0]["error"]


def test_close_happens_when_get_raises_network_error_and_retry_closes_again():
    s = FakeSession([opened("A"), requests.ConnectionError("reset"), closed(),
                     opened("B"), got(), closed()])
    out = make_client(s).fetch_supplier_document_lines([DOC])

    assert [(c["action"], c["oid"]) for c in s.calls] == [
        ("OPEN", None), ("GET", "A"), ("CLOSE", "A"), ("OPEN", None), ("GET", "B"), ("CLOSE", "B")]
    assert out[0]["ok"] is True and out[0]["attempts"] == 2


def test_failed_close_keeps_the_read_but_reports_it():
    s = FakeSession([opened(), got(), FakeResp(500, text="x")])
    out = make_client(s).fetch_supplier_document_lines([DOC])
    assert out[0]["ok"] is True and out[0]["closed"] is False


def test_close_exception_is_swallowed():
    s = FakeSession([opened(), got(), requests.ConnectionError("down")])
    out = make_client(s).fetch_supplier_document_lines([DOC])
    assert out[0]["ok"] is True and out[0]["closed"] is False


def test_open_without_objectid_fails_without_get():
    s = FakeSession([FakeResp(200, {"1209": {}}, headers={})])
    out = make_client(s).fetch_supplier_document_lines([DOC])
    assert out[0]["ok"] is False and "ObjectID" in out[0]["error"]
    assert len(s.calls) == 1  # nada aberto → nada a fechar


def test_open_5xx_retries_then_one_failure_does_not_abort_batch():
    other = {"docconfig_id": "1205", "docheader_id": "584955579139789438"}
    nc_header = {**HEADER_1020, "id": "584955579139789438", "docconfig_id": "1205"}
    s = FakeSession([FakeResp(500, text="1411"), FakeResp(500, text="1411"),
                     FakeResp(200, {"1205": {}}, headers={"ObjectID": "N"}), got(nc_header, dc="1205"),
                     FakeResp(200, {"1205": {}})])
    out = make_client(s).fetch_supplier_document_lines([DOC, other])

    assert out[0]["ok"] is False and out[0]["attempts"] == 2
    assert out[1]["ok"] is True and out[1]["docconfig_id"] == "1205"
    assert s.calls[-1]["url"] == f"{BASE}/service/1205" and s.calls[-1]["action"] == "CLOSE"


def test_parse_rejects_header_of_another_document():
    with pytest.raises(RuntimeError, match="não é o documento pedido"):
        parse_document_lines({"1209": {"header": [HEADER_1020], "details": []}}, "1209", "999")


def test_parse_requires_details_block():
    with pytest.raises(RuntimeError, match="details"):
        parse_document_lines({"1209": {"header": [HEADER_1020]}}, "1209", HEADER_1020["id"])


def test_parse_document_without_lines_is_valid():
    res = parse_document_lines({"1209": {"header": [HEADER_1020], "details": []}}, "1209", HEADER_1020["id"])
    assert res["details"] == [] and res["header"]["id"] == HEADER_1020["id"]


def test_run_mode_validates_and_delegates(monkeypatch):
    class FakeClient:
        def __enter__(self):
            return self

        def __exit__(self, *a):
            return False

        def fetch_supplier_document_lines(self, docs):
            return [{"docheader_id": d["docheader_id"], "ok": True} for d in docs]

    monkeypatch.setattr(runner, "build_client", lambda **kw: FakeClient())
    monkeypatch.setattr(runner, "build_kwargs", lambda cfg: {})
    monkeypatch.setattr(runner, "check_hosts_allowed", lambda cfg: None)

    assert runner.run({"mode": "supplier_document_lines", "docs": []})["ok"] is False
    res = runner.run({"mode": "supplier_document_lines", "docs": [DOC]})
    assert res["ok"] is True and res["documents"] == [{"docheader_id": DOC["docheader_id"], "ok": True}]
