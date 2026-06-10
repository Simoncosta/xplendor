"""
Unit tests for vehicle_type routing and max_results enforcement.
Run from the scraper/ directory: python -m pytest tests/
"""
import subprocess
import sys
import os

# Ensure scraper root is on path so imports resolve without installation.
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

import pytest
from config import (
    VEHICLE_TYPE_PATHS,
    VALID_VEHICLE_TYPES,
    MAX_RESULTS_HARD_CAP,
    VEHICLE_TYPE_MAX_RESULTS_DEFAULT,
    ScraperConfig,
    resolve_max_results,
)

SCRAPER_CONTAINER = os.getenv("SCRAPER_CONTAINER", "xplendor-scraper")
MAIN_PY = "/scraper/main.py"


def _container_running() -> bool:
    """Returns True if the scraper Docker container is reachable."""
    result = subprocess.run(
        ["docker", "inspect", "--format", "{{.State.Running}}", SCRAPER_CONTAINER],
        capture_output=True, text=True,
    )
    return result.returncode == 0 and result.stdout.strip() == "true"


def _docker_run(*args: str) -> subprocess.CompletedProcess:
    return subprocess.run(
        ["docker", "exec", SCRAPER_CONTAINER, "python", MAIN_PY, *args],
        capture_output=True, text=True,
    )


class TestSearchPathRouting:
    """vehicle_type=car builds /carros; vehicle_type=motorhome builds /autocaravanas."""

    def test_car_uses_carros_path(self):
        assert VEHICLE_TYPE_PATHS["car"] == "/carros"

    def test_motorhome_uses_autocaravanas_path(self):
        assert VEHICLE_TYPE_PATHS["motorhome"] == "/autocaravanas"

    def test_config_search_path_is_overridable(self):
        cfg = ScraperConfig()
        assert cfg.search_path == "/carros"
        cfg.search_path = VEHICLE_TYPE_PATHS["motorhome"]
        assert cfg.search_path == "/autocaravanas"


class TestInvalidVehicleType:
    """Unsupported vehicle_type values are not in VALID_VEHICLE_TYPES."""

    def test_caravan_not_valid_yet(self):
        assert "caravan" not in VALID_VEHICLE_TYPES

    def test_motorcycle_not_valid(self):
        assert "motorcycle" not in VALID_VEHICLE_TYPES

    def test_empty_string_not_valid(self):
        assert "" not in VALID_VEHICLE_TYPES

    def test_valid_types_are_car_and_motorhome(self):
        assert set(VALID_VEHICLE_TYPES) == {"car", "motorhome"}


class TestMaxResults:
    """resolve_max_results applies per-type defaults and hard cap."""

    def test_car_default_is_50(self):
        assert resolve_max_results("car", None) == VEHICLE_TYPE_MAX_RESULTS_DEFAULT["car"]

    def test_motorhome_default_is_15(self):
        assert resolve_max_results("motorhome", None) == VEHICLE_TYPE_MAX_RESULTS_DEFAULT["motorhome"]

    def test_explicit_value_respected_below_cap(self):
        assert resolve_max_results("car", 30) == 30

    def test_hard_cap_enforced(self):
        assert resolve_max_results("car", MAX_RESULTS_HARD_CAP + 999) == MAX_RESULTS_HARD_CAP

    def test_hard_cap_exactly_allowed(self):
        assert resolve_max_results("motorhome", MAX_RESULTS_HARD_CAP) == MAX_RESULTS_HARD_CAP


@pytest.mark.skipif(not _container_running(), reason="xplendor-scraper container not running")
class TestSmokeCli:
    """
    Smoke tests that run main.py inside the Docker container.
    These catch import errors and NameErrors that unit tests cannot see,
    because those only execute when the full module is bootstrapped.
    Skipped automatically when the container is not up (e.g. CI without Docker).
    """

    def test_help_exits_zero(self):
        """--help must parse, print usage, and exit 0 — no NameError, no ImportError."""
        result = _docker_run("--help")
        assert result.returncode == 0, f"--help failed:\n{result.stderr}"

    def test_help_includes_vehicle_type_flag(self):
        """--vehicle-type must appear in --help output with both valid choices."""
        result = _docker_run("--help")
        assert "--vehicle-type" in result.stdout
        assert "car" in result.stdout
        assert "motorhome" in result.stdout

    def test_help_includes_max_results_flag(self):
        """--max-results must appear in --help output with the hard cap value."""
        result = _docker_run("--help")
        assert "--max-results" in result.stdout
        assert str(MAX_RESULTS_HARD_CAP) in result.stdout

    def test_invalid_vehicle_type_exits_nonzero(self):
        """Passing an unknown vehicle_type must be rejected by argparse (exit 2)."""
        result = _docker_run("--source", "standvirtual", "--mode", "preview", "--vehicle-type", "caravan")
        assert result.returncode != 0


# ─────────────────────────────────────────────────────────────────────────────
# MS1.a — slugs de combustível por vertical (validados empiricamente 2026-06-10)
# ─────────────────────────────────────────────────────────────────────────────
from config import _normalize_fuel, FUEL_SLUGS_BY_VERTICAL, SearchFilters


class TestFuelSlugsByVertical:
    """O Standvirtual usa dicionários diferentes por secção: /carros (gaz, lpg)
    vs /autocaravanas (gasolina). Slug não validado → omitir (omissão > slug
    errado, que zeraria a pesquisa silenciosamente)."""

    # ── /carros — regressão (mapa antigo continua a funcionar) ────────────
    def test_car_gasolina_to_gaz(self):
        assert _normalize_fuel("gasolina", "car") == "gaz"

    def test_car_petrol_to_gaz(self):
        assert _normalize_fuel("petrol", "car") == "gaz"

    def test_car_diesel_to_diesel(self):
        assert _normalize_fuel("diesel", "car") == "diesel"

    def test_car_lpg_to_gpl(self):
        assert _normalize_fuel("lpg", "car") == "gpl"

    def test_car_hybrid_to_hibride_gaz(self):
        assert _normalize_fuel("hybrid", "car") == "hibride-gaz"

    def test_car_plugin_hybrid_to_plugin_hybrid(self):
        assert _normalize_fuel("plug-in-hybrid", "car") == "plugin-hybrid"

    def test_car_electric_to_electric(self):
        assert _normalize_fuel("electric", "car") == "electric"

    # ── /autocaravanas — só slugs validados ───────────────────────────────
    def test_motorhome_gasolina_stays_gasolina(self):
        """Validado live 2026-06-10: ?fuel=gasolina → 2 anúncios."""
        assert _normalize_fuel("gasolina", "motorhome") == "gasolina"

    def test_motorhome_petrol_aliased_to_gasolina(self):
        """Alias EN → slug pt-PT do Standvirtual motorhome."""
        assert _normalize_fuel("petrol", "motorhome") == "gasolina"

    def test_motorhome_diesel_stays_diesel(self):
        """Validado live 2026-06-10: ?fuel=diesel → 321 anúncios (88% do mercado)."""
        assert _normalize_fuel("diesel", "motorhome") == "diesel"

    # ── /autocaravanas — slugs NÃO validados → omitir ─────────────────────
    def test_motorhome_lpg_returns_none(self):
        """0 resultados em 364 anúncios (inconclusivo). MS1.a: omite o parâmetro."""
        assert _normalize_fuel("lpg", "motorhome") is None

    def test_motorhome_gpl_returns_none(self):
        assert _normalize_fuel("gpl", "motorhome") is None

    def test_motorhome_electric_returns_none(self):
        assert _normalize_fuel("electric", "motorhome") is None

    def test_motorhome_hybrid_returns_none(self):
        assert _normalize_fuel("hybrid", "motorhome") is None

    def test_motorhome_plug_in_hybrid_returns_none(self):
        assert _normalize_fuel("plug-in-hybrid", "motorhome") is None

    # ── Controlo negativo: slug carro NÃO se aplica a motorhome ───────────
    def test_motorhome_gaz_returns_none(self):
        """Slug Standvirtual de /carros — em /autocaravanas dá 0 anúncios.
        Validado live 2026-06-10. MS1.a: omite o parâmetro."""
        assert _normalize_fuel("gaz", "motorhome") is None

    # ── Vertical desconhecida ─────────────────────────────────────────────
    def test_unknown_vertical_returns_none(self):
        assert _normalize_fuel("diesel", "tractor") is None

    # ── Mapas têm a estrutura esperada ────────────────────────────────────
    def test_both_verticals_present(self):
        assert "car" in FUEL_SLUGS_BY_VERTICAL
        assert "motorhome" in FUEL_SLUGS_BY_VERTICAL


class TestSearchFiltersOmitFuelWhenUnvalidated:
    """SearchFilters.to_query_params omite filter_enum_fuel_type quando o slug
    não tem mapeamento para a vertical — não envia slug errado."""

    def test_motorhome_with_gasolina_emits_fuel(self):
        f = SearchFilters(brand="Carado", year_from=2018, year_to=2022,
                          fuel="gasolina", vehicle_type="motorhome")
        params = f.to_query_params()
        assert params.get("search[filter_enum_fuel_type]") == "gasolina"

    def test_motorhome_with_diesel_emits_fuel(self):
        f = SearchFilters(fuel="diesel", vehicle_type="motorhome")
        assert f.to_query_params().get("search[filter_enum_fuel_type]") == "diesel"

    def test_motorhome_with_lpg_omits_fuel(self):
        """Caso central do bug MS1.a: lpg em motorhome zerava a pesquisa.
        Agora omite e o scrape devolve mercado da marca/ano sem filtro de fuel."""
        f = SearchFilters(brand="Carado", year_from=2018, year_to=2022,
                          fuel="lpg", vehicle_type="motorhome")
        params = f.to_query_params()
        assert "search[filter_enum_fuel_type]" not in params
        # restantes filtros mantêm-se
        assert params["search[filter_float_first_registration_year:to]"] == 2022

    def test_motorhome_with_electric_omits_fuel(self):
        f = SearchFilters(fuel="electric", vehicle_type="motorhome")
        assert "search[filter_enum_fuel_type]" not in f.to_query_params()

    def test_car_with_gasolina_emits_gaz(self):
        """Regressão: carros continuam a usar o slug 'gaz' (validado 984 anúncios)."""
        f = SearchFilters(brand="Bmw", year_from=2018, year_to=2022,
                          fuel="gasolina", vehicle_type="car")
        assert f.to_query_params().get("search[filter_enum_fuel_type]") == "gaz"

    def test_car_with_lpg_emits_gpl(self):
        """Regressão: carros mantêm o mapa antigo."""
        f = SearchFilters(fuel="lpg", vehicle_type="car")
        assert f.to_query_params().get("search[filter_enum_fuel_type]") == "gpl"

    def test_default_vehicle_type_is_car(self):
        """Caller que não passe vehicle_type cai no comportamento de carros (default)."""
        f = SearchFilters(fuel="gasolina")
        # Sem vehicle_type → "car" → "gaz"
        assert f.to_query_params().get("search[filter_enum_fuel_type]") == "gaz"


# ─────────────────────────────────────────────────────────────────────────────
# MS1.b — widen-on-empty (só motorhome)
# ─────────────────────────────────────────────────────────────────────────────
# Em /autocaravanas 88% do mercado é diesel — o filtro de combustível
# discrimina pouco e zera pesquisas. Se a tentativa 1 com fuel devolveu 0
# anúncios, scraper tenta de novo sem fuel.
# Tem de distinguir "zero legítimo" (HTTP 200 + totalCount 0) de "falha
# técnica" (HTTP error, parse falhou) — só o primeiro caso dispara o widen.
from unittest.mock import patch, MagicMock
from scraper import StandvirtualScraper

FUEL_KEY = "search[filter_enum_fuel_type]"


def _make_scraper(vehicle_type: str, fuel: str | None) -> StandvirtualScraper:
    """Cria scraper com search_path do vertical certo (config é global)."""
    from config import VEHICLE_TYPE_PATHS, config
    config.search_path = VEHICLE_TYPE_PATHS[vehicle_type]
    filters = SearchFilters(
        brand="Carado",
        year_from=2018,
        year_to=2022,
        fuel=fuel,
        vehicle_type=vehicle_type,
    )
    return StandvirtualScraper(filters=filters)


def _fake_next_data(total_count: int) -> dict:
    """Estrutura mínima compatível com _get_advert_search → totalCount.
    Importante: urqlState[key].data é uma JSON STRING (o parser faz json.loads
    interno), não um dict. Fixture replica essa forma."""
    import json
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
