"""FASE 0 — integração das specs de autocaravana no ListingNormalizer.

Valida as prioridades de fonte por campo:
  displacement: parameter estruturado (engine_capacity) > texto livre
  layout:       body_type estruturado (detalhe SV) > filtro (override)
                > categoria canónica CustoJusto > texto
  beds/length:  título + description (só vehicle_type=motorhome)
E que carros NÃO ganham beds/layout/length (mas ganham displacement estruturado).
"""
import pathlib
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))

from normalizer import ListingNormalizer
from sources.base import RawListing


def _raw(params=None, title="Benimar Tessoro 481", category=None,
         source="standvirtual", url="https://example.test/ad-1"):
    return RawListing(
        external_id="123",
        title=title,
        url=url,
        price_raw="45000",
        params=params or {},
        category=category,
        region="Porto",
        price_evaluation=None,
        source=source,
    )


def _normalize(raw, vehicle_type="motorhome", body_type_override=None):
    return ListingNormalizer().normalize(
        raw, vehicle_type=vehicle_type, body_type_override=body_type_override
    )


class TestDisplacement:
    def test_estruturado_tem_prioridade_sobre_texto(self):
        raw = _raw(params={
            "engine_capacity": "2200",
            "description": "Motorização Fiat 2987cc",  # texto diz outra coisa
        })
        snap = _normalize(raw)
        assert snap.displacement == 2200

    def test_fallback_para_texto_sem_estruturado(self):
        raw = _raw(params={"description": "Motor 2.3 Multijet 130cv"})
        assert _normalize(raw).displacement == 2300

    def test_carro_tambem_ganha_displacement_estruturado(self):
        raw = _raw(params={"engine_capacity": "1997"})
        snap = _normalize(raw, vehicle_type="car")
        assert snap.displacement == 1997

    def test_estruturado_fora_da_gama_cai_no_texto(self):
        raw = _raw(params={"engine_capacity": "12", "description": "2.8 JTD"})
        assert _normalize(raw).displacement == 2800


class TestLayout:
    def test_body_type_estruturado_tem_prioridade_maxima(self):
        raw = _raw(params={"body_type": "caravana",
                           "description": "Autocaravana perfilada"})
        snap = _normalize(raw, body_type_override="integral")
        assert snap.layout == "caravana"

    def test_override_do_filtro_em_segundo(self):
        raw = _raw(params={"description": "Autocaravana perfilada"})
        snap = _normalize(raw, body_type_override="capucine")
        assert snap.layout == "capucine"

    def test_categoria_canonica_custojusto_em_terceiro(self):
        raw = _raw(source="custojusto", category="furgao", params={})
        assert _normalize(raw).layout == "furgao"

    def test_categoria_numerica_standvirtual_nao_e_layout(self):
        raw = _raw(source="standvirtual", category="660", params={})
        assert _normalize(raw).layout is None

    def test_texto_em_ultimo_recurso(self):
        raw = _raw(params={"description": "Autocaravana perfilada. Ano 2013"})
        assert _normalize(raw).layout == "perfiladas"

    def test_body_type_nao_canonico_cai_no_seguinte(self):
        raw = _raw(params={"body_type": "atrelado-tenda",
                           "description": "Autocaravana perfilada"})
        assert _normalize(raw).layout == "perfiladas"


class TestBedsLength:
    def test_extraidos_da_descricao_em_motorhome(self):
        raw = _raw(params={"description": "Lugares dormida 4\nCom. 6,70 Larg 2,36"})
        snap = _normalize(raw)
        assert snap.beds == 4
        assert snap.length == 6.70

    def test_carro_nunca_ganha_beds_layout_length(self):
        raw = _raw(params={"description": "Lugares dormida 4\nComprimento 6,70 mt\nperfilada"})
        snap = _normalize(raw, vehicle_type="car")
        assert snap.beds is None
        assert snap.layout is None
        assert snap.length is None

    def test_payload_inclui_campos_novos(self):
        raw = _raw(params={
            "engine_capacity": "2300",
            "body_type": "perfiladas",
            "description": "Lugares dormida 5\nComprimento 7,45 mt",
        })
        payload = _normalize(raw).to_dict()
        assert payload["beds"] == 5
        assert payload["layout"] == "perfiladas"
        assert payload["displacement"] == 2300
        assert payload["length"] == 7.45

    def test_sem_specs_fica_tudo_none(self):
        payload = _normalize(_raw(params={})).to_dict()
        assert payload["beds"] is None
        assert payload["layout"] is None
        assert payload["displacement"] is None
        assert payload["length"] is None
