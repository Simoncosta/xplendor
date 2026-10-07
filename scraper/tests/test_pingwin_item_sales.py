"""
XPLENDOR — F1-1: Vendas por artigo (MyCloudPieClient.fetch_item_sales) e o pedido de
relatório genérico (run_report) de que o Resumo de Vendas passou a depender.

Prova, sem rede (sessão falsa), que:
  · o corpo do pedido é o capturado (report.id, datas, lojas, JSON, Action OPEN,GET,CLOSE);
  · só saem os campos da lista fechada (sem empresa, sem descontos), com a data em AAAA-MM-DD;
  · aceita o reportdata com caracteres de controlo (\r) entre campos;
  · repete enquanto o servidor devolve vazio e falha com mensagem clara no fim;
  · recusa intervalos acima de 7 dias ou invertidos, sem pedir nada;
  · o Resumo de Vendas continua a enviar os mesmos parâmetros.
"""
import base64
import json
import os
import sys
from datetime import datetime

import pytest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from sources.pingwin import mycloudpie  # noqa: E402
from sources.pingwin.mycloudpie import MyCloudPieClient, shape_item_sale  # noqa: E402

ROW = {
    "key": "00000000", "idx": 0, "company_id": "1649601156492", "company": "Yuko BO",
    "store_id": "1099845342604", "store": "Tabern Yuko Baixa", "doc_date": "20260930T00:00:00",
    "product_code": "7", "product_desc": "Cerveja s/alcool", "product_id": "1649601157157",
    "family_id": "1649601156816", "family_desc": "Família \\ Bebidas \\ Cerveja",
    "qnt": 2.0, "discount": 10.0, "basevalue": 4.88, "taxvalue": 1.12, "total": 6.0,
}


def _reportdata(rows):
    # Como o servidor: JSON com \r entre campos, em base64.
    text = json.dumps({"data": {"label": "Vendas por artigo", "info": {}, "data": rows}}).replace(", ", ",\r")
    return base64.b64encode(text.encode("utf-8")).decode("ascii")


class FakeResponse:
    def __init__(self, body, status_code=200):
        self.status_code = status_code
        self._body = body
        self.text = json.dumps(body)[:300]

    def json(self):
        return self._body

    def raise_for_status(self):
        raise AssertionError(f"HTTP {self.status_code}")


class ReportSession:
    """Devolve `empty_first` respostas vazias e depois o relatório com `rows`."""
    def __init__(self, rows, empty_first=0):
        self.rows = rows
        self.empty_first = empty_first
        self.calls = []
        self.headers = {}

    def mount(self, *_):
        pass

    def post(self, url, *args, **kwargs):
        self.calls.append({"url": url, "json": kwargs.get("json"), "headers": kwargs.get("headers", {})})
        if len(self.calls) <= self.empty_first:
            return FakeResponse({"report": {"report": []}})
        return FakeResponse({"report": {"report": [{"reportfile": "x.json", "reportdata": _reportdata(self.rows)}]}})


def _client(session):
    c = MyCloudPieClient(
        auth_url="https://auth.example", api_url="https://api.example",
        username="u", password="p", report_id="RESUMO", stores="1",
        frontend_url="https://fe.example", database="db", app_version="1",
    )
    c.session = session
    return c


@pytest.fixture(autouse=True)
def _no_sleep(monkeypatch):
    monkeypatch.setattr(mycloudpie.time, "sleep", lambda *_: None)


def test_payload_matches_capture():
    s = ReportSession([ROW])
    _client(s).fetch_item_sales("919525121217", "1099845342604,584955579139649880",
                                datetime(2026, 9, 30), datetime(2026, 10, 6))
    call = s.calls[0]
    assert call["url"] == "https://api.example/service/report/*/report"
    assert call["headers"]["Action"] == "OPEN,GET,CLOSE"
    p = call["json"]["params"]
    assert p["querystring"] == "report.id = '919525121217'"
    assert p["START_DATE"] == "20260930T00:00:00"
    assert p["END_DATE"] == "20261006T00:00:00"
    assert p["Stores"] == "1099845342604,584955579139649880"
    assert p["GROUP_MENUS"] == 1 and p["PIVOT"] == 0 and p["SHOWEXTFAC"] == 0
    assert p["mimetype"] == "application/json" and p["sendmail"] == 0


def test_only_closed_list_of_fields_comes_out():
    rows = _client(ReportSession([ROW])).fetch_item_sales("R", "1", datetime(2026, 9, 30), datetime(2026, 9, 30))
    assert rows == [{
        "store_id": "1099845342604", "date": "2026-09-30", "product_id": "1649601157157",
        "product_code": "7", "product_name": "Cerveja s/alcool", "family_id": "1649601156816",
        "family_path": "Família \\ Bebidas \\ Cerveja", "qty": 2.0, "net": 4.88, "tax": 1.12, "gross": 6.0,
    }]
    assert set(rows[0]) == set(mycloudpie.ITEM_SALES_FIELDS)


def test_rows_without_store_day_or_article_are_dropped():
    assert shape_item_sale({**ROW, "product_id": ""}) is None
    assert shape_item_sale({**ROW, "store_id": None}) is None
    assert shape_item_sale({**ROW, "doc_date": ""}) is None


def test_retries_while_empty_then_returns_rows():
    s = ReportSession([ROW], empty_first=3)
    rows = _client(s).fetch_item_sales("R", "1", datetime(2026, 9, 30), datetime(2026, 9, 30))
    assert len(s.calls) == 4 and len(rows) == 1


def test_fails_clearly_when_always_empty():
    s = ReportSession([ROW], empty_first=99)
    with pytest.raises(RuntimeError, match="Vendas por artigo"):
        _client(s).fetch_item_sales("R", "1", datetime(2026, 9, 30), datetime(2026, 9, 30))
    assert len(s.calls) == MyCloudPieClient.REPORT_ATTEMPTS


def test_empty_day_is_a_valid_answer():
    rows = _client(ReportSession([])).fetch_item_sales("R", "1", datetime(2026, 9, 30), datetime(2026, 9, 30))
    assert rows == []


def test_rejects_more_than_seven_days_or_inverted_range_without_calling():
    s = ReportSession([ROW])
    with pytest.raises(ValueError):
        _client(s).fetch_item_sales("R", "1", datetime(2026, 9, 30), datetime(2026, 10, 7))
    with pytest.raises(ValueError):
        _client(s).fetch_item_sales("R", "1", datetime(2026, 10, 2), datetime(2026, 10, 1))
    with pytest.raises(ValueError):
        _client(s).fetch_item_sales("", "1", datetime(2026, 10, 1), datetime(2026, 10, 1))
    assert s.calls == []


def test_sales_summary_keeps_its_parameters():
    s = ReportSession([])
    _client(s).trigger_report(datetime(2026, 10, 6))
    p = s.calls[0]["json"]["params"]
    assert p == {
        "querystring": "report.id = 'RESUMO'", "Stores": "1",
        "START_DATE": "20261006T00:00:00", "END_DATE": "20261006T00:00:00",
        "Groupby": "1", "Family_level": "-1", "GROUPBY_LOCAL": 0,
        "sendmail": 0, "mimetype": "application/json",
    }
