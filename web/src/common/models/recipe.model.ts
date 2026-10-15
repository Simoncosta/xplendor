/**
 * Fatia 1 — fichas técnicas (só leitura). Os campos *_eur vêm a null para quem não vê as Finanças
 * (financial.visible false): o ecrã mostra-os desfocados (HiddenMoney).
 */
export type RecipeState = "com_ficha" | "sem_ficha" | "sem_custo" | "loja_sem_ficha";

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
    states: { id: RecipeState; label: string }[];
    sort: { sort: string; dir: "asc" | "desc" };
    financial: RecipeFinancial;
};

export type RecipeWarning = { type: string; text: string; line?: string };

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
    cost_status: "ok" | "antigo" | "sem_custo" | "parcial" | "ciclo" | "fundo";
    unit_cost_last_eur: number | null;
    unit_cost_avg_eur: number | null;
    last_purchase: string | null;
    pingwin_unit_cost_eur: number | null;
    pingwin_cost_eur: number | null;
    line_cost_eur: number | null;
    diff_pct: number | null;
    warnings: RecipeWarning[];
    sub: {
        product_id: string;
        version: { bom_id: string; key: string; valid_from: string | null };
        cost_eur: number | null;
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
        qty_90d: number;
        sales_eur: number | null;
        price_eur: number | null;
        list_price_eur: number | null;
        list_price_wo_tax_eur: number | null;
        bom_synced_at: string | null;
    };
    state: RecipeState;
    missing_stores: string[];
    versions: RecipeVersion[];
    used_in: { product_id: string; code: string | null; description: string; valid_from: string | null; in_force: boolean; qnt: number; unit: string | null }[];
    warehouses: { id: string; name: string }[];
    window: { days: number; since: string; until: string };
    financial: RecipeFinancial;
};
