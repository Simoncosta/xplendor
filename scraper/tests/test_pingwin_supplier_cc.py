"""
XPLENDOR — Conta corrente de fornecedor (S1, SÓ LEITURA). Prova, sem rede:
  · caminho /service/suppliercc/*/ccdocuments e */ccbalance, Action OPEN,GET,CLOSE,
    período largo fixo (20000101 → 21001231);
  · 5xx → repete → ok;
  · lista vazia com saldo ≠ 0 (incoerente) → repete → falha nesse fornecedor;
  · lista vazia com saldo 0 é coerente (fornecedor sem movimentos) → ok;
  · um fornecedor que falha NÃO aborta o lote;
  · modo supplier_cc do run.py (sem entity_ids → ok:false; com → delega).
"""
import os
import sys
from contextlib import contextmanager

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

import sources.pingwin.run as runner  # noqa: E402
from sources.pingwin.mycloudpie import MyCloudPieClient  # noqa: E402


class FakeResp:
    def __init__(self, status=200, payload=None, text=""):
        self.status_code = status
        self._payload = payload
        self.text = text or str(payload)

    def json(self):
        return self._payload


def docs_resp(docs):
    return FakeResp(200, {"suppliercc": {"ccdocuments": docs}})


def bal_resp(balance):
    return FakeResp(200, {"suppliercc": {"ccbalance": [{"key": "00000000", "balance": balance,
                                                        "overdue_balance": 0.0, "suspended_balance": 0.0}]}})


class FakeSession:
    """Responde por ordem a partir de uma fila por dataset (ccdocuments / ccbalance)."""

    def __init__(self, queues):
        self.queues = {k: list(v) for k, v in queues.items()}
        self.calls = []

    def post(self, url, json=None, headers=None, **kw):
        self.calls.append({"url": url, "json": json, "headers": headers})
        dataset = url.rsplit("/", 1)[-1]
        return self.queues[dataset].pop(0)


def make_client(session):
    c = MyCloudPieClient.__new__(MyCloudPieClient)
    c.api_url = "https://srv-soa.example:8136"
    c.session = session
    c.CC_RETRY_DELAY_S = 0  # sem esperas nos testes
    return c


DOC = {"docheader_id": "1", "docconfig_id": "1209", "total": 52.55, "topay": 52.55, "ca_signal": 1}


def test_ok_caminho_action_e_periodo():
    s = FakeSession({"ccdocuments": [docs_resp([DOC])], "ccbalance": [bal_resp(52.55)]})
    out = make_client(s).fetch_supplier_cc(["584955579139737427"])

    assert out == [{"entity_id": "584955579139737427", "ok": True, "documents": [DOC],
                    "balance": {"key": "00000000", "balance": 52.55, "overdue_balance": 0.0, "suspended_balance": 0.0},
                    "attempts": 1}]
    docs_call, bal_call = s.calls
    assert docs_call["url"] == "https://srv-soa.example:8136/service/suppliercc/*/ccdocuments"
    assert bal_call["url"] == "https://srv-soa.example:8136/service/suppliercc/*/ccbalance"
    assert docs_call["headers"]["Action"] == "OPEN,GET,CLOSE"
    assert bal_call["headers"]["Action"] == "OPEN,GET,CLOSE"
    assert docs_call["json"] == {"params": {"entity_id": "584955579139737427",
                                            "start_date": "20000101T00:00:00", "end_date": "21001231T00:00:00"}}
    assert bal_call["json"] == {"params": {"entity_id": "584955579139737427", "cc_date": "21001231T00:00:00"}}


def test_5xx_repete_e_recupera():
    s = FakeSession({"ccdocuments": [FakeResp(500, text="1411"), docs_resp([DOC])], "ccbalance": [bal_resp(52.55)]})
    out = make_client(s).fetch_supplier_cc(["e1"])

    assert out[0]["ok"] is True
    assert [c["url"].rsplit("/", 1)[-1] for c in s.calls] == ["ccdocuments", "ccdocuments", "ccbalance"]


def test_5xx_persistente_falha_so_esse_fornecedor():
    s = FakeSession({"ccdocuments": [FakeResp(500, text="x")] * 3, "ccbalance": []})
    out = make_client(s).fetch_supplier_cc(["e1"])

    assert out[0]["ok"] is False
    assert "HTTP 500" in out[0]["error"]
    assert len(s.calls) == 3  # 3 tentativas, nunca chega ao ccbalance


def test_vazio_incoerente_repete_e_falha():
    s = FakeSession({"ccdocuments": [docs_resp([])] * 3, "ccbalance": [bal_resp(146.55)] * 3})
    out = make_client(s).fetch_supplier_cc(["e1"])

    assert out == [{"entity_id": "e1", "ok": False,
                    "error": "RuntimeError: lista vazia incoerente: ccbalance=146.55 sem documentos"}]
    assert len(s.calls) == 6  # 3 tentativas × (docs + saldo)


def test_vazio_incoerente_recupera_na_repeticao():
    s = FakeSession({"ccdocuments": [docs_resp([]), docs_resp([DOC])], "ccbalance": [bal_resp(52.55), bal_resp(52.55)]})
    out = make_client(s).fetch_supplier_cc(["e1"])

    assert out[0]["ok"] is True
    assert out[0]["attempts"] == 2


def test_vazio_coerente_com_saldo_zero_e_ok():
    s = FakeSession({"ccdocuments": [docs_resp([])], "ccbalance": [bal_resp(0.0)]})
    out = make_client(s).fetch_supplier_cc(["e1"])

    assert out[0]["ok"] is True
    assert out[0]["documents"] == []


def test_falha_num_fornecedor_nao_aborta_o_lote():
    s = FakeSession({
        "ccdocuments": [FakeResp(400, text="bad"), docs_resp([DOC])],
        "ccbalance": [bal_resp(52.55)],
    })
    out = make_client(s).fetch_supplier_cc(["mau", "bom"])

    assert [o["entity_id"] for o in out] == ["mau", "bom"]
    assert out[0]["ok"] is False and "HTTP 400" in out[0]["error"]
    assert out[1]["ok"] is True


def test_run_modo_supplier_cc(monkeypatch):
    class FakeClient:
        def fetch_supplier_cc(self, ids):
            return [{"entity_id": i, "ok": True, "documents": [], "balance": {"balance": 0}} for i in ids]

    @contextmanager
    def fake_build_client(**kw):
        yield FakeClient()

    monkeypatch.setattr(runner, "build_client", fake_build_client)

    assert runner.run({"mode": "supplier_cc", "entity_ids": []})["ok"] is False
    out = runner.run({"mode": "supplier_cc", "entity_ids": ["a", 2]})
    assert out["ok"] is True and out["mode"] == "supplier_cc"
    assert [s["entity_id"] for s in out["suppliers"]] == ["a", "2"]
