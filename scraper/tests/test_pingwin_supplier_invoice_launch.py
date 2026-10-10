"""
XPLENDOR — FB-1: lançar a fatura de fornecedor no PingWin em RASCUNHO (8001). Prova, sem rede:
  · a sequência completa (NEW → GET header SEM corpo → EDIT fornecedor → linhas NEW/EDIT com a
    ÚLTIMA key → moradas GET sem corpo + MERGE tal como vieram → acerto → EDIT referência/acerto/
    8001 → verifydoc → SAVE → CLOSE);
  · troca do grupo de IVA quando a taxa do artigo não é a da fatura;
  · acerto acima do máximo → CLOSE SEM SAVE;
  · CLOSE em finally (também em erro) e SAVE sem resposta → saved "unknown";
  · mudança de estado num só pedido (só 8002/8003) e os modos do run.py.
"""
import json
import os
import sys

import requests

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

import sources.pingwin.run as runner  # noqa: E402
from sources.pingwin.mycloudpie import MyCloudPieClient  # noqa: E402

BASE = "https://srv-soa.example:8136"


class R:
    def __init__(self, status=200, payload=None, headers=None, text=None):
        self.status_code = status
        self._p = payload if payload is not None else {}
        self.headers = headers or {}
        self.text = text if text is not None else json.dumps(self._p)

    def json(self):
        return self._p


def k(ver, row=1):
    return f"00000001000000020000000{ver}000000000000000{row}"


class FakePW:
    """Servidor simulado: mantém o documento em memória e versiona as keys como o PingWin."""

    def __init__(self, line_tax="Reduzida", base_total="10.40", save_status=200, save_raises=False, fail_at=None):
        self.calls = []
        self.ver = 1
        self.lines = []
        self.header = {"key": k(1), "id": "DOC1", "doc_prefix": "VFT BOVFT", "doc_number": 1077, "store_id": "S", "store_code": "BO",
                       "docstatus_id": "8002", "total": "0"}
        self.line_tax = line_tax
        self.base_total = base_total
        self.save_status = save_status
        self.save_raises = save_raises
        self.fail_at = fail_at

    def post(self, url, data=None, headers=None, **kw):
        path = url.split("/service/", 1)[1]
        action = headers.get("Action")
        body = json.loads(data) if data else None
        self.calls.append({"path": path, "action": action, "body": body, "oid": headers.get("ObjectID"),
                           "ctype": headers.get("Content-Type")})
        if self.fail_at and self.fail_at == (path, action):
            raise requests.ConnectionError("rede")
        if path == "1209" and action == "NEW":
            return R(200, {"1209": {}}, {"ObjectID": "OID"})
        if path == "1209" and action == "CLOSE":
            return R(200, {"1209": {}})
        if path == "1209" and action == "SAVE":
            if self.save_raises:
                raise requests.Timeout("sem resposta")
            return R(self.save_status, {"1209": {}} if self.save_status == 200 else {"code": 500, "message": "Documento em branco!"})
        if path == "1209/header,doctaxes.taxgroup":
            return R(200, {"1209": {"header": [self.header], "doctaxes.taxgroup": [
                {"id": "1003001", "description": "Normal"}, {"id": "1003002", "description": "Intermédia"},
                {"id": "1003003", "description": "Reduzida"}, {"id": "1003004", "description": "Isenta"}]}})
        if path == "1209/header" and action == "EDIT":
            row = body[0]
            assert row["key"] == self.header["key"], f"key antiga no header: {row['key']}"
            self.ver += 1
            self.header = {**self.header, **{x: v for x, v in row.items() if x != "key"}, "key": k(self.ver)}
            if "adjustment" in row:
                self.header["total"] = str(round(float(self.base_total) + float(row["adjustment"]), 2))
            return R(200, {"1209": {"header": [self.header]}})
        if path == "1209/header" and action == "GET,INFO":
            return R(200, {"1209": {"header": [{**self.header, "total": self.base_total}]}})
        if path == "1209/details" and action == "NEW":
            n = len(self.lines) + 1
            line = {"key": k(1, n), "product_id": body[0]["product_id"], "unit_id": body[0]["unit_id"], "price": 1.0, "qnt": 1.0,
                    "taxgroup_id": "1003003", "tax_description": self.line_tax, "stk_qnt": 5.0}
            self.lines.append(line)
            return R(200, {"1209": {"details": [line]}})
        if path == "1209/details" and action == "EDIT":
            row = body[0]
            line = next(x for x in self.lines if x["key"] == row["key"])  # key antiga → StopIteration
            line.update({x: v for x, v in row.items() if x != "key"})
            ver = int(line["key"][23]) + 1
            line["key"] = line["key"][:23] + str(ver) + line["key"][24:]
            if "taxgroup_id" in row:
                line["tax_description"] = {"1003001": "Normal", "1003003": "Reduzida"}[row["taxgroup_id"]]
            return R(200, {"1209": {"details": [line]}})
        if path.startswith("1209/entity_address.address") and action == "GET,INFO":
            return R(200, {"1209": {"entity_address.address": [{"key": "E", "id": "A1"}], "expedition_address.address": [{"key": "X", "id": "A1"}],
                                    "delivery_address.address": [{"key": "D", "id": "LOJA"}]}})
        if path.startswith("1209/entity_address.address") and action == "MERGE":
            return R(200, {"1209": body})
        if path == "1209/fiscalrules.verifydoc":
            return R(200, {"1209": {"fiscalrules.verifydoc": [{"code": 0, "msg": "OK"}]}})
        if path.startswith("document/"):
            return R(200, {"document": {"header": [{"docstatus_id": body[0]["docstatus_id"], "doc_number": 1077}]}})
        raise AssertionError(f"pedido inesperado {path} {action}")


def client(fake):
    c = MyCloudPieClient.__new__(MyCloudPieClient)
    c.api_url = BASE
    c.session = fake
    c.database = "db"
    c.app_grupopie = "grp"
    return c


DOC = {"docconfig_id": "1209", "entity_id": "SUP1", "docstatus_id": "8001", "target_total": "10.43", "max_adjustment": "0.05",
       "docreference_id": "1649601156562", "docreference_number": "FT FAR.2026/379-ESTE-TEXTO-E-MUITO-LONGO",
       "docreference_date": "20261005T00:00:00",
       "lines": [{"product_id": "P1", "unit_id": "11002", "qnt": "2.000000", "price": "1.415000", "discount1": "0", "vat_rate": 6},
                 {"product_id": "P2", "unit_id": "11001", "qnt": "16.175000", "price": "0.250000", "discount1": "10", "vat_rate": 23}]}


def test_full_sequence_draft_with_versioned_keys_merge_and_adjustment():
    f = FakePW()
    out = client(f).launch_supplier_invoice(DOC)

    assert out["ok"] is True and out["saved"] is True and out["closed"] is True
    assert out["docheader_id"] == "DOC1" and out["document"] == "VFT BOVFT/1077"
    seq = [(c["path"].split(",")[0], c["action"]) for c in f.calls]
    assert seq == [
        ("1209", "NEW"), ("1209/header", "GET,INFO"), ("1209/header", "EDIT"),
        ("1209/details", "NEW"), ("1209/details", "EDIT"), ("1209/details", "NEW"), ("1209/details", "EDIT"),
        ("1209/entity_address.address", "GET,INFO"), ("1209/entity_address.address", "MERGE"),
        ("1209/header", "GET,INFO"), ("1209/header", "EDIT"), ("1209/fiscalrules.verifydoc", "GET,INFO"),
        ("1209", "SAVE"), ("1209", "CLOSE"),
    ]
    # GET dos blocos SEM corpo e sem Content-Type; o mesmo ObjectID depois do NEW
    gets = [c for c in f.calls if c["action"] == "GET,INFO" and "verifydoc" not in c["path"]]
    assert all(c["body"] is None and c["ctype"] is None for c in gets)
    assert all(c["oid"] == "OID" for c in f.calls[1:])
    # linha: preço/quantidade como string com 6 casas; IVA trocado na linha 2 (Reduzida → Normal 23%)
    e1, e2 = [c["body"][0] for c in f.calls if c["path"] == "1209/details" and c["action"] == "EDIT"]
    assert (e1["price"], e1["qnt"]) == ("1.415000", "2.000000") and "taxgroup_id" not in e1
    assert e2["taxgroup_id"] == "1003001" and e2["discount1"] == "10"
    assert out["lines"][1]["tax_override"] == {"from": "1003003", "to": "1003001"}
    # MERGE tal como veio (morada de entrega da loja)
    merge = next(c for c in f.calls if c["action"] == "MERGE")["body"]
    assert merge["delivery_address.address"] == [{"key": "D", "id": "LOJA"}]
    # acerto 10,43 − 10,40 = 0,03; referência cortada a 25; rascunho
    final = [c for c in f.calls if c["path"] == "1209/header" and c["action"] == "EDIT"][-1]["body"][0]
    assert final["adjustment"] == "0.03" and final["docstatus_id"] == "8001"
    assert final["docreference_number"] == "FT FAR.2026/379-ESTE-TEXT" and len(final["docreference_number"]) == 25
    assert out["adjustment"] == "0.03"


def test_adjustment_above_max_closes_without_save():
    f = FakePW(base_total="10.00")  # alvo 10,43 → acerto 0,43 > 0,05
    out = client(f).launch_supplier_invoice(DOC)
    assert out["saved"] is False and out["reason"] == "acerto" and out["adjustment"] == "0.43"
    actions = [(c["path"], c["action"]) for c in f.calls]
    assert ("1209", "SAVE") not in actions
    assert actions[-1] == ("1209", "CLOSE")


def test_close_in_finally_on_error_and_save_refused():
    f = FakePW(fail_at=("1209/details", "NEW"))
    out = client(f).launch_supplier_invoice(DOC)
    assert out["saved"] is False and "ConnectionError" in out["error"] and out["closed"] is True
    assert f.calls[-1]["action"] == "CLOSE"

    f = FakePW(save_status=500)
    out = client(f).launch_supplier_invoice(DOC)
    assert out["saved"] is False and "Documento em branco!" in out["error"]
    assert f.calls[-1]["action"] == "CLOSE"


def test_save_without_answer_is_unknown_never_false():
    f = FakePW(save_raises=True)
    out = client(f).launch_supplier_invoice(DOC)
    assert out["saved"] == "unknown" and out["ok"] is False
    assert f.calls[-1]["action"] == "CLOSE"


def test_only_draft_and_guards():
    f = FakePW()
    assert client(f).launch_supplier_invoice({**DOC, "docstatus_id": "8004"})["saved"] is False
    assert client(f).launch_supplier_invoice({**DOC, "lines": []})["error"]
    assert f.calls == []  # nada foi pedido ao PingWin


def test_status_change_single_request_only_8002_8003():
    f = FakePW()
    c = client(f)
    assert c.set_document_status("DOC1", "8004")["ok"] is False and f.calls == []
    out = c.set_document_status("DOC1", "8002")
    assert out == {"ok": True, "docstatus_id": "8002", "doc_number": 1077}
    assert f.calls == [{"path": "document/DOC1/header", "action": "OPEN,EDIT,SAVE,CLOSE", "body": [{"docstatus_id": "8002"}],
                        "oid": None, "ctype": "application/json;charset=UTF-8"}]


def test_run_modes(monkeypatch):
    class FakeClient:
        def __enter__(self):
            return self

        def __exit__(self, *a):
            return False

        def launch_supplier_invoice(self, doc):
            return {"ok": True, "saved": True, "docheader_id": "DOC1", "docconfig_id": "1209"}

        def set_document_status(self, dh, st):
            return {"ok": True, "docstatus_id": st}

        def fetch_supplier_document_lines(self, docs):
            return [{"docheader_id": docs[0]["docheader_id"], "ok": True, "header": {"docstatus_id": "8001"}}]

    monkeypatch.setattr(runner, "build_client", lambda **kw: FakeClient())
    monkeypatch.setattr(runner, "build_kwargs", lambda cfg: {})
    monkeypatch.setattr(runner, "check_hosts_allowed", lambda cfg: None)
    res = runner.run({"mode": "launch_supplier_invoice", "doc": DOC})
    assert res["result"]["saved"] is True and res["reread"]["header"]["docstatus_id"] == "8001"
    res = runner.run({"mode": "document_status", "docheader_id": "DOC1", "docstatus_id": "8003"})
    assert res["result"]["docstatus_id"] == "8003" and res["reread"]["ok"] is True
    assert runner.run({"mode": "document_status"})["ok"] is False
