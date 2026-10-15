/** XPLENDOR — Fatia 7a: stock (espelho do PingWin), consumo teórico e armazéns. */

export type StockState = "negativo" | "abaixo_minimo" | "sem_consumo" | "cobertura_60";

export interface StockRow {
    product_id: string;
    code: string | null;
    description: string;
    family: string;
    family_group: string;
    unit: string | null;
    qnt: number;
    pcm_eur: number | null;
    pcu_eur: number | null;
    value_eur: number | null;
    min_stock: number | null;
    max_stock: number | null;
    per_day: number | null;
    coverage_days: number | null;
    low_confidence: boolean;
    valorization_date: string | null;
    states: StockState[];
    warehouses: number;
    pareto: number;
    share_pct: number;
    cum_pct: number | null;
}

export interface StockStoreWarehouse {
    store_id: string;
    store_name: string | null;
    location_id: number;
    warehouse_id: string | null;
    source: "xplendor" | "pingwin" | null;
    candidates: string[];
}

export interface StockWarehouseOption {
    id: string;
    code: string | null;
    name: string | null;
    stores: string[];
}

export interface StockListResponse {
    stock: { data: StockRow[]; current_page: number; last_page: number; total: number; per_page: number; from: number | null; to: number | null };
    summary: {
        articles: number;
        value_eur: number | null;
        top80: number;
        counts: Record<StockState, number>;
        window: { from: string | null; to: string | null; days: number };
        last_read_at: string | null;
        last_run: { finished_at: string | null; origin: string; products_read: number; products_failed: number } | null;
        stores: StockStoreWarehouse[];
    };
    families: string[];
    warehouses: StockWarehouseOption[];
    warehouse_id: string | null;
    states: { id: StockState; label: string }[];
    financial?: { visible: boolean; note: string | null };
}

export interface StockWrite {
    id: number;
    kind: "limites" | "armazem_criar" | "armazem_editar" | "armazem_apagar";
    status: "queued" | "running" | "ok" | "mismatch" | "conflict" | "failed";
    warehouse_id: string | null;
    product_id: string | null;
    payload: Record<string, any> | null;
    error: string | null;
    checks: Record<string, boolean> | null;
    after: Record<string, any> | null;
    before: Record<string, any> | null;
    user: string | null;
    created_at: string | null;
    finished_at: string | null;
}

export interface StockArticleWarehouse {
    warehouse_id: string;
    warehouse: string;
    code: string | null;
    stores: string[];
    unit: string | null;
    qnt: number;
    pcm_eur: number | null;
    pcu_eur: number | null;
    value_eur: number | null;
    min_stock: number | null;
    max_stock: number | null;
    disable_generic: boolean;
    qnt_pend_cli: number;
    qnt_pend_sup: number;
    valorization_date: string | null;
    per_day: number | null;
    coverage_days: number | null;
    states: StockState[];
    synced_at: string | null;
}

export interface StockArticleResponse {
    product: { id: string; code: string | null; description: string; family: string; forsale: boolean; forpurchase: boolean };
    stock_item: { stockconfig: string | null; movestock: boolean | null; stock_unit: string | null; last_read_at: string | null; last_error: string | null } | null;
    warehouses: StockArticleWarehouse[];
    total: { qnt: number; value_eur: number | null; per_day: number | null; coverage_days: number | null; unassigned_per_day: number | null };
    consumption: {
        window: { from: string | null; to: string | null; days: number };
        low_confidence: boolean;
        sources: { product_id: string; description: string; qty: number; via: "ficha" | "venda" }[];
        used_by: { product_id: string; description: string }[];
    };
    history: { date: string; qnt: number }[];
    writes: StockWrite[];
    write_guard: { mode: string; reason: string } | null;
    can_edit: boolean;
    financial?: { visible: boolean; note: string | null };
}

export interface WarehouseRow {
    id: string;
    code: string | null;
    description: string | null;
    deleted: boolean;
    inv_costmethod: number | null;
    exit_costmethod: number | null;
    inv_costmethod_label: string | null;
    exit_costmethod_label: string | null;
    stores: { store_id: string; code: string | null; name: string | null; served: boolean }[];
    default_for: (string | null)[];
    articles_with_stock: number;
    last_valorization: string | null;
    synced_at: string | null;
    write_guard: { mode: string; reason: string } | null;
}

export interface WarehousesResponse {
    warehouses: WarehouseRow[];
    stores: StockStoreWarehouse[];
    known_stores: { id: string; name: string }[];
    costmethods: { id: number; label: string }[];
    create_guard: { mode: string; reason: string } | null;
    allowed_names: string[];
    writes: StockWrite[];
    can_edit: boolean;
}
