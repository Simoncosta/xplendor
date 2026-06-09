// ── CarSpecs ──────────────────────────────────────────────────────────────────

export interface CarSpecsImage {
    id: number;
    url: string;
    is_primary: boolean;
}

export interface CarSpecsPotentialScore {
    score: number | null;
    classification: string;
}

export interface CarSpecsAnalyses {
    urgency_level: string | null;
    price_alert: boolean;
    analysis: Record<string, unknown> | null;
}

export interface CarSpecsSale {
    sale_price: number | null;
    sale_channel: string | null;
    buyer_name: string | null;
    buyer_phone: string | null;
    buyer_email: string | null;
    buyer_gender: string | null;
    buyer_age_range: string | null;
    contact_consent: boolean;
    notes: string | null;
    sold_at: string | null;
}

export interface CarSpecs {
    id: number;
    status: string;
    created_at: string | null;
    brand: { id: number; name: string } | null;
    model: { id: number; name: string } | null;
    version: string | null;
    specs: {
        fuel_type: string | null;
        transmission: string | null;
        power_hp: number | null;
        engine_capacity_cc: number | null;
        doors: number | null;
        seats: number | null;
        segment: string | null;
        exterior_color: string | null;
    };
    state: {
        condition: string | null;
        origin: string | null;
        mileage_km: number | null;
        has_spare_key: boolean;
        has_manuals: boolean;
        is_trade_in: boolean;
    };
    price: {
        gross: number | null;
        promo_gross: number | null;
        promo_discount_pct: number | null;
        hide_price_online: boolean;
    };
    registration: {
        year: number | null;
        month: number | null;
    };
    identification: {
        license_plate: string | null;
        vin: string | null;
    };
    description: string | null;
    images: CarSpecsImage[];
    header_meta: {
        potential_score: CarSpecsPotentialScore | null;
        analyses: CarSpecsAnalyses | null;
    };
    sale: CarSpecsSale | null;
}

// ── MarketAggregate ───────────────────────────────────────────────────────────

export type MarketAggregateStatus =
    | 'pending'
    | 'running'
    | 'success'
    | 'failed'
    | 'none'
    | 'blocked'
    | 'error';

export type MarketAggregateConfidence = 'high' | 'medium' | 'low' | 'none';

export type MarketPriceSignal = 'overpriced' | 'slightly_high' | 'fair' | 'competitive';

export interface MarketComparable {
    url: string;
    title: string;
    year: number | null;
    price: number;
    fuel?: string;
    gearbox?: string;
    region?: string;
}

export interface MarketAggregatePrices {
    median: number | null;
    min: number | null;
    max: number | null;
    avg: number | null;
}

export interface MarketAggregateComparison {
    car_price: number | null;
    /** Only present when a promo price was active at snapshot time. Represents the list price. */
    car_price_gross?: number;
    difference_percent: number | null;
    signal: MarketPriceSignal | null;
}

export interface MarketAggregate {
    id: number;
    status: MarketAggregateStatus;
    confidence: MarketAggregateConfidence;
    comparables_count: number;
    prices: MarketAggregatePrices;
    comparison: MarketAggregateComparison;
    top_comparables: MarketComparable[];
    fallback_used: boolean;
    search_url: string | null;
    created_at: string;
    updated_at: string;
}

export interface RefreshMarketAggregateResult {
    aggregate_id: number;
    status: MarketAggregateStatus;
}
