/** XPLENDOR — Fatia 3: engenharia de menu. */
export type MenuClass = "estrela" | "cavalo" | "quebra_cabecas" | "cao";
export type Confidence = "confirmado" | "parcial" | "sem_custo";
export type PriceMode = "media" | "ultimo";

export type MissingPart = { component_id: string; code: string | null; description: string; reason: string; unit?: string | null };
export type Reason = { code: string; text: string };

export type MenuItem = {
    product_id: string; code: string | null; description: string; family: string; qty: number; revenue_eur: number | null; vat_pct: number | null;
    price_eur: number | null; cost_eur: number | null; margin_eur: number | null; margin_total_eur: number | null; food_cost_pct: number | null;
    share_pct: number | null; revenue_share_pct: number | null; confidence: Confidence; class: MenuClass | null; popular: boolean | null;
    high_margin: boolean | null; fragile: boolean; excluded: string | null; excluded_reason: string | null; pareto: number; play: string | null;
    changed: boolean; missing: MissingPart[]; fragile_reasons: Reason[];
    versions?: { bom_id: string; valid_from: string | null; qty: number; cost: number | null; complete: boolean }[];
    warnings?: { type: string; text: string; line?: string | null }[];
};

export type FamilyStats = {
    family: string; items: number; costed: number; qty: number; revenue: number; popularity_threshold_pct: number | null; margin_line: number | null;
    price_cv_pct: number | null; coverage_pct: number | null; fragile: Reason[];
};

export type FoodCostMonth = {
    month: string; revenue_eur: number | null; complete: boolean; comida_eur: number | null; bebida_eur: number | null; total_eur: number | null;
    comida_pct: number | null; bebida_pct: number | null; total_pct: number | null; avg3_pct: number | null;
};

export type WorkAction = { type: "criar_ficha" | "confirmar_fator" | "ver_auditoria" | "ver_ficha"; product_id: string; label: string; text: string; search?: string };

export type MenuSnapshot = {
    id: number; calculated_at: string | null; period_from: string; period_to: string; location_id: number | null; price_mode: PriceMode;
    period: { from: string; to: string; days: number };
    summary: {
        revenue_eur: number | null; articles: number; with_bom: number; included: number; excluded: Record<string, number>;
        coverage: { confirmado_pct: number | null; parcial_pct: number | null; sem_custo_pct: number | null; with_cost_pct: number | null };
        fichas_to_target: number; coverage_target_pct: number;
        food_cost_theoretical: { pct: number | null; coverage_pct: number | null };
        food_cost_purchases: { month: string; avg3_pct: number | null; total_pct: number | null } | null;
        compare_allowed: boolean;
        classes: Record<MenuClass, number>;
        pareto: { articles: number; top_articles: number; top_articles_pct: number | null; revenue_pct: number };
        top5: { product_id: string; description: string; family: string; revenue_eur: number | null; cost_eur: number | null; class: MenuClass | null; confidence: Confidence; excluded: string | null }[];
    };
    families: FamilyStats[];
    food_cost: { months: FoodCostMonth[]; latest: { month: string; avg3_pct: number | null; total_pct: number | null } | null; families: Record<string, string[]>; excluded_families: string[] };
    pareto: { rank: number; product_id: string; name: string; revenue_eur: number | null; cum_pct: number; confidence: Confidence; class: MenuClass | null }[];
    worklist: { product_id: string; description: string; family: string; confidence: Confidence; revenue_eur: number | null; revenue_share_pct: number | null; cost_eur: number | null; actions: WorkAction[] }[];
};

export type MenuSettings = { include_zero_price: boolean; include_low_volume: boolean; include_retired: boolean; include_uber_eats: boolean };

export type MenuResponse = {
    locations: { id: number; name: string }[];
    running: { status: string; at: string; error?: string } | null;
    settings: MenuSettings;
    exclusions: { product_id: string; reason: string; created_at: string }[];
    can_manage: boolean;
    labels: { classes: Record<MenuClass, string>; exclusions: Record<string, string>; plays: Record<MenuClass, string> };
    snapshot: MenuSnapshot | null;
    items?: MenuItem[];
    financial?: { visible: boolean; note: string | null };
};
