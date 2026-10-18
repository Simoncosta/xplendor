"""
XPLENDOR — Documentos de caixa (Auditoria v2-C · C3, SÓ LEITURA). Prova, sem rede:
  · um dia e um tipo de cada vez (DOCCONFIG_ID, DOCTYPE vazio, FILTER_DATE_BY 0);
  · só os tipos da lista de leitura; no máximo 31 dias por chamada;
  · uma linha de outro tipo na resposta não entra; dedupe por id;
  · modo pos_documents do run.py.
"""
import os
import sys
from contextlib import contextmanager
from datetime import date

import pytest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

import sources.pingwin.run as runner  # noqa: E402
from sources.pingwin.mycloudpie import MyCloudPieClient  # noqa: E402


class FakeResp:
    status_code = 200

    def __init__(self, items):
        self._items = items

    def json(self):
        return {"browser": {"browserdataset": self._items}}


class FakeSession:
    def __init__(self, rows):
        self.rows = rows  # {(dia, tipo): [linhas]}
        self.calls = []

    def post(self, url, json=None, headers=None, **kw):
        p = json["params"]
        self.calls.append({"url": url, "headers": headers, "params": p})
        a, b = (int(x) for x in headers["Range"].split("=")[1].split("-"))
        items = self.rows.get((p["START_DATE"][:8], p["DOCCONFIG_ID"]), [])
        return FakeResp(items[a:b])


def client(session):
    c = MyCloudPieClient.__new__(MyCloudPieClient)
    c.api_url = "https://srv-soa.example:8136"
    c.session = session
    c.SUPDOCS_PAGE_SIZE = 1000
    c.SUPDOCS_MAX_PAGES = 10
    return c


def test_um_dia_e_um_tipo_de_cada_vez():
    s = FakeSession({
        ("20261016", "1002"): [{"id": "a1", "docconfig_id": "1002", "employee": "X"}],
        ("20261017", "1002"): [{"id": "a2", "docconfig_id": "1002"}, {"id": "a2", "docconfig_id": "1002"}],
        ("20261017", "1031"): [{"id": "c1", "docconfig_id": "1031"}, {"id": "z9", "docconfig_id": "1018"}],
    })
    out = client(s).fetch_pos_documents("1099511639269", date(2026, 10, 16), date(2026, 10, 17), ["1002", "1031"])

    assert out["calls"] == 4
    assert sorted(d["id"] for d in out["documents"]) == ["a1", "a2", "c1"]
    p = s.calls[0]["params"]
    assert p["DOCTYPE"] == "" and p["DOCCONFIG_ID"] == "1002" and p["FILTER_DATE_BY"] == "0"
    assert p["START_DATE"] == "20261016T00:00:00" and p["END_DATE"] == "20261016T00:00:00"
    assert s.calls[0]["headers"]["Action"] == "OPEN,GET,INFO,CLOSE"


def test_so_tipos_da_lista_e_no_maximo_31_dias():
    c = client(FakeSession({}))
    with pytest.raises(ValueError, match="fora da lista"):
        c.fetch_pos_documents("ds", date(2026, 10, 1), date(2026, 10, 1), ["1209"])
    with pytest.raises(ValueError, match="31 dias"):
        c.fetch_pos_documents("ds", date(2026, 9, 1), date(2026, 10, 15))
    with pytest.raises(ValueError, match="fim antes"):
        c.fetch_pos_documents("ds", date(2026, 10, 2), date(2026, 10, 1))


def test_run_modo_pos_documents(monkeypatch):
    seen = {}

    class FakeClient:
        def fetch_pos_documents(self, ds, start, end, docconfigs=None):
            seen.update(ds=ds, start=start, end=end, docconfigs=docconfigs)
            return {"documents": [{"id": "1"}], "calls": 2}

    @contextmanager
    def fake_build_client(**kw):
        yield FakeClient()

    monkeypatch.setattr(runner, "build_client", fake_build_client)

    assert runner.run({"mode": "pos_documents", "start": "2026-10-05", "end": "2026-10-05"})["ok"] is False
    assert runner.run({"mode": "pos_documents", "supplier_documents_dataset_id": "ds", "start": "x", "end": "y"})["ok"] is False
    out = runner.run({"mode": "pos_documents", "supplier_documents_dataset_id": "ds", "start": "2026-10-05", "end": "2026-10-06", "docconfigs": ["1002"]})
    assert out == {"ok": True, "mode": "pos_documents", "start": "2026-10-05", "end": "2026-10-06", "documents": [{"id": "1"}], "calls": 2}
    assert seen == {"ds": "ds", "start": date(2026, 10, 5), "end": date(2026, 10, 6), "docconfigs": ["1002"]}
