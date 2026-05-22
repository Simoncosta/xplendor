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
