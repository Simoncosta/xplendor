import os
import unicodedata
from dataclasses import asdict, dataclass, field
from typing import Optional
from dotenv import load_dotenv

load_dotenv()

def _slugify_search_value(value: str) -> str:
    normalized = unicodedata.normalize("NFKD", value).encode("ascii", "ignore").decode("ascii")
    cleaned = normalized.strip().lower().replace("/", " ").replace("_", " ")
    
    return "-".join(part for part in cleaned.split() if part)


def _normalize_fuel(value: str) -> str:
    slug = _slugify_search_value(value)
    aliases = {
        "gasolina": "petrol",
        "petrol": "petrol",
        "diesel": "diesel",
        "eletrico": "electric",
        "electrico": "electric",
        "electric": "electric",
        "hibrido": "hybrid",
        "hybrid": "hybrid",
        "plug-in-hybrid": "plug_in_hybrid",
        "plugin-hybrid": "plug_in_hybrid",
        "hibrido-plug-in": "plug_in_hybrid",
        "gpl": "lpg",
        "lpg": "lpg",
    }
    return aliases.get(slug, slug)


def _normalize_gearbox(value: str) -> str:
    slug = _slugify_search_value(value)
    aliases = {
        "automatica": "automatic",
        "caixa-automatica": "automatic",
        "automatic": "automatic",
        "manual": "manual",
        "caixa-manual": "manual",
    }
    return aliases.get(slug, slug)


@dataclass
class SearchFilters:
    brand: Optional[str] = None
    model: Optional[str] = None
    year_from: Optional[int] = None
    year_to: Optional[int] = None
    fuel: Optional[str] = None
    gearbox: Optional[str] = None
    price_from: Optional[int] = None
    price_to: Optional[int] = None

    def to_path_suffix(self) -> str:
        """Marca e ano 'desde' vão no PATH (formato path-based do Standvirtual).

        Formato validado em 2026-05-28: /{categoria}/{marca}/desde-{ano}. Sem
        marca, devolve "" (degradação graciosa — fica só /{categoria} + query).
        Tem de ficar consistente com MarketSnapshotService::buildSearchUrl (PHP).
        """
        if not self.brand:
            return ""
        suffix = "/" + _slugify_search_value(self.brand)
        if self.year_from is not None:
            suffix += f"/desde-{int(self.year_from)}"
        return suffix

    def to_query_params(self) -> dict:
        # Combustível e limite superior do ano em query (fuel SEM índice [0]).
        # Marca, modelo e ano-from NÃO vão em query: marca/ano-from vão no PATH
        # (ver to_path_suffix) e o modelo é abandonado no URL — a precisão vem
        # da filtragem por modelo/preço no processamento (lado Laravel).
        params = {}

        if self.fuel:
            params["search[filter_enum_fuel_type]"] = _normalize_fuel(self.fuel)
        if self.year_to is not None:
            params["search[filter_float_first_registration_year:to]"] = int(self.year_to)
        if self.gearbox:
            params["search[filter_enum_gearbox]"] = _normalize_gearbox(self.gearbox)
        if self.price_from is not None:
            params["search[filter_float_price:from]"] = int(self.price_from)
        if self.price_to is not None:
            params["search[filter_float_price:to]"] = int(self.price_to)

        return params

    def to_log_dict(self) -> dict:
        return {key: value for key, value in asdict(self).items() if value not in (None, "", [])}


VEHICLE_TYPE_PATHS: dict[str, str] = {
    "car": "/carros",
    "motorhome": "/autocaravanas",
}

VALID_VEHICLE_TYPES: list[str] = list(VEHICLE_TYPE_PATHS.keys())

# Default max results per vehicle type — prevents runaway scraping and cost overruns.
VEHICLE_TYPE_MAX_RESULTS_DEFAULT: dict[str, int] = {
    "car": 50,
    "motorhome": 15,
}

MAX_RESULTS_HARD_CAP: int = 100


def resolve_max_results(vehicle_type: str, requested: Optional[int]) -> int:
    """Returns effective max_results, applying per-type default and hard cap."""
    default = VEHICLE_TYPE_MAX_RESULTS_DEFAULT.get(vehicle_type, 50)
    effective = requested if requested is not None else default
    return min(effective, MAX_RESULTS_HARD_CAP)


@dataclass
class ScraperConfig:
    # --- Standvirtual ---
    base_url: str = "https://www.standvirtual.com"
    search_path: str = "/carros"

    # Parâmetros de pesquisa (podes expandir por categoria)
    search_params: dict = field(default_factory=lambda: {
        "search[order]": "created_at_first:desc",
        "page": 1,
    })

    max_pages: int = int(os.getenv("SCRAPER_MAX_PAGES", 50))
    delay_between_requests: float = float(os.getenv("SCRAPER_DELAY", 2.5))   # segundos
    delay_between_pages: float = float(os.getenv("SCRAPER_PAGE_DELAY", 4.0))
    request_timeout: int = 15
    max_retries: int = 3

    # Buscar cor e portas na página individual de cada carro
    # AVISO: activa 1 request por carro — muito mais lento
    fetch_details: bool = os.getenv("SCRAPER_FETCH_DETAILS", "false").lower() == "true"
    delay_between_details: float = float(os.getenv("SCRAPER_DETAIL_DELAY", 1.5))

    # --- Laravel API ---
    laravel_api_url: str = os.getenv("LARAVEL_API_URL", "http://localhost:8001")
    laravel_api_token: str = os.getenv("LARAVEL_API_TOKEN", "")
    laravel_endpoint: str = "/api/market/snapshots"
    batch_size: int = int(os.getenv("SCRAPER_BATCH_SIZE", 25))  # enviar em batches

    # --- User-Agents rotativos ---
    user_agents: list = field(default_factory=lambda: [
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36",
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/123.0",
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 14_3) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.3 Safari/605.1.15",
        "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
    ])

    # --- Logging ---
    log_level: str = os.getenv("LOG_LEVEL", "INFO")
    log_file: Optional[str] = os.getenv("LOG_FILE", "scraper.log")

    def build_search_params(self, filters: Optional[SearchFilters] = None) -> dict:
        params = dict(self.search_params)
        if filters:
            params.update(filters.to_query_params())

        import logging
        logging.getLogger("debug").info(f"QUERY PARAMS FINAL: {params}")

        return params


config = ScraperConfig()
