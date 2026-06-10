"""
Tests do adapter Standvirtual.

MOVIDO de test_scraper_extension.py em MS2.b (2026-06-10). Asserções
intactas — só mudança de imports (passa a importar do novo módulo
sources.standvirtual em vez do shim scraper). O ficheiro shim continua
a funcionar; estes testes só vivem aqui por clareza arquitectural.

Inclui TestWidenOnEmpty (MS1.b) — distinção zero legítimo vs falha
técnica + isolamento ao motorhome. Logs do widen são contrato (runbook
depende — ver comentário de cabeçalho em sources/standvirtual.py).
"""
import json
import os
import sys
from unittest.mock import patch

# Garante que scraper root está no path.
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from config import SearchFilters
from sources.standvirtual import StandvirtualAdapter


FUEL_KEY = "search[filter_enum_fuel_type]"


def _make_scraper(vehicle_type: str, fuel):
    """Cria adapter com search_path do vertical certo (config é global)."""
    from config import VEHICLE_TYPE_PATHS, config
    config.search_path = VEHICLE_TYPE_PATHS[vehicle_type]
    filters = SearchFilters(
        brand="Carado",
        year_from=2018,
        year_to=2022,
        fuel=fuel,
        vehicle_type=vehicle_type,
    )
    return StandvirtualAdapter(filters=filters)


def _fake_next_data(total_count: int) -> dict:
    """Estrutura mínima compatível com _get_advert_search → totalCount.
    Importante: urqlState[key].data é uma JSON STRING (o parser faz json.loads
    interno), não um dict. Fixture replica essa forma."""
    return {
        "props": {
            "pageProps": {
                "urqlState": {
                    "x": {
                        "data": json.dumps({
                            "advertSearch": {
                                "totalCount": total_count,
                                "edges": [],
                                "pageInfo": {"pageSize": 32},
                            }
                        })
                    }
                }
            }
        }
    }


class TestWidenOnEmpty:
    """Critérios de aceitação MS1.b."""

    def test_motorhome_fuel_zero_triggers_retry_without_fuel(self):
        """Tentativa 1 com fuel → 0 anúncios. Tentativa 2 sem fuel acontece."""
        scraper = _make_scraper("motorhome", "gasolina")

        first_response = _fake_next_data(0)
        second_response = _fake_next_data(50)

        captured_params: list[dict] = []

        def fake_fetch_first(url, params):
            captured_params.append(dict(params))
            return (first_response if len(captured_params) == 1 else second_response,
                    0 if len(captured_params) == 1 else 50)

        with patch.object(scraper, "_fetch_first_page", side_effect=fake_fetch_first), \
             patch.object(scraper, "_parse_listings_from_next_data", return_value=[]):
            list(scraper.scrape_all())

        # 2 chamadas
        assert len(captured_params) == 2
        # 1ª tem fuel
        assert captured_params[0].get(FUEL_KEY) == "gasolina"
        # 2ª NÃO tem fuel (foi widened)
        assert FUEL_KEY not in captured_params[1]
        # year_to preservado (só removeu fuel)
        assert captured_params[1]["search[filter_float_first_registration_year:to]"] == 2022

    def test_motorhome_http_error_does_not_trigger_widen(self):
        """HTTP/parse error na 1ª tentativa → return sem retry-sem-fuel.
        Distinção crítica: erro não é zero legítimo."""
        scraper = _make_scraper("motorhome", "gasolina")

        call_count = [0]

        def fake_fetch_first(url, params):
            call_count[0] += 1
            return None  # simula HTTP/parse fail

        with patch.object(scraper, "_fetch_first_page", side_effect=fake_fetch_first):
            list(scraper.scrape_all())

        # Só 1 chamada (a 2ª não acontece em caso de erro)
        assert call_count[0] == 1

    def test_car_with_zero_does_not_trigger_widen(self):
        """Carros não disparam widening — mercado tem volume, 0 é provavelmente real."""
        scraper = _make_scraper("car", "gasolina")

        call_count = [0]
        captured_params: list[dict] = []

        def fake_fetch_first(url, params):
            call_count[0] += 1
            captured_params.append(dict(params))
            return (_fake_next_data(0), 0)

        with patch.object(scraper, "_fetch_first_page", side_effect=fake_fetch_first), \
             patch.object(scraper, "_parse_listings_from_next_data", return_value=[]):
            list(scraper.scrape_all())

        # Carros: só 1 tentativa (não dispara widening mesmo com 0)
        assert call_count[0] == 1

    def test_motorhome_without_initial_fuel_does_not_trigger_widen(self):
        """Se já não havia fuel filter, nada a alargar — não dispara widen."""
        scraper = _make_scraper("motorhome", None)

        call_count = [0]

        def fake_fetch_first(url, params):
            call_count[0] += 1
            return (_fake_next_data(0), 0)

        with patch.object(scraper, "_fetch_first_page", side_effect=fake_fetch_first), \
             patch.object(scraper, "_parse_listings_from_next_data", return_value=[]):
            list(scraper.scrape_all())

        # Sem fuel inicial: não há nada para remover
        assert call_count[0] == 1

    def test_motorhome_with_results_does_not_trigger_widen(self):
        """Se a 1ª tentativa devolveu anúncios, não há widening."""
        scraper = _make_scraper("motorhome", "diesel")

        call_count = [0]

        def fake_fetch_first(url, params):
            call_count[0] += 1
            return (_fake_next_data(321), 321)

        with patch.object(scraper, "_fetch_first_page", side_effect=fake_fetch_first), \
             patch.object(scraper, "_parse_listings_from_next_data", return_value=[]), \
             patch.object(scraper, "_get_total_pages", return_value=1):
            list(scraper.scrape_all())

        # Diesel → 321 anúncios, sem widening
        assert call_count[0] == 1


# ─────────────────────────────────────────────────────────────────────────────
# MS2.b — comportamento pré-MS2 inalterado até flip da MS2.e
# ─────────────────────────────────────────────────────────────────────────────

import main as main_module


class TestDefaultSourcesIsStandvirtualOnly:
    """MS2.b — invariante crítica do deploy: o scheduler/job de prod chama
    main.py SEM --sources. Default tem de continuar a fazer correr só o
    adapter Standvirtual até ao flip explícito da MS2.e. Mudar isto sem
    sincronizar com o backend parte produção em silêncio."""

    def test_default_constant_is_standvirtual(self):
        assert main_module.DEFAULT_SOURCES == "standvirtual"

    def test_parse_sources_none_resolves_to_standvirtual_only(self):
        assert main_module._parse_sources(None) == ["standvirtual"]

    def test_parse_sources_empty_string_resolves_to_standvirtual_only(self):
        assert main_module._parse_sources("") == ["standvirtual"]

    def test_parse_sources_explicit_standvirtual_alone(self):
        assert main_module._parse_sources("standvirtual") == ["standvirtual"]

    def test_arg_parser_default_sources_is_standvirtual(self):
        # Simula a invocação real do scheduler em prod (sem --sources).
        parser = main_module.build_arg_parser()
        args = parser.parse_args([
            "--source", "standvirtual",
            "--mode", "preview",
            "--vehicle-type", "motorhome",
        ])
        assert args.sources == "standvirtual"

    def test_parse_sources_rejects_unknown_source_with_clear_error(self):
        # Slug desconhecido falha CONTROLADO antes de qualquer fetch (vs slug
        # silente que infectaria a BD). Mesmo padrão do enum no Form Request.
        import pytest
        with pytest.raises(KeyError) as exc:
            main_module._parse_sources("olx")
        assert "olx" in str(exc.value)
        assert "standvirtual" in str(exc.value)  # mostra disponíveis
