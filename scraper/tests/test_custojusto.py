"""Tests offline do CustojustoAdapter (MS2.c, 2026-06-10).

Todos os testes correm contra fixtures em tests/fixtures/custojusto/ —
nunca contra a rede. As fixtures foram capturadas via curl ao live em
2026-06-10 (ver tabela empírica no cabeçalho de sources/custojusto.py).
"""
from __future__ import annotations

import os
import sys
from unittest.mock import patch

import pytest

# Garante que scraper root está no path.
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from config import SearchFilters
from sources.custojusto import (
    BASE_URL,
    BODY_TYPE_TO_PATHS,
    CATEGORY_BASE_PATH,
    CustojustoAdapter,
    EXCLUDED_CATEGORY_NAMES,
    SUBCAT_TARGETS,
    _has_autocaravana_keyword,
    _normalize_str,
)


FIXTURES_DIR = os.path.join(os.path.dirname(__file__), "fixtures", "custojusto")


def _read_fixture(name: str) -> str:
    with open(os.path.join(FIXTURES_DIR, name), encoding="utf-8") as f:
        return f.read()


def _make_adapter(brand=None, year_from=None, year_to=None, body_type=None) -> CustojustoAdapter:
    f = SearchFilters(
        brand=brand, year_from=year_from, year_to=year_to,
        vehicle_type="motorhome", body_type=body_type,
    )
    return CustojustoAdapter(filters=f)


# ─────────────────────────────────────────────────────────────────────────────
# Helpers
# ─────────────────────────────────────────────────────────────────────────────

class TestHelpers:
    def test_normalize_str_strips_accents_and_lowers(self):
        assert _normalize_str("Bragança") == "braganca"
        assert _normalize_str("McLouis Yearling 2020") == "mclouis yearling 2020"

    def test_autocaravana_keyword_detects(self):
        assert _has_autocaravana_keyword("Autocaravana Mercedes 2.8")
        assert _has_autocaravana_keyword("Camper Volkswagen T6")
        assert not _has_autocaravana_keyword("Atrelado tenda Erde")
        assert not _has_autocaravana_keyword("Reboque basculante")


# ─────────────────────────────────────────────────────────────────────────────
# Selecção de paths (body_type → SUBCAT_TARGETS subset)
# ─────────────────────────────────────────────────────────────────────────────

class TestSelectTargets:
    """Emenda do utilizador: quando body_type vem mapeado, scrape SÓ a(s)
    path(s) correspondente(s) — menos requests, mais politeness."""

    def test_body_type_integral_picks_only_integral_path(self):
        targets = _make_adapter(body_type="integral")._select_targets()
        assert [t[0] for t in targets] == ["/integral"]

    def test_body_type_perfiladas_picks_only_perfilada_path(self):
        targets = _make_adapter(body_type="perfiladas")._select_targets()
        assert [t[0] for t in targets] == ["/perfilada"]

    def test_body_type_furgao_picks_two_paths(self):
        """furgao SV cobre /a_furgao E /campervan no CustoJusto."""
        targets = _make_adapter(body_type="furgao")._select_targets()
        assert sorted([t[0] for t in targets]) == ["/a_furgao", "/campervan"]

    def test_no_body_type_picks_all_six_targets(self):
        """Sem categoria mapeada → 5 paths limpas + /caravana com gate."""
        targets = _make_adapter()._select_targets()
        paths = [t[0] for t in targets]
        assert set(paths) == {
            "/integral", "/perfilada", "/capucino",
            "/a_furgao", "/campervan", "/caravana",
        }

    def test_caravana_target_has_none_category(self):
        """Vocabulário canónico: /caravana é ambígua → category=None
        (NÃO alimenta degrau 4 da cascata)."""
        targets = _make_adapter()._select_targets()
        for path, cat in targets:
            if path == "/caravana":
                assert cat is None
                return
        pytest.fail("/caravana não está em SUBCAT_TARGETS")

    def test_category_mapping_to_standvirtual_vocabulary(self):
        """Emenda B do utilizador: category canónica = slug Standvirtual
        para alimentar degrau 4 sem normalização extra."""
        mapping = {p: c for p, c in SUBCAT_TARGETS}
        assert mapping["/integral"]  == "integral"
        assert mapping["/perfilada"] == "perfiladas"  # plural SV
        assert mapping["/capucino"]  == "capucine"
        assert mapping["/a_furgao"]  == "furgao"
        assert mapping["/campervan"] == "furgao"  # ambos → furgao


# ─────────────────────────────────────────────────────────────────────────────
# URL building
# ─────────────────────────────────────────────────────────────────────────────

class TestBuildUrl:
    def test_url_includes_base_and_category(self):
        url = _make_adapter()._build_url("/perfilada")
        assert url == f"{BASE_URL}{CATEGORY_BASE_PATH}/perfilada"

    def test_url_with_query_includes_q_param(self):
        url = _make_adapter()._build_url("/perfilada", q="McLouis")
        assert "?q=mclouis" in url

    def test_url_q_normalized_and_no_accents(self):
        url = _make_adapter()._build_url("/integral", q="Hymer Bürstner")
        # Espaços → +, acentos removidos, tudo lowercase
        assert "?q=hymer+burstner" in url


# ─────────────────────────────────────────────────────────────────────────────
# Parsing fixtures reais
# ─────────────────────────────────────────────────────────────────────────────

class TestParseListItems:
    def test_perfilada_fixture_returns_40_items(self):
        html = _read_fixture("perfilada-page1.html")
        items = _make_adapter()._parse_list_items(html)
        assert items is not None
        assert len(items) == 40

    def test_page2_fixture_returns_40_items(self):
        html = _read_fixture("all-page2.html")
        items = _make_adapter()._parse_list_items(html)
        assert items is not None
        assert len(items) == 40

    def test_invalid_html_returns_none(self):
        assert _make_adapter()._parse_list_items("<html>no next_data</html>") is None

    def test_corrupted_json_returns_none(self):
        # Estrutura __NEXT_DATA__ mas JSON corrompido.
        bad = '<script id="__NEXT_DATA__" type="application/json">{ not json }</script>'
        assert _make_adapter()._parse_list_items(bad) is None


# ─────────────────────────────────────────────────────────────────────────────
# Normalização item-a-item (regras de exclusão)
# ─────────────────────────────────────────────────────────────────────────────

class TestNormalizeItem:
    def _base_item(self, **overrides) -> dict:
        item = {
            "listID": "45028266",
            "title": "Autocaravana Mercedes 2.8",
            "body": "Ano: 2000 Quilómetros: 199.460 km Modelo: James Cook Combustível: Diesel Tipo de Caixa: Caixa manual",
            "price": 3800,
            "url": "/braganca/veiculos/autocaravanas-reboques/caravana/autocaravana-mercedes-2-8-45028266",
            "categoryName": "Caravana",
            "params": {"regdate": "2000"},
            "locationNames": {"district": "Bragança"},
        }
        item.update(overrides)
        return item

    def test_normal_item_produces_raw_listing(self):
        rl = _make_adapter()._normalize_item(self._base_item(), category_canonical="perfiladas")
        assert rl is not None
        assert rl.external_id == "45028266"
        assert rl.source == "custojusto"
        assert rl.url.startswith("https://www.custojusto.pt/")
        assert rl.category == "perfiladas"
        assert rl.region == "Bragança"
        assert rl.params.get("year") == "2000"
        assert rl.params.get("fuel") == "Diesel"
        assert rl.params.get("km") == "199460"  # normaliza pontos como milhar

    def test_url_is_absolute_emenda_c(self):
        """Emenda C: URL absoluto — listItem.url é relativo, prefixar BASE_URL."""
        rl = _make_adapter()._normalize_item(self._base_item(), category_canonical=None)
        assert rl is not None
        assert rl.url.startswith("https://www.custojusto.pt/")

    def test_price_zero_excluded_emenda_a(self):
        """Emenda A: preço <= 0 envenena a mediana — exclui."""
        rl = _make_adapter()._normalize_item(self._base_item(price=0), category_canonical="integral")
        assert rl is None

    def test_negative_price_excluded(self):
        rl = _make_adapter()._normalize_item(self._base_item(price=-100), category_canonical="integral")
        assert rl is None

    def test_atrelado_category_excluded(self):
        """Defesa em profundidade — URL já excluiu, mas se algo escapar..."""
        rl = _make_adapter()._normalize_item(
            self._base_item(categoryName="Atrelado"),
            category_canonical="perfiladas",
        )
        assert rl is None

    def test_reboque_category_excluded(self):
        rl = _make_adapter()._normalize_item(
            self._base_item(categoryName="Reboque"),
            category_canonical="perfiladas",
        )
        assert rl is None

    def test_caravana_with_autocaravana_keyword_passes_gate(self):
        """/caravana com 'autocaravana' no título → entra com category=None."""
        rl = _make_adapter()._normalize_item(
            self._base_item(title="Autocaravana Mercedes 2.8"),
            category_canonical=None,
        )
        assert rl is not None
        assert rl.category is None  # ambígua — não alimenta degrau 4

    def test_caravana_without_autocaravana_keyword_excluded_by_gate(self):
        """/caravana sem keyword 'autocaravana' → exclui (gate de título)."""
        item = self._base_item(
            title="Caravana Vimara Sport 340",  # caravana rebocável
            body="Cama de casal, frigorífico, ano 2010",  # sem keyword motorhome
        )
        rl = _make_adapter()._normalize_item(item, category_canonical=None)
        assert rl is None

    def test_integral_path_with_clear_title_passes_without_gate(self):
        """Para sub-categorias-alvo (integral etc.) gate de título não corre —
        confiamos na URL."""
        rl = _make_adapter()._normalize_item(
            self._base_item(title="Carado T 449"),  # sem palavra "autocaravana"
            category_canonical="integral",
        )
        assert rl is not None
        assert rl.category == "integral"


# ─────────────────────────────────────────────────────────────────────────────
# Filtragem pós-fetch (marca + ano)
# ─────────────────────────────────────────────────────────────────────────────

class TestPostFetchFilters:
    def test_brand_match_tolerates_case(self):
        a = _make_adapter(brand="McLouis")
        assert a._matches_brand("Autocaravana McLouis Yearling 2020", "McLouis")
        assert a._matches_brand("autocaravana mclouis tandy", "McLouis")

    def test_brand_match_tolerates_accents(self):
        a = _make_adapter(brand="Pössl")
        assert a._matches_brand("Possl Roadcamp 2020", "Pössl")

    def test_brand_mismatch_returns_false(self):
        a = _make_adapter(brand="McLouis")
        assert not a._matches_brand("Adria Matrix Plus 670", "McLouis")

    def test_empty_brand_always_matches(self):
        a = _make_adapter()
        assert a._matches_brand("Qualquer coisa", "")

    def test_year_within_window(self):
        a = _make_adapter(year_from=2018, year_to=2022)
        assert a._matches_year_window("2019")
        assert a._matches_year_window("2020")
        assert a._matches_year_window("2022")

    def test_year_outside_window(self):
        a = _make_adapter(year_from=2018, year_to=2022)
        assert not a._matches_year_window("2017")
        assert not a._matches_year_window("2023")

    def test_year_missing_passes_conservative(self):
        """Conservador: snapshots sem ano entram. Não excluo dado em falta."""
        a = _make_adapter(year_from=2018, year_to=2022)
        assert a._matches_year_window(None)
        assert a._matches_year_window("")


# ─────────────────────────────────────────────────────────────────────────────
# Fetch + isolamento de falha
# ─────────────────────────────────────────────────────────────────────────────

class TestFetchOne:
    def test_fetch_url_returns_none_on_http_error(self):
        a = _make_adapter()
        with patch.object(a.session, "get") as mock_get:
            mock_get.return_value.status_code = 403  # bloqueio
            assert a._fetch_url("https://x") is None

    def test_fetch_url_returns_none_on_network_exception(self):
        import requests
        a = _make_adapter()
        with patch.object(a.session, "get", side_effect=requests.ConnectionError("boom")):
            assert a._fetch_url("https://x") is None

    def test_fetch_one_returns_empty_when_html_fetch_fails(self):
        a = _make_adapter()
        with patch.object(a, "_fetch_url", return_value=None):
            assert a._fetch_one("/perfilada", "perfiladas", q=None, brand_filter=None) == []

    def test_fetch_one_returns_empty_when_no_next_data(self):
        a = _make_adapter()
        with patch.object(a, "_fetch_url", return_value="<html>broken</html>"):
            assert a._fetch_one("/perfilada", "perfiladas", q=None, brand_filter=None) == []

    def test_fetch_one_real_fixture_returns_listings(self):
        """End-to-end com fixture: perfilada → ≥1 listing após filtros."""
        html = _read_fixture("perfilada-page1.html")
        a = _make_adapter()  # sem brand/year — aceita todos
        with patch.object(a, "_fetch_url", return_value=html):
            listings = a._fetch_one("/perfilada", "perfiladas", q=None, brand_filter=None)
        assert len(listings) >= 1
        # Todos têm URL absoluto + source CJ + category canónica.
        for rl in listings:
            assert rl.source == "custojusto"
            assert rl.url.startswith("https://www.custojusto.pt/")
            assert rl.category == "perfiladas"

    def test_fetch_one_filters_by_brand_when_brand_filter_set(self):
        """brand_filter explícito → pós-filtro de marca activo. Cenário da
        1ª tentativa em search() quando há marca."""
        html = _read_fixture("perfilada-page1.html")
        a = _make_adapter(brand="McLouis")
        with patch.object(a, "_fetch_url", return_value=html):
            listings = a._fetch_one(
                "/perfilada", "perfiladas",
                q=None, brand_filter="McLouis",
            )
        # Se houver McLouis na fixture, todos os retornados são McLouis.
        for rl in listings:
            assert "mclouis" in _normalize_str(rl.title)

    def test_fetch_one_excludes_zero_price_listings(self):
        """Emenda A — preço 0 (sob consulta) NÃO entra."""
        html = _read_fixture("perfilada-page1.html")
        a = _make_adapter()
        with patch.object(a, "_fetch_url", return_value=html):
            listings = a._fetch_one("/perfilada", "perfiladas", q=None, brand_filter=None)
        for rl in listings:
            # price_raw é string com o int — todos > 0
            assert int(rl.price_raw or "0") > 0


# ─────────────────────────────────────────────────────────────────────────────
# Widen-on-empty (paridade MS1.b mas para CustoJusto)
# ─────────────────────────────────────────────────────────────────────────────

class TestWidenOnEmpty:
    def test_widen_triggered_when_brand_search_empty(self):
        """Brand devolve 0 → retry sem q= na mesma path."""
        a = _make_adapter(brand="MarcaInventada")
        calls = []

        def fake_fetch_one(path, cat, q, brand_filter):
            calls.append(q)
            if q is not None:  # 1ª chamada com brand → 0
                return []
            return [object()]  # 2ª sem brand → ≥1 (stub)

        with patch.object(a, "_fetch_one", side_effect=fake_fetch_one), \
             patch("sources.custojusto.time.sleep"):
            list(a.search())

        # Para cada path tentou com q, depois sem q
        assert any(c is not None for c in calls)
        assert any(c is None for c in calls)

    def test_no_widen_when_no_brand(self):
        """Sem brand inicial, não há widen a fazer."""
        a = _make_adapter()
        calls = []

        def fake_fetch_one(path, cat, q, brand_filter):
            calls.append(q)
            return []

        with patch.object(a, "_fetch_one", side_effect=fake_fetch_one), \
             patch("sources.custojusto.time.sleep"):
            list(a.search())

        # Todas as chamadas têm q=None (sem brand inicial)
        assert all(c is None for c in calls)

    # ── Fix 1 (2026-06-10) — semântica POOL DE CATEGORIA ────────────────────

    def test_widen_drops_brand_filter_to_accumulate_pool(self):
        """Fix 1 — no retry, brand_filter é None (NÃO filtra por marca).
        Garantia: o widen acumula pool da categoria em vez de buscar e
        descartar. Snapshots gravados alimentam o degrau 4 da cascata."""
        a = _make_adapter(body_type="integral", brand="MarcaInventada")
        calls = []

        def fake_fetch_one(path, cat, q, brand_filter):
            calls.append((q, brand_filter))
            if q is not None:  # 1ª chamada com brand → 0
                return []
            return [object()]  # 2ª (widen) → pool da categoria

        with patch.object(a, "_fetch_one", side_effect=fake_fetch_one), \
             patch("sources.custojusto.time.sleep"):
            list(a.search())

        # body_type=integral → 1 path × 2 tentativas = 2 calls
        assert len(calls) == 2
        # 1ª: q=MarcaInventada, brand_filter=MarcaInventada (pós-filtro activo).
        # Strings brutas — o lowercase só ocorre dentro de _build_url.
        assert calls[0] == ("MarcaInventada", "MarcaInventada")
        # 2ª (widen Fix 1): q=None, brand_filter=None (acumula pool)
        assert calls[1] == (None, None)

    def test_widen_preserves_year_price_and_reboque_filters(self):
        """Fix 1 — no retry, todos os OUTROS filtros continuam aplicados:
        janela de ano, preço > 0, exclusão de reboques (defesa em profundidade),
        gate de título no /caravana. Só se removem ?q= e o filtro de marca."""
        # Adapter com brand inventada (força widen) + window de ano apertada
        a = _make_adapter(body_type="integral", brand="MarcaInventada",
                          year_from=2018, year_to=2022)

        # Fixture: 4 items, vários cenários de exclusão para o widen processar.
        # Estes items NÃO têm a MarcaInventada — provam que widen aceita
        # outras marcas (pool de categoria), mas continuam a respeitar os
        # outros filtros.
        widen_items = [
            # Item 1: OK — entra no pool (ano OK, preço OK, sem reboque)
            {
                "listID": "111", "title": "Adria Matrix 670 SC",
                "body": "Ano: 2020 Quilómetros: 50000",
                "price": 65000, "url": "/lisboa/x/integral/adria-111",
                "categoryName": "Integral",
                "params": {"regdate": "2020"},
                "locationNames": {"district": "Lisboa"},
            },
            # Item 2: ano fora da janela — exclui
            {
                "listID": "222", "title": "Hymer B598 NACIONAL",
                "body": "Ano: 2010",
                "price": 40000, "url": "/porto/x/integral/hymer-222",
                "categoryName": "Integral",
                "params": {"regdate": "2010"},
                "locationNames": {"district": "Porto"},
            },
            # Item 3: preço <= 0 — exclui (Emenda A)
            {
                "listID": "333", "title": "Pössl Roadcamp 2021",
                "body": "Ano: 2021",
                "price": 0, "url": "/braga/x/integral/possl-333",
                "categoryName": "Integral",
                "params": {"regdate": "2021"},
                "locationNames": {"district": "Braga"},
            },
            # Item 4: categoryName Atrelado — exclui (defesa em profundidade)
            {
                "listID": "444", "title": "Reboque Tenda Erde 2020",
                "body": "Ano: 2020",
                "price": 1500, "url": "/aveiro/x/atrelado/erde-444",
                "categoryName": "Atrelado",
                "params": {"regdate": "2020"},
                "locationNames": {"district": "Aveiro"},
            },
        ]

        # 1ª chamada (com marca) → 0; 2ª (widen) → 4 items para processar.
        call_state = {"n": 0}

        def fake_fetch_url(url):
            call_state["n"] += 1
            if "?q=" in url:
                # 1ª chamada — devolve HTML "vazio" (next_data com listItems=[])
                return _fake_html_with_items([])
            # 2ª chamada (widen) — devolve os 4 items para o filtro testar
            return _fake_html_with_items(widen_items)

        with patch.object(a, "_fetch_url", side_effect=fake_fetch_url), \
             patch("sources.custojusto.time.sleep"):
            all_listings = []
            for batch in a.search():
                all_listings.extend(batch)

        # Após filtros, só o Item 1 (Adria 2020) entra.
        # - Item 2 excluído por ano.
        # - Item 3 excluído por preço.
        # - Item 4 excluído por categoryName=Atrelado.
        # - Item 1 entra mesmo NÃO sendo MarcaInventada → prova POOL DE CATEGORIA.
        assert len(all_listings) == 1
        rl = all_listings[0]
        assert rl.external_id == "111"
        assert "adria" in _normalize_str(rl.title)  # NÃO é MarcaInventada
        assert rl.category == "integral"  # vocabulário SV
        assert int(rl.price_raw) == 65000


def _fake_html_with_items(items: list[dict]) -> str:
    """Constrói HTML mínimo com __NEXT_DATA__ contendo listItems."""
    import json as _json
    payload = {
        "props": {
            "pageProps": {"listItems": items}
        }
    }
    return f'<script id="__NEXT_DATA__" type="application/json">{_json.dumps(payload)}</script>'


# ─────────────────────────────────────────────────────────────────────────────
# search() — isolamento de falha entre paths
# ─────────────────────────────────────────────────────────────────────────────

class TestSearchIsolation:
    def test_exception_in_one_path_does_not_kill_others(self):
        a = _make_adapter(body_type="furgao")  # 2 paths
        call_order = []

        def fake_fetch_one(path, cat, q, brand_filter):
            call_order.append(path)
            if path == "/a_furgao":
                raise RuntimeError("simulação de falha")
            return [object()]

        with patch.object(a, "_fetch_one", side_effect=fake_fetch_one), \
             patch("sources.custojusto.time.sleep"):
            list(a.search())

        # As DUAS paths foram tentadas, apesar da 1ª ter falhado
        assert "/a_furgao" in call_order
        assert "/campervan" in call_order


# ─────────────────────────────────────────────────────────────────────────────
# MS2.e — yield order: brand-matched de TODAS as paths ANTES de qualquer widen
# ─────────────────────────────────────────────────────────────────────────────

class TestBrandMatchedYieldedBeforeWiden:
    """O cap final do main.py (--max-results) corta pela ORDEM em que os
    listings são yielded. Para preservar prioridade de brand-matched (que
    alimentam degraus 1/2/5 da cascata Laravel), todos os brand-matched de
    todas as paths têm de vir ANTES de qualquer widen-pool (que alimenta o
    degrau 4 da categoria). Sem isto, num cenário de pool cheio (Hymer com
    /integral widening), os McLouis brand-matched de uma path mais tardia
    seriam cortados a favor de 30 perfiladas de outras marcas."""

    def test_brand_matched_yielded_before_widen_pool(self):
        # Cenário:
        #   - /integral devolve 0 brand-matched (→ widen)
        #   - /perfilada devolve 1 brand-matched
        #   - /capucino devolve 0 brand-matched (→ widen)
        # Antes do fix MS2.e o yield seria:
        #   [40 pool widen /integral, 1 brand-matched /perfilada, 40 pool /capucino]
        # Após o fix:
        #   [1 brand-matched /perfilada, 40 pool /integral, 40 pool /capucino]
        a = _make_adapter()
        a.filters.brand = "TargetBrand"

        # Override targets para 3 paths controladas
        with patch.object(a, "_select_targets", return_value=[
            ("/integral", "integral"),
            ("/perfilada", "perfiladas"),
            ("/capucino", "capucine"),
        ]):
            def fake_fetch_one(path, cat, q, brand_filter):
                # 1ª passada (brand_filter is not None):
                if brand_filter is not None:
                    if path == "/perfilada":
                        return ["BRAND-MATCH-perfilada-1"]
                    return []
                # 2ª passada (widen): só /integral e /capucino chegam aqui
                if path == "/integral":
                    return ["POOL-integral-A", "POOL-integral-B"]
                if path == "/capucino":
                    return ["POOL-capucino-A"]
                return []

            with patch.object(a, "_fetch_one", side_effect=fake_fetch_one), \
                 patch("sources.custojusto.time.sleep"):
                yielded: list = []
                for batch in a.search():
                    yielded.extend(batch)

        # 1. Brand-matched é o PRIMEIRO yield
        assert yielded[0] == "BRAND-MATCH-perfilada-1"
        # 2. Pool widen aparece depois (não importa ordem interna)
        rest = set(yielded[1:])
        assert rest == {"POOL-integral-A", "POOL-integral-B", "POOL-capucino-A"}

    def test_brand_matched_in_late_path_survives_max_results_cap(self):
        """Caso de aceitação real do utilizador: marca só aparece na ÚLTIMA
        path. Com 2 passadas, o brand-matched é o 1.º yield e fica em segurança
        independentemente do volume de widens das outras paths."""
        a = _make_adapter()
        a.filters.brand = "RareBrand"

        # 6 paths; só a última (/caravana) tem brand-matched.
        with patch.object(a, "_select_targets", return_value=[
            ("/integral",  "integral"),
            ("/perfilada", "perfiladas"),
            ("/capucino",  "capucine"),
            ("/a_furgao",  "furgao"),
            ("/campervan", "furgao"),
            ("/caravana",  None),
        ]):
            def fake_fetch_one(path, cat, q, brand_filter):
                if brand_filter is not None:
                    if path == "/caravana":
                        return ["RARE-MATCH-caravana"]
                    return []
                # Widens das outras 5 paths devolvem pool gordo
                return [f"POOL-{path}-{i}" for i in range(8)]

            with patch.object(a, "_fetch_one", side_effect=fake_fetch_one), \
                 patch("sources.custojusto.time.sleep"):
                yielded: list = []
                for batch in a.search():
                    yielded.extend(batch)

        # 1.º yield é o brand-matched, antes dos 40 do pool.
        assert yielded[0] == "RARE-MATCH-caravana"
        # 5 paths × 8 = 40 pool items depois
        assert len(yielded) == 1 + 40

    def test_no_brand_no_widen_no_passes_collapse(self):
        """Sem brand inicial → 1ª passada faz o fetch normal; 2ª passada
        nunca corre (nenhum path ficou em 0 a ser candidato a widen).
        Eficiência preservada — não duplicamos requests sem motivo."""
        a = _make_adapter()  # sem brand

        with patch.object(a, "_select_targets", return_value=[
            ("/integral", "integral"),
        ]):
            calls = []

            def fake_fetch_one(path, cat, q, brand_filter):
                calls.append((q, brand_filter))
                return ["ok"]  # devolve algo → não vai para widen

            with patch.object(a, "_fetch_one", side_effect=fake_fetch_one), \
                 patch("sources.custojusto.time.sleep"):
                list(a.search())

        # 1 chamada apenas; sem widen porque a 1ª devolveu listings.
        assert len(calls) == 1
        assert calls[0] == (None, None)


# ─────────────────────────────────────────────────────────────────────────────
# Contrato SourceAdapter
# ─────────────────────────────────────────────────────────────────────────────

class TestSourceAdapterContract:
    def test_name_attribute(self):
        assert CustojustoAdapter.name == "custojusto"

    def test_is_registered_in_adapters_registry(self):
        from sources import ADAPTERS, get_adapter_class
        assert "custojusto" in ADAPTERS
        assert get_adapter_class("custojusto") is CustojustoAdapter
