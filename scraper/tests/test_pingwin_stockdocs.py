"""
XPLENDOR — Documentos de stock (Fatia 7b). Prova, sem rede, com as respostas dos HAR de 15/10 (sem
cabeçalhos nem identificadores de sessão):
  · a guarda (códigos de artigo; ["0"] = nenhum) recusa antes de qualquer pedido;
  · a Contagem 1106: NEW → GET,INFO → loja → armazém → data e estado → linhas (todas num NEW; as que
    faltarem, uma a uma) → physical_qnt por EDIT → MERGE do eco → SAVE → CLOSE; o 500 do SAVE dá
    CANCEL e "O documento não foi gravado no PingWin."; a unidade diferente pára antes de gravar;
  · o Desperdício 1100: o armazém pela entidade do cabeçalho, confirmado em cada linha; o EDIT do
    cabeçalho é o eco sem os campos que o ecrã não reenvia; SAVE → CLOSE;
  · só as combinações caminho+ação do protocolo passam; os Movimentos (sinal, código do artigo).
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
FX = json.load(open(os.path.join(os.path.dirname(__file__), "fixtures", "pingwin_stockdocs", "stockdocs.json")))
BO_STORE, BO_WH, CC_WH = "1099511639284", "584955579139620864", "584955579139649883"
A = {"product_id": "584955579139631978", "code": "100415", "description": "COCA COLA ZERO 0.35"}


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


def seq(s):
    return [(c["path"].split("/")[0] if c["path"].startswith("address") else c["path"], c["action"]) for c in s.calls]


def count_header(**over):
    h = copy.deepcopy(FX["count_header_edit"])
    h["1106"]["header"][0].update(over)
    return h


def count_line(**over):
    d = copy.deepcopy(FX["count_details_new"])
    d["1106"]["details"][0].update(over)
    return d


# ── Guarda ───────────────────────────────────────────────────────────────────

@pytest.mark.parametrize("allowed", [["999999"], ["0"]])
def test_guard_refuses_before_any_request(allowed):
    s = FakeSession([])
    with pytest.raises(RuntimeError, match="escrita recusada"):
        client(s).write_count(BO_STORE, BO_WH, "2026-10-16", "8002", [{**A, "physical_qnt": 8}], allowed)
    with pytest.raises(RuntimeError, match="escrita recusada"):
        client(s).write_waste(CC_WH, [{**A, "qnt": 1}], "", "8002", allowed)
    assert s.calls == []


@pytest.mark.parametrize("path,action", [("1105", "NEW"), ("1106/header", "MERGE"), ("1106", "DELETE"), ("1100/details", "DELETE"), ("document/1/header", "OPEN,EDIT,SAVE,CLOSE")])
def test_only_protocol_actions_pass(path, action):
    s = FakeSession([])
    with pytest.raises(RuntimeError, match="fora do protocolo"):
        client(s)._sd(path, action)
    assert s.calls == []


# ── Contagem ─────────────────────────────────────────────────────────────────

def count_happy(save=None, details_after_edit=None):
    line = count_line()
    edited = count_line(physical_qnt=8.0, qnt_difference=-5104.0, price_difference=-2728.47)
    return [
        FakeResp(200, {}, headers={"ObjectID": "OID-C"}),          # NEW
        ok({"1106": {}}),                                           # GET,INFO
        ok(FX["count_store"]),                                      # EDIT,GET loja
        ok(FX["count_header_edit"]),                                # EDIT armazém
        ok(count_header(inventory_date="20261016T00:00:00")),       # EDIT data e estado
        ok(line),                                                   # NEW linhas
        ok(line),                                                   # GET,INFO linhas
        ok(edited),                                                 # EDIT physical_qnt
        ok(details_after_edit or edited),                           # GET,INFO linhas
        ok({}),                                                     # MERGE
        save or ok({"1106": {}}),                                   # SAVE
        ok({}),                                                     # CLOSE / CANCEL
    ]


def test_count_sequence_payloads_like_the_har():
    s = FakeSession(count_happy())
    out = client(s).write_count(BO_STORE, BO_WH, "2026-10-16", "8002", [{**A, "physical_qnt": 8, "unit_id": "11001"}], [])
    assert seq(s) == [("1106", "NEW"), ("1106/" + MyCloudPieClient._COUNT_GET, "GET,INFO"), ("1106/header/warehouse,docserie/", "EDIT,GET"),
                      ("1106/header", "EDIT"), ("1106/header", "EDIT"), ("1106/details", "NEW"), ("1106/details", "GET,INFO"), ("1106/details", "EDIT"),
                      ("1106/details", "GET,INFO"), ("1106/header,details", "MERGE"), ("1106", "SAVE"), ("1106", "CLOSE")]
    assert {c["oid"] for c in s.calls[1:]} == {"OID-C"}
    assert s.calls[2]["body"] == [{"key": "00000000", "store_id": BO_STORE}]
    assert s.calls[3]["body"] == [{"key": "00000000", "warehouse_id": BO_WH}]
    assert s.calls[4]["body"] == [{"key": "00000000", "inventory_date": "20261016T00:00:00", "docstatus_id": "8002"}]
    assert s.calls[5]["body"] == [{"product_id": A["product_id"], "code": "100415", "description": "COCA COLA ZERO 0.35"}]
    assert s.calls[7]["body"] == [{"key": "00000000", "physical_qnt": "8.000"}]
    merge = s.calls[9]["body"]
    assert list(merge.keys()) == ["header", "details"] == list(FX["count_merge_req"].keys())
    assert merge["details"][0]["physical_qnt"] == 8.0 and set(merge["details"][0]) == set(FX["count_merge_req"]["details"][0])
    assert out["ok"] and out["doc_number"] == "CNTW BOCNTW/1" and out["lines"][0]["qnt_difference"] == -5104.0
    assert "novas_linhas_num_pedido=1" in out["steps"]


def test_count_lines_missing_from_the_batch_new_are_created_one_by_one():
    b = {"product_id": "584955579139631980", "code": "100416", "description": "COCA COLA 0.35"}
    first = count_line()
    second = count_line(key="00000001", product_id=b["product_id"], product_code="100416")
    both = copy.deepcopy(first)
    both["1106"]["details"].append(second["1106"]["details"][0])
    edited = copy.deepcopy(both)
    s = FakeSession([
        FakeResp(200, {}, headers={"ObjectID": "OID-C"}), ok({"1106": {}}), ok(FX["count_store"]), ok(FX["count_header_edit"]),
        ok(count_header(inventory_date="20261016T00:00:00")),
        ok(first), ok(second),                         # o NEW com as 2 só criou 1: a outra vai sozinha
        ok(both), ok(first), ok(second), ok(edited), ok({}), ok({"1106": {}}), ok({}),
    ])
    out = client(s).write_count(BO_STORE, BO_WH, "2026-10-16", "8002", [{**A, "physical_qnt": 10}, {**b, "physical_qnt": 5}], [])
    assert out["ok"]
    news = [c for c in s.calls if c["path"] == "1106/details" and c["action"] == "NEW"]
    assert len(news[0]["body"]) == 2 and news[1]["body"] == [b]


def test_count_save_500_cancels_and_says_nothing_was_saved():
    s = FakeSession(count_happy(save=FakeResp(500, FX["count_save_500"])))
    out = client(s).write_count(BO_STORE, BO_WH, "2026-10-16", "8002", [{**A, "physical_qnt": 8}], [])
    assert out["ok"] is False and out["saved"] is False
    assert out["error"] == "Existem documentos que estão num estado em que não atualizam stock. O documento não foi gravado no PingWin."
    assert seq(s)[-1] == ("1106", "CANCEL")


def test_count_unit_mismatch_stops_before_saving():
    s = FakeSession(count_happy()[:7] + [ok({})])    # … GET,INFO linhas → CANCEL
    out = client(s).write_count(BO_STORE, BO_WH, "2026-10-16", "8002", [{**A, "physical_qnt": 2, "unit_id": "GRD24"}], [])
    assert out["unit_mismatch"] is True and out["saved"] is False and "100415" in out["error"]
    assert ("1106", "SAVE") not in seq(s) and seq(s)[-1] == ("1106", "CANCEL")


def test_count_warehouse_not_visible_for_the_store():
    s = FakeSession(count_happy()[:3] + [ok({})])
    out = client(s).write_count(BO_STORE, "999", "2026-10-16", "8002", [{**A, "physical_qnt": 2}], [])
    assert out["ok"] is False and "não aparece" in out["error"] and seq(s)[-1] == ("1106", "CANCEL")


# ── Desperdício ──────────────────────────────────────────────────────────────

def waste_seq(entity_ok=True):
    get = copy.deepcopy(FX["waste_get"])
    get["1100"]["header"][0]["entity_id"] = CC_WH
    header_test = copy.deepcopy(FX["waste_get"]["1100"]["header"][0])
    header_test["entity_id"] = BO_WH if entity_ok else CC_WH
    line = copy.deepcopy(FX["waste_details_new"])
    line["1100"]["details"][0]["warehouse_id"] = BO_WH
    dh = copy.deepcopy(FX["waste_details_header"])
    dh["1100"]["details"][0]["warehouse_id"] = BO_WH
    dh["1100"]["header"][0]["entity_id"] = BO_WH
    return [
        FakeResp(200, {}, headers={"ObjectID": "OID-D"}),             # NEW
        ok(get),                                                        # GET,INFO
        ok({"1100": {"header": [header_test]}}),                        # EDIT entity_id
        ok({"address": {"maindataset": []}}),                           # address OPEN,GET,CLOSE
        ok(line), ok(line),                                             # NEW linha, EDIT qnt
        ok(dh),                                                         # GET details,header
        ok(FX["waste_verify"]), ok({"1100": {"validation": []}}),       # verifydoc, validation
        ok({"1100": {k: FX["waste_get"]["1100"][k] for k in ("entity_address.address", "expedition_address.address", "delivery_address.address")}}),
        ok({}),                                                         # MERGE moradas
        ok({"1100": {"header": dh["1100"]["header"]}}),                 # EDIT cabeçalho
        ok(FX["waste_verify"]),                                         # verifydoc,alternativedocs
        ok({"1100": {}}), ok({}),                                       # SAVE, CLOSE
    ]


def test_waste_picks_the_warehouse_through_the_entity_and_echoes_the_header():
    s = FakeSession(waste_seq())
    out = client(s).write_waste(BO_WH, [{**A, "qnt": 1, "obs": "Partido ou entornado · Ana"}], "XPLENDOR · Desperdício · 1 registo", "8002", [])
    acts = seq(s)
    assert acts[0] == ("1100", "NEW") and acts[2] == ("1100/header", "EDIT") and acts[-2:] == [("1100", "SAVE"), ("1100", "CLOSE")]
    assert s.calls[2]["body"] == [{"key": FX["waste_get"]["1100"]["header"][0]["key"], "entity_id": BO_WH}]
    assert s.calls[3]["body"] == {"params": {"querystring": f"entity_id = {BO_WH} AND deleted = 0"}}
    assert s.calls[4]["body"] == [{"product_id": A["product_id"]}]
    assert s.calls[5]["body"] == [{"key": FX["waste_details_new"]["1100"]["details"][0]["key"], "qnt": "1.000", "obs": "Partido ou entornado · Ana"}]
    edit = next(c for c in s.calls if c["path"] == "1100/header" and isinstance(c["body"], list) and "docstatus_id" in c["body"][0])["body"][0]
    assert set(edit) == set(FX["waste_header_edit_req"][0])            # o eco do browser, sem total/paid/moradas
    assert edit["obs"] == "XPLENDOR · Desperdício · 1 registo" and edit["docstatus_id"] == "8002"
    assert out["ok"] and out["lines"][0]["warehouse_id"] == BO_WH


def test_waste_refuses_when_the_header_keeps_another_warehouse():
    s = FakeSession(waste_seq(entity_ok=False)[:3] + [ok({})])
    with pytest.raises(RuntimeError, match="não aceitou o armazém"):
        client(s).write_waste(BO_WH, [{**A, "qnt": 1}], "", "8002", [])
    assert seq(s)[-1] == ("1100", "CANCEL")


# ── Estado e movimentos ──────────────────────────────────────────────────────

def test_void_checks_the_new_status():
    s = FakeSession([ok(FX["void_res"])])
    assert client(s).change_stock_document_status("584955579139803088", "8003")["ok"] is True
    assert s.calls[0]["path"] == "document/584955579139803088/header" and s.calls[0]["body"] == [{"docstatus_id": "8003"}]
    s = FakeSession([ok({"document": {"header": [{"docstatus_id": "8002"}]}})])
    assert client(s).change_stock_document_status("1", "8003")["ok"] is False


def test_movement_parse_keeps_the_article_code_and_signal():
    m = MyCloudPieClient.parse_movement({"doctype": "Guia de ativos próprios", "docconfig_id": 1102, "document": "GA BO/1", "doc_date": "20260901T00:00:00",
                                         "product_code": "COLAZERO35", "bom_code": "100415", "unit_description": "Grade 24", "qnt": "2", "stk_signal": "-1",
                                         "warehouse_id": 584955579139649883, "docstatus": "Fechado"})
    assert (m["doc_date"], m["docconfig_id"], m["qnt"], m["stk_signal"], m["bom_code"]) == ("2026-09-01", "1102", 2.0, -1, "100415")


def test_run_modes_route_to_the_client(monkeypatch):
    seen = {}

    class FakeClient:
        def __enter__(self):
            return self

        def __exit__(self, *a):
            return False

        def write_count(self, store, wid, date, status, lines, allowed):
            seen["count"] = (store, wid, date, status, len(lines), allowed)
            return {"ok": True, "saved": True}

        def change_stock_document_status(self, doc, status):
            seen["status"] = (doc, status)
            return {"ok": True}

    monkeypatch.setattr(runner, "build_client", lambda **kw: FakeClient())
    assert runner.run({"mode": "count_write", "store_id": BO_STORE, "warehouse_id": BO_WH, "inventory_date": "2026-10-16", "lines": [A], "allowed_codes": ["100415"]})["ok"]
    assert seen["count"] == (BO_STORE, BO_WH, "2026-10-16", "8002", 1, ["100415"])
    assert runner.run({"mode": "stockdoc_status", "doc_id": "9", "status": "8003", "allowed_codes": ["0"]})["ok"] is False
    assert "status" not in seen
