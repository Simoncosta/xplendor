"""
XPLENDOR — Teste do fetch de UNIDADES do PingWin (porta 8138).

Prova, sem rede (sessão falsa), que:
  · fetch_units bate na PORTA 8138 (derivada do api_url:8136), no endpoint /service/units/*;
  · usa Action OPEN,GET,INFO (read-only) e corpo vazio;
  · devolve SÓ units.maindataset (as reais) e IGNORA units.baseunit ("radio conv." = lixo);
  · aceita um units_url explícito (override).
"""
import os
import sys

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from sources.pingwin.mycloudpie import MyCloudPieClient  # noqa: E402


class FakeResponse:
    def __init__(self, status_code, payload):
        self.status_code = status_code
        self._payload = payload
        self.text = ""

    def json(self):
        return self._payload

    def raise_for_status(self):
        raise AssertionError(f"HTTP {self.status_code}")


class UnitsSession:
    def __init__(self):
        self.posts = []
        self.headers = {}

    def mount(self, *_):
        pass

    def post(self, url, *args, **kwargs):
        self.posts.append((url, kwargs.get("headers", {}), kwargs.get("data")))
        payload = {"units": {
            "maindataset": [
                {"id": "11001", "description": "Unidade", "shortname": "UN", "parent_id": "", "unit_value": 1.0, "deleted": 0},
                {"id": "999", "description": "Barril 50lt", "shortname": "50lt", "parent_id": "11003", "unit_value": 50.0, "deleted": 0},
            ],
            "baseunit": [{"id": f"r{i}", "description": "radio conv."} for i in range(300)],  # LIXO
        }}
        return FakeResponse(200, payload)


def _client(units_url=""):
    c = MyCloudPieClient(
        auth_url="https://srv-soa.mycloudpie.com:8136", api_url="https://srv-soa.mycloudpie.com:8136",
        username="u", password="p", report_id="r", stores="1",
        frontend_url="https://fe.example", database="db", app_version="1", units_url=units_url,
    )
    c.session = UnitsSession()
    return c


def test_fetch_units_hits_port_8138_and_returns_only_maindataset():
    c = _client()
    units = c.fetch_units()
    url, headers, body = c.session.posts[0]
    assert ":8138/service/units/" in url          # porta 8138 (derivada da 8136)
    assert headers.get("Action") == "OPEN,GET,INFO"
    assert body == b""                             # corpo vazio
    assert len(units) == 2                         # SÓ o maindataset (2), o baseunit (300) foi ignorado
    assert units[1]["description"] == "Barril 50lt" and units[1]["unit_value"] == 50.0


def test_units_url_override_is_respected():
    c = _client(units_url="https://outra.example:9000")
    c.fetch_units()
    url = c.session.posts[0][0]
    assert url.startswith("https://outra.example:9000/service/units/")
