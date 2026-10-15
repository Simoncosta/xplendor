"""
XPLENDOR — Fichas técnicas (Fatia 1, SÓ LEITURA). Prova, sem rede, com as respostas do HAR de 15/10
(Coca Cola 0, sem cookies, tokens nem identificadores de sessão):
  · OPEN,GET,INFO pelo id → GET,INFO da ficha → EDIT,GET da versão (só posiciona) → GET,INFO das
    linhas em "" e em cada armazém → CLOSE, sempre no fim (também quando um passo falha);
  · nunca MERGE, SAVE, NEW, DELETE, nem EDIT em productbom.storerelation.storedata;
  · parse do artigo (unidades por função, stock, preços), das versões, das lojas (ativa = deleted 0)
    e das linhas (custo por armazém, isbom, estado);
  · semi-produtos recursivos, sem repetir (ciclo) e até 5 níveis; uma falha não pára as outras;
  · modo boms do run.py.
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
FIX = json.load(open(os.path.join(os.path.dirname(__file__), "fixtures", "pingwin_bom", "coca_cola_0.json")))
COCA = "1649601156985"
WAREHOUSES = ["584955579139649883", "584955579139620864", "1649601156541"]


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
                           "oid": (headers or {}).get("ObjectID"), "data": data})
        r = self.responses.pop(0)
        if isinstance(r, Exception):
            raise r
        return r


def make_client(session):
    c = MyCloudPieClient.__new__(MyCloudPieClient)
    c.api_url = BASE
    c.session = session
    c.app_grupopie = "grp"
    return c


def opened(product=None, oid="OID-1"):
    return FakeResp(200, product or FIX["open"], headers={"ObjectID": oid})


def lines(cost_factor=1.0, payload=None):
    p = copy.deepcopy(payload or FIX["lines_all"])
    for row in p["product"]["productbom.prodbomdetail"]:
        row["unit_cost"] = round(row["unit_cost"] * cost_factor, 6)
        row["cost"] = round(row["cost"] * cost_factor, 6)
    return FakeResp(200, p)


def coca_responses(select=None):
    return [opened(), FakeResp(200, FIX["info"]), FakeResp(200, select or FIX["select_v0"]),
            lines(), lines(24.0), lines(), lines(), FakeResp(200, {})]


def test_protocol_is_read_only_and_closes():
    s = FakeSession(coca_responses())
    bom = make_client(s).read_bom(COCA)

    urls = [c["url"].replace(BASE, "") for c in s.calls]
    assert urls[0] == f"/service/product/{COCA}/maindataset,productunits.baseunit,productunits.lkunitsale," \
                      "productunits.lkunitpurchase,lk_stockconfig,prices,productbom.maindataset"
    assert urls[1] == "/service/product/productbom.lkunits,productbom.lkprodunits,productbom.prodbomdetail," \
                      "productbom.storerelation.storedata,productbom.storerelation.storegroup,productbom.lkwarehouses"
    assert urls[2] == "/service/product/productbom.maindataset/productbom.storerelation.storedata,productbom.storerelation.storegroup"
    assert urls[3:7] == ["/service/product/productbom.prodbomdetail,productbom.lkunits,productbom.lkprodunits"] * 4
    assert urls[7] == "/service/product"
    assert [c["action"] for c in s.calls] == ["OPEN,GET,INFO", "GET,INFO", "EDIT,GET"] + ["GET,INFO"] * 4 + ["CLOSE"]
    assert [c["oid"] for c in s.calls] == [None] + ["OID-1"] * 7
    # Corpos: OPEN e ficha sem corpo; versão pela key; linhas por armazém ("" primeiro); CLOSE sem corpo.
    assert s.calls[0]["data"] == b"" and s.calls[1]["data"] == b"" and s.calls[7]["data"] == b""
    assert json.loads(s.calls[2]["data"]) == [{"key": "00000000"}]
    assert [json.loads(c["data"])["params"]["warehouse_id"] for c in s.calls[3:7]] == [""] + WAREHOUSES
    # Nunca escreve.
    for c in s.calls:
        assert not any(w in (c["action"] or "") for w in ("MERGE", "SAVE", "NEW", "DELETE"))
        assert not (c["action"] == "EDIT,GET" and c["url"].endswith("/productbom.storerelation.storedata"))

    assert bom["id"] == COCA and bom["code"] == "23" and bom["description"] == "Coca Cola 0"
    assert bom["units"] == {"base": "11001", "sale": "11001", "purchase": "11001", "stock": "11001"}
    assert bom["forsale"] == 1 and bom["stockconfig_id"] == "7501" and bom["cost_warehouse_id"] is None
    assert bom["prices"] == {"saleprice": 2.9, "saleprice_wo_tax": 2.357724}
    caixa = next(u for u in bom["unit_list"] if u["id"] == "584955579139625329")
    assert (caixa["description"], caixa["unit_value"], caixa["parent_id"], caixa["parent_qnt"]) == ("Caixa 24 Unidades", 1.0, "11001", 1.0)
    assert [w["id"] for w in bom["warehouses"]] == WAREHOUSES

    v = bom["versions"][0]
    assert (v["key"], v["id"], v["valid_from"], v["deleted"]) == ("00000000", "584955579139626657", "2026-05-06", 0)
    assert [(st["store_code"], st["active"]) for st in v["stores"]] == [("BO", True), ("YUKOPORTO", True), ("YUKOORIGEM", True)]
    line = v["lines"][""][0]
    assert (line["component_code"], line["component_description"], line["unit_desc"]) == ("100366", "COCA-COLA ZERO GARRAFA", "Unidade")
    assert (line["qnt"], line["qnt_base"], line["waste"], line["recovery"], line["isbom"], line["status"]) == (1.0, 1.0, 0.0, 0.0, 0, 1)
    assert line["cost"] == 0.5275                                     # todos os armazéns
    assert v["lines"][WAREHOUSES[0]][0]["cost"] == round(0.5275 * 24, 6)  # o armazém com a caixa a fator 1


def test_store_inactive_in_version():
    s = FakeSession(coca_responses(select=FIX["select_v0_original_inactive"]))
    bom = make_client(s).read_bom(COCA)
    stores = {st["store_code"]: st["active"] for st in bom["versions"][0]["stores"]}
    assert stores == {"BO": True, "YUKOPORTO": True, "YUKOORIGEM": False}


def test_close_happens_even_when_a_step_fails():
    s = FakeSession([opened(), FakeResp(200, FIX["info"]), FakeResp(500, text="boom"), FakeResp(200, {})])
    with pytest.raises(requests.HTTPError):
        make_client(s).read_bom(COCA)
    assert [c["action"] for c in s.calls] == ["OPEN,GET,INFO", "GET,INFO", "EDIT,GET", "CLOSE"]
    assert s.calls[-1]["oid"] == "OID-1"


def test_lines_of_another_version_are_refused():
    other = copy.deepcopy(FIX["lines_all"])
    other["product"]["productbom.prodbomdetail"][0]["productbom_id"] = "999"
    s = FakeSession([opened(), FakeResp(200, FIX["info"]), FakeResp(200, FIX["select_v0"]), FakeResp(200, other), FakeResp(200, {})])
    with pytest.raises(RuntimeError, match="outra versão"):
        make_client(s).read_bom(COCA)
    assert s.calls[-1]["action"] == "CLOSE"


def test_article_without_bom_opens_and_closes_only():
    p = copy.deepcopy(FIX["open"])
    p["product"]["productbom.maindataset"] = []
    s = FakeSession([opened(p), FakeResp(200, {})])
    bom = make_client(s).read_bom(COCA)
    assert bom["versions"] == [] and [c["action"] for c in s.calls] == ["OPEN,GET,INFO", "CLOSE"]


@pytest.mark.parametrize("action", ["MERGE", "SAVE", "NEW", "DELETE", "EDIT", "NEW,GET"])
def test_write_actions_are_refused(action):
    s = FakeSession([])
    with pytest.raises(RuntimeError, match="recusada"):
        make_client(s)._bom_post("productbom.storerelation.storedata", action, "OID")
    assert s.calls == []


def _article(pid, component=None, isbom=1):
    """Um artigo com uma versão e uma linha (componente `component`, semi-produto se isbom=1)."""
    o = copy.deepcopy(FIX["open"])
    o["product"]["maindataset"][0]["id"] = pid
    o["product"]["productbom.maindataset"][0]["id"] = f"BOM-{pid}"
    lp = copy.deepcopy(FIX["lines_all"])
    row = lp["product"]["productbom.prodbomdetail"][0]
    row["productbom_id"] = f"BOM-{pid}"
    row["product_id"] = component or "62590249681593592"
    row["isbom"] = isbom if component else 0
    info = copy.deepcopy(FIX["info"])
    info["product"]["productbom.lkwarehouses"] = info["product"]["productbom.lkwarehouses"][:1]  # só ""
    return [opened(o), FakeResp(200, info), FakeResp(200, FIX["select_v0"]), FakeResp(200, lp), FakeResp(200, {})]


def test_semi_products_recursive_without_cycles():
    # A usa B (semi-produto); B usa A (ciclo): cada um lê-se uma vez.
    s = FakeSession(_article("A", "B") + _article("B", "A"))
    out = make_client(s).read_boms(["A"])
    assert set(out["boms"]) == {"A", "B"} and out["errors"] == {}
    assert (out["boms"]["A"]["depth"], out["boms"]["B"]["depth"]) == (0, 1)
    assert sum(1 for c in s.calls if c["action"] == "OPEN,GET,INFO") == 2


def test_semi_products_stop_at_max_depth():
    chain = ["L0", "L1", "L2", "L3", "L4", "L5", "L6", "L7"]
    responses = []
    for i, pid in enumerate(chain[:6]):  # L0..L5 (níveis 0 a 5)
        responses += _article(pid, chain[i + 1])
    s = FakeSession(responses)
    out = make_client(s).read_boms(["L0"], max_depth=5)
    assert list(out["boms"]) == chain[:6]  # o L6 (nível 6) já não se lê


def test_a_failure_does_not_stop_the_others():
    s = FakeSession([FakeResp(500, text="x")] + _article("B"))
    out = make_client(s).read_boms(["A", "B"])
    assert list(out["boms"]) == ["B"] and "A" in out["errors"]


def test_run_mode_boms(monkeypatch):
    calls = {}

    class FakeClient:
        def __enter__(self):
            return self

        def __exit__(self, *a):
            return False

        def read_boms(self, ids, max_depth=5):
            calls["ids"], calls["depth"] = ids, max_depth
            return {"boms": {"1": {"id": "1"}}, "errors": {}}

        def list_bom_products(self, probe):
            calls["probe"] = probe
            return [{"id": "1", "code": "1", "isbom": 1}, {"id": "2", "code": "2", "isbom": 0}]

    monkeypatch.setattr(runner, "build_client", lambda **kw: FakeClient())
    out = runner.run({"mode": "boms", "product_ids": ["10", "11"]})
    assert out["ok"] is True and calls["ids"] == ["10", "11"] and calls["depth"] == 5
    out = runner.run({"mode": "boms", "probe_product_id": "10"})
    assert calls["probe"] == "10" and calls["ids"] == ["1"] and out["product_list"][1]["isbom"] == 0
    assert runner.run({"mode": "boms"})["ok"] is False
