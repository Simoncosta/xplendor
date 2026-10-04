import { buildAutoInsights, comparisonView, countHighPriority, usesSeasonality } from "./automotiveMarketingText";
import type { AutoComparison, AutomotiveMarketing } from "common/models/automotiveMarketing.model";
import type { Recommendation } from "common/models/recommendation.model";

const prev = (delta_pct: number | null, base = 100): AutoComparison => ({
    tier: "previous_month", reference_month: "2026-09", window: { start: "2026-09-01", end: "2026-09-15" },
    base_value: base, delta_abs: 0, delta_pct, seasonality_warning: true,
});
const lastYearZero: AutoComparison = {
    tier: "same_month_last_year", reference_month: "2025-10", window: { start: "2025-10-01", end: "2025-10-15" },
    base_value: 0, delta_abs: 71, delta_pct: null, seasonality_warning: false,
};
const none: AutoComparison = { tier: null, reason: "no_history" };

const data = (over: Partial<AutomotiveMarketing["metrics"]> = {}): AutomotiveMarketing => ({
    month: "2026-10",
    is_current_month: true,
    period: { start: "2026-10-01", end: "2026-10-15", days: 15 },
    comparison_windows: {},
    sources: { tracking: { state: "ok", since: "2026-01-01" }, meta: { state: "ok" }, ga4: { state: "ok" } },
    metrics: {
        leads: { total: 12, paid: 4, series: [], comparison: prev(20), paid_comparison: prev(0) },
        contacts: { total: 30, by_type: { whatsapp: 20, call: 6, phone_reveal: 4 }, series: [], comparison: prev(-10) },
        meta_spend: {
            total: 71, clicks: 140, series: [], comparison: lastYearZero, clicks_comparison: lastYearZero,
            breakdown: { ad_level_available: true, by_car: 40, by_car_tag: 40, by_car_manual_mapping: 0, general_stock: 25, unattributed: 6 },
        },
        ga4_sessions: {
            total: 800, series: [], comparison: prev(10),
            by_group: {
                paid: { total: 200, comparison: none }, organic_social: { total: 200, comparison: none },
                search: { total: 200, comparison: none }, direct: { total: 150, comparison: none }, other: { total: 50, comparison: none },
            },
        },
        paid_cpl: { spend: 71, paid_leads: 4, value: 17.75, state: "ok", comparison: none },
        sales: { context_only: true, count: 3, revenue: 54000, without_value: 0 },
        ...over,
    },
    quality_signals: [],
});

/** O Intl usa um espaço não separável antes do "€". */
const sp = (s: string) => s.replace(/\u00a0/g, " ");

const CAUSAL = /porque|devido|graças|por causa|resultou|causou|gerou/i;

describe("automotiveMarketingText", () => {
    it("base 0 aparece em texto, nunca como percentagem infinita", () => {
        expect(comparisonView(lastYearZero, "investimento")).toEqual({
            kind: "zero", text: "sem investimento no mesmo mês do ano passado", seasonal: false,
        });
        const s = sp(buildAutoInsights(data()).join(" "));
        expect(s).toContain("investiu 71 € na Meta (sem investimento no mesmo mês do ano passado)");
        expect(s).not.toMatch(/∞|Infinity|NaN/);
    });

    it("sem comparação por o registo ter começado a meio da janela mostra a data", () => {
        const c: AutoComparison = { tier: null, reason: "tracking_started", tracking_since: "2026-10-02" };
        expect(comparisonView(c, "leads")).toEqual({ kind: "none", text: "sem comparação: o registo de visitas começou a 02/10" });
        expect(comparisonView(none, "leads")).toEqual({ kind: "none", text: "sem histórico" });
    });

    it("delta com a etiqueta do escalão e o aviso de sazonalidade", () => {
        expect(comparisonView(prev(20), "leads")).toEqual({ kind: "delta", deltaPct: 20, tag: "vs set. 2026", seasonal: true });
        expect(usesSeasonality(data())).toBe(true);
    });

    it("2 a 4 frases descritivas, sem travessões e nunca causais", () => {
        const s = buildAutoInsights(data()).map(sp);
        expect(s.length).toBeGreaterThanOrEqual(2);
        expect(s.length).toBeLessThanOrEqual(4);
        expect(s[1]).toBe("No mesmo período, registou 12 leads, 4 das quais com origem paga (+20% face a setembro).");
        expect(s[2]).toBe("O custo por lead pago foi de 17,75 €.");
        s.forEach((t) => {
            expect(t).not.toMatch(CAUSAL);
            expect(t).not.toContain("—");
        });
    });

    it("gasto sem lead paga é descrito sem dividir por zero", () => {
        const s = buildAutoInsights(data({ paid_cpl: { spend: 50, paid_leads: 0, value: null, state: "spend_without_lead", comparison: none } })).map(sp);
        expect(s).toContain("Houve 50 € de investimento na Meta e nenhuma lead com origem paga.");
    });

    it("contador do separador Stock: uma recomendação alta por viatura", () => {
        const r = (car: number | null, level: "high" | "medium", priority: number): Recommendation => ({
            rule_key: "x", priority, level, title: "", why: "", evidence: car === null ? {} : { car_id: car },
            action: { label: "", url: "" }, generated_at: "",
        });
        expect(countHighPriority([r(1, "high", 100), r(1, "high", 90), r(2, "medium", 60), r(null, "high", 80)])).toBe(2);
        expect(countHighPriority([r(3, "medium", 65), r(3, "high", 70)])).toBe(1);
    });
});
