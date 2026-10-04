// XPLENDOR — Dashboard do automóvel: bloco "Marketing e resultados" (GA4 + Meta +
// leads) de um mês. Espelha a resposta de
// GET /companies/{id}/analytics/automotive/marketing?month=AAAA-MM.

export type AutoComparisonTier = "same_month_last_year" | "previous_month";

/**
 * Comparação de um total. Sem escalão: 'no_history' (sem base completa) ou
 * 'tracking_started' (o registo de visitas começou a meio da janela de base).
 * Com escalão e base 0: delta_pct vem null (nunca uma percentagem infinita).
 */
export type AutoComparison =
    | { tier: null; reason: "no_history" }
    | { tier: null; reason: "tracking_started"; tracking_since: string }
    | {
        tier: AutoComparisonTier;
        reference_month: string; // AAAA-MM
        window: { start: string; end: string };
        base_value: number;
        delta_abs: number;
        delta_pct: number | null;
        seasonality_warning: boolean;
    };

export type SourceState = "ok" | "not_connected" | "token_expired" | "needs_account" | "syncing_first" | "sync_failed" | "no_data" | "error";

export interface Ga4Groups { paid: number; organic_social: number; search: number; direct: number; other: number; }

export interface AutomotiveMarketing {
    month: string;
    is_current_month: boolean;
    period: { start: string; end: string; days: number };
    comparison_windows: Record<string, { start: string; end: string }>;
    sources: {
        tracking: { state: "ok" | "no_data"; since: string | null };
        meta: { state: SourceState };
        ga4: { state: SourceState; error?: string };
    };
    metrics: {
        leads: null | {
            total: number;
            paid: number;
            series: { date: string; total: number; paid: number }[];
            comparison: AutoComparison;
            paid_comparison: AutoComparison;
        };
        contacts: null | {
            total: number;
            by_type: { whatsapp: number; call: number; phone_reveal: number };
            series: { date: string; value: number }[];
            comparison: AutoComparison;
        };
        meta_spend: null | {
            total: number;
            clicks: number;
            series: { date: string; spend: number; clicks: number }[];
            comparison: AutoComparison;
            clicks_comparison: AutoComparison;
            breakdown: {
                ad_level_available: boolean;
                by_car: number;
                by_car_tag: number;
                by_car_manual_mapping: number;
                general_stock: number | null;
                unattributed: number | null;
            };
        };
        ga4_sessions: null | {
            total: number;
            by_group: Record<keyof Ga4Groups, { total: number; comparison: AutoComparison }>;
            series: ({ date: string } & Ga4Groups)[];
            comparison: AutoComparison;
        };
        paid_cpl: null | {
            spend: number;
            paid_leads: number;
            value: number | null;
            state: "ok" | "spend_without_lead" | "no_spend";
            comparison: AutoComparison;
        };
        sales: null | { context_only: true; count: number; revenue: number; without_value: number };
    };
    quality_signals: { code: "possible_missing_utm"; meta_spend: number; paid_leads: number; organic_social_leads: number }[];
}
