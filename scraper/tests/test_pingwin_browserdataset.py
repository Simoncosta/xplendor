"""
XPLENDOR — Teste do helper ÚNICO de leitura paginada do PingWin:
MyCloudPieClient.fetch_browserdataset (artigos, documentos, fornecedores, condições…).

⚠️ O servidor falso honra a semântica REAL do Range, provada ao vivo (2026-10-09):
"items=a-b" devolve [a, b) — o b NÃO vem. O falso antigo usava fim inclusivo e por isso
nunca apanhou o bug do "catálogo preso nos 999".

Prova, sem rede (sessão falsa), que:
  · pede items=0-1000, 1000-2000, … e ACUMULA todas as páginas, sem saltar a linha 999;
  · PARA quando a página vem incompleta (len < page_size) — sem pedir a seguinte;
  · deduplica por id (linhas repetidas entre páginas);
  · trava max_pages: se a última página ainda vier completa → BrowserDatasetLimitError;
  · aceita HTTP 200 E 206 (Partial Content);
  · serve os documentos (fetch_document_configs) — mesmo helper, outro dataset.
"""
import os
import sys

import pytest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from sources.pingwin.mycloudpie import BrowserDatasetLimitError, MyCloudPieClient  # noqa: E402


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
    def __init__(self, total, page_size=1000, status_code=200, repeat_first_of_page=False):
        self.total = total
        self.page_size = page_size
        self.status_code = status_code
        self.repeat_first_of_page = repeat_first_of_page
        self.ranges = []
        self.headers = {}

    def mount(self, *_):  # noqa: D401
        pass

    def post(self, url, *args, **kwargs):
        rng = kwargs.get("headers", {}).get("Range", "")
        self.ranges.append(rng)
        # "items=START-END" → [START, END) — fim EXCLUSIVO, como o servidor real.
        span = rng.split("=", 1)[1]
        start, end = (int(x) for x in span.split("-"))
        items = [{"id": str(i), "code": f"C{i}"} for i in range(start, min(end, self.total))]
        if self.repeat_first_of_page and start > 0 and items:
            # o servidor repete a última linha da página anterior no início desta
            items = [{"id": str(start - 1), "code": f"C{start - 1}"}] + items[:-1]
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
    # 3 páginas: 0-1000 (cheia), 1000-2000 (cheia), 2000-3000 (incompleta → para).
    assert c.session.ranges == ["items=0-1000", "items=1000-2000", "items=2000-3000"]
    assert out[0]["id"] == "0" and out[-1]["id"] == "2499"


def test_nao_salta_a_linha_999_nem_repete():
    # O bug antigo: items=0-999 trazia 999 linhas (0..998) e parava; mesmo que seguisse,
    # items=1000-1999 saltava a linha 999. Agora as linhas vêm todas, contíguas, uma vez.
    c = _client()
    c.session = PagingSession(total=1500, page_size=1000)
    out = c.fetch_browserdataset("DS", {"params": {}}, page_size=1000)
    assert [int(x["id"]) for x in out] == list(range(1500))


def test_stops_immediately_when_first_page_is_short():
    c = _client()
    c.session = PagingSession(total=42, page_size=1000)
    out = c.fetch_browserdataset("DS", {"params": {}}, page_size=1000)
    assert len(out) == 42
    assert c.session.ranges == ["items=0-1000"]    # só um pedido


def test_exact_multiple_makes_one_extra_empty_page():
    # Página cheia exata → pede a seguinte, que vem vazia (incompleta) e para.
    c = _client()
    c.session = PagingSession(total=1000, page_size=1000)
    out = c.fetch_browserdataset("DS", {"params": {}}, page_size=1000)
    assert len(out) == 1000
    assert c.session.ranges == ["items=0-1000", "items=1000-2000"]


def test_deduplica_por_id():
    c = _client()
    c.session = PagingSession(total=25, page_size=10, repeat_first_of_page=True)
    out = c.fetch_browserdataset("DS", {"params": {}}, page_size=10)
    ids = [x["id"] for x in out]
    assert len(ids) == len(set(ids))               # nenhum repetido


def test_trava_de_paginas_recusa_leitura_cortada():
    c = _client()
    c.session = PagingSession(total=10_000, page_size=1000)
    with pytest.raises(BrowserDatasetLimitError):
        c.fetch_browserdataset("DS", {"params": {}}, page_size=1000, max_pages=3)
    assert len(c.session.ranges) == 3


def test_trava_nao_dispara_se_a_ultima_pagina_couber():
    c = _client()
    c.session = PagingSession(total=2999, page_size=1000)
    out = c.fetch_browserdataset("DS", {"params": {}}, page_size=1000, max_pages=3)
    assert len(out) == 2999


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
    assert c.session.ranges == ["items=0-1000"]
