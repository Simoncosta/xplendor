"""
XPLENDOR — Teste do helper ÚNICO de leitura paginada do PingWin:
MyCloudPieClient.fetch_browserdataset (usado por artigos E documentos).

Prova, sem rede (sessão falsa), que:
  · itera o header Range (items=0-999, 1000-1999, …) e ACUMULA todas as páginas;
  · PARA quando a página vem curta (len < page_size) — sem pedir a seguinte;
  · respeita a trava max_pages (não pagina sem fim);
  · aceita HTTP 200 E 206 (Partial Content);
  · lê resposta["browser"]["browserdataset"];
  · serve os documentos (fetch_document_configs) — mesmo helper, outro dataset.
"""
import os
import sys

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from sources.pingwin.mycloudpie import MyCloudPieClient  # noqa: E402


class FakeResponse:
    def __init__(self, status_code, items):
        self.status_code = status_code
        self._items = items
        self.text = ""

    def json(self):
        return {"browser": {"browserdataset": self._items}}

    def raise_for_status(self):
        raise AssertionError(f"HTTP {self.status_code}")


class PagingSession:
    """Serve `total` itens paginados, honrando o header Range. Regista os Ranges pedidos."""
    def __init__(self, total, page_size=1000, status_code=200):
        self.total = total
        self.page_size = page_size
        self.status_code = status_code
        self.ranges = []
        self.headers = {}

    def mount(self, *_):  # noqa: D401
        pass

    def post(self, url, *args, **kwargs):
        rng = kwargs.get("headers", {}).get("Range", "")
        self.ranges.append(rng)
        # "items=START-END"
        span = rng.split("=", 1)[1]
        start, end = (int(x) for x in span.split("-"))
        items = [{"id": str(i), "code": f"C{i}"} for i in range(start, min(end + 1, self.total))]
        return FakeResponse(self.status_code, items)


def _client():
    c = MyCloudPieClient(
        auth_url="https://auth.example", api_url="https://api.example",
        username="u", password="p", report_id="r", stores="1",
        frontend_url="https://fe.example", database="db", app_version="1",
    )
    return c


def test_accumulates_across_pages_and_stops_on_short_page():
    c = _client()
    c.session = PagingSession(total=2500, page_size=1000)
    out = c.fetch_browserdataset("DS", {"params": {}}, page_size=1000)
    assert len(out) == 2500                       # acumulou tudo
    # 3 páginas: 0-999 (cheia), 1000-1999 (cheia), 2000-2999 (curta → para).
    assert c.session.ranges == ["items=0-999", "items=1000-1999", "items=2000-2999"]
    assert out[0]["id"] == "0" and out[-1]["id"] == "2499"


def test_stops_immediately_when_first_page_is_short():
    c = _client()
    c.session = PagingSession(total=42, page_size=1000)
    out = c.fetch_browserdataset("DS", {"params": {}}, page_size=1000)
    assert len(out) == 42
    assert c.session.ranges == ["items=0-999"]     # só um pedido


def test_exact_multiple_makes_one_extra_empty_page():
    # Página cheia exata → pede a seguinte, que vem vazia (curta) e para.
    c = _client()
    c.session = PagingSession(total=1000, page_size=1000)
    out = c.fetch_browserdataset("DS", {"params": {}}, page_size=1000)
    assert len(out) == 1000
    assert c.session.ranges == ["items=0-999", "items=1000-1999"]


def test_respects_max_pages_cap():
    c = _client()
    c.session = PagingSession(total=10_000, page_size=1000)
    out = c.fetch_browserdataset("DS", {"params": {}}, page_size=1000, max_pages=3)
    assert len(out) == 3000                        # travou às 3 páginas
    assert len(c.session.ranges) == 3


def test_accepts_http_206_partial_content():
    c = _client()
    c.session = PagingSession(total=1500, page_size=1000, status_code=206)
    out = c.fetch_browserdataset("DS", {"params": {}}, page_size=1000)
    assert len(out) == 1500                        # 206 é aceite (não levanta)


def test_document_configs_use_the_same_paginated_helper():
    # fetch_document_configs delega no helper (mesmo caminho de paginação).
    c = _client()
    c.session = PagingSession(total=5, page_size=1000)
    docs = c.fetch_document_configs()
    assert len(docs) == 5
    assert c.session.ranges == ["items=0-999"]
