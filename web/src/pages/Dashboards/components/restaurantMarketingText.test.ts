import { buildInsights, comparisonTag, usesSeasonalityWarning } from "./restaurantMarketingText";
import type { RestaurantMarketing, MarketingComparison } from "common/models/restaurantMarketing.model";

const prev = (delta_pct: number | null, base = 100): MarketingComparison => ({
    tier: "previous_month", reference_month: "2026-08", window: { start: "2026-08-01", end: "2026-08-31" },
    base_value: base, delta_abs: 0, delta_pct, seasonality_warning: true,
});
const none: MarketingComparison = { tier: null, reason: "no_history" };

const base = (over: Partial<RestaurantMarketing["metrics"]> = {}): RestaurantMarketing => ({
    month: "2026-09",
    is_current_month: false,
    period: { start: "2026-09-01", end: "2026-09-30", days: 30 },
    sources: { internal: { state: "ok" }, meta: { state: "ok" }, ga4: { state: "ok" } },
    metrics: {
        revenue: { unit: "EUR", total: 12000, series: [], comparison: prev(12) },
        covers: { unit: "people", total: 900, series: [], comparison: prev(8), has_data: true },
        meta_spend: { unit: "EUR", total: 320, series: [], comparison: prev(40) },
        meta_clicks: { unit: "clicks", total: 410, series: [], comparison: prev(15) },
        ga4_sessions: {
            unit: "sessions", total: 2000, series: [], comparison: prev(10),
            by_group: {
                paid: { total: 500, comparison: none }, organic_social: { total: 600, comparison: none },
                search: { total: 400, comparison: none }, direct: { total: 400, comparison: none }, other: { total: 100, comparison: none },
            },
        },
        marketing_weight: { unit: "pct", value: 2.67, comparison: { ...prev(null), delta_pp: 0.5 } as MarketingComparison },
        reservations_mix: null,
        ...over,
    },
    declared: null,
});

const norm = (s: string) => s.replace(/ /g, " ");

describe("buildInsights", () => {
    it("compõe frases descritivas lado a lado com as referências", () => {
        const s = buildInsights(base()).map(norm);

        expect(s.length).toBeGreaterThanOrEqual(2);
        expect(s.length).toBeLessThanOrEqual(4);
        expect(s[0]).toBe("Em setembro, investiu 320 € na Meta (+40% face a agosto).");
        expect(s[1]).toBe("No mesmo período, a faturação variou +12% e as pessoas +8%.");
        expect(s[2]).toBe("O site teve 2000 sessões (+10% face a agosto), 25% das quais vindas de anúncios pagos.");
        expect(s[3]).toContain("representou 2,7% da faturação (+0,5 p.p. face a agosto)");
    });

    it("nunca usa linguagem causal nem travessões", () => {
        const text = buildInsights(base()).join(" ").toLowerCase();
        for (const word of ["porque", "devido", "graças", "por causa", "resultou", "causou", "levou a", "fez com que"]) {
            expect(text).not.toContain(word);
        }
        expect(text).not.toMatch(/[–—]/); // – e —
    });

    it("sem comparação mostra os totais sem inventar percentagens", () => {
        const s = buildInsights(base({
            revenue: { unit: "EUR", total: 5000, series: [], comparison: none },
            covers: { unit: "people", total: 300, series: [], comparison: none, has_data: true },
            meta_spend: null, meta_clicks: null, ga4_sessions: null, marketing_weight: null,
        })).map(norm);

        expect(s[0]).toBe("Em setembro, faturou 5000 € e recebeu 300 pessoas.");
        expect(s.join(" ")).not.toContain("%");
    });

    it("no mês em curso fala dos dias decorridos", () => {
        const d = base();
        d.is_current_month = true;
        d.month = "2026-10";
        d.period = { start: "2026-10-01", end: "2026-10-15", days: 15 };
        expect(norm(buildInsights(d)[0])).toMatch(/^Entre 1 e 15 de outubro, investiu/);
    });

    it("mês sem dias completos não gera frases", () => {
        const d = base();
        d.period = { start: "2026-10-01", end: "2026-09-30", days: 0 };
        expect(buildInsights(d)).toEqual([]);
    });
});

describe("etiquetas", () => {
    it("etiqueta da comparação", () => {
        expect(comparisonTag(prev(5))).toBe("vs ago. 2026");
        expect(comparisonTag(none)).toBe("sem histórico");
    });
    it("deteta o aviso de sazonalidade", () => {
        expect(usesSeasonalityWarning(base())).toBe(true);
    });
});
