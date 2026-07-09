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
    /** MS2.f — fonte do anúncio. NOT optional após MS2 (backend sempre o emite
     *  via selectTop5 → top_comparables[i].source). Slug ∈ {standvirtual,
     *  custojusto, …}; traduzido para apresentação via MARKET_SOURCE_LABELS. */
    source: string;
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
    /** MS1.c — derivado do car, permite ao UI escolher a mensagem accionável
     *  quando o aggregate está vazio (preço 0 vs 'Sob consulta'). Optional para
     *  tolerar payloads de versões antigas ou outras fontes que não o emitam. */
    hide_price_online?: boolean;
    /** MS2.f — contagens por fonte sobre o pool POS-dedup que alimentou a
     *  mediana. Ex.: {"standvirtual": 8, "custojusto": 4}. Optional para
     *  fallback gracioso quando o payload é de versões pré-MS2 ou está vazio. */
    sources_breakdown?: Record<string, number>;
    created_at: string;
    updated_at: string;
}

export interface RefreshMarketAggregateResult {
    aggregate_id: number;
    status: MarketAggregateStatus;
}

// ── Relatório A — candidatas a promoção ───────────────────────────────────────
//
// Linha tabular consumida por `/stock/promotion`. Null states são CASOS REAIS:
//   - `market: null`             → motorhome sem aggregate (frequente)
//   - `ips.score: null`          → sem sinais; classification "pending" (frequente)
//   - `promotion: null`          → não marcada
//   - `category: null`           → carro (não tem categoria — só motorhome)
//   - `engine_brand: null`       → carro (só motorhome/caravan)
//
// Numéricos seguros (sem null mascarado):
//   - `engagement.{views,leads}: number`   (0 é real)
//   - `days_in_stock: number`              (0 = entrou hoje)

export type PromotionVehicleType = 'car' | 'motorcycle' | 'motorhome' | 'caravan';
export type PromotionVehicleStatus = 'active' | 'available_soon' | 'reserved';

export interface PromotionCandidateMarket {
    status: MarketAggregateStatus;
    confidence: MarketAggregateConfidence;
    comparables_count: number;
    median_price: number | null;
    price_signal: MarketPriceSignal | null;
    price_difference_percent: number | null;
}

export interface PromotionCandidateIps {
    score: number | null;             // null + classification "pending" = a calibrar
    classification: 'hot' | 'warm' | 'cold' | 'pending';
    calculated_at: string | null;
}

export interface PromotionCandidatePriority {
    id: number;
    marked_at: string | null;
    note: string | null;
    marked_by: { id: number; name: string } | null;
}

export interface PromotionCandidate {
    id: number;
    vehicle_type: PromotionVehicleType;
    status: PromotionVehicleStatus;
    brand: { id: number; name: string } | null;
    model: { id: number; name: string } | null;
    category: { id: number; name: string; slug: string } | null;
    segment: string | null;
    engine_brand: string | null;
    version: string | null;
    public_version_name: string | null;
    registration_year: number | null;
    mileage_km: number | null;
    /** Caminho relativo da imagem principal. Frontend prefixa com REACT_APP_PUBLIC_URL.
     *  `null` quando a viatura ainda não tem imagens — placeholder gracioso. */
    thumbnail: string | null;
    price: {
        gross: number | null;
        promo: number | null;
        effective: number | null;
        has_promo: boolean;
        hide_online: boolean;
    };
    days_in_stock: number;
    is_stale: boolean;
    engagement: { views_count: number; leads_count: number };
    market: PromotionCandidateMarket | null;
    ips: PromotionCandidateIps | null;
    promotion: PromotionCandidatePriority | null;
}

export interface PromotionCandidatesMeta {
    current_page?: number;
    last_page?: number;
    per_page?: number;
    total?: number;
    from?: number;
    to?: number;
    thresholds: {
        stock_age_days: Record<string, number>;
        default: number;
    };
}

export interface PromotionSummary {
    visible_by_type: Record<string, number>;
    visible_total: number;
    marked_total: number;
}

export interface PromotionCandidatesPage {
    data: PromotionCandidate[];
    meta: PromotionCandidatesMeta;
    links?: unknown;
    current_page?: number;
    last_page?: number;
    per_page?: number;
    total?: number;
}

export interface ListPromotionCandidatesParams {
    page?: number;
    per_page?: number;
    vehicle_type?: PromotionVehicleType;
    status?: PromotionVehicleStatus[];
    min_price?: number;
    max_price?: number;
    min_days_in_stock?: number;
    max_days_in_stock?: number;
    price_signal?: MarketPriceSignal[];
    only_marked?: boolean;
    sort_by?: 'days_in_stock' | 'price' | 'views' | 'leads' | 'ips';
    sort_dir?: 'asc' | 'desc';
}

// ────────────────────────────────────────────────────────────────────────────
// Visões 1+2 do Dashboard (2026-06-25) — stock por marca + tipo.
// ────────────────────────────────────────────────────────────────────────────

export interface StockBreakdownBrandRow {
    name: string;
    count: number;
}

export interface StockBreakdownTypeRow {
    /** Slug cru emitido pelo backend — frontend traduz via VEHICLE_TYPE_LABELS. */
    type: PromotionVehicleType;
    count: number;
}

export interface StockBreakdown {
    by_brand: StockBreakdownBrandRow[];
    by_type: StockBreakdownTypeRow[];
}

// ────────────────────────────────────────────────────────────────────────────
// Visão 3 do Dashboard (2026-06-25) — FATURAÇÃO (NÃO é lucro) por período.
// Sem `purchase_price` em prod, lucro real só existirá quando essa faixa
// entrar. Rotular sempre "Vendas no período" / "Faturação" — nunca "Lucro".
// ────────────────────────────────────────────────────────────────────────────

export type SalesRevenueGranularity = "month" | "year";

export type SalesRevenuePreset =
    | "this_month"
    | "last_month"
    | "this_quarter"
    | "this_year"
    | "custom";

export interface SalesRevenueBucket {
    /** `YYYY-MM` para granularity=month, `YYYY` para granularity=year. */
    period: string;
    revenue: number;
    sales_count: number;
}

export interface SalesRevenueRange {
    from: string;             // Y-m-d
    to: string;               // Y-m-d
    granularity: SalesRevenueGranularity;
}

export interface SalesRevenue {
    total_revenue: number;
    sales_count: number;
    /** Vendas no período sem `sale_price` registado — reportar honestamente. */
    sales_without_value_count: number;
    buckets: SalesRevenueBucket[];
    range: SalesRevenueRange;
}

// ────────────────────────────────────────────────────────────────────────────
// Ficha de impressão A4 (2026-06-27) — payload do endpoint dedicado.
// FE flatten-a os grupos de `vehicle_attributes` para renderização; a shape
// aqui reflecte o que o backend devolve directamente do accessor
// `Car::vehicle_attributes` (normalizado via `VehicleAttribute::normalizeShape`).
// ────────────────────────────────────────────────────────────────────────────

export interface PrintSheetCompany {
    trade_name: string | null;
    fiscal_name: string | null;
    logo_path: string | null;
    address: string | null;
    postal_code: string | null;
    phone: string | null;
    mobile: string | null;
    email: string | null;
    website: string | null;
}

export interface PrintSheetHeadlineStats {
    seats: number | null;
    sleeps: number | null;
    length_m: number | null;
    gross_weight_kg: number | null;
    mileage_km: number | null;
}

export interface CarPrintSheet {
    company: PrintSheetCompany;
    vehicle_type: 'car' | 'motorcycle' | 'motorhome' | 'caravan' | null;
    brand: { id: number; name: string } | null;
    model: { id: number; name: string } | null;
    category: { id: number; name: string; slug: string } | null;
    version: string | null;
    engine_brand: string | null;
    license_plate: string | null;
    vin: string | null;
    registration: { year: number | null; month: number | null };
    price: {
        gross: number | null;
        promo_gross: number | null;
        hide_price_online: boolean;
    };
    headline_stats: PrintSheetHeadlineStats;
    specs: {
        fuel_type: string | null;
        transmission: string | null;
        power_hp: number | null;
        engine_capacity_cc: number | null;
        doors: number | null;
        segment: string | null;
        exterior_color: string | null;
        is_metallic: boolean;
        interior_color: string | null;
    };
    state: {
        condition: string | null;
        origin: string | null;
        has_spare_key: boolean;
        has_manuals: boolean;
        is_trade_in: boolean;
    };
    warranty_months: number | null;
    /** Shape completo normalizado (habitation_basics, energy_climate, exterior, security, chassis_structure, interior_furniture, living_room, beds…). */
    vehicle_attributes: Record<string, any>;
    extras: Array<{ group: string; items: string[] }>;
}
