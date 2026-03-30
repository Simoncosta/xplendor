import re
import logging
from typing import Optional
from dataclasses import dataclass

from scraper import RawListing

logger = logging.getLogger(__name__)


@dataclass
class NormalizedSnapshot:
    """Schema final para enviar ao Laravel (car_market_snapshots)."""
    external_id: str
    source: str

    # Identificação da viatura
    brand: Optional[str]
    model: Optional[str]
    year: Optional[int]
    title: str
    url: str
    category: Optional[str]
    region: Optional[str]

    # Preço
    price: Optional[float]
    price_evaluation: Optional[str]  # good_price, low_price, high_price, average, None

    # Estado
    km: Optional[int]
    fuel: Optional[str]
    gearbox: Optional[str]
    power_hp: Optional[int]
    color: Optional[str]
    doors: Optional[int]

    # Com valor padrão — têm de vir no fim
    price_currency: str = "EUR"

    def to_dict(self) -> dict:
        return {
            "external_id": self.external_id,
            "source": self.source,
            "brand": self.brand,
            "model": self.model,
            "year": self.year,
            "title": self.title,
            "url": self.url,
            "category": self.category,
            "region": self.region,
            "price": self.price,
            "price_currency": self.price_currency,
            "price_evaluation": self.price_evaluation,
            "km": self.km,
            "fuel": self.fuel,
            "gearbox": self.gearbox,
            "power_hp": self.power_hp,
            "color": self.color,
            "doors": self.doors,
        }


class ListingNormalizer:

    # Mapeamento de combustíveis para valores canónicos
    FUEL_MAP = {
        "gasolina": "gasoline",
        "petrol": "gasoline",
        "benzina": "gasoline",
        "diesel": "diesel",
        "gasóleo": "diesel",
        "gasoleo": "diesel",
        "elétrico": "electric",
        "electrico": "electric",
        "electric": "electric",
        "híbrido": "hybrid",
        "hibrido": "hybrid",
        "hybrid": "hybrid",
        "plug-in": "plugin_hybrid",
        "plug in": "plugin_hybrid",
        "gpl": "lpg",
        "lpg": "lpg",
        "gnv": "cng",
        "hidrogénio": "hydrogen",
    }

    GEARBOX_MAP = {
        "manual": "manual",
        "automático": "automatic",
        "automatico": "automatic",
        "automatic": "automatic",
        "semi-automático": "semi_automatic",
        "sequencial": "semi_automatic",
    }

    def normalize(self, raw: RawListing) -> Optional[NormalizedSnapshot]:
        try:
            brand, model = self._parse_brand_model(raw.title, raw.params)
            year = self._parse_year(raw.params)
            price = self._parse_price(raw.price_raw)
            km = self._parse_km(raw.params)
            fuel = self._parse_fuel(raw.params)
            gearbox = self._parse_gearbox(raw.params)
            power_hp = self._parse_power(raw.params)
            color = self._parse_field(raw.params, ["color", "cor", "colour"])
            doors = self._parse_doors(raw.params)
            price_evaluation = self._normalize_price_evaluation(raw.price_evaluation)

            return NormalizedSnapshot(
                external_id=raw.external_id,
                source=raw.source,
                brand=brand,
                model=model,
                year=year,
                title=raw.title,
                url=raw.url,
                category=raw.category,
                region=raw.region,
                price=price,
                price_evaluation=price_evaluation,
                km=km,
                fuel=fuel,
                gearbox=gearbox,
                power_hp=power_hp,
                color=color,
                doors=doors,
            )
        except Exception as e:
            logger.debug(f"Erro ao normalizar anúncio {raw.external_id}: {e}")
            return None

    def _normalize_price_evaluation(self, raw: Optional[str]) -> Optional[str]:
        """Normaliza o indicador de preço do Standvirtual para valores canónicos."""
        if not raw:
            return None
        mapping = {
            "GOOD_PRICE": "good_price",
            "LOW_PRICE": "low_price",
            "HIGH_PRICE": "high_price",
            "AVERAGE": "average",
            "IN": "average",        # "Dentro da média"
            "BELOW": "good_price",  # "Abaixo da média"
            "ABOVE": "high_price",  # "Acima da média"
            "NONE": None,
        }
        return mapping.get(raw.upper(), raw.lower())

    # ------------------------------------------------------------------
    # Marca / Modelo
    # ------------------------------------------------------------------

    def _parse_brand_model(self, title: str, params: dict) -> tuple[Optional[str], Optional[str]]:
        # Keys reais do Standvirtual: "make" e "model"
        brand = self._parse_field(params, ["make", "brand", "marca"])
        model = self._parse_field(params, ["model", "modelo"])

        if not brand and title:
            parts = title.strip().split()
            if len(parts) >= 2:
                brand = parts[0].capitalize()
                model = parts[1] if len(parts) > 1 else None

        return (
            brand.strip().capitalize() if brand else None,
            model.strip() if model else None,
        )

    def _parse_year(self, params: dict) -> Optional[int]:
        # Key real do Standvirtual: "first_registration_year"
        raw = self._parse_field(params, ["first_registration_year", "year", "ano"])
        if raw:
            year = self._extract_int(str(raw))
            if year and 1950 <= year <= 2030:
                return year
        return None

    def _parse_km(self, params: dict) -> Optional[int]:
        # Key real do Standvirtual: "mileage"
        raw = self._parse_field(params, ["mileage", "km", "kilometers"])
        if raw:
            km = self._extract_int(str(raw))
            if km is not None and 0 <= km <= 2_000_000:
                return km
        return None

    def _parse_fuel(self, params: dict) -> Optional[str]:
        # Key real do Standvirtual: "fuel_type"
        raw = self._parse_field(params, ["fuel_type", "fuel", "combustivel"])
        if raw:
            normalized = self.FUEL_MAP.get(str(raw).lower().strip())
            return normalized or str(raw).lower().strip()
        return None

    def _parse_gearbox(self, params: dict) -> Optional[str]:
        # Key real do Standvirtual: "gearbox"
        raw = self._parse_field(params, ["gearbox", "caixa", "transmission"])
        if raw:
            normalized = self.GEARBOX_MAP.get(str(raw).lower().strip())
            return normalized or str(raw).lower().strip()
        return None

    def _parse_power(self, params: dict) -> Optional[int]:
        # Key real do Standvirtual: "engine_power"
        raw = self._parse_field(params, ["engine_power", "power", "potencia", "hp", "cv"])
        if raw:
            power = self._extract_int(str(raw))
            if power and 10 <= power <= 2000:
                return power
        return None

    def _parse_price(self, price_raw: Optional[str]) -> Optional[float]:
        # O Standvirtual já envia o preço como número inteiro (ex: "30165")
        if not price_raw:
            return None
        cleaned = re.sub(r"[^\d]", "", str(price_raw))
        try:
            value = float(cleaned)
            if 100 <= value <= 5_000_000:
                return round(value, 2)
        except ValueError:
            pass
        return None

    def _parse_doors(self, params: dict) -> Optional[int]:
        raw = self._parse_field(params, ["doors", "portas"])
        if raw:
            doors = self._extract_int(str(raw))
            if doors and 2 <= doors <= 6:
                return doors
        return None

    # ------------------------------------------------------------------
    # Helpers
    # ------------------------------------------------------------------

    def _parse_field(self, params: dict, keys: list[str]) -> Optional[str]:
        for key in keys:
            val = params.get(key)
            if val is not None and str(val).strip():
                return str(val).strip()
        return None

    def _extract_int(self, text: str) -> Optional[int]:
        """Extrai primeiro número inteiro de uma string."""
        cleaned = re.sub(r"[^\d]", "", text.split(".")[0].split(",")[0])
        if cleaned:
            try:
                return int(cleaned[:10])  # evitar números absurdos
            except ValueError:
                pass
        return None