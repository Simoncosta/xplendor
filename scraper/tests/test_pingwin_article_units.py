"""
XPLENDOR — Auditoria v2/v2.1: a escrita das unidades de um artigo no PingWin, sem rede.

O PingWin tem unidades GLOBAIS (de todos os artigos) e não deixa mudar a quantidade de uma unidade.
Prova:
  · cada operação lê o artigo antes, grava UMA coisa e relê depois;
  · regra 1: antes de criar, reutiliza a unidade global com a mesma base e a mesma quantidade; só cria
    se não existir, e nunca com um nome repetido;
  · regra 2: nunca anula nem altera uma unidade global (nem a que a XPLENDOR criou);
  · pôr a unidade como compra por defeito e voltar ao que estava deixa o artigo como estava;
  · a guarda pelo código ("0" = nada; lista = só esses; vazia = todos); nunca a unidade base.
"""
import os
import sys

import pytest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from sources.pingwin.article_units import UnitWriteRefused, write_article_unit  # noqa: E402
from sources.pingwin.mycloudpie import MyCloudPieClient  # noqa: E402

A = "584955579139803444"
UNIDADE = "11001"
CAIXA6 = "584955579139625325"   # «Caixa 6 Unidades», global, com quantidade 1 (incoerente)
GRADE24 = "584955579139626529"  # «Grade 24», global, com quantidade 24


class FakePingwin:
    """O estado do PingWin: o artigo e a grelha das unidades. Regista as escritas, por ordem."""

    def __init__(self):
        self.product = {"id": A, "code": "101343", "description": "TESTE XPLENDOR STOCK A", "base_unit_id": UNIDADE,
                        "default_purchase_unit_id": CAIXA6, "default_stock_unit_id": UNIDADE, "default_sale_unit_id": UNIDADE}
        self.units = [
            {"id": UNIDADE, "description": "Unidade", "product_id": "", "parent_id": "", "parent_qnt": 1, "deleted": 0},
            {"id": CAIXA6, "description": "Caixa 6 Unidades", "product_id": "", "parent_id": UNIDADE, "parent_qnt": 1, "deleted": 0},
            {"id": GRADE24, "description": "Grade 24", "product_id": "", "parent_id": UNIDADE, "parent_qnt": 24, "deleted": 0},
        ]
        self.writes = []
        self.seq = 0

    def read_product(self, pid):
        return {"found": pid == A, "maindataset": dict(self.product) if pid == A else {}}

    def fetch_units(self):
        return [dict(u) for u in self.units]

    def create_unit(self, row, extra_keys=()):
        self.writes.append(("create_unit", dict(row)))
        self.seq += 1
        u = {"id": f"NEW{self.seq}", "deleted": 0, "product_id": "", **row}
        self.units.append(u)
        return dict(u)

    def save_unit(self, unit):
        self.writes.append(("save_unit", dict(unit)))
        target = next(u for u in self.units if u["id"] == unit["id"])
        target.update({k: v for k, v in unit.items() if k != "id"})
        return dict(target)

    def update_product(self, pid, changes):
        self.writes.append(("update_product", dict(changes)))
        self.product.update(changes)
        return {"ok": True, "confirm": {"code": self.product["code"]}}


def _w(pw, op, data, allowed=("101343",)):
    return write_article_unit(pw, A, op, data, list(allowed))


def test_creates_when_missing_then_purchase_unit_and_back_leaves_the_article_as_it_was():
    pw = FakePingwin()
    start = pw.read_product(A)["maindataset"]

    r1 = _w(pw, "create", {"description": "Caixa 6 un", "parent_qnt": 6})
    assert r1["ok"] and not r1["reused"] and r1["note"] == "Criada (unidade global, de todos os artigos)."
    new_id = r1["unit"]["id"]
    # Filha da unidade base do artigo, com a quantidade 6; sem product_id (o PingWin não o guarda).
    assert pw.writes[0] == ("create_unit", {"description": "Caixa 6 un", "shortname": "Caixa 6 un", "parent_id": UNIDADE, "parent_qnt": "6"})

    assert _w(pw, "set_purchase", {"unit_id": new_id})["after"]["default_purchase_unit_id"] == new_id
    assert _w(pw, "set_purchase", {"unit_id": CAIXA6})["after"]["default_purchase_unit_id"] == CAIXA6
    assert [w[0] for w in pw.writes] == ["create_unit", "update_product", "update_product"]
    assert pw.read_product(A)["maindataset"] == start


def test_reuses_the_global_unit_with_the_same_base_and_quantity_without_writing():
    pw = FakePingwin()
    r = _w(pw, "create", {"description": "Grade 24 un", "parent_qnt": 24})
    assert r["ok"] and r["reused"] and r["written"]["id"] == GRADE24
    assert "Reutilizada a unidade «Grade 24»" in r["note"]
    assert pw.writes == []
    # Uma segunda vez também não cria (nunca duplicados).
    pw.units.append({"id": "X6", "description": "Caixa 6 un", "product_id": "", "parent_id": UNIDADE, "parent_qnt": 6, "deleted": 0})
    assert _w(pw, "create", {"description": "Caixa 6 un", "parent_qnt": 6})["written"]["id"] == "X6"
    assert pw.writes == []


def test_never_a_unit_with_a_repeated_name():
    pw = FakePingwin()
    with pytest.raises(UnitWriteRefused, match="não cria duplicados"):
        _w(pw, "create", {"description": "Caixa 6 Unidades", "parent_qnt": 6})
    assert pw.writes == []


def test_never_voids_nor_changes_a_global_unit_even_one_created_by_xplendor():
    pw = FakePingwin()
    new_id = _w(pw, "create", {"description": "Caixa 6 un", "parent_qnt": 6})["unit"]["id"]
    for unit_id in (CAIXA6, new_id):
        with pytest.raises(UnitWriteRefused, match="nunca a anula nem a altera"):
            _w(pw, "void", {"unit_id": unit_id})
        with pytest.raises(UnitWriteRefused, match="nunca a anula nem a altera"):
            _w(pw, "edit_factor", {"unit_id": unit_id, "parent_qnt": 24, "try_anyway": True})
    # Mudar a quantidade: recusado antes de tocar no PingWin (o PingWin também não deixa).
    with pytest.raises(UnitWriteRefused):
        _w(pw, "edit_factor", {"unit_id": CAIXA6, "parent_qnt": 6})
    assert [w[0] for w in pw.writes] == ["create_unit"]


@pytest.mark.parametrize("allowed,blocked", [(["0"], True), (["999999"], True), (["101343"], False), ([], False)])
def test_guard_by_article_code(allowed, blocked):
    pw = FakePingwin()
    if blocked:
        with pytest.raises(UnitWriteRefused):
            _w(pw, "set_purchase", {"unit_id": GRADE24}, allowed)
        assert pw.writes == []
    else:
        assert _w(pw, "set_purchase", {"unit_id": GRADE24}, allowed)["ok"]


def test_never_the_base_unit():
    pw = FakePingwin()
    with pytest.raises(UnitWriteRefused, match="unidade base"):
        _w(pw, "set_purchase", {"unit_id": UNIDADE, "base_unit_id": "11002"})
    assert pw.writes == []


def test_mismatch_when_the_reread_does_not_show_the_change():
    pw = FakePingwin()
    pw.update_product = lambda pid, changes: {"ok": True, "confirm": {}}  # o PingWin "aceita" mas não grava
    r = _w(pw, "set_purchase", {"unit_id": GRADE24})
    assert r["ok"] is False and r["note"] == f"Unidade de compra relida: {CAIXA6}."


# ── O cliente: o NEW da grelha só leva o product_id quando se pede (extra_keys) ─────────────
class FakeResp:
    def __init__(self, payload=None, headers=None):
        self.status_code = 200
        self._payload = payload
        self.text = ""
        self.headers = headers or {}

    def json(self):
        return self._payload


class GridSession:
    def __init__(self):
        self.rows = [{"id": UNIDADE, "description": "Unidade"}]
        self.calls = []

    def post(self, url, *a, **k):
        action = k.get("headers", {}).get("Action")
        self.calls.append((action, k.get("json")))
        if action == "NEW":
            row = {"id": "N1", **k["json"][0]}
            self.rows.append(row)
            return FakeResp({"units": {"maindataset": [row]}})
        return FakeResp({"units": {"maindataset": [dict(r) for r in self.rows]}}, {"ObjectID": "OID"})


def test_create_unit_commits_the_exact_list_and_sends_product_id_only_when_asked():
    c = MyCloudPieClient(auth_url="https://h:8136", api_url="https://h:8136", username="u", password="p", report_id="r",
                         stores="1", frontend_url="https://fe", database="db", app_version="1")
    c.session = GridSession()
    c.create_unit({"description": "Caixa 6 un", "parent_id": UNIDADE, "parent_qnt": 6, "product_id": A}, extra_keys=("product_id",))
    assert next(body for action, body in c.session.calls if action == "NEW") == [{"description": "Caixa 6 un", "parent_id": UNIDADE, "parent_qnt": "6", "product_id": A}]
    assert [a for a, _ in c.session.calls] == ["OPEN,GET,INFO", "GET,INFO", "NEW", "GET,INFO", "EDIT,SAVE", "GET,INFO"]
    c.session = GridSession()
    c.create_unit({"description": "X", "parent_id": UNIDADE, "parent_qnt": 1, "product_id": A})
    assert "product_id" not in next(body for action, body in c.session.calls if action == "NEW")[0]
