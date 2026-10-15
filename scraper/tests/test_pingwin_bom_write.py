"""
XPLENDOR — Fichas técnicas: ESCRITA (Fatia 4). Prova, sem rede, com as respostas do HAR de 15/10
(Coca Cola 0, sem cookies, tokens nem identificadores de sessão):
  · a guarda: só se escreve nos artigos autorizados (o artigo de teste no teste real);
  · a sequência de cada operação: versão nova com linhas e lojas; corrigir uma versão (editar e
    apagar linhas); apagar uma versão; o MERGE é o eco do OPEN sem as chaves de preço; SAVE; CLOSE;
  · concorrência: a ficha mudou na PingWin desde a leitura → pára sem escrever nem gravar;
  · uma falha a meio fecha a sessão SEM gravar;
  · só as combinações caminho+ação do protocolo passam;
  · modo bom_write do run.py, com o progresso no stderr.
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
FIX = json.load(open(os.path.join(os.path.dirname(__file__), "fixtures", "pingwin_bom", "coca_cola_0_write.json")))
COCA = "1649601156985"
V0 = "584955579139626657"
WRITES = ("NEW", "NEW,GET", "EDIT", "DELETE", "LOCATE,GET", "MERGE", "SAVE")


class FakeResp:
    def __init__(self, status=200, payload=None, headers=None, text=""):
        self.status_code = status
        self._payload = payload if payload is not None else {}
        self.headers = headers or {}
        self.text = text or json.dumps(self._payload)

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
        self.calls.append({"path": url.replace(BASE + "/service/product", ""), "action": (headers or {}).get("Action"),
                           "oid": (headers or {}).get("ObjectID"), "body": (None if not data else __import__("json").loads(data))})
        r = self.responses.pop(0)
        if isinstance(r, Exception):
            raise r
        return r


def make_client(session):
    c = MyCloudPieClient.__new__(MyCloudPieClient)
    c.api_url = BASE
    c.session = session
    c.app_grupopie = "grp"
    c.read_bom = lambda pid: {"id": pid, "versions": [], "reread": True}  # a releitura testa-se na Fatia 1
    return c


def ok(name):
    return FakeResp(200, copy.deepcopy(FIX[name]))


def opened():
    return FakeResp(200, copy.deepcopy(FIX["open_form"]), headers={"ObjectID": "OID-W"})


def expected_v0(**line_changes):
    """O espelho da Coca Cola 0 (o que a pessoa viu): a versão de 06/05 com as 2 linhas do HAR."""
    lines = []
    for l in FIX["lines_v0"]["product"]["productbom.prodbomdetail"]:
        lines.append({"key": l["key"], "component_id": l["product_id"], "unit_id": l["unit_id"], "qnt": l["qnt"],
                      "waste": l["waste"], "recovery": l["recovery"]} | line_changes.get(l["key"], {}))
    stores = [{"store_id": s["store_id"], "active": s["deleted"] == 0} for s in FIX["select_v0"]["product"]["productbom.storerelation.storedata"]]
    return {"versions": [{"id": V0, "key": "00000000", "valid_from": "2026-05-06", "lines": lines, "stores": stores}]}


def actions(s):
    return [c["action"] for c in s.calls]


# ── Guarda ───────────────────────────────────────────────────────────────────

def test_guard_refuses_any_product_outside_the_authorised_one():
    s = FakeSession([])
    with pytest.raises(RuntimeError, match="recusada"):
        make_client(s).write_bom(COCA, {"ops": [{"op": "delete_version", "version": V0}]}, allowed_product_ids=["999"])
    assert s.calls == []  # nem abre


@pytest.mark.parametrize("path,action", [
    ("productbom.maindataset", "EDIT"),                       # não se edita a versão (data) por aqui
    ("productbom.storerelation.storedata", "DELETE"),
    ("", "MERGE"),
    ("maindataset", "MERGE"),
    ("productbom.prodbomdetail", "SAVE"),
    ("productbom.productlist", "GET,INFO"),
])
def test_only_protocol_actions_pass(path, action):
    s = FakeSession([])
    with pytest.raises(RuntimeError, match="fora do protocolo"):
        make_client(s)._bom_write_post(path, action, "OID", [{}])
    assert s.calls == []


# ── Versão nova com 2 linhas e lojas ──────────────────────────────────────────

def test_new_version_with_lines_and_stores():
    new_line_a = copy.deepcopy(FIX["new_line"])
    new_line_b = copy.deepcopy(FIX["new_line"])
    new_line_b["product"]["productbom.prodbomdetail"][0]["key"] = "00000001"
    s = FakeSession([
        opened(), ok("info"),
        ok("new_version"), ok("stores_after_new"),
        FakeResp(200, new_line_a), ok("edit_line"),
        FakeResp(200, new_line_b), ok("edit_line"),
        ok("stores_after_new"),                 # EDIT storedata
        FakeResp(200, {}), FakeResp(200, {}),   # MERGE, SAVE
        FakeResp(200, {}),                      # CLOSE
    ])
    steps = []
    plan = {"expected": expected_v0(), "ops": [
        {"op": "new_version", "valid_from": "2026-10-20", "ref": "new"},
        {"op": "add_line", "version": "ref:new", "component_id": "584955579139631978", "description": "COCA COLA ZERO 0.35",
         "unit_id": "11001", "qnt": 1, "waste": 0, "recovery": 0},
        {"op": "add_line", "version": "ref:new", "component_id": "584955579139722616", "description": "VASILHAME",
         "unit_id": "584955579139626529", "qnt": 5, "waste": 2.5, "recovery": 0},
        {"op": "set_stores", "version": "ref:new", "stores": {"1099511639284": True, "1099845342604": True, "584955579139649880": False}},
    ]}
    out = make_client(s).write_bom(COCA, plan, allowed_product_ids=[COCA], progress=lambda **kw: steps.append(kw))

    assert actions(s) == ["OPEN,GET,INFO", "GET,INFO", "NEW", "GET,INFO", "NEW,GET", "EDIT", "NEW,GET", "EDIT", "EDIT", "MERGE", "SAVE", "CLOSE"]
    assert [c["path"] for c in s.calls][2:5] == ["/productbom.maindataset", "/productbom.maindataset,productbom.storerelation.storedata,productbom.storerelation.storegroup",
                                                 "/productbom.prodbomdetail/productbom.lkunits,productbom.lkprodunits"]
    assert all(c["oid"] == "OID-W" for c in s.calls[1:])
    assert s.calls[2]["body"] == [{"product_id": COCA, "cashbox_date": "20261020"}]
    new_id = FIX["new_version"]["product"]["productbom.maindataset"][0]["id"]
    assert s.calls[4]["body"] == [{"product_id": "584955579139631978", "productbom_id": new_id, "product_description": "COCA COLA ZERO 0.35"}]
    edit = s.calls[7]["body"][0]
    assert {k: edit[k] for k in ("key", "unit_id", "qnt", "waste", "recovery", "deleted")} == \
        {"key": "00000001", "unit_id": "584955579139626529", "qnt": 5, "waste": 2.5, "recovery": 0, "deleted": 0}
    stores = s.calls[8]["body"]
    assert [(r["store_code"], r["deleted"]) for r in stores] == [("BO", 0), ("YUKOPORTO", 0), ("YUKOORIGEM", 1)]  # TODAS as lojas
    merge = s.calls[9]["body"]
    assert s.calls[9]["path"] == "/maindataset,productgroup,tbtaxtable,additionalfields.maindataset"
    assert list(merge) == FIX["merge_req_keys"]
    assert not {"saleprice", "purchaseprice", "markup"} & set(merge["maindataset"][0])  # o eco sem preços
    assert merge["productgroup"] == FIX["open_form"]["product"]["productgroup"]
    assert s.calls[10]["body"] is None and s.calls[11]["body"] is None                   # SAVE e CLOSE sem corpo
    assert out["ok"] is True and out["saved"] is True and out["refs"]["new"]["id"] == new_id
    assert out["article"]["productgroup"] == 0   # a linha "Gr. Artigos Compras" vem apagada (deleted 1): não conta
    assert out["reread"]["reread"] is True
    assert [st["step"] for st in steps] == ["abrir", "ler", "versao", "linhas", "linhas", "lojas", "gravar", "confirmar"]
    assert steps[3] == {"step": "linhas", "n": 1, "m": 2}


def test_stores_are_not_sent_when_they_do_not_change():
    s = FakeSession([opened(), ok("info"), ok("new_version"), ok("stores_after_new"), FakeResp(200, {}), FakeResp(200, {}), FakeResp(200, {})])
    plan = {"expected": expected_v0(), "ops": [
        {"op": "new_version", "valid_from": "2026-10-20", "ref": "new"},
        {"op": "set_stores", "version": "ref:new", "stores": {"1099511639284": True, "1099845342604": True, "584955579139649880": True}},
    ]}
    make_client(s).write_bom(COCA, plan)
    assert actions(s) == ["OPEN,GET,INFO", "GET,INFO", "NEW", "GET,INFO", "MERGE", "SAVE", "CLOSE"]


# ── Corrigir uma versão: editar e apagar linhas ───────────────────────────────

def test_correct_version_edits_and_deletes_lines_as_read():
    s = FakeSession([
        opened(), ok("info"),
        ok("select_v0"), ok("lines_v0"),                         # concorrência: igual ao espelho
        ok("select_v0"), ok("lines_v0"),                         # posicionar a versão
        ok("edit_line"), ok("edit_line"),                        # LOCATE,GET + EDIT da 00000001
        ok("delete_line"),                                       # DELETE da 00000000
        FakeResp(200, {}), FakeResp(200, {}), FakeResp(200, {}),
    ])
    plan = {"expected": expected_v0(), "ops": [
        {"op": "edit_line", "version": V0, "key": "00000001", "unit_id": "584955579139626529", "qnt": 6, "waste": 5, "recovery": 0},
        {"op": "delete_line", "version": V0, "key": "00000000"},
    ]}
    out = make_client(s).write_bom(COCA, plan)

    assert actions(s) == ["OPEN,GET,INFO", "GET,INFO", "EDIT,GET", "GET,INFO", "EDIT,GET", "GET,INFO", "LOCATE,GET", "EDIT", "DELETE", "MERGE", "SAVE", "CLOSE"]
    rows = {l["key"]: l for l in FIX["lines_v0"]["product"]["productbom.prodbomdetail"]}
    assert s.calls[6]["body"] == [rows["00000001"]]          # LOCATE com a linha completa
    assert s.calls[7]["body"][0]["qnt"] == 6 and s.calls[7]["body"][0]["waste"] == 5
    assert s.calls[8]["body"] == [rows["00000000"]]          # DELETE com a linha tal como foi lida
    assert s.calls[2]["body"] == [{"key": "00000000"}]
    assert out["saved"] is True


# ── Concorrência ─────────────────────────────────────────────────────────────

def test_conflict_when_the_bom_changed_in_pingwin_stops_without_writing():
    s = FakeSession([opened(), ok("info"), ok("select_v0"), ok("lines_v0"), FakeResp(200, {})])
    plan = {"expected": expected_v0(**{"00000001": {"qnt": 4}}), "ops": [{"op": "delete_line", "version": V0, "key": "00000000"}]}
    out = make_client(s).write_bom(COCA, plan)
    assert out["ok"] is False and out["conflict"] is True and out["saved"] is False
    assert "mudou" in out["diff"][0]
    assert actions(s) == ["OPEN,GET,INFO", "GET,INFO", "EDIT,GET", "GET,INFO", "CLOSE"]
    assert not set(actions(s)) & set(WRITES)


def test_conflict_when_a_version_appeared():
    exp = {"versions": []}  # a XPLENDOR não conhecia nenhuma versão
    s = FakeSession([opened(), ok("info"), FakeResp(200, {})])
    out = make_client(s).write_bom(COCA, {"expected": exp, "ops": [{"op": "new_version", "valid_from": "2026-10-20", "ref": "new"}]})
    assert out["conflict"] is True and "nova na PingWin" in out["diff"][0]


# ── Falha a meio ─────────────────────────────────────────────────────────────

def test_failure_midway_closes_without_saving():
    s = FakeSession([opened(), ok("info"), ok("new_version"), ok("stores_after_new"), ok("new_line"),
                     FakeResp(500, text="erro"), FakeResp(200, {})])
    plan = {"expected": expected_v0(), "ops": [
        {"op": "new_version", "valid_from": "2026-10-20", "ref": "new"},
        {"op": "add_line", "version": "ref:new", "component_id": "584955579139631978", "description": "X", "unit_id": "11001", "qnt": 1},
    ]}
    with pytest.raises(RuntimeError, match="EDIT"):
        make_client(s).write_bom(COCA, plan)
    assert actions(s)[-1] == "CLOSE" and "SAVE" not in actions(s) and "MERGE" not in actions(s)


# ── Apagar uma versão ─────────────────────────────────────────────────────────

def test_delete_version_sends_the_full_version_row():
    s = FakeSession([opened(), ok("info"), ok("select_v0"), ok("lines_v0"),  # a versão a apagar ainda é a do espelho
                     ok("delete_version"), FakeResp(200, {}), FakeResp(200, {}), FakeResp(200, {})])
    out = make_client(s).write_bom(COCA, {"expected": expected_v0(), "ops": [{"op": "delete_version", "version": V0}]})
    assert actions(s) == ["OPEN,GET,INFO", "GET,INFO", "EDIT,GET", "GET,INFO", "DELETE", "MERGE", "SAVE", "CLOSE"]
    assert s.calls[4]["path"] == "/productbom.maindataset"
    assert s.calls[4]["body"] == FIX["open_form"]["product"]["productbom.maindataset"]
    assert out["saved"] is True


# ── run.py ────────────────────────────────────────────────────────────────────

def test_run_mode_bom_write_reports_progress_on_stderr(monkeypatch, capsys):
    class FakeClient:
        def __enter__(self):
            return self

        def __exit__(self, *a):
            return False

        def write_bom(self, pid, plan, allowed, progress):
            progress(step="abrir")
            progress(step="linhas", n=1, m=2)
            return {"ok": True, "saved": True, "refs": {}, "reread": {"id": pid}, "allowed": allowed}

    monkeypatch.setattr(runner, "build_client", lambda **kw: FakeClient())
    out = runner.run({"mode": "bom_write", "product_id": COCA, "plan": {"ops": [{"op": "x"}]}, "allowed_product_ids": [COCA]})
    err = capsys.readouterr().err
    assert out["ok"] is True and out["allowed"] == [COCA]
    assert 'XPPROGRESS {"step": "abrir"}' in err and 'XPPROGRESS {"step": "linhas", "n": 1, "m": 2}' in err
    assert runner.run({"mode": "bom_write", "product_id": COCA, "plan": {}})["ok"] is False
