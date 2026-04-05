import argparse
import json
import logging
import logging.handlers
import time
from datetime import datetime
from typing import Optional
from dotenv import load_dotenv
import sys

from config import SearchFilters, config
from scraper import StandvirtualScraper
from normalizer import ListingNormalizer
from sender import LaravelSender

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
    parser.add_argument("--brand")
    parser.add_argument("--model")
    parser.add_argument("--year-from", type=int, dest="year_from")
    parser.add_argument("--year-to", type=int, dest="year_to")
    parser.add_argument("--fuel")
    parser.add_argument("--gearbox")
    parser.add_argument("--price-from", type=int, dest="price_from")
    parser.add_argument("--price-to", type=int, dest="price_to")
    parser.add_argument("--preview-limit", type=int, default=10, dest="preview_limit")
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
    )


def run(
    preview: bool = False,
    preview_limit: int = 10,
    filters: Optional[SearchFilters] = None,
):
    setup_logging()
    logger = logging.getLogger("main")
    filters = filters or SearchFilters()

    scraper = StandvirtualScraper(filters=filters)
    normalizer = ListingNormalizer()
    sender = LaravelSender()

    preview_results = []
    total_normalized = 0
    total_raw = 0  # ✅ CORRETO (variável interna)

    try:
        for page_batch in scraper.scrape_all():
            total_raw += len(page_batch)  # ✅ CORRETO

            for raw_listing in page_batch:

                if config.fetch_details and raw_listing.url:
                    time.sleep(config.delay_between_details)
                    detail = scraper.fetch_detail(raw_listing.url)
                    if detail.get("color"):
                        raw_listing.params["color"] = detail["color"]
                    if detail.get("doors"):
                        raw_listing.params["doors"] = str(detail["doors"])

                snapshot = normalizer.normalize(raw_listing)

                if not snapshot:
                    continue

                if preview:
                    preview_results.append(snapshot.to_dict())

                    if len(preview_results) >= preview_limit:
                        print(json.dumps(preview_results, ensure_ascii=False))
                        return
                else:
                    sender.add(snapshot)

                total_normalized += 1

    except Exception as e:
        logger.error(f"Erro inesperado: {e}", exc_info=True)

    # PREVIEW MODE
    if preview:
        print(json.dumps(preview_results, ensure_ascii=False))
        return

    # RUN MODE
    sender.flush()
    stats = sender.stats()

    # 🔥 OUTPUT LIMPO PARA LARAVEL (SEM LOGS)
    print(json.dumps({
        "total_raw": total_raw,
        "total_normalized": total_normalized,
        "total_sent": stats["total_sent"],
        "total_failed": stats["total_failed"]
    }))

if __name__ == "__main__":
    args = build_arg_parser().parse_args()
    filters = build_filters_from_args(args)
    run(
        preview=args.mode == "preview",
        preview_limit=args.preview_limit,
        filters=filters,
    )
