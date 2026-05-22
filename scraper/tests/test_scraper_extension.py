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
