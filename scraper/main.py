import argparse
import json
import logging
import logging.handlers
import time
from datetime import datetime
from typing import Optional
from dotenv import load_dotenv

from config import SearchFilters, config
from scraper import StandvirtualScraper
from normalizer import ListingNormalizer
from sender import LaravelSender

load_dotenv()

def setup_logging():
    level = getattr(logging, config.log_level.upper(), logging.INFO)
    handlers = [logging.StreamHandler()]
    if config.log_file:
        handlers.append(
            logging.handlers.RotatingFileHandler(
                config.log_file,
                maxBytes=10 * 1024 * 1024,  # 10MB
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
    parser.add_argument(
        "mode",
        nargs="?",
        choices=["preview"],
        help="Usa 'preview' para mostrar resultados no terminal sem enviar ao Laravel.",
    )
    parser.add_argument(
        "preview_limit",
        nargs="?",
        type=int,
        default=10,
        help="Número de anúncios a mostrar em modo preview.",
    )
    parser.add_argument("--brand", help="Marca a filtrar, ex: BMW")
    parser.add_argument("--model", help="Modelo a filtrar, ex: Série 3")
    parser.add_argument("--year-from", type=int, dest="year_from", help="Ano mínimo")
    parser.add_argument("--year-to", type=int, dest="year_to", help="Ano máximo")
    parser.add_argument("--fuel", help="Combustível, ex: diesel, gasolina, elétrico")
    parser.add_argument("--gearbox", help="Caixa, ex: automática, manual")
    parser.add_argument("--price-from", type=int, dest="price_from", help="Preço mínimo")
    parser.add_argument("--price-to", type=int, dest="price_to", help="Preço máximo")
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

    start = datetime.now()
    logger.info("=" * 60)
    if preview:
        logger.info(f"MODO PREVIEW — os dados NÃO são enviados ao Laravel")
        logger.info(f"A mostrar os primeiros {preview_limit} anúncios em JSON")
    else:
        logger.info(f"Xplendor Market Scraper — início: {start.strftime('%Y-%m-%d %H:%M:%S')}")
    if filters.to_log_dict():
        logger.info(f"Filtros de pesquisa: {filters.to_log_dict()}")
    logger.info("=" * 60)

    scraper = StandvirtualScraper(filters=filters)
    normalizer = ListingNormalizer()
    sender = LaravelSender()

    total_raw = 0
    total_normalized = 0
    total_skipped = 0
    preview_results = []

    try:
        for page_batch in scraper.scrape_all():
            total_raw += len(page_batch)

            for raw_listing in page_batch:
                # Fetch de detalhes (cor + portas) se activado
                if config.fetch_details and raw_listing.url:
                    time.sleep(config.delay_between_details)
                    detail = scraper.fetch_detail(raw_listing.url)
                    if detail.get("color"):
                        raw_listing.params["color"] = detail["color"]
                    if detail.get("doors"):
                        raw_listing.params["doors"] = str(detail["doors"])

                snapshot = normalizer.normalize(raw_listing)
                if snapshot:
                    if preview:
                        preview_results.append(snapshot.to_dict())
                        if len(preview_results) >= preview_limit:
                            raise StopIteration
                    else:
                        sender.add(snapshot)
                    total_normalized += 1
                else:
                    total_skipped += 1

    except StopIteration:
        pass  # preview atingiu o limite, saída limpa

    except KeyboardInterrupt:
        logger.info("Scraper interrompido manualmente (Ctrl+C).")

    except Exception as e:
        logger.error(f"Erro inesperado: {e}", exc_info=True)

    finally:
        if preview:
            # Mostra JSON formatado no terminal
            print("\n" + "=" * 60)
            print(f"PREVIEW — {len(preview_results)} anúncios extraídos:")
            print("=" * 60)
            print(json.dumps(preview_results, ensure_ascii=False, indent=2))
            print("=" * 60)

            # Guarda também em ficheiro para consulta fácil
            with open("preview_output.json", "w", encoding="utf-8") as f:
                json.dump(preview_results, f, ensure_ascii=False, indent=2)
            print(f"\nFicheiro guardado: preview_output.json")

        else:
            logger.info("A enviar batch final...")
            sender.flush()

            elapsed = (datetime.now() - start).total_seconds()
            stats = sender.stats()

            logger.info("=" * 60)
            logger.info("Resumo de execução:")
            logger.info(f"  Tempo total:       {elapsed:.1f}s")
            logger.info(f"  Anúncios raw:      {total_raw}")
            logger.info(f"  Normalizados:      {total_normalized}")
            logger.info(f"  Saltados:          {total_skipped}")
            logger.info(f"  Enviados Laravel:  {stats['total_sent']}")
            logger.info(f"  Falhados:          {stats['total_failed']}")
            logger.info("=" * 60)


if __name__ == "__main__":
    args = build_arg_parser().parse_args()
    filters = build_filters_from_args(args)
    run(
        preview=args.mode == "preview",
        preview_limit=args.preview_limit,
        filters=filters,
    )
