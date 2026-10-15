/**
 * Fatia 1 — fichas técnicas (só leitura). Os campos *_eur vêm a null para quem não vê as Finanças
 * (financial.visible false): o ecrã mostra-os desfocados (HiddenMoney).
 */
export type RecipeState = "com_ficha" | "sem_ficha" | "sem_custo" | "loja_sem_ficha";
/** Fatia 1b: marcas que se juntam ao estado. */
export type RecipeFlag = "rendimento_por_confirmar" | "fator_por_confirmar";

/** O rendimento de uma versão da ficha de um semi-produto (só na XPLENDOR). */
export type RecipeYield = {
    confirmed: boolean;
    source: "confirmado" | "sugerido" | null;
    quantity: number | null;
    unit_id: string | null;
    unit_label: string;
    reason: string | null;
    confirmed_by: string | null;
    confirmed_at: string | null;
    suggestions: { quantity: number; reason: string }[];
    sums: { label: string; quantity: number }[];
    looks_like_batch: boolean;
};

export type RecipeCostEffect = { unit_cost_eur: number | null; line_cost_eur: number | null; diff_pct: number | null };

/** A unidade de compra de um componente e as sugestões de fator (só na XPLENDOR). */
export type RecipeConversion = {
    unit_key: string;
    unit_label: string;
    base_label: string;
    price_per_unit_eur: number | null;
    raw_factor: number | null;
    current_factor: number | null;
    current: RecipeCostEffect | null;
    suppliers: { nif: string; name: string | null }[];
    confirmed: { factor: number; supplier_nif: string | null; confirmed_by: string | null; confirmed_at: string | null } | null;
    suggestions: ({ factor: number; reason: string } & RecipeCostEffect)[];
};

export type RecipeRow = {
    product_id: string;
    catalog_item_id: number | null;
    code: string | null;
    description: string;
    family: string | null;
    qty: number;
    sales_eur: number | null;
    pareto: number;
    share_pct: number | null;
    cum_pct: number | null;
    state: RecipeState;
    state_label: string;
    flags: RecipeFlag[];
    flag_labels: string[];
    low_confidence: boolean;
    yield: { quantity: number | null; unit_label: string; confirmed: boolean } | null;
    cost_per_unit_eur: number | null;
    missing_stores: string[];
    components: number | null;
    cost_eur: number | null;
    cost_complete: boolean | null;
    pingwin_cost_eur: number | null;
    price_eur: number | null;
    list_price_eur: number | null;
    food_cost_pct: number | null;
    margin_eur: number | null;
    valid_from: string | null;
    stores: string[];
    warnings: number;
};

export type RecipeFinancial = { visible: boolean; note: string | null };

export type RecipeListResponse = {
    recipes: {
        data: RecipeRow[];
        current_page: number;
        last_page: number;
        total: number;
        per_page: number;
        from: number | null;
        to: number | null;
    };
    summary: {
        coverage_pct: number | null;
        top_without_bom: number;
        top_share: number;
        articles: number;
        with_bom: number;
        window_days: number;
        since: string;
        until: string;
        last_synced_at: string | null;
    };
    families: string[];
    locations: { id: number; name: string }[];
    states: { id: RecipeState | RecipeFlag; label: string }[];
    sort: { sort: string; dir: "asc" | "desc" };
    financial: RecipeFinancial;
};

export type RecipeWarning = { type: string; text: string; line?: string; level?: "info" };

export type RecipeLine = {
    key: string;
    component_id: string;
    code: string | null;
    description: string | null;
    qnt: number;
    unit: string | null;
    qnt_base: number;
    waste_pct: number;
    recovery_pct: number;
    is_bom: boolean;
    inactive: boolean;
    cost_source: "compras" | "ficha";
    cost_status: "ok" | "antigo" | "sem_custo" | "parcial" | "ciclo" | "fundo" | "zero";
    unit_cost_last_eur: number | null;
    unit_cost_avg_eur: number | null;
    last_purchase: string | null;
    pingwin_unit_cost_eur: number | null;
    pingwin_cost_eur: number | null;
    line_cost_eur: number | null;
    diff_pct: number | null;
    pingwin_by_warehouse: { warehouse_id: string; unit_cost_eur: number | null; cost_eur: number | null }[];
    qty_source: "raw" | "confirmado" | "misto" | null;
    low_confidence: boolean;
    needs_factor: boolean;
    conversion: RecipeConversion | null;
    similar: { product_id: string; code: string | null; description: string; score: number; last_eur: number | null; avg_eur: number | null; last_purchase: string | null } | null;
    warnings: RecipeWarning[];
    sub: {
        product_id: string;
        version: { bom_id: string; key: string; valid_from: string | null };
        cost_eur: number | null;
        cost_per_unit_eur: number | null;
        yield: RecipeYield;
        cost_complete: boolean;
        pingwin_cost_eur: number | null;
        lines: RecipeLine[];
    } | null;
};

export type RecipeStore = { store_id: string; code: string | null; name: string | null; active: boolean; in_force: boolean };

export type RecipeVersion = {
    bom_id: string;
    key: string;
    valid_from: string | null;
    deleted: boolean;
    line_count: number;
    in_force: boolean;
    stores: RecipeStore[];
    lines: RecipeLine[];
    cost_eur: number | null;
    cost_complete: boolean;
    pingwin_cost_eur: number | null;
    diff_pct: number | null;
    food_cost_pct: number | null;
    margin_eur: number | null;
    yield: RecipeYield | null;
    cost_per_unit_eur: number | null;
    low_confidence: boolean;
    needs_factor: boolean;
    warnings: RecipeWarning[];
};

export type RecipeDetail = {
    article: {
        product_id: string;
        catalog_item_id: number | null;
        code: string | null;
        description: string | null;
        family: string | null;
        is_active: boolean | null;
        forsale: boolean | null;
        forproduction: boolean | null;
        is_semi: boolean;
        qty_90d: number;
        sales_eur: number | null;
        price_eur: number | null;
        list_price_eur: number | null;
        list_price_wo_tax_eur: number | null;
        bom_synced_at: string | null;
    };
    state: RecipeState;
    flags: RecipeFlag[];
    missing_stores: string[];
    versions: RecipeVersion[];
    used_in: { product_id: string; code: string | null; description: string; valid_from: string | null; in_force: boolean; qnt: number; unit: string | null }[];
    warehouses: { id: string; name: string }[];
    window: { days: number; since: string; until: string };
    financial: RecipeFinancial;
};

// ── Fatia 4: editor (criar, editar e apagar na PingWin) ───────────────────────

export type RecipeUnitOption = { id: string; label: string; short: string | null; factor: number; base: boolean; suspicious: boolean };

export type RecipeEditorLine = {
    key: string; component_id: string; description: string | null; unit_id: string; qnt: number; waste: number; recovery: number;
    units: RecipeUnitOption[];
};

export type RecipeEditorVersion = {
    id: string; key: string; valid_from: string | null; deleted: boolean;
    stores: { store_id: string; name: string | null; active: boolean }[];
    lines: RecipeEditorLine[];
};

export type RecipeWriteMode = "criar" | "nova_versao" | "corrigir";

/** O rascunho (fica na XPLENDOR até "Gravar na PingWin"). */
export type RecipeDraftLine = {
    uid: string; key: string | null; component_id: string; description: string; code: string | null;
    unit_id: string; units: RecipeUnitOption[]; qnt: string; waste: string; recovery: string; advanced: boolean;
};
export type RecipeDraftPayload = {
    mode: RecipeWriteMode; bom_id: string | null; valid_from: string; stores: Record<string, boolean>; lines: RecipeDraftLine[];
};

export type RecipeWriteStep = { step: string; n?: number; m?: number; at?: string; new?: boolean; delete?: string; version?: string };
export type RecipeWriteInfo = {
    id: number; operation: "criar" | "nova_versao" | "corrigir" | "apagar_versao" | "apagar_ficha";
    status: "queued" | "running" | "ok" | "mismatch" | "conflict" | "failed";
    bom_id: string | null; valid_from: string | null;
    progress: { steps: RecipeWriteStep[]; current: string | null; n?: number; m?: number } | null;
    result: { saved?: boolean; refs?: Record<string, { id: string; key: string }>; diff?: string[] } | null;
    error: string | null; user: string | null; created_at: string | null; started_at: string | null; finished_at: string | null; ops: number;
};

export type RecipeEditorContext = {
    product: { product_id: string; code: string | null; description: string | null };
    versions: RecipeEditorVersion[];
    signature: string;
    in_force: string | null;
    stores: { store_id: string; name: string }[];
    today: string;
    draft: { payload: RecipeDraftPayload; base_signature: string | null; updated_at: string | null } | null;
    active_write: RecipeWriteInfo | null;
    write_allowed: boolean;
};

export type RecipeComponentHit = {
    component_id: string; code: string | null; description: string; family: string | null; unit: string | null;
    inactive: boolean; is_semi: boolean; approximate: boolean;
    last_purchase: { date: string | null; supplier: string | null } | null;
    units: RecipeUnitOption[]; default_unit_id: string | null;
};

export type RecipeStoreEffect = {
    store_id: string; name: string; from: string; before: string | null; before_key: string | null;
    after: string | null; after_key: string | null; changes: boolean; loses_bom: boolean; without_bom: boolean;
};

export type RecipePreview = {
    lines: (RecipeLine & { units: RecipeUnitOption[]; input_key: string | null; yield: RecipeYield | null })[];
    cost_eur: number | null; cost_complete: boolean; low_confidence: boolean;
    price_eur: number | null; food_cost_pct: number | null; margin_eur: number | null;
    store_effects: RecipeStoreEffect[];
    financial: RecipeFinancial;
};

export type RecipeHistoryRow = RecipeWriteInfo & {
    before: { versions: RecipeEditorVersion[] } | null;
    after: { valid_from?: string | null; stores?: Record<string, boolean>; lines?: { component_id: string; unit_id: string; qnt: number; waste: number; recovery: number }[]; deleted_versions?: string[] } | null;
    names: { components: Record<string, string>; units: Record<string, string> };
};
