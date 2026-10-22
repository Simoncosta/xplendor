"""
XPLENDOR — lançar a NOTA DE CRÉDITO de fornecedor (1205) no PingWin, com o MESMO fluxo da fatura
(launch_supplier_invoice), em RASCUNHO (8001). Respostas simuladas tiradas do HAR de 21/10/2026
(fixtures/pingwin_1205/har_1205.json: só os campos usados, sem sessão nem nomes). Prova, sem rede:
  · a sequência completa da 1205 (NEW → modelo → fornecedor → linhas → moradas → total → EDIT do
    cabeçalho com a data da nota, o Documento de referência e 8001 → verifydoc → SAVE → CLOSE);
  · a nota nasce Fechada (8002) no modelo e fica em rascunho; valores positivos; sem acerto;
  · a referência ou a data que não ficam → CLOSE SEM SAVE;
  · só 1209 e 1205 (a nota de débito 1206 é recusada).
"""
import json
import os
import sys

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from sources.pingwin.mycloudpie import MyCloudPieClient  # noqa: E402

HAR = json.load(open(os.path.join(os.path.dirname(__file__), "fixtures", "pingwin_1205", "har_1205.json"), encoding="utf-8"))
BASE = "https://srv-soa.example:8136"
DC = "1205"
VAS = HAR["request_line_new"][0]["product_id"]  # o artigo do vasilhame no HAR (UN, isento)


class R:
    def __init__(self, status=200, payload=None, headers=None):
        self.status_code = status
        self._p = payload if payload is not None else {}
        self.headers = headers or {}
        self.text = json.dumps(self._p)

    def json(self):
        return self._p


def bump(key: str) -> str:
    """As keys do PingWin têm versão (o 3.º bloco de 8): cada resposta traz a seguinte."""
    blocks = [key[i:i + 8] for i in range(0, len(key), 8)]
    blocks[2] = f"{int(blocks[2], 16) + 1:08X}"
    return "".join(blocks)


class FakePW1205:
    """Servidor simulado com as respostas do HAR: guarda a nota em memória e versiona as keys."""

    def __init__(self, keep_reference=True, keep_date=True):
        self.calls = []
        self.header = dict(HAR["model_header"])  # 8002 (Fechada) e a data do dia, como no HAR
        self.lines = []
        self.keep_reference = keep_reference
        self.keep_date = keep_date

    def _total(self):
        return round(sum(float(l["price"]) * float(l["qnt"]) for l in self.lines), 2)

    def post(self, url, data=None, headers=None, **kw):
        path = url.split("/service/", 1)[1]
        action = headers.get("Action")
        body = json.loads(data) if data else None
        self.calls.append({"path": path, "action": action, "body": body, "oid": headers.get("ObjectID")})
        if path == DC and action == "NEW":
            return R(200, {DC: {}}, {"ObjectID": "OID"})
        if path == DC and action in ("SAVE", "CLOSE"):
            return R(200, {DC: {}})
        if path == f"{DC}/header,doctaxes.taxgroup" and action == "GET,INFO":
            assert body is None, "o modelo pede-se SEM corpo"
            return R(200, {DC: {"header": [self.header], "doctaxes.taxgroup": HAR["taxgroups"]}})
        if path == f"{DC}/header" and action == "EDIT":
            row = body[0]
            assert row["key"] == self.header["key"], f"key antiga no header: {row['key']}"
            if "entity_id" in row and len(row) == 2:
                self.header = {**HAR["header_after_entity"], "entity_id": row["entity_id"]}
            else:
                upd = {k: v for k, v in row.items() if k != "key"}
                if not self.keep_reference:
                    upd.pop("docreference_number", None)
                if not self.keep_date:
                    upd.pop("doc_date", None)
                self.header = {**self.header, **upd, "key": bump(self.header["key"]),
                               "total": round(self._total() + float(row.get("adjustment") or 0), 2)}
            return R(200, {DC: {"header": [self.header]}})
        if path == f"{DC}/header" and action == "GET,INFO":
            return R(200, {DC: {"header": [{**self.header, "total": self._total()}]}})
        if path == f"{DC}/details" and action == "NEW":
            line = {**HAR["line_new"], "key": f"{HAR['line_new']['key'][:-1]}{len(self.lines) + 1}",
                    "product_id": body[0]["product_id"], "unit_id": body[0]["unit_id"]}
            self.lines.append(line)
            return R(200, {DC: {"details": [line]}})
        if path == f"{DC}/details" and action == "EDIT":
            row = body[0]
            line = next(x for x in self.lines if x["key"] == row["key"])  # key antiga → StopIteration
            assert isinstance(row["price"], str) and isinstance(row["qnt"], str), "valores em texto, como no HAR"
            line.update({k: v for k, v in row.items() if k != "key"})
            line["key"] = bump(line["key"])
            line["total"] = round(float(line["price"]) * float(line["qnt"]), 2)
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
    c.api_url = BASE
    c.session = fake
    c.database = "db"
    c.app_grupopie = "grp"
    return c


# Uma nota de 45,00 € (caixas de vasilhame devolvidas, isentas), como a XPLENDOR a envia: valores positivos.
DOC = {"docconfig_id": DC, "entity_id": HAR["request_entity"][0]["entity_id"], "docstatus_id": "8001", "target_total": "45.00", "max_adjustment": "0.00",
       "docreference_id": "", "docreference_number": "NC CX/0196", "docreference_date": "20261015T00:00:00", "doc_date": "20261015T00:00:00",
       "lines": [{"product_id": VAS, "unit_id": "11001", "qnt": "6.000000", "price": "5.000000", "discount1": "0", "vat_rate": 0},
                 {"product_id": VAS, "unit_id": "11001", "qnt": "1.000000", "price": "5.000000", "discount1": "0", "vat_rate": 0},
                 {"product_id": VAS, "unit_id": "11001", "qnt": "2.000000", "price": "5.000000", "discount1": "0", "vat_rate": 0}]}


def test_the_1205_flow_is_the_invoice_flow_in_draft_with_the_note_date_and_reference():
    fake = FakePW1205()
    res = client(fake).launch_supplier_invoice(DOC)
    assert res["ok"] is True and res["saved"] is True, res
    assert res["docconfig_id"] == DC and res["document"] == "VNC BOVNC/89" and res["closed"] is True
    seq = [(c["path"].split(",")[0], c["action"]) for c in fake.calls]
    assert seq[:3] == [(DC, "NEW"), (f"{DC}/header", "GET,INFO"), (f"{DC}/header", "EDIT")]
    assert seq[3:9] == [(f"{DC}/details", "NEW"), (f"{DC}/details", "EDIT")] * 3
    assert seq[9:] == [(f"{DC}/entity_address.address", "GET,INFO"), (f"{DC}/entity_address.address", "MERGE"), (f"{DC}/header", "GET,INFO"),
                       (f"{DC}/header", "EDIT"), (f"{DC}/fiscalrules.verifydoc", "GET,INFO"), (DC, "SAVE"), (DC, "CLOSE")]
    assert all(c["oid"] == "OID" for c in fake.calls[1:])
    # O fornecedor, como no HAR: só a key e o entity_id.
    assert fake.calls[2]["body"] == [{"key": HAR["model_header"]["key"], "entity_id": DOC["entity_id"]}]
    # Cada linha: NEW com artigo e unidade; EDIT com o preço e a quantidade em texto (positivos).
    assert fake.calls[3]["body"] == [{"product_id": VAS, "unit_id": "11001"}]
    assert {k: fake.calls[4]["body"][0][k] for k in ("price", "qnt", "discount1")} == {"price": "5.000000", "qnt": "6.000000", "discount1": "0"}
    # O cabeçalho final: a data da nota (não a do dia), a referência com a data dela, sem acerto e em RASCUNHO.
    final = fake.calls[12]["body"][0]
    assert final["doc_date"] == "20261015T00:00:00" != HAR["model_header"]["doc_date"]
    assert (final["docreference_id"], final["docreference_number"], final["docreference_date"]) == ("", "NC CX/0196", "20261015T00:00:00")
    assert (final["adjustment"], final["docstatus_id"]) == ("0.00", "8001")
    assert HAR["model_header"]["docstatus_id"] == "8002"  # sem isto ficava Fechada (o defeito da 1205)
    assert res["header_before_save"]["docstatus_id"] == "8001" and res["header_before_save"]["doc_date"] == "20261015T00:00:00"
    assert res["header_before_save"]["total"] == 45.0


def test_a_total_that_is_not_the_notes_closes_without_save():
    fake = FakePW1205()
    res = client(fake).launch_supplier_invoice({**DOC, "target_total": "45.01"})
    assert res["saved"] is False and res["reason"] == "acerto"
    assert ("1205", "SAVE") not in [(c["path"], c["action"]) for c in fake.calls]
    assert fake.calls[-1]["action"] == "CLOSE"


def test_reference_or_date_that_does_not_stay_closes_without_save():
    for fake, word in ((FakePW1205(keep_reference=False), "referência"), (FakePW1205(keep_date=False), "data")):
        res = client(fake).launch_supplier_invoice(DOC)
        assert res["saved"] is False and word in res["error"] and "Nada foi gravado" in res["error"]
        assert ("1205", "SAVE") not in [(c["path"], c["action"]) for c in fake.calls]
        assert fake.calls[-1]["action"] == "CLOSE"


def test_only_invoices_and_credit_notes_are_launched():
    fake = FakePW1205()
    res = client(fake).launch_supplier_invoice({**DOC, "docconfig_id": "1206"})
    assert res == {"ok": False, "saved": False, "error": "Tipo de documento não permitido (1206)."}
    assert fake.calls == []


def test_the_har_fixture_has_no_session_data_or_names():
    text = json.dumps(HAR, ensure_ascii=False)
    for bad in ("SessionID", "Signature", "Rua "):
        assert bad not in text
    assert HAR["docconfig"][0]["docconfigid"] == "1205" and HAR["docconfig"][0]["stksignal"] == -1
    assert HAR["docconfig"][0]["ordered_dates"] is False  # a data da nota pode ser anterior à do último documento
    assert HAR["docreference"] == [{"key": HAR["docreference"][0]["key"], "id": "", "document": ""}]  # sem tipo de referência
