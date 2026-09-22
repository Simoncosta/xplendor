"""
XPLENDOR — Teste da ESCRITA criar unidade: create_unit (cadeia completa do HAR).

⚠️ Cadeia real: OPEN → GET,INFO(baseline) → NEW(1 linha) → GET,INFO(lista completa c/
nova) → EDIT,SAVE(essa lista EXATA = COMMIT) → GET,INFO(confirma). Tudo na mesma
sessão, mesmo ObjectID. Prova, sem rede:
  · OPEN antes do NEW antes do EDIT,SAVE (o commit);
  · o EDIT,SAVE reenvia a LISTA COMPLETA do GET,INFO (não reconstruída);
  · ⚠️ ABORTA (não grava) se a lista vier incompleta / sem a nova;
  · erro REAL propagado.
"""
import os
import sys

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from sources.pingwin.mycloudpie import MyCloudPieClient  # noqa: E402

OID = "abc123def456abc123def456abc12345"
NEW_ID = "584955579139771856"
# Baseline: 3 unidades já existentes.
BASE = [{"id": "11001", "key": "0", "description": "Unidade"},
        {"id": "11002", "key": "1", "description": "Quilograma"},
        {"id": "11003", "key": "2", "description": "Litro"}]
NEW_ROW = {"id": NEW_ID, "key": "3", "description": "UNIDADE TESTE X", "shortname": "*UNX*", "parent_id": "11001"}


class FakeResp:
    def __init__(self, status=200, payload=None, text="", headers=None):
        self.status_code = status
        self._payload = payload
        self.text = text
        self.headers = headers or {}

    def json(self):
        return self._payload


class CreateSession:
    def __init__(self, truncate_after_new=False, new_status=200):
        self.calls = []          # (action, objectid, body)
        self.headers = {}
        self.created = False
        self.truncate = truncate_after_new
        self.new_status = new_status

    def mount(self, *a):
        pass

    def post(self, url, *a, **k):
        h = k.get("headers", {})
        action = h.get("Action")
        self.calls.append((action, h.get("ObjectID"), k.get("json")))
        if action == "OPEN,GET,INFO":
            return FakeResp(200, payload={"units": {"maindataset": BASE}}, headers={"ObjectID": OID})
        if action == "NEW":
            if self.new_status != 200:
                return FakeResp(self.new_status, text='{"error":"NIF do emissor inválido"}')
            self.created = True
            return FakeResp(200, payload={"units": {"maindataset": [dict(NEW_ROW)]}})
        if action == "GET,INFO":
            if not self.created:
                return FakeResp(200, payload={"units": {"maindataset": [dict(x) for x in BASE]}})  # baseline
            lst = [dict(x) for x in BASE] + [dict(NEW_ROW)]
            if self.truncate:
                lst = lst[:2]   # ⚠️ lista veio truncada → tem de ABORTAR
            return FakeResp(200, payload={"units": {"maindataset": lst}})
        if action == "EDIT,SAVE":
            return FakeResp(200, payload={"units": {"maindataset": k.get("json")}})
        return FakeResp(200, payload={})


def _client():
    return MyCloudPieClient(
        auth_url="https://srv-soa.mycloudpie.com:8136", api_url="https://srv-soa.mycloudpie.com:8136",
        username="u", password="p", report_id="r", stores="1",
        frontend_url="https://fe.example", database="db", app_version="1",
    )


def test_create_full_chain_commits_exact_server_list():
    c = _client()
    c.session = CreateSession()
    created = c.create_unit({"description": "UNIDADE TESTE X", "shortname": "*UNX*", "parent_qnt": 1, "parent_id": "11001"})

    actions = [call[0] for call in c.session.calls]
    # OPEN antes do NEW antes do EDIT,SAVE (o commit).
    assert actions.index("OPEN,GET,INFO") < actions.index("NEW") < actions.index("EDIT,SAVE")

    new = next(call for call in c.session.calls if call[0] == "NEW")
    assert new[2] == [{"description": "UNIDADE TESTE X", "shortname": "*UNX*", "parent_qnt": "1", "parent_id": "11001"}]  # só a nova

    save = next(call for call in c.session.calls if call[0] == "EDIT,SAVE")
    # ⚠️ o EDIT,SAVE reenvia a LISTA COMPLETA (baseline + nova) EXATA do GET,INFO.
    assert len(save[2]) == len(BASE) + 1
    assert any(str(u.get("id")) == NEW_ID for u in save[2])
    assert save[1] == OID   # mesmo ObjectID da grelha

    assert created["id"] == NEW_ID
    assert created["_committed_in_getinfo"] is True
    assert created["_units_before"] == 3 and created["_units_after"] == 4


def test_create_aborts_if_list_incomplete_and_does_not_commit():
    c = _client()
    c.session = CreateSession(truncate_after_new=True)  # GET,INFO devolve lista truncada
    raised = ""
    try:
        c.create_unit({"description": "X", "shortname": "X", "parent_id": "11001"})
    except RuntimeError as e:
        raised = str(e)
    assert "ABORTADO" in raised
    assert "EDIT,SAVE" not in [call[0] for call in c.session.calls]   # ⚠️ NUNCA gravou a lista incompleta


def test_create_propagates_real_error():
    c = _client()
    c.session = CreateSession(new_status=500)
    raised = ""
    try:
        c.create_unit({"description": "X", "shortname": "X", "parent_id": "11001"})
    except RuntimeError as e:
        raised = str(e)
    assert "500" in raised and "NIF do emissor inválido" in raised
    assert "EDIT,SAVE" not in [call[0] for call in c.session.calls]   # falhou no NEW → não commit
