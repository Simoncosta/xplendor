// ── CarSpecs ──────────────────────────────────────────────────────────────────

export interface CarSpecsImage {
    id: number;
    url: string;
    is_primary: boolean;
}

export interface CarSpecsPotentialScore {
    score: number;
    classification: string;
}

export interface CarSpecsAnalyses {
    urgency_level: string | null;
    price_alert: boolean;
    analysis: Record<string, unknown> | null;
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
    brand: string;
    model: string;
    year: number;
    km: number | null;
    price: number;
}

export interface MarketAggregatePrices {
    median: number | null;
    min: number | null;
    max: number | null;
    avg: number | null;
}

export interface MarketAggregateComparison {
    car_price: number | null;
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
    created_at: string;
    updated_at: string;
}

export interface RefreshMarketAggregateResult {
    aggregate_id: number;
    status: MarketAggregateStatus;
}
