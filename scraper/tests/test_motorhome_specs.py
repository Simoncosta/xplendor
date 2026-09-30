"""FASE 0 — testes do parsing de specs de autocaravana (motorhome_specs.py).

Os casos positivos e os falsos positivos das classes TestParse* são LINHAS
REAIS do corpus de 91 anúncios únicos (Standvirtual detalhe ×18, CustoJusto
×73) recolhido em 2026-09-30. As classes TestAdversarial* cobrem inputs
plausíveis CONSTRUÍDOS das mesmas frases reais numa passagem adversarial
(verificadores independentes que confirmaram cada falha executando o parser).
A taxa de acerto sobre o corpus completo vive em
tests/fixtures/motorhome_specs_ground_truth.json + test_corpus_accuracy.
"""
import json
import pathlib
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))

import pytest

from motorhome_specs import (
    normalize_structured_layout,
    parse_beds,
    parse_displacement_cc,
    parse_layout_text,
    parse_length_m,
    parse_motorhome_specs,
)


# ─────────────────────────────────────────────────────────────────────────────
# DORMIDAS
# ─────────────────────────────────────────────────────────────────────────────

class TestParseBeds:
    @pytest.mark.parametrize("text,expected", [
        # Padrão dominante nos profissionais SV
        ("Lugares dormida 4", 4),
        ("Lug dormida - 5", 5),
        ("Lugares dormida 3", 3),
        # "N Dormidas"
        ("- Cama Central, Quarto Privativo, Cama Basculante, 4 Dormidas, Cozinha em L", 4),
        ("- 6 Dormidas", 6),
        # "lugares de livrete e dormida" — o número serve os dois
        ("Autocaravana Nacional 4 lugares de livrete e dormida, tem cama transversal", 4),
        ("5 lugares de viagem e de dormida", 5),
        ("✅ 4 lugares de viagem e dormir", 4),
        # livrete N + dormidas N' distintos — ganham as dormidas explícitas
        ("5 lugares de livrete e 6 dormidas.", 6),
        ("4 lugares de livrete 4 Dormidas Autocaravana Fiat Ducato", 4),
        # padrão fraco (último recurso)
        ("Transporta e acomoda 4 pessoas, distribuídas por uma cama central", 4),
        # nº ANTES de "Lugares Dormida" ganha (título real CJ 45024620)
        ("4 Lugares Dormida 5 Lugares livrete", 4),
        ("5 Viajantes + 4 Dormidas", 4),
    ])
    def test_padroes_reais(self, text, expected):
        assert parse_beds(text) == expected

    @pytest.mark.parametrize("text", [
        # lotação NÃO é dormidas
        "5 lugares de livrete.",
        "Lotação 4-5",
        # layout de camas sem contagem
        "Cama Francesa + Cama basculante",
        "Transformação de cama casal em individuais",
        "Estofo adicional entre as camas individuais longitudinais",
        # números de equipamento (falsos positivos reais do corpus)
        "caixa de 6 velocidades",
        "Fogão c/ 3 bocas",
        "Pacote de Conforto de Habitação 4",
        "",
    ])
    def test_falsos_positivos_reais(self, text):
        assert parse_beds(text) is None


# ─────────────────────────────────────────────────────────────────────────────
# COMPRIMENTO
# ─────────────────────────────────────────────────────────────────────────────

class TestParseLength:
    @pytest.mark.parametrize("text,expected", [
        ("Comprimento -  7450 mm", 7.45),          # milímetros
        ("Com. 6,25 Larg. 2,10 Alt. 2,65 mt", 6.25),
        ("Com. 5,10/6,20 Larg 2,25 Alt 2,65", 6.20),   # par lança/total → total
        ("Com. 6,70 Larg 2,36 Alt 2,80", 6.70),
        (": Comprimento 6,72 mt + Largura 2,17 mt + Altura 2,75 mt", 6.72),
        (": Comprimento 7,00 mt + Largura 2,30 mt", 7.00),
        ("Caixa manual Comprimento 6.8m 5 lugares", 6.8),
        ("Apenas 5,90mt de comprimento", 5.90),
        # sem unidade nem "de" (real, CJ #64)
        ("Compacta com apenas 5,90 comprimento", 5.90),
    ])
    def test_padroes_reais(self, text, expected):
        assert parse_length_m(text) == pytest.approx(expected)

    @pytest.mark.parametrize("text", [
        "Pneus - 195/70 R14",                    # pneus
        "Largura 2,30 mt + Altura 2,80 mt",       # sem comprimento
        "rampa para autocaravana com 200cm de comprimento",  # acessório (2 m < gama)
        "Dimensões: 350x180",
        "",
    ])
    def test_falsos_positivos_reais(self, text):
        assert parse_length_m(text) is None


# ─────────────────────────────────────────────────────────────────────────────
# CILINDRADA
# ─────────────────────────────────────────────────────────────────────────────

class TestParseDisplacement:
    @pytest.mark.parametrize("text,expected", [
        ("Motorização -Fiat 2200cc 140 CV", 2200),
        ("Motorização – Mercedes 2987cc 180cv", 2987),
        ("Motor - 2.800Cm. Com 128 Cv.", 2800),     # CJ, ponto de milhar + "Cm"
        ("2 200 cm3", 2200),                        # displayValue SV detalhe
        ("- Motor 2.3 Multijet 130cv", 2300),
        ("Chausson Welcome 79 2.3M-Jet 130cv - Fiat Ducato", 2300),
        ("Fiat ducato integral laika 2500TDI 116CV", 2500),  # cc colado ao sufixo
        ("Autocaravana Citroen Jumper2.8 JTD", 2800),        # colado ao modelo
        ("Autocaravana Citroen Jumper 2.8 HDI Pilote, 2004", 2800),
        ("Motor 1.9TD fiável e muito económico", 1900),
        ("motor 2 .0 JTD com alarme", 2000),         # espaço real no corpus
        ("MONCAYO SILVER 422 | 2.3 JTD | 2006", 2300),
        ("Auto caravana Peugeot j5 motor 2.5 S/documentos", 2500),
        # unidade "c.c." com pontos + âncora "cilindrada" (reais, CJ)
        ("Motor 2198 c.c. a Diesel com 110 cv", 2198),
        ("Motor 2.200c.c 155 cv", 2200),
        ("Cilindrada: 2.8 idtd 127cv", 2800),
        # litros com unidade "cc" errada do anunciante (real, CJ #63/68/69)
        ("Motor 2.8cc 129cv Caixa Manual", 2800),
        ("Motor 2.3cc 130cv", 2300),
        # cc inteiro sem unidade, âncora motor (real, CJ #25)
        ("Motor 2300 130cv motor Iveco", 2300),
        # litros + chassis conhecido (real, CJ #10)
        ("Elnagh Clipper 90 2.3 Ducato", 2300),
    ])
    def test_padroes_reais(self, text, expected):
        assert parse_displacement_cc(text) == expected

    @pytest.mark.parametrize("text", [
        "motor 140cv caixa de 6 velocidades",   # potência não é cilindrada
        "Aquecimento Truma Combi 6",
        "Frigorifico 156 lts",
        "Sunlight T 690L REF.N29",
        "130.000 KM",
        "Peso bruto 3500 Kgs",
        "Pneus - 195/70 R14",
        "rampa com 200cm de comprimento",       # 200 cm < gama de cilindrada
        "",
    ])
    def test_falsos_positivos_reais(self, text):
        assert parse_displacement_cc(text) is None


# ─────────────────────────────────────────────────────────────────────────────
# TIPOLOGIA
# ─────────────────────────────────────────────────────────────────────────────

class TestParseLayout:
    @pytest.mark.parametrize("text,expected", [
        ("Autocaravana perfilada. Ano 2013", "perfiladas"),
        ("Fiat Perfilada DEZEMBRO 2009", "perfiladas"),
        ("Fiat ducato integral laika 2500TDI", "integral"),
        ("Para venda autocaravana laika integral", "integral"),
        ("Autocaravana capucino em bom estado", "capucine"),
        ("furgão camperizado", "furgao"),
        ("Campervan pronta a viajar", "furgao"),
        ("Transporter T5 Camper legalizada ate 9 lugares", "furgao"),
    ])
    def test_padroes_reais(self, text, expected):
        assert parse_layout_text(text) == expected

    @pytest.mark.parametrize("text", [
        # Falso positivo REAL do corpus (Hymer 4x4)
        '"Tração integral 4matic apenas em combinação com 190 CV"',
        "tracção integral permanente",
        "traccao integral",
        # 'caravana' por texto nunca infere tipologia (colide com autocaravana)
        "Autocaravana Mercedes 2.8",
        "Caravana à venda em Bragança",
        "",
    ])
    def test_falsos_positivos_e_ambiguos(self, text):
        assert parse_layout_text(text) is None

    @pytest.mark.parametrize("value,expected", [
        ("perfiladas", "perfiladas"),
        ("Perfilada", "perfiladas"),
        ("integral", "integral"),
        ("capucine", "capucine"),
        ("capucino", "capucine"),
        ("furgao", "furgao"),
        ("campervan", "furgao"),
        ("caravana", "caravana"),        # estruturado SV aceita caravana
        ("atrelado-tenda", None),        # não é tipologia de autocaravana
        ("660", None),                   # category numérica da listagem SV
        (None, None),
        ("", None),
    ])
    def test_normalize_structured(self, value, expected):
        assert normalize_structured_layout(value) == expected


# ─────────────────────────────────────────────────────────────────────────────
# Entrada única + integração com anúncios reais completos
# ─────────────────────────────────────────────────────────────────────────────

class TestParseMotorhomeSpecs:
    def test_anuncio_real_completo_ci_magis(self):
        """Descrição REAL (SV detalhe d1, CI International Magis)."""
        description = (
            "Carateristicas:\nMarca - CI \nModelo - Magis 67 XT\n"
            "Motorização -Fiat 2200cc 140 CV \nCaixa automática\n"
            "Peso bruto - 3500 Kgs\nTara - 2970 Kgs\n"
            "Comprimento -  7450 mm\nLargura - 2350 mm\nAltura - 2850 mm\n"
            "Lug dormida - 5\nLugares livrete - 4\n"
        )
        specs = parse_motorhome_specs("CI International Magis Selekt 67 XT N20", description)
        assert specs == {"beds": 5, "length": 7.45, "displacement": 2200, "layout": None}

    def test_anuncio_real_completo_challenger_cj(self):
        """Body REAL (CJ listagem 44783572, Ford Challenger)."""
        body = (
            "Autocaravana perfilada. Ano 2013 Motorização: Ford Transit 140 cv "
            "Caixa manual Comprimento 6.8m 5 lugares de viagem e de dormida  Acessórios:"
        )
        specs = parse_motorhome_specs("Autocaravana Ford Challenger Genesis 52", body)
        assert specs["beds"] == 5
        assert specs["length"] == pytest.approx(6.8)
        assert specs["layout"] == "perfiladas"

    def test_texto_sem_specs_devolve_nones(self):
        """Caso negativo real: descrição só de equipamento (SV corpus2 d1)."""
        description = (
            "ELECTRÓNICA/SEGURANÇA\nAC Cabine\nAirbags\nRetrovisores Ajustáveis\n"
            "Cruise Control\nPorta USB\nCONFORTO\nClarabóia Midi-heki na sala\n"
        )
        specs = parse_motorhome_specs("Sunlight T 68 Adventure REF.N50", description)
        assert specs == {"beds": None, "length": None, "displacement": None, "layout": None}


# ─────────────────────────────────────────────────────────────────────────────
# Passagem ADVERSARIAL — inputs plausíveis construídos de frases reais do
# corpus; cada um correspondeu a uma falha confirmada antes do endurecimento.
# ─────────────────────────────────────────────────────────────────────────────

class TestAdversarialBeds:
    def test_livrete_noutra_linha_nao_rouba_as_dormidas(self):
        # Template real de c1/d1 com as linhas pela ordem inversa.
        assert parse_beds("Lugares livrete - 4\nLug dormida - 5") == 5
        assert parse_beds("Lugares livrete 4\nLugares dormida 5") == 5

    def test_dormidas_de_aluguer_sao_noites_nao_camas(self):
        assert parse_beds("Aluguer, mínimo 2 dormidas em época alta") is None

    def test_acomoda_sem_pessoas_nao_conta(self):
        assert parse_beds("garagem acomoda 2 bicicletas e pranchas") is None


class TestAdversarialLength:
    def test_preco_apos_a_palavra_comprimento_nao_e_comprimento(self):
        # Estrutura real de cj/44996331 com preço dentro da gama 4-9.5.
        assert parse_length_m("Apenas 5,90mt de comprimento   8.900  2008") == 5.90
        assert parse_length_m("bom estado, de comprimento   8.900  2008") is None

    def test_comprimento_de_acessorio_nao_e_do_veiculo(self):
        assert parse_length_m("Toldo Fiamma com 4,50 m de comprimento") is None

    def test_comprimento_em_cm(self):
        assert parse_length_m("Comprimento: 745 cm") == 7.45

    def test_palavra_truncada_pela_listagem(self):
        # Doc real cj/44991497: o site corta o body a meio da palavra.
        assert parse_length_m("apesar de medir apenas 5.9m de comprime") == 5.9


class TestAdversarialDisplacement:
    def test_medida_de_cama_nao_silencia_a_cilindrada(self):
        # As duas metades existem verbatim no corpus (c2/d8 + c1/d1).
        text = "Cama de casal 190cm x 140cm sempre feita. Motorização Fiat 2200cc 140 CV"
        assert parse_displacement_cc(text) == 2200

    def test_anos_nao_sao_cilindrada(self):
        assert parse_displacement_cc("Motor 2019 recondicionado, poucos km") is None
        assert parse_displacement_cc("Autocaravana VW LT ano 2005 TDI 102cv") is None

    def test_consumo_nao_e_cilindrada(self):
        assert parse_displacement_cc("motor consome 6.9 litros aos 100") is None

    def test_medida_em_cm_nao_e_cilindrada(self):
        assert parse_displacement_cc("Comprimento: 850 cm, garagem grande") is None

    def test_motorizacao_com_cc_inteiro(self):
        assert parse_displacement_cc("Motorização 2200") == 2200


class TestAdversarialLayout:
    def test_integral_adjetivo_nao_e_tipologia(self):
        assert parse_layout_text("Autocaravana Fiat Ducato, revisão integral feita em 2024") is None
        assert parse_layout_text("Pintura integral nova, sem ferrugem") is None

    def test_perfilada_explicita_ganha_ao_chassis_furgao(self):
        text = "Autocaravana perfilada construída sobre furgão Fiat Ducato"
        assert parse_layout_text(text) == "perfiladas"


# ─────────────────────────────────────────────────────────────────────────────
# Taxa de acerto sobre o corpus real anotado (ground truth manual)
# ─────────────────────────────────────────────────────────────────────────────

GROUND_TRUTH = pathlib.Path(__file__).parent / "fixtures" / "motorhome_specs_ground_truth.json"


@pytest.mark.skipif(not GROUND_TRUTH.exists(), reason="ground truth ainda não gerado")
def test_corpus_accuracy():
    """Compara o parser com a anotação manual do corpus real. Exige ≥90% de
    acerto por campo (acerto = igualdade exata, incluindo None==None)."""
    docs = json.loads(GROUND_TRUTH.read_text(encoding="utf-8"))
    fields = ["beds", "length", "displacement", "layout"]
    hits = {f: 0 for f in fields}
    misses: list[str] = []

    for doc in docs:
        got = parse_motorhome_specs(doc["title"], doc["text"])
        for f in fields:
            expected = doc["expected"][f]
            value = got[f]
            ok = (
                value == pytest.approx(expected)
                if isinstance(expected, float) else value == expected
            )
            if ok:
                hits[f] += 1
            else:
                misses.append(f"{doc['id']}·{f}: esperado {expected!r}, obtido {value!r}")

    total = len(docs)
    rates = {f: hits[f] / total for f in fields}
    detail = " | ".join(f"{f}={rates[f]:.0%}" for f in fields)
    assert all(r >= 0.90 for r in rates.values()), (
        f"Taxa de acerto abaixo de 90%: {detail}\nFalhas:\n" + "\n".join(misses)
    )
