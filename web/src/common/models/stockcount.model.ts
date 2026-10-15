/** XPLENDOR — Fatia 7b: contagens de stock, zonas, desperdício e movimentos. */

export type CountStatus = "em_contagem" | "em_revisao" | "a_enviar" | "enviada" | "falhou" | "anulada";
export type Guard = { mode: string; reason: string } | null;

export interface CountUnit { unit: string; factor: number; source: string }
export interface CountEntry { qty: number | string | null; unit: string; factor?: number }

export interface StockCountSummary {
    id: number;
    warehouse_id: string;
    warehouse: string | null;
    scope: "completa" | "artigos_a" | "zona";
    zone_id: number | null;
    moment: "antes_abertura" | "depois_fecho";
    inventory_date: string;
    blind: boolean;
    status: CountStatus;
    lines: number | null;
    counted: number | null;
    created_by: string | null;
    created_at: string | null;
    pingwin_doc_number: string | null;
    error_message: string | null;
    confirmed_at: string | null;
    send_block: Guard;
}

export interface CountLine {
    id: number;
    product_id: string;
    code: string | null;
    description: string;
    abc: "A" | "B" | "C" | null;
    zone_id: number | null;
    unit: string | null;
    units: CountUnit[];
    entries: CountEntry[];
    qty_base: number | null;
    counted_at: string | null;
    recount_requested: boolean;
    theoretical: number | null;
    confirm_status: "confirmado" | "diferente" | null;
    confirmed_qty: number | null;
}

export interface ReviewRow {
    id: number;
    product_id: string;
    code: string | null;
    description: string;
    abc: string | null;
    unit: string | null;
    counted: number | null;
    previous: number | null;
    theoretical: number | null;
    unit_cost_eur: number | null;
    diff: number | null;
    diff_eur: number | null;
    diff_pct: number | null;
    recount_suggested: boolean;
    recount_requested: boolean;
    pingwin: { theoretical: number | null; difference: number | null; price_difference_eur: number | null; confirmed_qty: number | null; status: string } | null;
}

export interface CountReview {
    rows: ReviewRow[];
    totals: { counted_eur: number | null; theoretical_eur: number | null; diff_eur: number | null; with_diff: number; not_counted: number; counted: number };
    theoretical_at: string | null;
    limits: { eur: number; pct: number; min_qty: number };
    send_block: Guard;
}

export interface StockCountDetail {
    count: StockCountSummary;
    lines: CountLine[];
    zones: { id: number; name: string; position: number }[];
    can_manage: boolean;
    shows_theoretical: boolean;
    review?: CountReview;
    financial?: { visible: boolean; note: string | null };
}

export interface Movement {
    store: string | null;
    doctype: string | null;
    docconfig_id: string | null;
    document: string | null;
    doc_date: string | null;
    entity_name: string | null;
    unit_description: string | null;
    qnt: number;
    docstatus: string | null;
    stk_signal: number;
    warehouse_id: string | null;
    warehouse: string | null;
    signed_qty: number;
    moves_stock: boolean;
}

export interface WasteRow {
    id: number;
    warehouse_id: string;
    product_id: string;
    code: string | null;
    description: string;
    is_dish: boolean;
    qty: number;
    unit: string | null;
    qty_base: number;
    reason: string;
    reason_label: string;
    note: string | null;
    value_eur: number | null;
    registered_by: string | null;
    registered_at: string | null;
    sent: boolean;
    batch: { id: number; status: string; document: string | null } | null;
    explosion: { product_id: string; code: string | null; description: string | null; qty: number }[] | null;
}
