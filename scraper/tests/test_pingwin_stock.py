"""
XPLENDOR — Stock e armazéns (Fatia 7a). Prova, sem rede, com as respostas dos HAR de 15/10 (stock do
100415 COCA COLA ZERO 0.35 e o armazém de teste XPLENDOR TESTE; sem cookies nem identificadores de sessão):
  · o parser do tbstock (uma linha por armazém; mínimo e máximo vazios ficam None) e dos armazéns;
  · a leitura: OPEN → GET,INFO tbstock {"SHOW_TRANSIT":0} → CLOSE, sempre num finally;
  · a escrita do mínimo/máximo: a guarda, o MERGE com o eco do artigo (sem preços) e TODAS as linhas
    de tbstock (só a do armazém muda), SAVE, CLOSE, releitura; conflito; artigo sem linhas;
  · os armazéns: criar, editar (com e sem lojas), apagar, a guarda pelo nome e a valorização 0/1;
  · só as combinações caminho+ação do protocolo passam;
  · os modos do run.py.
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
FIXDIR = os.path.join(os.path.dirname(__file__), "fixtures", "pingwin_stock")
STOCK = json.load(open(os.path.join(FIXDIR, "stock_100415.json")))
WH = json.load(open(os.path.join(FIXDIR, "warehouses.json")))
COCA_ZERO = "584955579139631978"
BO, BAIXA, CC = "584955579139620864", "1649601156541", "584955579139649883"
TEST_WH = "584955579139801487"


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
        self.calls.append({"path": url.replace(BASE + "/service/", ""), "action": (headers or {}).get("Action"),
                           "oid": (headers or {}).get("ObjectID"), "body": body})
        r = self.responses.pop(0)
        if isinstance(r, Exception):
            raise r
        return r


def make_client(session):
    c = MyCloudPieClient.__new__(MyCloudPieClient)
    c.api_url = BASE
    c.session = session
    c.app_grupopie = "grp"
    c.database = "db"
    return c


def actions(s):
    return [(c["path"].split("/")[0] if c["path"].startswith(("entity", "warehouse", "browser")) else c["path"], c["action"]) for c in s.calls]


def stock_open(oid="OID-S"):
    return FakeResp(200, copy.deepcopy(STOCK["open"]), headers={"ObjectID": oid})


def stock_rows(**changes):
    body = copy.deepcopy(STOCK["tbstock"])
    for row in body["product"]["tbstock"]:
        for k, v in changes.get(row["warehouse_id"], {}).items():
            row[k] = v
    return FakeResp(200, body)


# ── Parser ───────────────────────────────────────────────────────────────────

def test_parse_tbstock_one_row_per_warehouse():
    rows = MyCloudPieClient.parse_stock_rows(STOCK["tbstock"]["product"]["tbstock"])
    assert [(r["warehouse_name"], r["qnt"]) for r in rows] == [("Armazém Yuko BO", 5112.0), ("Armazém Yuko Baixa", 1896.0), ("Armazém Yuko", 3432.0)]
    bo = rows[0]
    assert bo["pcm"] == pytest.approx(0.534564) and bo["pcu"] == pytest.approx(0.542976)
    assert bo["valorization_date"] == "2026-10-12"
    assert bo["min_stock"] is None and bo["max_stock"] is None      # "" no PingWin = sem limite
    assert bo["disable_generic"] == 0 and bo["unit_name"] == "Unidade" and bo["unit_value"] == 1.0
    assert sum(r["qnt"] for r in rows) == 10440                       # o caso da Coca Cola Zero


def test_parse_article_stockconfig_and_movestock():
    a = MyCloudPieClient.parse_stock_article(STOCK["open"]["product"]["maindataset"][0], STOCK["open"]["product"]["lk_stockconfig"])
    assert (a["code"], a["stockconfig_id"], a["stockconfig"], a["movestock"]) == ("100415", "7501", "Stock normal", 1)
    sem = dict(STOCK["open"]["product"]["maindataset"][0], stockconfig_id="7500")
    assert MyCloudPieClient.parse_stock_article(sem, STOCK["open"]["product"]["lk_stockconfig"])["movestock"] == 0


def test_parse_warehouses_list_and_form():
    rows = [MyCloudPieClient.parse_warehouse_row(r) for r in WH["list_after_create"]["browser"]["browserdataset"]]
    assert [(r["code"], r["inv_costmethod"], r["exit_costmethod"]) for r in rows] == [("1", 0, 0), ("BAIXA", 0, 0), ("2", 0, 0), ("3", 1, 1)]
    form = MyCloudPieClient.parse_warehouse_form({**WH["form_main"]["entity"], **WH["form_stores"]["entity"], **WH["form_extra"]["entity"]})
    assert form["inv_costmethod"] == 0 and form["deleted"] == 0
    assert [(s["code"], s["served"]) for s in form["stores"]] == [("BO", True), ("YUKOPORTO", True), ("YUKOORIGEM", True)]


# ── Leitura do stock ─────────────────────────────────────────────────────────

def test_read_stock_open_get_close():
    s = FakeSession([stock_open(), stock_rows(), FakeResp(200, {})])
    out = make_client(s).read_stock([COCA_ZERO])
    assert out["errors"] == {}
    assert actions(s) == [(f"product/{COCA_ZERO}/maindataset,lk_stockconfig", "OPEN,GET,INFO"),
                          ("product/tbstock,productset,lkwarehouse", "GET,INFO"), ("product", "CLOSE")]
    assert s.calls[1]["body"] == {"params": {"SHOW_TRANSIT": 0}} and s.calls[1]["oid"] == "OID-S" and s.calls[2]["oid"] == "OID-S"
    got = out["stock"][COCA_ZERO]
    assert got["article"]["movestock"] == 1 and len(got["rows"]) == 3
    assert [w["id"] for w in got["warehouses"]] == [BO, CC, BAIXA]


def test_read_stock_closes_even_when_the_get_fails_and_goes_on():
    s = FakeSession([stock_open("OID-1"), FakeResp(500, {"message": "x"}), FakeResp(200, {}),
                     stock_open("OID-2"), stock_rows(), FakeResp(200, {})])
    out = make_client(s).read_stock(["584955579139631978", "584955579139631978"])
    assert ("product", "CLOSE") in actions(s)[:3]
    assert len(s.calls) == 6


# ── Escrita do mínimo/máximo ─────────────────────────────────────────────────

def test_limits_guard_refuses_other_articles_and_zero_refuses_all():
    for allowed in (["101335"], ["0"]):
        s = FakeSession([])
        with pytest.raises(RuntimeError, match="recusada"):
            make_client(s).write_stock_limits(COCA_ZERO, BO, {"min_stock": 5}, allowed_product_ids=allowed)
        assert s.calls == []      # nem abre


def test_limits_merge_echoes_the_article_and_every_tbstock_row():
    s = FakeSession([
        stock_open(), stock_rows(), FakeResp(200, {}), FakeResp(200, {}), FakeResp(200, {}),   # OPEN, GET, MERGE, SAVE, CLOSE
        stock_open("OID-R"), stock_rows(**{BO: {"min_stock": 5, "max_stock": 50}}), FakeResp(200, {}),   # releitura
    ])
    out = make_client(s).write_stock_limits(COCA_ZERO, BO, {"min_stock": 5, "max_stock": 50},
                                            expected={"min_stock": None, "max_stock": None}, allowed_product_ids=[COCA_ZERO])
    assert [a for _, a in actions(s)] == ["OPEN,GET,INFO", "GET,INFO", "MERGE", "SAVE", "CLOSE", "OPEN,GET,INFO", "GET,INFO", "CLOSE"]
    assert s.calls[0]["path"].startswith(f"product/{COCA_ZERO}/maindataset,movementtype,")      # os 19 datasets do formulário
    merge = s.calls[2]
    assert merge["path"] == "product/maindataset,productgroup,tbtaxtable,tbstock,additionalfields.maindataset"
    har = STOCK["merge_req"]
    assert list(merge["body"].keys()) == list(har.keys())
    assert merge["body"]["maindataset"] == har["maindataset"]                  # o eco do OPEN, sem preços (como o browser)
    assert not ({"saleprice", "purchaseprice", "markup"} & set(merge["body"]["maindataset"][0]))
    assert merge["body"]["productgroup"] == har["productgroup"] and merge["body"]["additionalfields.maindataset"] == har["additionalfields.maindataset"]
    sent, browser = merge["body"]["tbstock"], har["tbstock"]
    assert len(sent) == 3
    assert [float(r["min_stock"] or 0) for r in sent] == [float(r["min_stock"] or 0) for r in browser]   # 5, "", ""
    assert [float(r["max_stock"] or 0) for r in sent] == [float(r["max_stock"] or 0) for r in browser]   # 50, "", ""
    assert sent[1] == STOCK["tbstock"]["product"]["tbstock"][1] and sent[2] == STOCK["tbstock"]["product"]["tbstock"][2]   # as outras linhas intactas
    assert out["ok"] is True and out["checks"] == {"min_stock": True, "max_stock": True}
    assert out["before"]["min_stock"] is None and out["after"]["max_stock"] == 50


def test_limits_conflict_stops_before_writing():
    s = FakeSession([stock_open(), stock_rows(**{BO: {"min_stock": 3}}), FakeResp(200, {})])
    out = make_client(s).write_stock_limits(COCA_ZERO, BO, {"min_stock": 5}, expected={"min_stock": None}, allowed_product_ids=[])
    assert out["conflict"] is True and out["saved"] is False
    assert [a for _, a in actions(s)] == ["OPEN,GET,INFO", "GET,INFO", "CLOSE"]


def test_limits_without_stock_rows_is_not_forced():
    empty = copy.deepcopy(STOCK["tbstock"])
    empty["product"]["tbstock"] = []
    s = FakeSession([stock_open(), FakeResp(200, empty), FakeResp(200, {})])
    out = make_client(s).write_stock_limits(COCA_ZERO, BO, {"max_stock": 10})
    assert out["no_stock_rows"] is True and out["saved"] is False
    assert [a for _, a in actions(s)] == ["OPEN,GET,INFO", "GET,INFO", "CLOSE"]


def test_limits_merge_failure_closes_without_saving():
    s = FakeSession([stock_open(), stock_rows(), FakeResp(500, {"message": "boom"}), FakeResp(200, {})])
    out = make_client(s).write_stock_limits(COCA_ZERO, BO, {"disable_generic": 1})
    assert out["saved"] is False and "MERGE falhou" in out["error"]
    assert [a for _, a in actions(s)] == ["OPEN,GET,INFO", "GET,INFO", "MERGE", "CLOSE"]


@pytest.mark.parametrize("path,action", [
    ("tbstock,productset,lkwarehouse", "MERGE"),
    ("maindataset,productgroup,tbtaxtable,tbstock,additionalfields.maindataset", "SAVE"),
    ("", "DELETE"),
    ("123/maindataset", "OPEN,GET,INFO"),
])
def test_stock_only_protocol_actions_pass(path, action):
    s = FakeSession([])
    with pytest.raises(RuntimeError, match="fora do protocolo"):
        make_client(s)._stock_post(path, action, "OID")
    assert s.calls == []


# ── Armazéns ─────────────────────────────────────────────────────────────────

def form_responses(oid="OID-W", deleted=0):
    main = copy.deepcopy(WH["edit_main"])
    main["entity"]["maindataset"][0]["deleted"] = deleted
    return [FakeResp(200, {}, headers={"ObjectID": oid}), FakeResp(200, main), FakeResp(200, copy.deepcopy(WH["form_stores"])),
            FakeResp(200, copy.deepcopy(WH["form_extra"]))]


def test_warehouse_guard_refuses_other_names_before_any_call():
    s = FakeSession([])
    with pytest.raises(RuntimeError, match="recusada"):
        make_client(s).create_warehouse({"description": "Armazém Yuko BO"}, allowed_names=["XPLENDOR TESTE"])
    with pytest.raises(RuntimeError, match="recusada"):
        make_client(s).create_warehouse({"description": "XPLENDOR TESTE"}, allowed_names=["0"])
    assert s.calls == []


def test_create_warehouse_like_the_har():
    new = FakeResp(200, copy.deepcopy(WH["new"]), headers={"ObjectID": "OID-N"})
    merged = {"entity": {"maindataset": [{"id": TEST_WH}]}}
    listed = copy.deepcopy(WH["list_after_create"])
    listed["browser"]["browserdataset"][3]["inv_costmethod"] = listed["browser"]["browserdataset"][3]["exit_costmethod"] = "PCM"
    edit_main = copy.deepcopy(WH["edit_main"])
    edit_main["entity"]["datasetwarehouse"][0]["inv_costmethod"] = edit_main["entity"]["datasetwarehouse"][0]["exit_costmethod"] = 0
    stores = copy.deepcopy(WH["form_stores"])
    for st in stores["entity"]["storerelation.storedata"]:
        st["deleted"] = 0 if st["store_code"] == "BO" else 1
    s = FakeSession([
        new, FakeResp(200, copy.deepcopy(WH["form_main"])), FakeResp(200, copy.deepcopy(WH["form_stores"])), FakeResp(200, copy.deepcopy(WH["form_extra"])),
        FakeResp(200, merged), FakeResp(200, {}), FakeResp(200, {}),                       # MERGE, SAVE, CLOSE
        FakeResp(200, {}, headers={"ObjectID": "OID-R"}), FakeResp(200, edit_main), FakeResp(200, stores), FakeResp(200, copy.deepcopy(WH["form_extra"])), FakeResp(200, {}),  # releitura
        FakeResp(200, listed),                                                              # lista STATE 0
    ])
    out = make_client(s).create_warehouse({"description": "XPLENDOR TESTE", "store_ids": ["1099511639284"], "inv_costmethod": 0, "exit_costmethod": 0},
                                          allowed_names=["XPLENDOR TESTE"])
    acts = [a for _, a in actions(s)]
    assert acts[:7] == ["NEW", "GET,INFO", "GET,INFO", "GET,INFO", "MERGE", "SAVE", "CLOSE"]
    assert s.calls[0]["body"] == {"params": {"entitytype": "warehouse"}}
    merge = s.calls[4]
    assert merge["path"] == "entity/maindataset,storerelation.storedata,datasetwarehouse,address.defaultaddress,additionalfields.maindataset,additionalfields.storedataset"
    har = WH["merge_create_req"]
    assert set(merge["body"].keys()) == set(har.keys())
    assert merge["body"]["maindataset"][0]["description"] == merge["body"]["maindataset"][0]["fiscalname"] == "XPLENDOR TESTE"
    assert merge["body"]["datasetwarehouse"][0]["inv_costmethod"] == "0" and merge["body"]["datasetwarehouse"][0]["exit_costmethod"] == "0"
    assert [st["deleted"] for st in merge["body"]["storerelation.storedata"]] == [0, 1, 1]
    assert merge["body"]["address.defaultaddress"] == WH["form_main"]["entity"]["address.defaultaddress"]
    assert out["ok"] is True and out["pingwin_id"] == TEST_WH and out["checks"]["na_lista"] is True


def test_update_warehouse_only_sends_stores_when_they_change():
    # Só a valorização: o MERGE curto do HAR (sem storerelation).
    s = FakeSession(form_responses() + [FakeResp(200, {"entity": {"maindataset": [{"id": TEST_WH}]}}), FakeResp(200, {}), FakeResp(200, {})]
                    + form_responses("OID-R")[:1] + [FakeResp(200, _edit_main_with(0, 0)), FakeResp(200, copy.deepcopy(WH["form_stores"])), FakeResp(200, copy.deepcopy(WH["form_extra"])), FakeResp(200, {})])
    out = make_client(s).update_warehouse(TEST_WH, {"inv_costmethod": 0, "exit_costmethod": 0}, allowed_names=["XPLENDOR TESTE"])
    merge = next(c for c in s.calls if c["action"] == "MERGE")
    assert merge["path"] == "entity/maindataset,datasetwarehouse,address.defaultaddress,additionalfields.maindataset"
    assert merge["body"]["datasetwarehouse"][0]["inv_costmethod"] == "0"
    assert out["ok"] is True


def test_update_warehouse_with_stores_and_conflict():
    stores = copy.deepcopy(WH["form_stores"])
    s = FakeSession(form_responses() + [FakeResp(200, {"entity": {"maindataset": [{"id": TEST_WH}]}}), FakeResp(200, {}), FakeResp(200, {})]
                    + form_responses("OID-R")[:1] + [FakeResp(200, _edit_main_with(1, 1)), FakeResp(200, _stores_served("BO")), FakeResp(200, copy.deepcopy(WH["form_extra"])), FakeResp(200, {})])
    out = make_client(s).update_warehouse(TEST_WH, {"store_ids": ["1099511639284"]}, allowed_names=["XPLENDOR TESTE"])
    merge = next(c for c in s.calls if c["action"] == "MERGE")
    assert merge["path"] == "entity/maindataset,storerelation.storedata,datasetwarehouse,address.defaultaddress,additionalfields.maindataset"
    assert [st["deleted"] for st in merge["body"]["storerelation.storedata"]] == [0, 1, 1]
    assert out["checks"]["stores"] is True
    assert stores  # (o HAR tinha as 3 lojas servidas)

    # Conflito: a pessoa viu PCM, o PingWin tem PCU → pára sem MERGE.
    s = FakeSession(form_responses() + [FakeResp(200, {})])
    out = make_client(s).update_warehouse(TEST_WH, {"inv_costmethod": 0}, expected={"inv_costmethod": 0}, allowed_names=[])
    assert out["conflict"] is True and "MERGE" not in [a for _, a in actions(s)]


def test_delete_warehouse_then_confirm_it_left_the_active_list():
    before = copy.deepcopy(WH["list_after_create"])
    after = copy.deepcopy(WH["list"])
    voided = {"browser": {"browserdataset": [dict(WH["list_after_create"]["browser"]["browserdataset"][3], deleted=1)]}}
    s = FakeSession(form_responses() + [FakeResp(200, {}), FakeResp(200, WH["delete"]), FakeResp(200, after), FakeResp(200, voided)])
    out = make_client(s).delete_warehouse(TEST_WH, allowed_names=["XPLENDOR TESTE"])
    assert ("warehouse", "DELETE,CLOSE") in actions(s)
    assert next(c for c in s.calls if c["action"] == "DELETE,CLOSE")["path"] == f"warehouse/{TEST_WH}"
    assert out == {**out, "ok": True, "deleted": True, "in_state0_ativos": False, "in_state1_anulados": True}
    assert before


def test_delete_refuses_a_real_warehouse_by_name():
    main = copy.deepcopy(WH["edit_main"])
    main["entity"]["maindataset"][0]["description"] = "Armazém Yuko BO"
    s = FakeSession([FakeResp(200, {}, headers={"ObjectID": "OID-W"}), FakeResp(200, main), FakeResp(200, copy.deepcopy(WH["form_stores"])),
                     FakeResp(200, copy.deepcopy(WH["form_extra"])), FakeResp(200, {})])
    with pytest.raises(RuntimeError, match="recusada"):
        make_client(s).delete_warehouse(TEST_WH, allowed_names=["XPLENDOR TESTE"])
    assert "DELETE,CLOSE" not in [a for _, a in actions(s)]


@pytest.mark.parametrize("path,action", [("entity", "DELETE"), ("entity/123", "SAVE"), ("warehouse/123", "DELETE"), ("entity/maindataset", "MERGE")])
def test_warehouse_only_protocol_actions_pass(path, action):
    s = FakeSession([])
    with pytest.raises(RuntimeError, match="fora do protocolo"):
        make_client(s)._wh_post(path, action)
    assert s.calls == []


def _edit_main_with(inv, exit_):
    m = copy.deepcopy(WH["edit_main"])
    m["entity"]["datasetwarehouse"][0]["inv_costmethod"] = inv
    m["entity"]["datasetwarehouse"][0]["exit_costmethod"] = exit_
    return m


def _stores_served(*codes):
    st = copy.deepcopy(WH["form_stores"])
    for x in st["entity"]["storerelation.storedata"]:
        x["deleted"] = 0 if x["store_code"] in codes else 1
    return st


# ── run.py ────────────────────────────────────────────────────────────────────

def test_run_modes_stock_and_writes(monkeypatch):
    seen = {}

    class FakeClient:
        def __enter__(self):
            return self

        def __exit__(self, *a):
            return False

        def read_stock(self, ids):
            return {"stock": {i: {"rows": []} for i in ids}, "errors": {}, "seconds": {}}

        def fetch_warehouses_rich(self):
            return {"warehouses": [{"id": BO}], "errors": {}}

        def write_stock_limits(self, pid, wid, changes, expected, allowed):
            seen["limits"] = (pid, wid, changes, expected, allowed)
            return {"ok": True, "saved": True}

        def delete_warehouse(self, wid, allowed):
            seen["delete"] = (wid, allowed)
            return {"ok": False, "deleted": False, "error": "x"}

    monkeypatch.setattr(runner, "build_client", lambda **kw: FakeClient())
    assert runner.run({"mode": "stock", "product_ids": [COCA_ZERO]})["stock"] == {COCA_ZERO: {"rows": []}}
    assert runner.run({"mode": "stock"})["ok"] is False
    assert runner.run({"mode": "warehouses"})["warehouses"] == [{"id": BO}]
    out = runner.run({"mode": "stock_limits_write", "product_id": COCA_ZERO, "warehouse_id": BO, "changes": {"min_stock": 5},
                      "expected": {"min_stock": None}, "allowed_product_ids": ["0"]})
    assert out["ok"] is True and seen["limits"] == (COCA_ZERO, BO, {"min_stock": 5}, {"min_stock": None}, ["0"])
    out = runner.run({"mode": "warehouse_delete", "warehouse_id": TEST_WH, "allowed_warehouse_names": ["XPLENDOR TESTE"]})
    assert out["ok"] is False and seen["delete"] == (TEST_WH, ["XPLENDOR TESTE"])
    assert runner.run({"mode": "warehouse_update"})["ok"] is False
