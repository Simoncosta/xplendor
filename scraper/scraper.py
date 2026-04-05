import json
import time
import random
import logging
from typing import Optional, Generator
from dataclasses import dataclass

import requests
from bs4 import BeautifulSoup

from config import SearchFilters, config

logger = logging.getLogger(__name__)


@dataclass
class RawListing:
    """Dados brutos tal como vêm do Standvirtual."""
    external_id: str
    title: str
    url: str
    price_raw: Optional[str]
    params: dict          # km, ano, combustível, caixa, potência, etc.
    category: Optional[str]
    region: Optional[str]
    price_evaluation: Optional[str]  # GOOD_PRICE, LOW_PRICE, HIGH_PRICE, NONE
    source: str = "standvirtual"


class StandvirtualScraper:

    def __init__(self, filters: Optional[SearchFilters] = None):
        self.session = requests.Session()
        self.filters = filters or SearchFilters()
        self._set_headers()

    # ------------------------------------------------------------------
    # Sessão / headers
    # ------------------------------------------------------------------

    def _set_headers(self):
        ua = random.choice(config.user_agents)
        self.session.headers.update({
            "User-Agent": ua,
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "pt-PT,pt;q=0.9,en;q=0.8",
            "Accept-Encoding": "gzip, deflate, br",
            "Connection": "keep-alive",
            "Referer": config.base_url,
            "DNT": "1",
        })

    def _rotate_ua(self):
        """Troca User-Agent periodicamente para parecer mais humano."""
        self._set_headers()

    def _make_soup(self, html: str) -> BeautifulSoup:
        try:
            return BeautifulSoup(html, "lxml")
        except Exception:
            return BeautifulSoup(html, "html.parser")

    # ------------------------------------------------------------------
    # Fetch de página
    # ------------------------------------------------------------------

    def _fetch_page(self, url: str, params: dict = None, attempt: int = 0) -> Optional[str]:
        try:
            response = self.session.get(
                url,
                params=params,
                timeout=config.request_timeout,
                allow_redirects=True,
            )
            response.raise_for_status()
            return response.text

        except requests.exceptions.HTTPError as e:
            status = e.response.status_code if e.response else None
            if status == 429:
                wait = (attempt + 1) * 15
                logger.warning(f"Rate limited (429). A aguardar {wait}s antes de retry...")
                time.sleep(wait)
            elif status in (403, 503):
                logger.warning(f"Bloqueado ({status}) em {url}. Retry {attempt + 1}/{config.max_retries}")
                time.sleep(10 + random.uniform(0, 5))
            else:
                logger.error(f"HTTP {status} em {url}: {e}")
                return None

            if attempt < config.max_retries - 1:
                self._rotate_ua()
                return self._fetch_page(url, params, attempt + 1)
            return None

        except requests.exceptions.RequestException as e:
            logger.error(f"Erro de rede em {url}: {e}")
            if attempt < config.max_retries - 1:
                time.sleep(5)
                return self._fetch_page(url, params, attempt + 1)
            return None

    # ------------------------------------------------------------------
    # Extração do __NEXT_DATA__
    # ------------------------------------------------------------------

    def _extract_next_data(self, html: str) -> Optional[dict]:
        try:
            soup = self._make_soup(html)
            script_tag = soup.find("script", {"id": "__NEXT_DATA__"})
            if not script_tag or not script_tag.string:
                logger.warning("__NEXT_DATA__ não encontrado na página.")
                return None
            return json.loads(script_tag.string)
        except json.JSONDecodeError as e:
            logger.error(f"Erro ao parsear __NEXT_DATA__: {e}")
            return None

    def _deep_get(self, data, *keys):
        current = data
        for key in keys:
            if not isinstance(current, dict):
                return None
            current = current.get(key)
        return current

    # ------------------------------------------------------------------
    # Extração do urqlState — onde o Standvirtual guarda os anúncios
    # ------------------------------------------------------------------

    def _get_advert_search(self, next_data: dict) -> Optional[dict]:
        """
        O Standvirtual usa urqlState para guardar dados em cache GraphQL.
        Os anúncios estão em: urqlState[key].data (JSON string) → advertSearch
        """
        urql_state = self._deep_get(next_data, "props", "pageProps", "urqlState") or {}

        for key, val in urql_state.items():
            try:
                inner = json.loads(val.get("data", "{}"))
                if "advertSearch" in inner:
                    return inner["advertSearch"]
            except (json.JSONDecodeError, AttributeError):
                continue

        logger.warning("advertSearch não encontrado no urqlState.")
        return None

    # ------------------------------------------------------------------
    # Parsing de anúncios
    # ------------------------------------------------------------------

    def _parse_listings_from_next_data(self, next_data: dict) -> list[RawListing]:
        listings = []
        try:
            advert_search = self._get_advert_search(next_data)
            if not advert_search:
                return []

            edges = advert_search.get("edges") or []
            if not edges:
                logger.warning("Nenhum edge/anúncio encontrado no advertSearch.")
                return []

            for edge in edges:
                node = edge.get("node") or {}
                listing = self._parse_single_ad(node)
                if listing:
                    listings.append(listing)

        except Exception as e:
            logger.error(f"Erro ao parsear listings: {e}", exc_info=True)

        return listings

    def _parse_single_ad(self, ad: dict) -> Optional[RawListing]:
        try:
            external_id = str(ad.get("id") or "")
            if not external_id:
                return None

            title = ad.get("title") or ""
            url = ad.get("url") or ""
            price_raw = str(self._deep_get(ad, "price", "amount", "value") or "")

            # Parameters → dict {key: value}
            params = {}
            for param in ad.get("parameters") or []:
                key = param.get("key") or ""
                value = param.get("value") or ""
                if key:
                    params[key] = value

            category = str(self._deep_get(ad, "category", "id") or "")

            region = self._extract_region(ad)

            # Avaliação de preço — já vem na listagem
            # Valores possíveis: GOOD_PRICE, LOW_PRICE, HIGH_PRICE, NONE
            price_evaluation = self._deep_get(ad, "priceEvaluation", "indicator")

            return RawListing(
                external_id=external_id,
                title=title,
                url=url,
                price_raw=price_raw,
                params=params,
                category=category,
                region=region,
                price_evaluation=price_evaluation,
            )

        except Exception as e:
            logger.debug(f"Erro ao parsear anúncio {ad.get('id')}: {e}")
            return None

    def _extract_region(self, ad: dict) -> Optional[str]:
        location = ad.get("location") or {}

        # Região é o valor preferencial e o que guardamos no snapshot.
        region = self._deep_get(location, "region", "name")
        if region:
            return region

        # Fallback seguro: alguns anúncios podem vir sem região mas com cidade.
        city = self._deep_get(location, "city", "name")
        if city:
            return city

        return None

    # ------------------------------------------------------------------
    # Fetch de página de detalhe (cor + portas)
    # ------------------------------------------------------------------

    def fetch_detail(self, url: str) -> dict:
        """
        Faz fetch da página individual do anúncio e extrai cor e portas.
        É mais lento — usar apenas quando FETCH_DETAILS=True no config.
        """
        html = self._fetch_page(url)
        if not html:
            return {}

        result = {}
        try:
            soup = self._make_soup(html)

            # Cor: <div data-testid="color"> ... <p>Preto</p>
            color_el = soup.find(attrs={"data-testid": "color"})
            if color_el:
                p_tags = color_el.find_all("p")
                if len(p_tags) >= 2:
                    result["color"] = p_tags[-1].get_text(strip=True)

            # Portas: <div data-testid="door_count"> ... <p>5</p>
            doors_el = soup.find(attrs={"data-testid": "door_count"})
            if doors_el:
                p_tags = doors_el.find_all("p")
                if len(p_tags) >= 2:
                    doors_text = p_tags[-1].get_text(strip=True)
                    try:
                        result["doors"] = int(doors_text)
                    except ValueError:
                        pass

        except Exception as e:
            logger.debug(f"Erro ao parsear detalhe {url}: {e}")

        return result

    # ------------------------------------------------------------------
    # Paginação
    # ------------------------------------------------------------------

    def _get_total_pages(self, next_data: dict) -> int:
        advert_search = self._get_advert_search(next_data)
        if not advert_search:
            return 1

        total_count = advert_search.get("totalCount") or 0
        page_size = self._deep_get(advert_search, "pageInfo", "pageSize") or 32

        if total_count and page_size:
            import math
            total_pages = math.ceil(total_count / page_size)
            return min(total_pages, config.max_pages)

        return 1

    # ------------------------------------------------------------------
    # Entry point: scrape_all
    # ------------------------------------------------------------------

    def scrape_all(self) -> Generator[list[RawListing], None, None]:
        """
        Generator que yield batches de listings por página.
        Uso: for batch in scraper.scrape_all(): ...
        """
        url = f"{config.base_url}{config.search_path}"
        params = config.build_search_params(self.filters)

        logger.info(f"PARAMS USADOS NA REQUEST: {params}")
        params["page"] = 1

        logger.info(f"A iniciar scraping: {url}")
        if self.filters.to_log_dict():
            logger.info(f"Filtros ativos: {self.filters.to_log_dict()}")

        # Fetch da primeira página para saber total de páginas
        html = self._fetch_page(url, params)
        if not html:
            logger.error("Falhou fetch da primeira página.")
            return

        next_data = self._extract_next_data(html)
        if not next_data:
            return

        total_pages = self._get_total_pages(next_data)
        logger.info(f"Total de páginas a scraper: {total_pages}")

        # Processa página 1
        listings = self._parse_listings_from_next_data(next_data)
        logger.info(f"Página 1/{total_pages}: {len(listings)} anúncios")
        if listings:
            yield listings

        # Itera páginas restantes
        for page in range(2, total_pages + 1):
            time.sleep(config.delay_between_pages + random.uniform(0, 2))

            if page % 10 == 0:
                self._rotate_ua()

            params["page"] = page
            html = self._fetch_page(url, params)
            if not html:
                logger.warning(f"Falhou fetch da página {page}. A saltar.")
                continue

            next_data = self._extract_next_data(html)
            if not next_data:
                continue

            listings = self._parse_listings_from_next_data(next_data)
            logger.info(f"Página {page}/{total_pages}: {len(listings)} anúncios")
            if listings:
                yield listings
