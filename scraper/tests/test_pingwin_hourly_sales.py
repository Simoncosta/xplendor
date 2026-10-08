"""
XPLENDOR — F2: Vendas por hora (MyCloudPieClient.fetch_hourly_sales), sem rede.

Prova que:
  · o corpo do pedido é o validado nas capturas h3/h3b (Period 1, sem IVA, hora a hora);
  · só saem loja, dia, hora e valor; a hora vem do group_label; o homólogo e a variação
    ficam de fora; horas sem vendas não geram linhas;
  · recusa intervalos acima de 7 dias ou invertidos, sem pedir nada.
"""
import base64
import json
import os
import sys
from datetime import datetime

import pytest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from sources.pingwin import mycloudpie  # noqa: E402
from sources.pingwin.mycloudpie import MyCloudPieClient, shape_hourly_sales  # noqa: E402

GRID = {
    "label": "Vendas por hora",
    "info": {
        "store_id": {"label": "Loja", "visible": "false"},
        "store": {"label": "Loja"},
        "dday": {"label": "Dia"},
        "48\\amount": {"group": "48", "group_label": "0", "label": "Valor"},
        "48\\oldamount": {"group": "48", "group_label": "0", "label": "Homólogo"},
        "835\\amount": {"group": "835", "group_label": "13", "label": "Valor"},
        "835\\oldamount": {"group": "835", "group_label": "13", "label": "Homólogo"},
        "835\\variation": {"group": "835", "group_label": "13", "label": "Variação"},
        "849\\amount": {"group": "849", "group_label": "21", "label": "Valor"},
    },
    "data": [
        {"key": "1", "store_id": "1099845342604", "store": "Tabern Yuko Baixa", "dday": "20261006T00:00:00",
         "835\\amount": 113.02, "835\\oldamount": 281.07, "835\\variation": -59.8, "849\\amount": 387.11},
        {"key": "2", "store_id": "584955579139649880", "store": "Tabern Yuko Original", "dday": "20261006T00:00:00",
         "48\\oldamount": 132.12, "835\\amount": 726.95, "849\\amount": ""},
    ],
}


class FakeResponse:
    def __init__(self, body):
        self.status_code = 200
        self._body = body
        self.text = ""

    def json(self):
        return self._body

    def raise_for_status(self):
        raise AssertionError("HTTP")


class Session:
    def __init__(self):
        self.calls = []
        self.headers = {}

    def mount(self, *_):
        pass

    def post(self, url, *args, **kwargs):
        self.calls.append({"url": url, "json": kwargs.get("json")})
        rd = base64.b64encode(json.dumps({"grid_data": GRID}).encode("utf-8")).decode("ascii")
        return FakeResponse({"report": {"report": [{"reportfile": "h.json", "reportdata": rd}]}})


def _client(session):
    c = MyCloudPieClient(auth_url="https://auth.example", api_url="https://api.example", username="u", password="p",
                         report_id="r", stores="1", frontend_url="https://fe.example", database="db", app_version="1")
    c.session = session
    return c


@pytest.fixture(autouse=True)
def _no_sleep(monkeypatch):
    monkeypatch.setattr(mycloudpie.time, "sleep", lambda *_: None)


def test_payload_matches_the_validated_capture():
    s = Session()
    _client(s).fetch_hourly_sales("1023875499019", "1099845342604,584955579139649880", datetime(2026, 9, 30), datetime(2026, 10, 6))
    p = s.calls[0]["json"]["params"]
    assert s.calls[0]["url"] == "https://api.example/service/report/*/report"
    assert p["querystring"] == "report.id = '1023875499019'"
    assert p["START_DATE"] == "20260930T00:00:00" and p["END_DATE"] == "20261006T00:00:00"
    assert p["Period"] == "1" and p["WithTax"] == 0 and p["GroupHours"] == 0 and p["FilterHours"] == 0
    assert p["Stores"] == "1099845342604,584955579139649880"
    assert p["mimetype"] == "application/json"


def test_only_store_day_hour_and_value_without_homologous():
    rows = shape_hourly_sales(GRID)
    assert sorted(rows, key=lambda r: (r["store_id"], r["hour"])) == [
        {"store_id": "1099845342604", "date": "2026-10-06", "hour": 13, "net": 113.02},
        {"store_id": "1099845342604", "date": "2026-10-06", "hour": 21, "net": 387.11},
        {"store_id": "584955579139649880", "date": "2026-10-06", "hour": 13, "net": 726.95},
    ]


def test_rejects_more_than_seven_days_or_inverted_range_without_calling():
    s = Session()
    with pytest.raises(ValueError):
        _client(s).fetch_hourly_sales("R", "1", datetime(2026, 9, 30), datetime(2026, 10, 7))
    with pytest.raises(ValueError):
        _client(s).fetch_hourly_sales("R", "1", datetime(2026, 10, 2), datetime(2026, 10, 1))
    assert s.calls == []
