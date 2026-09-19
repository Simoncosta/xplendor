"""
XPLENDOR — SMOKE TEST DO CRÍTICO: o LOGOUT GARANTIDO do PingWin.

Prova, sem rede nem credenciais reais (sessão falsa), que:
  · o context manager faz login à entrada e logout à saída;
  · o logout é SEMPRE feito, mesmo com exceção a meio da consulta;
  · o logout é best-effort (nunca levanta), mesmo se o pedido de logout falhar;
  · correr o ciclo DUAS vezes não acumula sessões presas (cada uma faz logout);
  · o logout bate em POST /service/logout com Content-Length 0.

É o teste que protege o restaurante (sessão presa estraga-o).
"""
import os
import sys

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from sources.pingwin.mycloudpie import MyCloudPieClient  # noqa: E402


class FakeResponse:
    def __init__(self, status_code=200, text="Session terminated", headers=None):
        self.status_code = status_code
        self.text = text
        self.headers = headers or {}


class FakeSession:
    """Sessão falsa: regista os posts e simula respostas. Sem rede."""
    def __init__(self, fail_logout=False):
        self.posts = []
        self.headers = {}
        self.fail_logout = fail_logout
        self.mounted = {}

    def mount(self, prefix, adapter):
        self.mounted[prefix] = adapter

    def post(self, url, *args, **kwargs):
        self.posts.append((url, kwargs.get("headers", {})))
        if url.endswith("/service/logout"):
            if self.fail_logout:
                raise ConnectionError("upstream down")
            return FakeResponse(200, "Session terminated")
        return FakeResponse(200, "")


def _client(fail_logout=False) -> MyCloudPieClient:
    c = MyCloudPieClient(
        auth_url="https://auth.example",
        api_url="https://api.example",
        username="u", password="p",
        report_id="r", stores="1", frontend_url="https://fe.example",
        database="db", app_version="1",
    )
    c.session = FakeSession(fail_logout=fail_logout)
    # Simula um login já feito (sem tocar no protocolo/rede).
    c.login = lambda: setattr(c, "session_id", "SESSIONABC123")  # type: ignore
    return c


def _logout_calls(c):
    return [u for (u, _h) in c.session.posts if u.endswith("/service/logout")]


def test_context_manager_logs_out_on_normal_exit():
    c = _client()
    with c as client:
        assert client.session_id == "SESSIONABC123"  # login à entrada
    assert _logout_calls(c) == ["https://api.example/service/logout"]  # logout à saída
    assert c.session_id is None  # sessão marcada encerrada


def test_logout_happens_even_when_body_raises():
    c = _client()
    raised = False
    try:
        with c:
            raise ValueError("erro a meio da consulta")
    except ValueError:
        raised = True
    assert raised, "a exceção original TEM de propagar (o with não a engole)"
    assert len(_logout_calls(c)) == 1, "logout tem de ser feito mesmo com erro a meio"
    assert c.session_id is None


def test_logout_is_best_effort_never_raises():
    c = _client(fail_logout=True)  # o POST de logout rebenta
    # Não deve propagar nada — best-effort.
    with c:
        pass
    assert c.session_id is None  # mesmo com falha, marca encerrada


def test_logout_sends_content_length_zero():
    c = _client()
    with c:
        pass
    _url, headers = [p for p in c.session.posts if p[0].endswith("/service/logout")][0]
    assert headers.get("Content-Length") == "0"
    assert headers.get("Sessionid") == "SESSIONABC123"


def test_two_cycles_do_not_accumulate_sessions():
    # Corre o ciclo DUAS vezes → dois logouts, nenhuma sessão fica presa.
    total_logouts = 0
    for _ in range(2):
        c = _client()
        with c:
            pass
        assert c.session_id is None
        total_logouts += len(_logout_calls(c))
    assert total_logouts == 2
