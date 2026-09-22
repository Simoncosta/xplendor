"""
XPLENDOR — Teste do fetch de UNIDADES do PingWin (porta 8138, LOGIN PRÓPRIO).

⚠️ Lição corrigida: a sessão da 8136 NÃO é válida na 8138 (HTTP 401 "Session not
found"). Cada porta exige o SEU login challenge-response. Prova, sem rede:
  · fetch_units faz LOGIN na 8138 (/service/login + /service/authenticate lá);
  · usa a sessão da 8138 (não a da 8136) para o maindataset;
  · faz LOGOUT na 8138 (garantido, mesmo em erro);
  · devolve SÓ units.maindataset (ignora o baseunit "radio conv.");
  · units_url override é respeitado.
"""
import os
import sys

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

import sources.pingwin.mycloudpie as m  # noqa: E402
from sources.pingwin.mycloudpie import MyCloudPieClient  # noqa: E402


class FakeResp:
    def __init__(self, status=200, headers=None, payload=None, text=""):
        self.status_code = status
        self.headers = headers or {}
        self._payload = payload
        self.text = text

    def json(self):
        return self._payload

    def raise_for_status(self):
        raise AssertionError(f"HTTP {self.status_code}")


class FakePortSession:
    """Fake do _TimeoutSession: responde a login/authenticate/units/logout por URL."""
    instances: list = []
    units_status = 200  # variável entre testes p/ simular erro

    def __init__(self):
        self.headers = {}
        self.calls = []
        FakePortSession.instances.append(self)

    def mount(self, *a):
        pass

    def post(self, url, *a, **k):
        self.calls.append(url)
        if url.endswith("/service/login"):
            return FakeResp(200, headers={"Challenge": "1:" + "a" * 32 + ":" + "b" * 16})
        if url.endswith("/service/authenticate"):
            return FakeResp(200, headers={"Sessionid": "UNIT-8138-SESSION"})
        if "/service/units/" in url:
            return FakeResp(FakePortSession.units_status, payload={"units": {
                "maindataset": [
                    {"id": "11001", "description": "Unidade", "shortname": "UN", "parent_id": "", "unit_value": 1.0, "deleted": 0},
                    {"id": "999", "description": "Barril 50lt", "shortname": "50lt", "parent_id": "11003", "unit_value": 50.0, "deleted": 0},
                ],
                "baseunit": [{"id": f"r{i}", "description": "radio conv."} for i in range(300)],
            }})
        if url.endswith("/service/logout"):
            return FakeResp(200, text="Session terminated")
        return FakeResp(200, payload={})


def _client(units_url=""):
    return MyCloudPieClient(
        auth_url="https://srv-soa.mycloudpie.com:8136", api_url="https://srv-soa.mycloudpie.com:8136",
        username="u", password="p", report_id="r", stores="1",
        frontend_url="https://fe.example", database="db", app_version="1", units_url=units_url,
    )


def _reset(units_status=200):
    FakePortSession.instances = []
    FakePortSession.units_status = units_status
    m._TimeoutSession = FakePortSession  # _port_session cria a sua própria sessão


def _port_instance():
    """A sessão que fez o pedido das unidades (a da 8138)."""
    return next(s for s in FakePortSession.instances if any("/service/units/" in u for u in s.calls))


def test_fetch_units_logs_in_on_8138_uses_that_session_and_logs_out():
    _reset()
    c = _client()
    units = c.fetch_units()

    ps = _port_instance()
    # Login PRÓPRIO na 8138 + units + logout, todos na 8138.
    assert any(u == "https://srv-soa.mycloudpie.com:8138/service/login" for u in ps.calls)
    assert any(u == "https://srv-soa.mycloudpie.com:8138/service/authenticate" for u in ps.calls)
    assert any("/service/units/" in u and ":8138" in u for u in ps.calls)
    assert any(u == "https://srv-soa.mycloudpie.com:8138/service/logout" for u in ps.calls)
    # Usou a sessão da 8138 (Sessionid próprio), não a da 8136.
    assert ps.headers.get("Sessionid") == "UNIT-8138-SESSION"
    # Só o maindataset (2); o baseunit (300) foi ignorado.
    assert len(units) == 2 and units[1]["description"] == "Barril 50lt"


def test_main_8136_session_is_not_used_for_units():
    _reset()
    c = _client()
    c.fetch_units()
    # A sessão principal (a 1.ª instância, criada no construtor) NUNCA chamou /units.
    main = FakePortSession.instances[0]
    assert not any("/service/units/" in u for u in main.calls)


def test_logout_on_8138_is_guaranteed_even_on_error():
    _reset(units_status=500)  # o pedido das unidades falha
    c = _client()
    raised = False
    try:
        c.fetch_units()
    except AssertionError:  # raise_for_status
        raised = True
    assert raised, "o erro do pedido tem de propagar"
    ps = _port_instance()
    assert any(u.endswith("/service/logout") for u in ps.calls), "logout na 8138 tem de ser feito mesmo com erro"


def test_units_url_override_is_respected():
    _reset()
    c = _client(units_url="https://outra.example:9000")
    c.fetch_units()
    ps = _port_instance()
    assert any(u.startswith("https://outra.example:9000/service/units/") for u in ps.calls)
    assert any(u == "https://outra.example:9000/service/login" for u in ps.calls)
