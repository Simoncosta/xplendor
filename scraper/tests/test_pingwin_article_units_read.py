"""
XPLENDOR — Catálogo da PingWin: as unidades de cada artigo (base, compra, venda e stock, ids e nomes), com o
mesmo OPEN da leitura do stock (maindataset e lk_stockconfig) e CLOSE num finally, sem o tbstock. Prova, sem
rede, com a resposta do HAR de 15/10 (100415 COCA COLA ZERO 0.35; sem cookies nem identificadores de sessão).
"""
import copy
import os
import sys

sys.path.insert(0, os.path.abspath(os.path.dirname(__file__)))
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

import sources.pingwin.run as runner  # noqa: E402
from sources.pingwin.mycloudpie import MyCloudPieClient  # noqa: E402
from test_pingwin_stock import COCA_ZERO, STOCK, FakeResp, FakeSession, make_client, stock_open  # noqa: E402


def test_parse_the_four_units_with_ids_and_names():
    u = MyCloudPieClient.parse_article_units(STOCK["open"]["product"]["maindataset"][0])
    assert (u["id"], u["code"]) == (COCA_ZERO, "100415")
    assert u["base"] == {"id": "11001", "name": "Unidade"}
    assert u["purchase"] == {"id": "584955579139626529", "name": "Grade 24"}
    assert u["sale"] == {"id": "11001", "name": "Unidade"} and u["stock"] == {"id": "11001", "name": "Unidade"}
    empty = dict(STOCK["open"]["product"]["maindataset"][0], default_sale_unit_id="")
    assert MyCloudPieClient.parse_article_units(empty)["sale"] == {"id": None, "name": None}


def test_read_article_units_is_open_then_close_without_tbstock():
    s = FakeSession([stock_open("OID-U"), FakeResp(200, {})])
    out = make_client(s).read_article_units([COCA_ZERO])
    assert out["units"][COCA_ZERO]["purchase"]["name"] == "Grade 24" and out["errors"] == {}
    assert [(c["path"], c["action"], c["oid"]) for c in s.calls] == [
        (f"product/{COCA_ZERO}/maindataset,lk_stockconfig", "OPEN,GET,INFO", None),
        ("product", "CLOSE", "OID-U"),
    ]


def test_a_failure_in_one_article_does_not_stop_the_others_and_always_closes():
    other = copy.deepcopy(STOCK["open"])
    other["product"]["maindataset"][0]["id"] = "999"
    s = FakeSession([FakeResp(500, {"error": "x"}), FakeResp(200, other, headers={"ObjectID": "OID-2"}), FakeResp(200, {}),
                     stock_open("OID-3"), FakeResp(200, {})])
    out = make_client(s).read_article_units(["111", "222", COCA_ZERO])
    assert set(out["errors"]) == {"111", "222"} and "o OPEN não devolveu este artigo" in out["errors"]["222"]
    assert list(out["units"]) == [COCA_ZERO]
    assert [c["action"] for c in s.calls] == ["OPEN,GET,INFO", "OPEN,GET,INFO", "CLOSE", "OPEN,GET,INFO", "CLOSE"]


def test_run_mode_article_units(monkeypatch):
    class FakeClient:
        def __enter__(self):
            return self

        def __exit__(self, *a):
            return False

        def read_article_units(self, ids):
            return {"units": {i: {"base": {"id": "11001", "name": "Unidade"}} for i in ids}, "errors": {}, "seconds": {}}

    monkeypatch.setattr(runner, "build_client", lambda **kw: FakeClient())
    assert runner.run({"mode": "article_units", "product_ids": [COCA_ZERO]})["units"][COCA_ZERO]["base"]["id"] == "11001"
    assert runner.run({"mode": "article_units"})["ok"] is False
