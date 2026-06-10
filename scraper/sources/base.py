"""Interface comum dos adapters de fontes de mercado (MS2.b)."""
from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass
from typing import ClassVar, Generator, Optional

from config import SearchFilters


@dataclass
class RawListing:
    """Listing tal como vem da fonte, antes da normalização para o payload
    do Laravel. `source` identifica o adapter que o produziu (preservado
    ponta-a-ponta até `car_market_snapshots.source`).

    NOTA MS2.a (2026-06-10): `external_id` é o ID do anúncio NA FONTE
    (Standvirtual: `ad.id` numérico; CustoJusto: `listID`). O unique
    composite [source, external_id] na BD já garante separação cross-fonte,
    pelo que `source_listing_id` foi descartado como redundante.
    """
    external_id: str
    title: str
    url: str
    price_raw: Optional[str]
    params: dict          # km, ano, combustível, caixa, potência, etc.
    category: Optional[str]
    region: Optional[str]
    price_evaluation: Optional[str]  # GOOD_PRICE, LOW_PRICE, HIGH_PRICE, NONE
    source: str = "standvirtual"


class SourceAdapter(ABC):
    """Contrato comum dos adapters.

    Cada adapter declara um `name` (slug) e implementa `search()` como
    generator que yields batches de RawListing. Erro de rede / HTML
    inesperado / bloqueio devolve lista vazia + log estruturado — NUNCA
    propaga excepção para o orquestrador (isolamento de falha por fonte
    é critério de aceitação da MS2.e).
    """

    name: ClassVar[str]

    def __init__(self, filters: Optional[SearchFilters] = None):
        self.filters = filters or SearchFilters()

    @abstractmethod
    def search(self) -> Generator[list[RawListing], None, None]:
        """Yields batches de listings para os filtros configurados."""
        ...
