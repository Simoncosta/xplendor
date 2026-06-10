"""Adapter CustoJusto — autocaravanas (motorhome/caravan).

═══════════════════════════════════════════════════════════════════════════════
TABELA EMPÍRICA — validada via curl ao live em 2026-06-10
═══════════════════════════════════════════════════════════════════════════════

URL base:  https://www.custojusto.pt/portugal/veiculos/autocaravanas-reboques
Mercado total declarado: ~2000 anúncios; autocaravanas reais: ~341 distribuídas
nas 6 sub-categorias abaixo (Standvirtual: 364 — ordens de magnitude iguais).

Estrutura:
  - Next.js com __NEXT_DATA__ embebido (mesmo padrão do Standvirtual)
  - listings em  props.pageProps.listItems  (array, 40 items por página)
  - ad individual em  props.pageProps.adData

Sub-categorias por URL path (AUTOCARAVANAS — alimentar degrau 4 da cascata):
  /integral      →  49 anúncios    →  category="integral"
  /perfilada     → 101 anúncios    →  category="perfiladas"   (slug Standvirtual plural)
  /capucino      →  42 anúncios    →  category="capucine"
  /a_furgao      →  22 anúncios    →  category="furgao"
  /campervan     →  47 anúncios    →  category="furgao"
  /caravana      →  80 anúncios    →  category=None  (taxonomia confusa — gate de título)

Sub-categorias EXCLUÍDAS (reboques — envenenam mediana de motorhomes):
  /atrelado      → 525 anúncios
  /a_reboque     → 180 anúncios

Filtros URL suportados (validados):
  ?q=marca           ✅ texto livre — filtra; mitiga risco 5 via gate de título pós-fetch
  ?ps=N&pe=N         ✅ faixa de preço (EUR) — validado (?ps=50000 → preços ≥ 50k)
  ?o=N               ✅ paginação real (página 1 e 2 têm listIDs disjuntos)
  ?fyfr=/?regdate=   ❌ ANO NÃO FILTRA — contagem inalterada → filtrar pós-fetch

Estratégia de exclusão de reboques (DEFESA EM PROFUNDIDADE):
  1. URL construído SÓ com as 6 sub-categorias-alvo.
  2. Pós-fetch: rejeitar se categoryName ∈ {Atrelado, Reboque}.
  3. /caravana e "Outras autocaravanas e reboques": GATE DE TÍTULO — requer
     keyword "autocaravana" no título ou body; senão exclui (regra do prompt
     "na dúvida, exclui").

Estratégia de selecção de sub-categorias (Emenda do utilizador):
  - Filter.body_type mapeado (capucine/integral/perfiladas/furgao) → SÓ a(s)
    path(s) correspondente(s) no CustoJusto. Menos requests, mais politeness.
  - Sem mapeamento → 5 paths limpas (exclui /caravana — taxonomia ambígua).

Widen-on-empty (paridade MS1.b mas para CustoJusto) — semântica de POOL:
  - Se o fetch com ?q={marca} devolveu 0 listings, retry com path SEM ?q=
    E sem o pós-filtro de marca (Fix 1, 2026-06-10). Garantia: o widen
    realmente acumula pool da categoria — em vez de buscar e descartar, como
    fazia na 1.ª versão. Resultado: snapshots gravados com category=slug SV
    alimentam o DEGRAU 4 da cascata Laravel (sem marca por definição).
  - Filtros mantidos no retry: janela de ano, price > 0, exclusão de reboques,
    gate de título no /caravana. Só se removem ?q= e o filtro de marca.
  - Risco: zero — o degrau 5 da cascata Laravel continua a filtrar por marca,
    e o degrau 4 escolhe top 5 por proximidade à mediana. Pool maior NÃO
    contamina medianas de outras viaturas (a cascata é quem decide o que entra).
  - Custo: até 12 requests por motorhome quando NÃO há body_type mapeado
    (6 paths × 2 tentativas). Cenário típico de prod (body_type mapeado) é
    1-2 requests. Caps a revisitar se aparecer bloqueio ou stock 5× actual.
  - Distingue zero legítimo (HTTP 200 + lista vazia) de falha técnica (HTTP/parse).

Exclusões automáticas no normalizer:
  - price <= 0 (sob consulta / sem preço) → exclui + conta (Emenda A do utilizador).
  - URLs absolutos: prefixa https://www.custojusto.pt ao listItem.url relativo
    (Emenda C — ↗ no UI abre o anúncio individual sem ambiguidade).

CONTRATO DOS LOGS:
  - "[custojusto] HTTP X em URL" (erro de rede/HTTP)
  - "[custojusto] widen sem q: path={p} marca={m}" (widen-on-empty)
  - "[custojusto] {N} listings, {M} excluídos por preço<=0"
  - "[custojusto] excluído path={p} categoria={c}" (defesa em profundidade)

CONSISTÊNCIA com o backend:
  - source="custojusto" em RawListing (StoreMarketSnapshotRequest::VALID_SOURCES
    aceita; CarMarketAggregateResource emite no top_comparables).
  - external_id = str(listID) directo (segue convenção Standvirtual sem prefixo).
"""
from __future__ import annotations

import json
import logging
import random
import re
import time
import unicodedata
from typing import Generator, Optional

import requests
from bs4 import BeautifulSoup

from config import SearchFilters, config
from sources.base import RawListing, SourceAdapter

logger = logging.getLogger(__name__)


# ─────────────────────────────────────────────────────────────────────────────
# Constantes
# ─────────────────────────────────────────────────────────────────────────────

BASE_URL = "https://www.custojusto.pt"
CATEGORY_BASE_PATH = "/portugal/veiculos/autocaravanas-reboques"

# Mapa interno → paths de URL do CustoJusto + category canónica para o degrau 4.
# Vocabulário canónico = slug Standvirtual (perfiladas plural, etc.) para que
# os snapshots CustoJusto alimentem o degrau 4 da cascata sem normalização extra.
# Tuple: (path, category_for_degrau_4 | None se a sub-cat for ambígua).
SUBCAT_TARGETS: list[tuple[str, Optional[str]]] = [
    ("/integral",  "integral"),
    ("/perfilada", "perfiladas"),
    ("/capucino",  "capucine"),
    ("/a_furgao",  "furgao"),
    ("/campervan", "furgao"),
    # /caravana: taxonomia confusa no CustoJusto ("Caravana" pode ser
    # autocaravana real OU rebocável). Entra com gate de título e category=None.
    ("/caravana",  None),
]

# Mapa body_type (slug Standvirtual filter passado pelo Job) → paths CJ.
# Quando o car tem categoria mapeada, scrape SÓ a(s) path(s) correspondente(s).
BODY_TYPE_TO_PATHS: dict[str, list[str]] = {
    "integral":   ["/integral"],
    "perfiladas": ["/perfilada"],
    "capucine":   ["/capucino"],
    "furgao":     ["/a_furgao", "/campervan"],  # o furgao SV cobre ambos
}

# Reboques — defesa em profundidade no normalizer (URL já exclui).
EXCLUDED_CATEGORY_NAMES = {"Atrelado", "Reboque"}

# Gate de título para sub-categorias ambíguas: exige keyword "autocaravana".
AUTOCARAVANA_KEYWORDS = ["autocaravana", "auto-caravana", "motorhome", "camper", "campervan"]

# Politeness defaults (mais conservador que o Standvirtual — CustoJusto é
# generalista e pode ser mais agressivo no anti-bot).
DEFAULT_PAGES_PER_PATH = 1   # 40 listings por path × 5 paths = 200 candidatos
DEFAULT_DELAY_SEC = 2.5

USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36"
)


# ─────────────────────────────────────────────────────────────────────────────
# Helpers
# ─────────────────────────────────────────────────────────────────────────────

def _normalize_str(s: str) -> str:
    """Lower + strip accents + colapsar espaços."""
    if not s:
        return ""
    s = unicodedata.normalize("NFKD", s).encode("ascii", "ignore").decode("ascii")
    return re.sub(r"\s+", " ", s.strip().lower())


def _extract_next_data(html: str) -> Optional[dict]:
    """Extrai __NEXT_DATA__ do HTML."""
    try:
        soup = BeautifulSoup(html, "lxml")
    except Exception:
        soup = BeautifulSoup(html, "html.parser")
    tag = soup.find("script", {"id": "__NEXT_DATA__"})
    if not tag or not tag.string:
        return None
    try:
        return json.loads(tag.string)
    except json.JSONDecodeError:
        return None


def _has_autocaravana_keyword(text: str) -> bool:
    """Gate de título para sub-categorias ambíguas."""
    norm = _normalize_str(text)
    return any(kw in norm for kw in AUTOCARAVANA_KEYWORDS)


# ─────────────────────────────────────────────────────────────────────────────
# Adapter
# ─────────────────────────────────────────────────────────────────────────────

class CustojustoAdapter(SourceAdapter):
    name = "custojusto"

    def __init__(self, filters: Optional[SearchFilters] = None):
        super().__init__(filters)
        self.session = requests.Session()
        self.session.headers.update({
            "User-Agent": USER_AGENT,
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "pt-PT,pt;q=0.9,en;q=0.8",
            "Connection": "keep-alive",
        })

    # ── Fetch ──────────────────────────────────────────────────────────────

    def _fetch_url(self, url: str) -> Optional[str]:
        """Fetch resiliente: devolve None em erro (sem propagar excepção).
        Critério de isolamento de falha por fonte (MS2.e)."""
        try:
            resp = self.session.get(url, timeout=config.request_timeout, allow_redirects=True)
            if resp.status_code != 200:
                logger.warning(f"[custojusto] HTTP {resp.status_code} em {url}")
                return None
            return resp.text
        except requests.RequestException as e:
            logger.warning(f"[custojusto] Erro de rede em {url}: {e}")
            return None

    def _parse_list_items(self, html: str) -> Optional[list[dict]]:
        """Devolve a lista listItems do __NEXT_DATA__ ou None em parsing fail."""
        data = _extract_next_data(html)
        if data is None:
            return None
        pp = (data.get("props") or {}).get("pageProps") or {}
        items = pp.get("listItems")
        return items if isinstance(items, list) else None

    # ── Estratégia de paths ────────────────────────────────────────────────

    def _select_targets(self) -> list[tuple[str, Optional[str]]]:
        """Decide que paths visitar.

        - filters.body_type mapeado → SÓ a(s) path(s) correspondente(s).
        - Sem mapeamento → 5 paths limpas (exclui /caravana, ambígua).
          O /caravana é incluído só na cascata sem categoria, com gate de título.
        """
        body_type = (self.filters.body_type or "").strip()
        if body_type and body_type in BODY_TYPE_TO_PATHS:
            paths = BODY_TYPE_TO_PATHS[body_type]
            # Procura no SUBCAT_TARGETS para devolver a tupla (path, category).
            return [t for t in SUBCAT_TARGETS if t[0] in paths]
        # Sem body_type — usa 5 sub-categorias-alvo + /caravana com category=None.
        return SUBCAT_TARGETS

    def _build_url(self, path: str, q: Optional[str] = None) -> str:
        url = f"{BASE_URL}{CATEGORY_BASE_PATH}{path}"
        if q:
            q_norm = _normalize_str(q).replace(" ", "+")
            url += f"?q={q_norm}"
        return url

    # ── Normalização para RawListing ───────────────────────────────────────

    def _normalize_item(self, item: dict, category_canonical: Optional[str]) -> Optional[RawListing]:
        """Filtra + normaliza um listItem em RawListing. Devolve None se
        excluído (reboque / preço<=0 / gate de título falhou)."""
        # Defesa em profundidade: URL já excluiu, mas verifica categoryName.
        category_name = item.get("categoryName") or ""
        if category_name in EXCLUDED_CATEGORY_NAMES:
            logger.debug(f"[custojusto] excluído categoria={category_name!r}")
            return None

        # Emenda A — exclui preço <= 0 (sob consulta / sem preço).
        price = item.get("price")
        if price is None or not isinstance(price, (int, float)) or price <= 0:
            return None  # contador no chamador

        title = (item.get("title") or "").strip()
        if not title:
            return None

        # /caravana e "Outras..." precisam de gate de título.
        # category_canonical=None marca sub-categorias ambíguas.
        if category_canonical is None:
            body = item.get("body") or ""
            if not _has_autocaravana_keyword(title + " " + body):
                logger.debug(f"[custojusto] gate de título falhou: {title[:50]!r}")
                return None

        # URL absoluto (Emenda C — listItem.url é relativo).
        url_rel = item.get("url") or ""
        if not url_rel.startswith("/"):
            return None
        url_abs = BASE_URL + url_rel

        # external_id = listID directo (convenção Standvirtual, sem prefixo).
        external_id = str(item.get("listID") or "")
        if not external_id:
            return None

        # params: extrai ano de params.regdate; outros campos do body (texto livre).
        params_cj = item.get("params") or {}
        params: dict = {}
        year = params_cj.get("regdate")
        if year:
            params["year"] = str(year)

        # Extrai do body (formato: "Ano: 2000 Quilómetros: 199.460 km Modelo: X
        # Combustível: Diesel Tipo de Caixa: Caixa manual").
        body_txt = item.get("body") or ""
        km_m = re.search(r"Quil[oó]metros?:\s*([\d\.\,]+)", body_txt)
        if km_m:
            params["km"] = km_m.group(1).replace(".", "").replace(",", "")
        fuel_m = re.search(r"Combust[ií]vel:\s*([A-Za-zÀ-ÿ]+)", body_txt)
        if fuel_m:
            params["fuel"] = fuel_m.group(1).strip()
        gearbox_m = re.search(r"Caixa:\s*Caixa\s+([A-Za-zÀ-ÿ]+)", body_txt)
        if gearbox_m:
            params["gearbox"] = gearbox_m.group(1).strip()

        region = ((item.get("locationNames") or {}).get("district")) or None

        return RawListing(
            external_id=external_id,
            title=title,
            url=url_abs,
            price_raw=str(int(price)),
            params=params,
            category=category_canonical,  # vocabulário canónico SV ou None
            region=region,
            price_evaluation=None,  # CustoJusto não tem GOOD_PRICE/HIGH_PRICE
            source="custojusto",
        )

    # ── Filtros pós-fetch ──────────────────────────────────────────────────

    def _matches_brand(self, title: str, brand: str) -> bool:
        """Marca tolerante: matching no título normalizado.
        Mitigação do risco 5 (?q= é texto livre — pode devolver false positives)."""
        if not brand:
            return True
        return _normalize_str(brand) in _normalize_str(title)

    def _matches_year_window(self, year_str: Optional[str]) -> bool:
        """Filtragem por ano pós-fetch (URL ?fyfr/?regdate NÃO filtra).
        Emenda G: usa a MESMA janela do Standvirtual (filters.year_from/year_to)."""
        if not year_str:
            # Snapshots sem ano ficam — não posso descartar dado em falta
            # vs ser-se rigoroso quando o ano é desconhecido. Conservador: aceita.
            return True
        try:
            year = int(year_str)
        except ValueError:
            return True
        yf = self.filters.year_from
        yt = self.filters.year_to
        if yf is not None and year < yf:
            return False
        if yt is not None and year > yt:
            return False
        return True

    # ── Loop principal ─────────────────────────────────────────────────────

    def _fetch_one(self, path: str, category_canonical: Optional[str],
                   q: Optional[str], brand_filter: Optional[str]) -> list[RawListing]:
        """Fetch + parse + normaliza uma path.

        `q` controla o filtro de URL `?q=`; `brand_filter` controla o pós-filtro
        de marca no título. SÃO INDEPENDENTES porque o widen-on-empty (Fix 1)
        remove ambos no retry para acumular pool de categoria. Outros filtros
        (ano, preço, reboques, gate de título) mantêm-se sempre.

        Devolve lista (pode ser vazia). Nunca propaga excepção (isolamento)."""
        url = self._build_url(path, q=q)
        html = self._fetch_url(url)
        if html is None:
            return []
        items = self._parse_list_items(html)
        if items is None:
            logger.warning(f"[custojusto] __NEXT_DATA__/listItems não encontrados em {url}")
            return []

        excluded_price = 0
        listings: list[RawListing] = []
        for item in items:
            # Conta exclusões por preço (Emenda A — visibilidade).
            price = item.get("price")
            if price is None or not isinstance(price, (int, float)) or price <= 0:
                excluded_price += 1
                continue
            listing = self._normalize_item(item, category_canonical)
            if listing is None:
                continue
            # Filtros pós-fetch SEMPRE aplicados: ano (CustoJusto não filtra ano
            # via URL). Brand SÓ se brand_filter passado (widen Fix 1 passa None).
            if brand_filter and not self._matches_brand(listing.title, brand_filter):
                continue
            if not self._matches_year_window(listing.params.get("year")):
                continue
            listings.append(listing)

        logger.info(
            f"[custojusto] {url}: {len(listings)} listings após filtros "
            f"(de {len(items)} brutos, {excluded_price} excluídos por preço<=0)"
        )
        return listings

    def search(self) -> Generator[list[RawListing], None, None]:
        """Generator que yields batches de RawListing.

        Ordem de prioridade (MS2.e, herança do Fix 1): TODOS os brand-matched
        de TODAS as paths são yielded ANTES de qualquer widen-pool. Isto
        garante que o cap final do main.py corta primeiro pool (que alimenta
        degrau 4), preservando os brand-matched que alimentam degraus 1/2/5
        no Laravel.

        Implementação em 2 passadas determinísticas:
          1ª passada: ?q=brand + pós-filtro de marca activos para CADA target.
                      Listings devolvidos vão directos. Paths que ficaram a 0
                      ficam memorizadas para widen.
          2ª passada: widen para as paths memorizadas (sem ?q=, sem brand_filter).

        Sem brand inicial → nunca há widen, e ambas as passadas colapsam numa
        só lista de fetches (sem perdas de eficiência).

        Erro em qualquer path não derruba as outras (isolamento de falha)."""
        targets = self._select_targets()
        brand = (self.filters.brand or "").strip() or None

        logger.info(f"[custojusto] iniciando com {len(targets)} path(s); brand={brand!r}")

        # ── 1ª passada: brand-matched (ou sem brand → fetch único da categoria)
        paths_needing_widen: list[tuple[str, Optional[str]]] = []
        for path, category_canonical in targets:
            try:
                listings = self._fetch_one(
                    path, category_canonical,
                    q=brand, brand_filter=brand,
                )
                if listings:
                    yield listings
                elif brand:
                    # 0 brand-matched + tem brand → candidato a widen na 2ª passada.
                    paths_needing_widen.append((path, category_canonical))
                # Politeness — delay entre paths.
                time.sleep(DEFAULT_DELAY_SEC + random.uniform(0, 1))

            except Exception as e:
                # Catch defensivo — isolamento por path (e por fonte).
                logger.error(
                    f"[custojusto] erro inesperado na 1ª passada path {path!r}: {e}",
                    exc_info=True,
                )
                continue

        # ── 2ª passada: widen-on-empty Fix 1 (POOL DE CATEGORIA) ─────────────
        # Snapshots da categoria sem filtro de marca para alimentar o DEGRAU 4
        # da cascata Laravel (sem marca por definição). Yields APÓS todos os
        # brand-matched para o cap final do main.py preservar prioridade.
        for path, category_canonical in paths_needing_widen:
            try:
                logger.warning(
                    f"[custojusto] widen sem q: path={path} marca={brand!r} "
                    f"(retry sem filtro de marca — pool de categoria)"
                )
                listings = self._fetch_one(
                    path, category_canonical,
                    q=None, brand_filter=None,
                )
                if listings:
                    yield listings
                time.sleep(DEFAULT_DELAY_SEC + random.uniform(0, 1))

            except Exception as e:
                logger.error(
                    f"[custojusto] erro inesperado na 2ª passada path {path!r}: {e}",
                    exc_info=True,
                )
                continue
