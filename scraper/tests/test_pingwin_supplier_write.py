"""
XPLENDOR — Fornecedores: ESCRITA no PingWin (FN). Prova, sem rede (servidor falso que
"grava" no SAVE e devolve o gravado na releitura):
  · CRIAR  = NEW → GET,INFO → MERGE → SAVE → CLOSE, MESMO ObjectID; releitura confirma;
  · EDITAR = OPEN → GET,INFO → MERGE (sem storerelation) → SAVE → CLOSE; campos não
    enviados mantêm o valor vivo;
  · ANULAR = POST /service/supplier/{id} DELETE,CLOSE, sem ObjectID nem body; confirma STATE;
  · CLOSE também em erro (MERGE 500, SAVE 500, GET falhado);
  · datasetclient e datasetemployee reenviados INTACTOS (deleted 1 — nunca vira cliente/utilizador);
  · guarda de NIF (tax_number_count) antes do SAVE; na edição só se o NIF mudar.
"""
import copy
import json as _json
import os
import sys

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from sources.pingwin.mycloudpie import MyCloudPieClient  # noqa: E402

API = "https://srv-soa.example:8136"


class Resp:
    def __init__(self, status=200, payload=None, headers=None):
        self.status_code = status
        self._payload = payload if payload is not None else {}
        self.headers = headers or {}
        self.text = _json.dumps(self._payload)

    def json(self):
        return self._payload


def model(eid="900", code="197"):
    return {
        "maindataset": [{"key": "00000000", "id": eid, "code": code, "description": "", "fiscalname": "", "tax_number": "",
                         "issupplier": 1, "currency_id": "5001", "deleted": 0, "tax_number_count": 0}],
        "datasetclient": [{"entity_id": eid, "paycond_id": "", "deleted": 1}],
        "datasetemployee": [{"entity_id": eid, "internal_name": "", "login_name": "", "is_user": 1, "deleted": 1}],
        "datasetsupplier": [{"entity_id": eid, "paycond_id": "", "deleted": 0}],
        "address.defaultaddress": [{"id": "901", "entity_id": eid, "contact_type_id": "6001", "address": "", "postalcode": "",
                                    "postalcode_description": "", "country_id": "30000", "isdefault": 1, "obs": "", "deleted": 0}],
        "storerelation.storedata": [{"store_id": "S1", "deleted": 0}, {"store_id": "S2", "deleted": 1}],
        "additionalfields.maindataset": [{"key1_id": eid, "iswinorder": 0}],
    }


class FakeSoa:
    """Servidor falso: um "registo gravado" por id; ObjectIDs por abertura."""

    def __init__(self, merge_status=200, save_status=200, get_status=200, tax_number_count=0):
        self.calls = []
        self.store = {}                 # id → blocos gravados
        self.voided = set()
        self.next_oid = 1
        self.staging = {}               # oid → blocos em edição
        self.merge_status, self.save_status, self.get_status = merge_status, save_status, get_status
        self.tax_number_count = tax_number_count

    def _oid(self):
        oid = f"OID{self.next_oid}"
        self.next_oid += 1
        return oid

    def post(self, url, json=None, data=None, headers=None, **kw):
        path = url.replace(API, "")
        action = (headers or {}).get("Action")
        oid = (headers or {}).get("ObjectID")
        self.calls.append({"path": path, "action": action, "oid": oid, "json": copy.deepcopy(json)})
        if path == "/service/entity" and action == "NEW":
            o = self._oid(); self.staging[o] = model()
            return Resp(200, {"entity": {"maindataset": self.staging[o]["maindataset"]}}, {"ObjectID": o})
        if path.startswith("/service/entity/") and action == "OPEN":
            eid = path.rsplit("/", 1)[1]
            o = self._oid(); self.staging[o] = copy.deepcopy(self.store[eid])
            return Resp(200, {"entity": {}}, {"ObjectID": o})
        if path.startswith("/service/entity/") and action == "GET,INFO":
            if self.get_status != 200:
                return Resp(self.get_status, {"message": "boom"})
            return Resp(200, {"entity": copy.deepcopy(self.staging[oid])})
        if path.startswith("/service/entity/") and action == "MERGE":
            if self.merge_status != 200:
                return Resp(self.merge_status, {"code": 500, "message": "validation error"})
            for k, v in json.items():
                self.staging[oid][k] = v
            main = dict(self.staging[oid]["maindataset"][0], tax_number_count=self.tax_number_count)
            return Resp(200, {"entity": {"maindataset": [main]}})
        if path == "/service/entity" and action == "SAVE":
            if self.save_status != 200:
                return Resp(self.save_status, {"code": 500, "message": "O campo Descrição não pode estar em branco!"})
            st = self.staging[oid]
            self.store[str(st["maindataset"][0]["id"])] = copy.deepcopy(st)
            return Resp(200, {})
        if path == "/service/entity" and action == "CLOSE":
            self.staging.pop(oid, None)
            return Resp(200, {})
        if path.startswith("/service/supplier/") and action == "DELETE,CLOSE":
            self.voided.add(path.rsplit("/", 1)[1])
            return Resp(200, {})
        if "/browserdataset" in path:
            state = json["params"]["STATE"]
            ids = [i for i in self.store if (i in self.voided) == (state == "1")]
            return Resp(200, {"browser": {"browserdataset": [{"id": i, "tax_number": self.store[i]["maindataset"][0]["tax_number"]} for i in ids]}})
        raise AssertionError(f"pedido inesperado {action} {path}")


def client(fake):
    c = MyCloudPieClient.__new__(MyCloudPieClient)
    c.api_url, c.database, c.app_grupopie = API, "yuko", "PBOWEB"
    c.paycond_url = c.paycond_port = ""
    c.session = fake
    return c


FIELDS = {"description": "TESTE XPLENDOR A", "fiscalname": "Teste Xplendor Lda", "tax_number": "999999990",
          "paycond_id": "584955579139752253", "address": "Rua A, 1", "postalcode": "4200-232",
          "postalcode_description": "Porto", "obs": "criado em teste"}


def test_criar_sequencia_mesmo_objectid_e_releitura():
    fake = FakeSoa()
    out = client(fake).create_supplier(FIELDS)

    assert out["ok"] is True and out["persisted"] is True
    assert all(out["checks"].values())
    actions = [c["action"] for c in fake.calls]
    assert actions[:5] == ["NEW", "GET,INFO", "MERGE", "SAVE", "CLOSE"]
    assert actions[5:] == ["OPEN", "GET,INFO", "CLOSE"]            # releitura (sem SAVE)
    assert len({c["oid"] for c in fake.calls[1:5]}) == 1          # MESMO ObjectID do NEW ao CLOSE
    merge = fake.calls[2]
    assert merge["path"].startswith("/service/entity/maindataset,storerelation.storedata,")
    assert merge["json"]["maindataset"][0]["description"] == "TESTE XPLENDOR A"
    assert merge["json"]["datasetsupplier"][0]["paycond_id"] == "584955579139752253"
    assert merge["json"]["address.defaultaddress"][0]["postalcode"] == "4200-232"
    assert merge["json"]["address.defaultaddress"][0]["postalcode_description"] == "Porto"
    assert [s["deleted"] for s in merge["json"]["storerelation.storedata"]] == [0, 0]   # lojas todas ativas


def test_cliente_e_utilizador_reenviados_intactos():
    fake = FakeSoa()
    client(fake).create_supplier(FIELDS)
    merge = fake.calls[2]["json"]
    assert merge["datasetclient"] == model()["datasetclient"]
    assert merge["datasetemployee"] == model()["datasetemployee"]
    assert merge["datasetclient"][0]["deleted"] == 1 and merge["datasetemployee"][0]["deleted"] == 1


def test_editar_sem_storerelation_mantem_campos_nao_enviados():
    fake = FakeSoa()
    c = client(fake)
    created = c.create_supplier(FIELDS)
    fake.calls.clear()

    out = c.update_supplier(created["pingwin_id"], {"description": "TESTE XPLENDOR A EDIT", "paycond_id": "16010",
                                                    "address": "Rua B, 2"})

    assert out["ok"] is True
    actions = [c["action"] for c in fake.calls]
    assert actions[:5] == ["OPEN", "GET,INFO", "MERGE", "SAVE", "CLOSE"]
    assert fake.calls[0]["path"] == f"/service/entity/{created['pingwin_id']}"
    merge = fake.calls[2]["json"]
    assert "storerelation.storedata" not in merge
    assert merge["maindataset"][0]["tax_number"] == "999999990"                      # mantido do vivo
    assert merge["address.defaultaddress"][0]["postalcode_description"] == "Porto"   # mantido
    assert out["confirm"]["description"] == "TESTE XPLENDOR A EDIT"
    assert out["confirm"]["paycond_id"] == "16010"


def test_anular_delete_close_sem_objectid_e_confirma_state():
    fake = FakeSoa()
    c = client(fake)
    sid = c.create_supplier(FIELDS)["pingwin_id"]
    fake.calls.clear()

    out = c.void_supplier(sid, "DS")

    assert out == {"ok": True, "voided_confirmed": True, "void_http": 200, "in_state0_ativos": False,
                   "in_state1_anulados": True, "pingwin_id": sid}
    void = [c for c in fake.calls if c["action"] == "DELETE,CLOSE"][0]
    assert void["path"] == f"/service/supplier/{sid}"
    assert void["oid"] is None and void["json"] is None


def test_anular_recusa_se_nao_estiver_ativo():
    fake = FakeSoa()
    out = client(fake).void_supplier("inexistente", "DS")
    assert out["ok"] is False
    assert not any(c["action"] == "DELETE,CLOSE" for c in fake.calls)


def test_close_tambem_quando_o_merge_falha():
    fake = FakeSoa(merge_status=500)
    out = client(fake).create_supplier(FIELDS)
    assert out["ok"] is False and out["aborted_before_commit"] is True
    actions = [c["action"] for c in fake.calls]
    assert "SAVE" not in actions and actions[-1] == "CLOSE"


def test_close_tambem_quando_o_save_falha():
    fake = FakeSoa(save_status=500)
    out = client(fake).create_supplier(FIELDS)
    assert out["ok"] is False and "não pode estar em branco" in out["error"]
    assert [c["action"] for c in fake.calls][-1] == "CLOSE"
    assert fake.store == {}


def test_close_tambem_quando_o_get_falha():
    fake = FakeSoa(get_status=500)
    try:
        client(fake).create_supplier(FIELDS)
        raise AssertionError("devia levantar")
    except RuntimeError:
        pass
    assert [c["action"] for c in fake.calls] == ["NEW", "GET,INFO", "CLOSE"]


def test_guarda_nif_duplicado_antes_do_save():
    fake = FakeSoa(tax_number_count=1)
    out = client(fake).create_supplier(FIELDS)
    assert out["ok"] is False and out["duplicate_nif"] is True
    assert "SAVE" not in [c["action"] for c in fake.calls]
    assert fake.store == {}

    fake2 = FakeSoa(tax_number_count=1)
    assert client(fake2).create_supplier(FIELDS, allow_duplicate_nif=True)["ok"] is True   # forçado


def test_edicao_com_nif_inalterado_nao_e_bloqueada_por_duplicados_antigos():
    fake = FakeSoa()
    c = client(fake)
    sid = c.create_supplier(FIELDS)["pingwin_id"]
    fake.tax_number_count = 1          # outra entidade já tem o mesmo NIF (legado)
    assert c.update_supplier(sid, {"description": "TESTE XPLENDOR A2"})["ok"] is True
    assert c.update_supplier(sid, {"tax_number": "123456789"})["ok"] is False   # NIF mudou → guarda


def test_nome_obrigatorio_sem_pedidos():
    fake = FakeSoa()
    out = client(fake).create_supplier(dict(FIELDS, description="  "))
    assert out["ok"] is False and fake.calls == []
