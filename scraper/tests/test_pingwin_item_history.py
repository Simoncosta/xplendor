"""
XPLENDOR — F1-2: catálogo completo (fetch_browserdataset_complete) e o
acumulado mensal de uma loja (fetch_store_year), sem rede (sessões falsas).

Prova que:
  · a leitura completa do catálogo NÃO para numa página curta (o caso da Yuko: 1000 + 12 e o
    resto a seguir) e para numa página vazia;
  · para quando o servidor ignora o Range (só repetidos) ou no total do Content-Range;
  · a leitura família a família deixou de existir (os vendidos em falta procuram-se nos anulados);
  · o relatório anual vai com uma loja, o ano e GROUPBY 1, e lê a linha Acumulado.
"""
import base64
import json
import os
import sys

import pytest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from sources.pingwin import mycloudpie  # noqa: E402
from sources.pingwin.mycloudpie import MyCloudPieClient, announced_total  # noqa: E402


class FakeResponse:
    def __init__(self, body, status_code=200, headers=None):
        self.status_code = status_code
        self._body = body
        self.headers = headers or {}
        self.text = ""

    def json(self):
        return self._body

    def raise_for_status(self):
        raise AssertionError(f"HTTP {self.status_code}")


def _items(a, b):
    return [{"id": str(i), "code": str(i)} for i in range(a, b)]


class CatalogSession:
    """Catálogo de `total` artigos. `short_after`: a página que começa nesse índice vem
    curta (12 itens), mas as seguintes continuam (o caso da Yuko). `ignore_range`: devolve
    sempre a primeira página. `families`: {family_id: [ids]} para os pedidos por família."""
    def __init__(self, total, short_after=None, ignore_range=False, announce=False, families=None):
        self.total = total
        self.short_after = short_after
        self.ignore_range = ignore_range
        self.announce = announce
        self.families = families or {}
        self.posts = []
        self.headers = {}

    def mount(self, *_):
        pass

    def get(self, url, *args, **kwargs):
        fams = [{"id": fid, "deleted": 0} for fid in self.families] + [{"id": "999", "deleted": 1}]
        return FakeResponse({"family": {"maindataset": fams}})

    def post(self, url, *args, **kwargs):
        rng = kwargs.get("headers", {}).get("Range", "items=0-499")
        body = kwargs.get("json") or {}
        self.posts.append((rng, body))
        start, end = (int(x) for x in rng.split("=", 1)[1].split("-"))
        family = (body.get("params") or {}).get("FAMILY_ID", "")
        if family:
            ids = self.families.get(family, [])
            page = [{"id": str(i), "code": str(i)} for i in ids[start:end + 1]]
            return FakeResponse({"browser": {"browserdataset": page}})
        if self.ignore_range:
            start, end = 0, end - start
        stop = min(end + 1, self.total)
        if self.short_after is not None and start == self.short_after:
            stop = min(start + 12, self.total)
        headers = {"Content-Range": f"items {start}-{end}/{self.total}"} if self.announce else {}
        return FakeResponse({"browser": {"browserdataset": _items(start, stop)}}, headers=headers)


def _client(session):
    c = MyCloudPieClient(
        auth_url="https://auth.example", api_url="https://api.example",
        username="u", password="p", report_id="r", stores="1",
        frontend_url="https://fe.example", database="db", app_version="1",
    )
    c.session = session
    return c


@pytest.fixture(autouse=True)
def _no_sleep(monkeypatch):
    monkeypatch.setattr(mycloudpie.time, "sleep", lambda *_: None)


def test_short_page_in_the_middle_does_not_stop_or_skip():
    # O caso da Yuko: a página que começa em 1000 vem cortada (12 itens). O pedido
    # seguinte começa em 1012 (não em 1500) e o catálogo chega aos 1340.
    s = CatalogSession(total=1340, short_after=1000)
    c = _client(s)
    items = c.fetch_catalog_complete("DS")
    assert len(items) == 1340
    assert [r for r, _ in s.posts] == ["items=0-499", "items=500-999", "items=1000-1499",
                                       "items=1012-1511", "items=1340-1839"]
    assert c.last_catalog_diag["stopped"] == "página vazia"


def test_reads_everything_when_server_pages_normally():
    s = CatalogSession(total=1340)
    c = _client(s)
    items = c.fetch_catalog_complete("DS")
    assert len(items) == 1340
    assert c.last_catalog_diag["stopped"] == "página vazia"
    assert c.last_catalog_diag["collected"] == 1340


def test_stops_when_server_ignores_range():
    s = CatalogSession(total=5000, ignore_range=True)
    c = _client(s)
    items = c.fetch_catalog_complete("DS")
    assert len(items) == 500
    assert c.last_catalog_diag["stopped"] == "só repetidos"
    assert len(s.posts) == 2


def test_stops_at_announced_total():
    s = CatalogSession(total=1200, announce=True)
    c = _client(s)
    items = c.fetch_catalog_complete("DS")
    assert len(items) == 1200
    assert c.last_catalog_diag["announced_total"] == 1200
    assert c.last_catalog_diag["stopped"] == "total anunciado"
    assert len(s.posts) == 3  # sem pedir uma página vazia a mais


def test_complete_reader_no_longer_reads_family_by_family():
    s = CatalogSession(total=1012, families={"F1": list(range(1000, 1100))})
    c = _client(s)
    items = c.fetch_catalog_complete("DS")
    assert len(items) == 1012
    assert all(not (b.get("params") or {}).get("FAMILY_ID") for _, b in s.posts)
    assert "by_family" not in c.last_catalog_diag


def test_old_catalog_reader_is_unchanged():
    # Sem catalog_complete, a leitura de sempre (para na página curta, como antes).
    s = CatalogSession(total=1340, short_after=1000)
    c = _client(s)
    assert len(c.fetch_catalog("DS")) == 1012


def test_deleted_ids_use_the_complete_reader():
    s = CatalogSession(total=700)
    ids = _client(s).fetch_catalog_deleted_ids_complete("DS")
    assert ids == []  # os itens falsos não têm deleted:1
    assert all(b["params"]["STATE"] == "1" for _, b in s.posts)


def test_announced_total_parsing():
    assert announced_total({"Content-Range": "items 0-499/1340"}) == 1340
    assert announced_total({"content-range": "items 0-0/7"}) == 7
    assert announced_total({}) is None
    assert announced_total(None) is None


# ── Relatório anual ────────────────────────────────────────────────────────────

class AnnualSession:
    def __init__(self, rows):
        self.rows = rows
        self.posts = []
        self.urls = []
        self.headers = {}

    def mount(self, *_):
        pass

    def get(self, url, *args, **kwargs):
        self.urls.append(("GET", url))
        raise AssertionError("o relatório anual não faz GET")

    def post(self, url, *args, **kwargs):
        self.urls.append(("POST", url))
        self.posts.append(kwargs.get("json"))
        text = json.dumps({"dados": {"label": "Analise", "info": {}, "data": self.rows}})
        rd = base64.b64encode(text.encode("utf-8")).decode("ascii")
        return FakeResponse({"report": {"report": [{"reportfile": "a.json", "reportdata": rd}]}})


ACUMULADO = {
    "band_0\\idx": -1, "band_1\\group_code": "", "band_1\\group_description": "Acumulado",
    "band_2\\january": 0.0, "band_2\\february": 0.0, "band_2\\march": 26328.92, "band_2\\april": 97514.86,
    "band_2\\may": 166862.74, "band_2\\june": 318688.98, "band_2\\july": 519474.34, "band_2\\august": 750158.02,
    "band_2\\september": 943979.37, "band_2\\october": 994943.86, "band_2\\november": 994943.86,
    "band_2\\december": 994943.86, "band_3\\total_current": "", "band_4\\fontstyle": "bold", "id": "",
}


def test_store_year_payload_and_accumulated_row():
    s = AnnualSession([ACUMULADO, {"band_0\\idx": 0, "band_1\\group_description": "1/2 Carqueijal", "band_2\\june": 79.66}])
    months = _client(s).fetch_store_year("1093764095994", "584955579139649880", 2026)
    assert months[:4] == [0.0, 0.0, 26328.92, 97514.86]
    assert len(months) == 12
    p = s.posts[0]["params"]
    assert p["querystring"] == "report.id = '1093764095994'"
    assert p["YEAR"] == "2026" and p["GROUPBY"] == "1" and p["Stores"] == "584955579139649880"
    assert p["Client"] == "" and p["Employee"] == "" and p["Locals"] == ""
    assert p["mimetype"] == "application/json"


def test_store_year_passes_explicit_locals():
    s = AnnualSession([ACUMULADO])
    _client(s).fetch_store_year("R", "1", 2025, "11,22")
    assert s.posts[0]["params"]["Locals"] == "11,22"


def test_store_year_without_rows_is_a_year_without_sales():
    assert _client(AnnualSession([])).fetch_store_year("R", "1", 2024) == [0.0] * 12


def test_store_year_without_accumulated_row_fails():
    with pytest.raises(RuntimeError, match="Acumulado"):
        _client(AnnualSession([{"band_0\\idx": 0, "band_1\\group_description": "Artigo"}])).fetch_store_year("R", "1", 2026)


def test_store_year_never_calls_the_params_listing():
    # A chamada /service/report/<ID>/params devolve clientes e funcionários com nomes: nunca.
    s = AnnualSession([ACUMULADO])
    _client(s).fetch_store_year("1093764095994", "1", 2026, "11,22")
    assert s.urls == [("POST", "https://api.example/service/report/*/report")]
