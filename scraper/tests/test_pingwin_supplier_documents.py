"""
XPLENDOR — Documentos de fornecedor (F1, SÓ LEITURA). Prova, sem rede:
  · período cortado em blocos de 7 dias, datas e body do HAR (DOCTYPE 2002, FILTER_DATE_BY 0);
  · paginação corrigida por bloco (Range items=0-1000, …);
  · bloco que bate na trava de páginas divide-se ao meio até caber;
  · um dia sozinho acima da trava → erro (nunca aceita bloco cortado);
  · 5xx → repete → ok; 5xx persistente → erro; 4xx → erro sem repetir;
  · dedupe por id; modo supplier_documents do run.py.
"""
import os
import sys
from contextlib import contextmanager
from datetime import date

import pytest
import requests

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

import sources.pingwin.run as runner  # noqa: E402
from sources.pingwin.mycloudpie import MyCloudPieClient  # noqa: E402


class FakeResp:
    def __init__(self, status=200, items=None):
        self.status_code = status
        self._items = items or []
        self.text = f"HTTP {status}"

    def json(self):
        return {"browser": {"browserdataset": self._items}}

    def raise_for_status(self):
        raise requests.HTTPError(f"HTTP {self.status_code}", response=self)


class FakeSession:
    """Gera N documentos por dia a partir de `per_day` ({'YYYYMMDD': n}); respeita o
    Range (fim exclusivo, como o servidor); `fail` = lista de status a devolver antes."""

    def __init__(self, per_day=None, fail=None):
        self.per_day = per_day or {}
        self.fail = list(fail or [])
        self.calls = []

    def post(self, url, json=None, headers=None, **kw):
        p = json["params"]
        self.calls.append({"url": url, "headers": headers, "start": p["START_DATE"][:8], "end": p["END_DATE"][:8], "params": p})
        if self.fail:
            return FakeResp(self.fail.pop(0))
        a, b = (int(x) for x in headers["Range"].split("=")[1].split("-"))
        items = [{"id": f"{d}-{i}", "doc_date": d} for d, n in sorted(self.per_day.items())
                 if p["START_DATE"][:8] <= d <= p["END_DATE"][:8] for i in range(n)]
        self.calls[-1]["n"] = len(items[a:b])
        return FakeResp(200, items[a:b])


def client(session, page_size=1000, max_pages=10):
    c = MyCloudPieClient.__new__(MyCloudPieClient)
    c.api_url = "https://srv-soa.example:8136"
    c.session = session
    c.SUPDOCS_PAGE_SIZE = page_size
    c.SUPDOCS_MAX_PAGES = max_pages
    c.SUPDOCS_RETRY_DELAY_S = 0
    return c


def test_blocos_de_7_dias_body_e_range():
    s = FakeSession({"20261005": 6, "20261012": 1})
    out = client(s).fetch_supplier_documents("1099511639269", date(2026, 10, 1), date(2026, 10, 16))

    assert [(c["start"], c["end"]) for c in s.calls] == [
        ("20261001", "20261007"), ("20261008", "20261014"), ("20261015", "20261016")]
    assert out["blocks"] == 3 and out["splits"] == 0
    assert len(out["documents"]) == 7
    call = s.calls[0]
    assert call["url"] == "https://srv-soa.example:8136/service/browser/1099511639269/browserdataset"
    assert call["headers"]["Action"] == "OPEN,GET,INFO,CLOSE"
    assert call["headers"]["Range"] == "items=0-1000"
    assert call["params"]["DOCTYPE"] == "2002"
    assert call["params"]["FILTER_DATE_BY"] == "0"
    assert call["params"]["START_DATE"] == "20261001T00:00:00"


def test_bloco_na_trava_divide_ao_meio_ate_caber():
    # páginas de 2, trava de 2 páginas → um bloco só cabe com ≤ 3 linhas.
    # 3 docs/dia num bloco de 7 dias = 21 → bate na trava → divide até caber.
    s = FakeSession({f"202610{d:02d}": 3 for d in range(1, 8)})
    out = client(s, page_size=2, max_pages=2).fetch_supplier_documents("ds", date(2026, 10, 1), date(2026, 10, 7))

    assert len(out["documents"]) == 21  # nada perdido
    assert len({d["id"] for d in out["documents"]}) == 21
    assert out["splits"] > 0
    assert out["blocks"] > 1


def test_dia_sozinho_acima_da_trava_falha():
    s = FakeSession({"20261005": 10})
    with pytest.raises(RuntimeError, match="não cabe na trava"):
        client(s, page_size=2, max_pages=2).fetch_supplier_documents("ds", date(2026, 10, 5), date(2026, 10, 5))


def test_5xx_repete_e_recupera():
    s = FakeSession({"20261005": 6}, fail=[500, 503])
    out = client(s).fetch_supplier_documents("ds", date(2026, 10, 5), date(2026, 10, 5))

    assert len(out["documents"]) == 6
    assert len(s.calls) == 3


def test_5xx_persistente_falha():
    s = FakeSession({"20261005": 6}, fail=[500, 500, 500])
    with pytest.raises(requests.HTTPError):
        client(s).fetch_supplier_documents("ds", date(2026, 10, 5), date(2026, 10, 5))
    assert len(s.calls) == 3


def test_4xx_nao_repete():
    s = FakeSession({"20261005": 6}, fail=[400])
    with pytest.raises(requests.HTTPError):
        client(s).fetch_supplier_documents("ds", date(2026, 10, 5), date(2026, 10, 5))
    assert len(s.calls) == 1


def test_run_modo_supplier_documents(monkeypatch):
    seen = {}

    class FakeClient:
        def fetch_supplier_documents(self, ds, start, end):
            seen.update(ds=ds, start=start, end=end)
            return {"documents": [{"id": "1"}], "blocks": 1, "splits": 0}

    @contextmanager
    def fake_build_client(**kw):
        yield FakeClient()

    monkeypatch.setattr(runner, "build_client", fake_build_client)

    assert runner.run({"mode": "supplier_documents", "start": "2026-10-05", "end": "2026-10-05"})["ok"] is False  # sem dataset
    assert runner.run({"mode": "supplier_documents", "supplier_documents_dataset_id": "ds", "start": "x", "end": "y"})["ok"] is False
    assert runner.run({"mode": "supplier_documents", "supplier_documents_dataset_id": "ds", "start": "2026-10-06", "end": "2026-10-05"})["ok"] is False
    out = runner.run({"mode": "supplier_documents", "supplier_documents_dataset_id": "ds", "start": "2026-10-01", "end": "2026-10-05"})
    assert out == {"ok": True, "mode": "supplier_documents", "start": "2026-10-01", "end": "2026-10-05",
                   "documents": [{"id": "1"}], "blocks": 1, "splits": 0}
    assert seen == {"ds": "ds", "start": date(2026, 10, 1), "end": date(2026, 10, 5)}
