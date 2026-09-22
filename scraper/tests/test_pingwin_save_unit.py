"""
XPLENDOR — Teste da ESCRITA editar/anular: save_unit (cadeia completa do HAR).

⚠️ Cadeia real: OPEN → GET,INFO(lista completa do servidor) → sobrepõe SÓ a linha
alvo → EDIT,SAVE(lista completa = COMMIT) → GET,INFO. Editar=deleted:0, anular=1.
Prova, sem rede:
  · o EDIT,SAVE reenvia a LISTA COMPLETA do servidor (não a nossa BD);
  · só a linha alvo é alterada; as outras ficam tal e qual;
  · ⚠️ ABORTA se o alvo não estiver na lista do servidor;
  · erro REAL propagado.
"""
import os
import sys

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from sources.pingwin.mycloudpie import MyCloudPieClient  # noqa: E402

OID = "aa11bb22cc33dd44ee55ff6677889900"
# Lista completa do servidor (3 unidades; a 999 é a alvo).
SERVER = [{"id": "11003", "key": "0", "description": "Litro", "deleted": 0},
          {"id": "999", "key": "1", "description": "TESTE", "shortname": "*T*", "parent_id": "11003", "parent_qnt": 2, "unit_value": 2, "deleted": 0, "tare": 1},
          {"id": "11001", "key": "2", "description": "Unidade", "deleted": 0}]


class FakeResp:
    def __init__(self, status=200, payload=None, text="", headers=None):
        self.status_code = status
        self._payload = payload
        self.text = text
        self.headers = headers or {}

    def json(self):
        return self._payload


class SaveSession:
    def __init__(self, save_status=200, server=None):
        self.calls = []
        self.headers = {}
        self.save_status = save_status
        self.server = server if server is not None else SERVER

    def mount(self, *a):
        pass

    def post(self, url, *a, **k):
        h = k.get("headers", {})
        action = h.get("Action")
        self.calls.append((action, h.get("ObjectID"), k.get("json")))
        if action == "OPEN,GET,INFO":
            return FakeResp(200, payload={"units": {"maindataset": self.server}}, headers={"ObjectID": OID})
        if action == "GET,INFO":
            return FakeResp(200, payload={"units": {"maindataset": [dict(x) for x in self.server]}})
        if action == "EDIT,SAVE":
            if self.save_status != 200:
                return FakeResp(self.save_status, text='{"error":"unidade bloqueada"}')
            return FakeResp(200, payload={"units": {"maindataset": k.get("json")}})
        return FakeResp(200, payload={})


def _client():
    return MyCloudPieClient(
        auth_url="https://srv-soa.mycloudpie.com:8136", api_url="https://srv-soa.mycloudpie.com:8136",
        username="u", password="p", report_id="r", stores="1",
        frontend_url="https://fe.example", database="db", app_version="1",
    )


def test_edit_commits_full_list_with_only_target_changed():
    c = _client()
    c.session = SaveSession()
    # PHP envia o objeto (id + campos a alterar); aqui muda a descrição, deleted=0.
    c.save_unit({"id": "999", "description": "TESTE EDIT", "deleted": 0})

    actions = [call[0] for call in c.session.calls]
    assert actions.index("OPEN,GET,INFO") < actions.index("EDIT,SAVE")

    save = next(call for call in c.session.calls if call[0] == "EDIT,SAVE")
    body = save[2]
    assert len(body) == len(SERVER)                 # ⚠️ lista COMPLETA (não encolheu)
    assert save[1] == OID                            # mesmo ObjectID
    target = next(u for u in body if str(u["id"]) == "999")
    assert target["description"] == "TESTE EDIT"     # alvo alterado
    assert target["deleted"] == 0
    assert target["tare"] == 1                        # campos do servidor preservados no alvo
    # as OUTRAS linhas ficaram tal e qual as do servidor
    outras = [u for u in body if str(u["id"]) != "999"]
    assert all(u["deleted"] == 0 for u in outras)
    assert {u["id"] for u in outras} == {"11003", "11001"}


def test_anular_sets_deleted_1_on_target_only():
    c = _client()
    c.session = SaveSession()
    c.save_unit({"id": "999", "description": "TESTE", "shortname": "*T*", "deleted": 1})
    save = next(call for call in c.session.calls if call[0] == "EDIT,SAVE")
    target = next(u for u in save[2] if str(u["id"]) == "999")
    assert target["deleted"] == 1                     # anular = deleted 1 (só no alvo)
    assert all(u["deleted"] == 0 for u in save[2] if str(u["id"]) != "999")


def test_save_requires_id():
    c = _client(); c.session = SaveSession()
    raised = False
    try:
        c.save_unit({"description": "sem id"})
    except RuntimeError:
        raised = True
    assert raised
    assert "EDIT,SAVE" not in [call[0] for call in c.session.calls]


def test_save_aborts_if_target_not_in_server_list():
    c = _client(); c.session = SaveSession()
    raised = ""
    try:
        c.save_unit({"id": "does-not-exist", "description": "X", "deleted": 1})
    except RuntimeError as e:
        raised = str(e)
    assert "ABORTADO" in raised
    assert "EDIT,SAVE" not in [call[0] for call in c.session.calls]   # ⚠️ não gravou


def test_save_propagates_real_error():
    c = _client(); c.session = SaveSession(save_status=500)
    raised = ""
    try:
        c.save_unit({"id": "999", "description": "X", "deleted": 0})
    except RuntimeError as e:
        raised = str(e)
    assert "500" in raised and "unidade bloqueada" in raised
