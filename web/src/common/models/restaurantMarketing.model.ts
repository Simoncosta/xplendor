// XPLENDOR — Dashboard de restauração: bloco "marketing e resultados" (GA4 + Meta +
// dados internos) de um mês. Espelha a resposta de
// GET /companies/{id}/analytics/restaurant/marketing?month=AAAA-MM (Parte A).

export type ComparisonTier = "same_month_last_year" | "previous_month" | "declared";

/** Comparação de um total (delta em %) ou de uma percentagem (delta em pontos percentuais). */
export type MarketingComparison =
    | { tier: null; reason: "no_history" }
    | {
        tier: ComparisonTier;
        reference_month: string; // AAAA-MM
        window: { start: string; end: string };
        base_value: number;
        delta_abs?: number;
        delta_pct?: number | null;
        delta_pp?: number;
        seasonality_warning: boolean;
    };

export interface SeriesPoint { date: string; value: number; }

export interface TotalMetric {
    unit: string;
    total: number;
    series: SeriesPoint[];
    comparison: MarketingComparison;
    has_data?: boolean;
}

export type Ga4Group = "paid" | "organic_social" | "search" | "direct" | "other";

export interface Ga4SessionsMetric {
    unit: "sessions";
    total: number;
    by_group: Record<Ga4Group, { total: number; comparison: MarketingComparison }>;
    series: Array<{ date: string } & Record<Ga4Group, number>>;
    comparison: MarketingComparison;
}

export interface WeightMetric { unit: "pct"; value: number | null; comparison: MarketingComparison; }

export interface ReservationsMixMetric {
    reserved: number;
    walk_ins: number;
    reserved_share_pct: number | null;
    series: Array<{ date: string; reserved: number; walk_ins: number }>;
    comparison: MarketingComparison;
}

export type MetaSourceState = "not_connected" | "token_expired" | "needs_account" | "sync_failed" | "syncing_first" | "ok";

export interface RestaurantMarketing {
    month: string;
    is_current_month: boolean;
    period: { start: string; end: string; days: number };
    sources: {
        internal: { state: "ok" | "no_data" };
        meta: { state: MetaSourceState };
        ga4: { state: "ok" | "not_connected" | "error"; error?: string };
    };
    metrics: {
        revenue: TotalMetric | null;
        covers: TotalMetric | null;
        meta_spend: TotalMetric | null;
        meta_clicks: TotalMetric | null;
        ga4_sessions: Ga4SessionsMetric | null;
        marketing_weight: WeightMetric | null;
        reservations_mix: ReservationsMixMetric | null;
    };
    declared: null;
}
