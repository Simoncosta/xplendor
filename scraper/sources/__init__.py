"""Adapters de fontes de mercado (MS2.b, 2026-06-10).

A interface comum vive em base.SourceAdapter; cada fonte tem o seu módulo
(standvirtual.py, custojusto.py em MS2.c). main.py orquestra-os por nome
via --sources (CSV, default "standvirtual" — comportamento idêntico ao
pré-MS2 até ao flip da MS2.e).

scraper/scraper.py é um shim de retro-compatibilidade — re-exporta
StandvirtualScraper e RawListing para preservar imports antigos durante
a transição.
"""

from sources.base import RawListing, SourceAdapter
from sources.custojusto import CustojustoAdapter
from sources.standvirtual import StandvirtualAdapter, StandvirtualScraper

__all__ = [
    "RawListing", "SourceAdapter",
    "StandvirtualAdapter", "StandvirtualScraper",
    "CustojustoAdapter",
]


# Registry de adapters por slug. main.py resolve --sources contra isto.
# Adicionar uma fonte aqui exige sincronizar com:
#   - StoreMarketSnapshotRequest::VALID_SOURCES no Laravel (MS2.a)
#   - MARKET_SOURCE_LABELS em web/src/helpers/labels.ts (MS2.f)
ADAPTERS: dict[str, type[SourceAdapter]] = {
    "standvirtual": StandvirtualAdapter,
    "custojusto":   CustojustoAdapter,
}


def get_adapter_class(name: str) -> type[SourceAdapter]:
    """Resolve um slug de fonte para a classe do adapter. Levanta KeyError
    com mensagem clara se desconhecido (preferível a slug silente)."""
    cls = ADAPTERS.get(name)
    if cls is None:
        known = ", ".join(sorted(ADAPTERS.keys())) or "(nenhum)"
        raise KeyError(f"Source desconhecida: {name!r}. Disponíveis: {known}")
    return cls
