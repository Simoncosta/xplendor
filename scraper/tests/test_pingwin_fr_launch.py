"""
XPLENDOR — lançar a FATURA-RECIBO de compra (FR C, 584955579139752236) no PingWin, com o MESMO fluxo
da fatura (launch_supplier_invoice), em RASCUNHO (8001). Respostas simuladas tiradas do HAR de
23/10/2026 (fixtures/pingwin_frc/har_frc.json: só os campos usados, sem sessão nem nomes). Prova, sem rede:
  · a sequência completa da FR C (NEW → modelo → fornecedor → linha NEW e EDIT com o preço e a quantidade
    → moradas → total → EDIT do cabeçalho com a data da fatura, o Documento de referência e 8001 →
    verifydoc → SAVE → CLOSE), sem preencher a forma de pagamento;
  · o que o lançamento à mão deixou errado (a data do dia e a referência vazia) fica certo;
  · uma falha antes de gravar descarta o documento novo com CANCEL.
"""
import json
import os
import sys

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from sources.pingwin.mycloudpie import MyCloudPieClient  # noqa: E402

HAR = json.load(open(os.path.join(os.path.dirname(__file__), "fixtures", "pingwin_frc", "har_frc.json"), encoding="utf-8"))
DC = HAR["docconfig"]["docconfigid"]
RATES = {"Normal": 0.23, "Intermédia": 0.13, "Reduzida": 0.06, "Isenta": 0.0}


class R:
    def __init__(self, status=200, payload=None, headers=None):
        self.status_code = status
        self._p = payload if payload is not None else {}
        self.headers = headers or {}
        self.text = json.dumps(self._p)

    def json(self):
        return self._p


def bump(key: str) -> str:
    blocks = [key[i:i + 8] for i in range(0, len(key), 8)]
    blocks[2] = f"{int(blocks[2], 16) + 1:08X}"
    return "".join(blocks)


class FakeFR:
    """Servidor simulado com as respostas do HAR da FR C (o total inclui o IVA de cada linha)."""

    def __init__(self, fail_at=None):
        self.calls = []
        self.header = dict(HAR["model_header"])  # 8002 (Fechada) e a data do dia, como no HAR
        self.lines = []
        self.fail_at = fail_at

    def _total(self):
        return round(sum(round(float(l["price"]) * float(l["qnt"]), 2) * (1 + RATES[l["tax_description"]]) for l in self.lines), 2)

    def post(self, url, data=None, headers=None, **kw):
        path = url.split("/service/", 1)[1]
        action = headers.get("Action")
        body = json.loads(data) if data else None
        self.calls.append({"path": path, "action": action, "body": body, "oid": headers.get("ObjectID")})
        if self.fail_at == (path, action):
            return R(500, {"code": 500, "message": "falha simulada"})
        if path == DC and action == "NEW":
            return R(200, {DC: {}}, {"ObjectID": "OID"})
        if path == DC and action in ("SAVE", "CLOSE", "CANCEL"):
            return R(200, {DC: {}})
        if path == f"{DC}/header,doctaxes.taxgroup" and action == "GET,INFO":
            assert body is None
            return R(200, {DC: {"header": [self.header], "doctaxes.taxgroup": HAR["taxgroups"]}})
        if path == f"{DC}/header" and action == "EDIT":
            row = body[0]
            assert row["key"] == self.header["key"]
            if set(row) == {"key", "entity_id"}:
                self.header = {**HAR["header_after_entity"], "entity_id": row["entity_id"]}
            else:
                self.header = {**self.header, **{k: v for k, v in row.items() if k != "key"}, "key": bump(self.header["key"]),
                               "total": round(self._total() + float(row.get("adjustment") or 0), 2)}
            return R(200, {DC: {"header": [self.header]}})
        if path == f"{DC}/header" and action == "GET,INFO":
            return R(200, {DC: {"header": [{**self.header, "total": self._total()}]}})
        if path == f"{DC}/details" and action == "NEW":
            line = {**HAR["line_new"], "product_id": body[0]["product_id"], "unit_id": body[0]["unit_id"]}
            self.lines.append(line)
            return R(200, {DC: {"details": [line]}})
        if path == f"{DC}/details" and action == "EDIT":
            row = body[0]
            line = next(x for x in self.lines if x["key"] == row["key"])
            line.update({k: v for k, v in row.items() if k != "key"})
            line["key"] = bump(line["key"])
            return R(200, {DC: {"details": [line]}})
        if path.startswith(f"{DC}/entity_address.address") and action == "GET,INFO":
            return R(200, {DC: {"entity_address.address": [{"key": "E", "id": "A1"}], "expedition_address.address": [{"key": "X", "id": "A2"}],
                                "delivery_address.address": [{"key": "D", "id": "A1"}]}})
        if path.startswith(f"{DC}/entity_address.address") and action == "MERGE":
            return R(200, {DC: body})
        if path == f"{DC}/fiscalrules.verifydoc":
            return R(200, {DC: {"fiscalrules.verifydoc": HAR["verifydoc"]}})
        raise AssertionError(f"pedido inesperado {path} {action}")


def client(fake):
    c = MyCloudPieClient.__new__(MyCloudPieClient)
    c.api_url = "https://srv-soa.example:8136"
    c.session = fake
    c.database = "db"
    c.app_grupopie = "grp"
    return c


# A fatura-recibo do HAR (1 artigo a 1,89 € + IVA 23 % = 2,32 €), como a XPLENDOR a envia.
DOC = {"docconfig_id": DC, "entity_id": HAR["request_entity"][0]["entity_id"], "docstatus_id": "8001", "target_total": "2.32", "max_adjustment": "0.05",
       "docreference_id": "1649601156562", "docreference_number": "FR FR.JM26/23178", "docreference_date": "20261020T00:00:00", "doc_date": "20261020T00:00:00",
       "lines": [{"product_id": HAR["request_line_new"][0]["product_id"], "unit_id": "11001", "qnt": "1.000000", "price": "1.890000", "discount1": "0", "vat_rate": 23}]}


def test_the_fr_c_flow_is_the_invoice_flow_in_draft_with_the_invoice_date_and_reference():
    fake = FakeFR()
    res = client(fake).launch_supplier_invoice(DOC)
    assert res["ok"] is True and res["saved"] is True, res
    assert res["docconfig_id"] == DC and res["document"] == " BO/138" and res["closed"] is True
    seq = [(c["path"].split(",")[0], c["action"]) for c in fake.calls]
    assert seq == [(DC, "NEW"), (f"{DC}/header", "GET,INFO"), (f"{DC}/header", "EDIT"), (f"{DC}/details", "NEW"), (f"{DC}/details", "EDIT"),
                   (f"{DC}/entity_address.address", "GET,INFO"), (f"{DC}/entity_address.address", "MERGE"), (f"{DC}/header", "GET,INFO"),
                   (f"{DC}/header", "EDIT"), (f"{DC}/fiscalrules.verifydoc", "GET,INFO"), (DC, "SAVE"), (DC, "CLOSE")]
    # O preço e a quantidade escrevem-se sempre (no HAR o preço veio do artigo).
    assert {k: fake.calls[4]["body"][0][k] for k in ("price", "qnt")} == {"price": "1.890000", "qnt": "1.000000"}
    final = fake.calls[8]["body"][0]
    # À mão ficou a data do dia e a referência vazia; aqui, a data da fatura e o número do fornecedor.
    assert HAR["header_saved_by_hand"]["doc_date"] == "20261023T00:00:00" and HAR["header_saved_by_hand"]["docreference_number"] == ""
    assert (final["doc_date"], final["docreference_id"], final["docreference_number"], final["docreference_date"]) == (
        "20261020T00:00:00", "1649601156562", "FR FR.JM26/23178", "20261020T00:00:00")
    assert (final["docstatus_id"], final["adjustment"]) == ("8001", "0.00")
    # A forma de pagamento não se preenche (no HAR o PingWin gravou sem ela).
    assert not any("paymethod" in c["path"] for c in fake.calls)
    assert res["header_before_save"]["total"] == 2.32 and res["header_before_save"]["docstatus_id"] == "8001"


def test_a_failure_before_save_cancels_the_new_document():
    fake = FakeFR(fail_at=(f"{DC}/details", "EDIT"))
    res = client(fake).launch_supplier_invoice(DOC)
    assert res["saved"] is False and res["cancelled"] is True
    assert (DC, "SAVE") not in [(c["path"], c["action"]) for c in fake.calls]
    assert fake.calls[-1]["action"] == "CANCEL" == HAR["cancel_action"]


def test_the_fr_c_fixture_matches_the_type_and_has_no_session_data_or_names():
    c = HAR["docconfig"]
    assert (c["code"], c["stksignal"], c["settled"], c["default_docstatus0"]) == ("FR C", 1, True, "8002")
    assert HAR["paycondition"] == [{"id": "584955579139752255", "description": "Pronto Pagamento"}]
    assert {"id": "1649601156562", "document": "Documento de Referência"} in HAR["docreference"]
    text = json.dumps(HAR, ensure_ascii=False)
    for bad in ("SessionID", "Signature", "Rua ", "@"):
        assert bad not in text
