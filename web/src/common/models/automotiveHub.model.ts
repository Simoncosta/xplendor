// XPLENDOR — Hub do Automóvel (separador "Stock" do dashboard). Espelha
// GET /companies/{id}/automotive-hub e GET /companies/{id}/automotive-hub/funnel?days=14|30.
import type { Recommendation, RecommendationNotice } from "./recommendation.model";

export type HubPricePosition = "above_market" | "aligned_market" | "below_market" | "insufficient_data" | "approximate_comparison";

export interface HubPrice {
    effective_price: number | null;
    display_position: HubPricePosition;
    comparison: "exact" | "approximate" | null;
    criteria_widened: boolean;
    difference_pct: number | null;
    confidence: string | null;
}

export interface AutomotiveHubSummary {
    stock: { total_cars: number; own_stock: number; trade_ins: number; avg_days_in_stock: number; avg_price: number };
    stuck_capital: { amount: number; total_capital: number; cars: number; thresholds: Record<string, number> };
    price_position: {
        above_market_pct: number | null;
        above_market_cars: number;
        eligible_cars: number;
        approximate_cars: number;
        low_confidence_cars: number;
        no_data_cars: number;
        min_confidence: string;
    };
    meta_spend: {
        window_days: number;
        uses_tags: boolean;
        ad_level_available: boolean;
        by_car: number;
        by_car_tag: number;
        by_car_manual_mapping: number;
        general_stock: number | null;
        unattributed: number | null;
    };
}

export interface InvalidTagWarning {
    ad_id: string;
    ad_name: string | null;
    invalid_ids: string[];
    effective_status: string | null;
    spend_recent: number;
}

export interface AutomotiveHub {
    summary: AutomotiveHubSummary;
    recommendations: { recommendations: Recommendation[]; total: number; high_count: number; notices: RecommendationNotice[] };
    warnings: { invalid_tags: { count: number; items: InvalidTagWarning[] } };
}

export interface FunnelRow {
    car_id: number;
    car_title: string;
    status: string;
    days_in_stock: number;
    price: HubPrice;
    views: number;
    contacts: number;
    leads: number;
    paid_leads: number;
    sold: boolean;
    sold_at: string | null;
    paid_spend: number;
    cpl: number | null;
    cpl_state: "ok" | "spend_without_lead" | "no_spend";
    ad_status: { status: "active" | "inactive" | "none"; active_ads: number };
}

export type FunnelSortKey = "days_in_stock" | "views" | "contacts" | "leads";
export type SortDirection = "asc" | "desc";

export interface AutomotiveFunnel {
    days: 14 | 30;
    from: string;
    to: string;
    rows: FunnelRow[];
    /** Ordenação aplicada no backend, antes da paginação (por omissão: dias em stock, maior primeiro). */
    sort: { by: FunnelSortKey; direction: SortDirection };
    /** Paginação no backend. */
    pagination: { current_page: number; per_page: number; total: number; last_page: number; from: number; to: number };
    /** Totais de TODAS as viaturas do período (não só da página). */
    totals: {
        cars: number; views: number; contacts: number; leads: number; paid_leads: number; sales: number;
        paid_spend: number; cpl: number | null; cpl_state: "ok" | "spend_without_lead" | "no_spend";
    };
}
