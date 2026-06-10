import argparse
import json
import logging
import logging.handlers
import time
from datetime import datetime
from typing import Optional
from dotenv import load_dotenv
import sys

from config import SearchFilters, config, VEHICLE_TYPE_PATHS, VALID_VEHICLE_TYPES, MAX_RESULTS_HARD_CAP, VEHICLE_TYPE_MAX_RESULTS_DEFAULT, resolve_max_results
from sources import ADAPTERS, get_adapter_class
from normalizer import ListingNormalizer
from sender import LaravelSender


# MS2.b — default --sources mantém comportamento pré-MS2 (só Standvirtual).
# Mudar este default sem flip explícito (MS2.e) parte produção em silêncio:
# o scheduler/job de prod chama main.py com `--vehicle-type N --max-results M`
# (sem --sources). Default à letra → mesmo adapter, mesma sequência, mesmos logs.
DEFAULT_SOURCES = "standvirtual"

load_dotenv()

def setup_logging():
    level = getattr(logging, config.log_level.upper(), logging.INFO)

    handlers = [
        logging.StreamHandler(sys.stderr)  # 🔥 FIX
    ]

    if config.log_file:
        handlers.append(
            logging.handlers.RotatingFileHandler(
                config.log_file,
                maxBytes=10 * 1024 * 1024,
                backupCount=5,
                encoding="utf-8",
            )
        )

    logging.basicConfig(
        level=level,
        format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
        handlers=handlers,
    )


def build_arg_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Scraper modular do Standvirtual com preview e filtros opcionais.",
    )
    parser.add_argument("--source", required=True)
    parser.add_argument("--mode", required=True, choices=["preview", "run"])
    parser.add_argument(
        "--vehicle-type",
        dest="vehicle_type",
        default="car",
        choices=VALID_VEHICLE_TYPES,
        help=f"Vehicle category to scrape. Valid: {', '.join(VALID_VEHICLE_TYPES)}. Default: car.",
    )
    parser.add_argument(
        "--max-results",
        type=int,
        dest="max_results",
        default=None,
        help=f"Maximum snapshots to collect (hard cap: {MAX_RESULTS_HARD_CAP}). Defaults per type: {VEHICLE_TYPE_MAX_RESULTS_DEFAULT}.",
    )
    parser.add_argument("--brand")
    parser.add_argument("--model")
    parser.add_argument("--year-from", type=int, dest="year_from")
    parser.add_argument("--year-to", type=int, dest="year_to")
    parser.add_argument("--fuel")
    parser.add_argument("--gearbox")
    parser.add_argument("--body-type", dest="body_type")
    parser.add_argument("--price-from", type=int, dest="price_from")
    parser.add_argument("--price-to", type=int, dest="price_to")
    parser.add_argument("--preview-limit", type=int, default=10, dest="preview_limit")
    # MS2.b — multi-fonte. CSV de slugs (registry: scraper/sources/__init__.py).
    # Default 'standvirtual' = comportamento pré-MS2 inalterado.
    parser.add_argument(
        "--sources",
        default=DEFAULT_SOURCES,
        help=(
            f"CSV de fontes a executar. Disponíveis: {', '.join(sorted(ADAPTERS.keys()))}. "
            f"Default: {DEFAULT_SOURCES} (mantém comportamento pré-MS2)."
        ),
    )
    return parser


def build_filters_from_args(args: argparse.Namespace) -> SearchFilters:
    year_from = args.year_from
    year_to = args.year_to
    price_from = args.price_from
    price_to = args.price_to

    if year_from and year_to and year_from > year_to:
        year_from, year_to = year_to, year_from

    if price_from and price_to and price_from > price_to:
        price_from, price_to = price_to, price_from

    return SearchFilters(
        brand=args.brand,
        model=args.model,
        year_from=year_from,
        year_to=year_to,
        fuel=args.fuel,
        gearbox=args.gearbox,
        price_from=price_from,
        price_to=price_to,
        body_type=args.body_type,
        # vehicle_type circula para o slug de combustível ser resolvido contra
        # o dicionário da vertical correcta (MS1.a, 2026-06-10).
        vehicle_type=args.vehicle_type,
    )


def _parse_sources(sources_csv: str) -> list[str]:
    """Decompõe '--sources standvirtual,custojusto' em lista validada.
    Slug desconhecido → KeyError (falha controlada antes de qualquer fetch)."""
    names = [s.strip() for s in (sources_csv or "").split(",") if s.strip()]
    for name in names:
        get_adapter_class(name)  # valida antes de iniciar
    return names or [DEFAULT_SOURCES]


def run(
    preview: bool = False,
    preview_limit: int = 10,
    filters: Optional[SearchFilters] = None,
    vehicle_type: str = "car",
    max_results: Optional[int] = None,
    sources: Optional[str] = None,
):
    setup_logging()
    logger = logging.getLogger("main")
    filters = filters or SearchFilters()

    # Route search to the correct Standvirtual category path.
    config.search_path = VEHICLE_TYPE_PATHS[vehicle_type]

    effective_max = resolve_max_results(vehicle_type, max_results)
    logger.info(f"vehicle_type={vehicle_type} search_path={config.search_path} max_results={effective_max}")

    # MS2.b — adapters por nome. sources=None → DEFAULT_SOURCES (= standvirtual).
    source_names = _parse_sources(sources or DEFAULT_SOURCES)
    logger.info(f"sources={source_names}")

    normalizer = ListingNormalizer()
    sender = LaravelSender()

    preview_results = []
    total_normalized = 0
    total_raw = 0
    # MS2.d — buffer por fonte ANTES do merge para 1 POST consolidado no fim.
    # Os contadores ficam visíveis no log antes do envio, antes de o backend
    # fazer dedup cross-fonte (que pode reduzir o número final). Permite ao
    # diagnóstico (ex.: "CustoJusto recebeu 22 raw mas 8 chegaram") separar
    # falhas de scraping de falhas de dedup.
    buffered_by_source: dict[str, list] = {name: [] for name in source_names}

    def _ingest_or_preview(snapshot, source_name):
        """Acumula o snapshot — preview directo, run mode adia para o POST final."""
        nonlocal total_normalized
        if preview:
            preview_results.append(snapshot.to_dict())
        else:
            buffered_by_source[source_name].append(snapshot)
        total_normalized += 1

    # Iteração determinística por fonte. Cada adapter é isolado num try/except —
    # falha duma fonte (HTTP / parse / bloqueio) não derruba as outras nem o
    # aggregate (critério de aceitação MS2.e). Limite global de max_results
    # partilhado para evitar inflação cross-fonte.
    for source_name in source_names:
        adapter_cls = get_adapter_class(source_name)
        adapter = adapter_cls(filters=filters)

        try:
            for page_batch in adapter.search():
                total_raw += len(page_batch)

                for raw_listing in page_batch:

                    if config.fetch_details and raw_listing.url:
                        time.sleep(config.delay_between_details)
                        # fetch_detail é específico do Standvirtual hoje; outros
                        # adapters podem não o ter. Chamada defensiva.
                        if hasattr(adapter, "fetch_detail"):
                            detail = adapter.fetch_detail(raw_listing.url)
                            if detail.get("color"):
                                raw_listing.params["color"] = detail["color"]
                            if detail.get("doors"):
                                raw_listing.params["doors"] = str(detail["doors"])

                    snapshot = normalizer.normalize(
                        raw_listing,
                        vehicle_type=vehicle_type,
                        body_type_override=filters.body_type,
                    )

                    if not snapshot:
                        continue

                    _ingest_or_preview(snapshot, source_name)

                    if preview and len(preview_results) >= preview_limit:
                        print(json.dumps(preview_results, ensure_ascii=False))
                        return

                    if total_normalized >= effective_max:
                        logger.info(f"Limite de resultados atingido ({effective_max}). A parar.")
                        _flush_consolidated(sender, buffered_by_source, logger)
                        print(json.dumps({
                            "total_raw": total_raw,
                            "total_normalized": total_normalized,
                            "total_sent": sender.stats()["total_sent"],
                            "total_failed": sender.stats()["total_failed"],
                        }))
                        return

        except Exception as e:
            # Isolamento de falha — log + continua para a próxima fonte.
            logger.error(f"Erro inesperado na fonte {source_name!r}: {e}", exc_info=True)

    # PREVIEW MODE
    if preview:
        print(json.dumps(preview_results, ensure_ascii=False))
        return

    # RUN MODE — 1 POST consolidado com contadores logados ANTES do envio.
    _flush_consolidated(sender, buffered_by_source, logger)
    stats = sender.stats()

    print(json.dumps({
        "total_raw": total_raw,
        "total_normalized": total_normalized,
        "total_sent": stats["total_sent"],
        "total_failed": stats["total_failed"],
    }))


def _flush_consolidated(sender, buffered_by_source: dict, logger) -> None:
    """MS2.d — 1 POST consolidado por execução.

    Loga contadores por fonte ANTES do merge (separação de diagnóstico:
    falha de scraping vs falha de dedup vs falha de POST). O dedup
    cross-fonte é feito no backend (CarMarketSnapshotService::persistSnapshots);
    daí esta soma só representa "candidatos enviados", não "snapshots persistidos"
    — o dedup pode reduzir o número final, e isso fica visível no log do Laravel.
    """
    counts = {src: len(items) for src, items in buffered_by_source.items()}
    total_buffered = sum(counts.values())

    logger.info(
        f"[ingest] consolidando POST único · total={total_buffered} · "
        f"por fonte (pré-dedup-backend)={counts}"
    )

    if total_buffered == 0:
        return

    # Vai tudo num único batch (forçar batch_size grande para o auto-flush
    # do LaravelSender não partir em vários POSTs intermédios).
    sender.batch_size = max(total_buffered, sender.batch_size) + 1
    for source_name, snapshots in buffered_by_source.items():
        for snapshot in snapshots:
            sender.add(snapshot)
    sender.flush()

if __name__ == "__main__":
    args = build_arg_parser().parse_args()
    filters = build_filters_from_args(args)
    run(
        preview=args.mode == "preview",
        preview_limit=args.preview_limit,
        filters=filters,
        vehicle_type=args.vehicle_type,
        max_results=args.max_results,
        sources=args.sources,
    )
