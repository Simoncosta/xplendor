/** XPLENDOR — Auditoria v2: o Guião de unidades (fila, artigo, proposta, o que muda, PingWin). */

/** por_rever: estava decidido, mas a unidade base mudou no PingWin depois (volta à fila). */
export type UnitProfileState = "por_identificar" | "sugerido" | "confirmado" | "aplicado_no_pingwin" | "nao_se_aplica" | "por_rever";
export type UnitBase = "kg" | "L" | "un";

export type UnitVerdictKey = "mudar" | "ajuda" | "certo";
export type UnitVerdict = { key: UnitVerdictKey; title: string; text: string; tone: string };
export type UnitSeenSource = "faturas" | "fichas" | "contagens" | "pingwin";

export type UnitQueueItem = {
    verdict: UnitVerdictKey; verdict_text: string;
    product_id: string; label: string; family: string; spend_90d_eur: number | null; state: UnitProfileState; por_identificar: boolean;
    confidence: number; findings: number; impact_cents: number | null; pending_cents: number | null; assigned: boolean; skipped: boolean;
    /** A unidade base ou de compra mudou há pouco num artigo com histórico de compras. */
    unit_changed?: boolean;
    /** O código do artigo no PingWin (na pesquisa). */
    code?: string | null;
};
/** O filtro de estado do Guião (na URL: ?estado=). */
export type UnitGuideEstado = "todos" | "por_confirmar" | "por_rever" | "confirmados" | "unidade_mudou";
/** Uma mudança de unidade num artigo com histórico: as linhas antigas ficam na unidade anterior. */
export type ArticleUnitChange = { field: "base" | "purchase"; from: string | null; to: string | null; changed_at: string; history_lines: number; warning: string };
export type UnitQueue = { items: UnitQueueItem[]; total: number; counts: { por_identificar: number; administrador: number; mudar?: number; ajuda?: number; certo?: number }; financial?: { visible: boolean } };

export type UnitPackaging = { family: string; each: number; count: number; quantity: number; container: string | null; text: string; label: string; dose: string | null; source?: string };

export type UnitQuestion = {
    id: string; kind: "choice" | "number" | "quantity"; text: string;
    options: { value: string; label: string }[]; units?: [string, string][]; answer: string | null; default?: string | null;
};

export type UnitPurchaseUnit = {
    supplier: string; nif: string; unit_key: string; unit_id: string; unit_label: string; proposed_unit: string; factor: number | null;
    why: string | null; answer_key: string; pingwin_factor: number | null; label: string; confirmed: boolean;
};

export type UnitProposal = {
    identified: boolean; type: string | null; type_label: string; base: UnitBase | null; base_name: string; base_label: string | null; dose: string | null;
    current_base: UnitBase | null; current_base_label: string | null; old_unit_is_pack: boolean; recipes_use_pack: boolean;
    conflicts: string[]; purchase_units: UnitPurchaseUnit[]; count_unit: { label: string; factor: number | null; approximate: boolean } | null;
    deactivate: string[]; base_change: boolean; base_change_warning: { title: string; text: string; paths: string[] } | null;
    questions: UnitQuestion[]; learned: string | null; complete: boolean;
};

export type UnitImpactRecipe = {
    product_id: string; label: string; bom_id: string; before: string; after: string | null; after_qnt: number | null;
    cost_before_eur: number | null; cost_after_eur: number | null; sold_qty_90d: number;
};
export type UnitImpact = {
    recipes: UnitImpactRecipe[]; stock: { warehouse: string; before: string; after: string | null; pcm_before_eur: number | null; pcm_after_eur: number | null }[];
    invoices: string[]; count: string | null; price_before_eur: number | null; price_after_eur: number | null;
    recipes_monthly_delta_eur: number; monthly_eur: number | null; recipes_note: string | null;
};

export type UnitApplyStep = { op: "create" | "set_purchase" | "void"; data: Record<string, any>; label: string; status: string | null; write_id: number | null; unit_id: string | null; error: string | null };

export type UnitEvidencePurchase = {
    supplier: string; unit_label: string; qnt: number; total_eur: number | null; price_per_unit: number | null; descriptions: string[];
    packaging: UnitPackaging | null; first_date: string; last_date: string; document_url: string | null; last_document: string | null; pingwin_factor: number | null;
};

export type UnitGuideView = {
    article: { product_id: string; code: string | null; label: string; description: string; family: string; base_unit: string | null; base_family: string | null; url: string };
    unit_changes?: ArticleUnitChange[];
    profile: { state: UnitProfileState; base_confirmed: string | null; type: string | null; count_unit: string | null; confirmed_by: string | null; confirmed_at: string | null;
        reason: string | null; info_text: string | null; has_photo: boolean; photo_url: string | null; skipped: boolean } | null;
    answers: Record<string, string | null>;
    por_identificar: boolean;
    confidence: number;
    /** A conclusão antes das evidências. */
    verdict: UnitVerdict;
    confidence_label: { label: "Alta" | "Média" | "Baixa"; score: number; why: string };
    changes: string[];
    /** "O que vimos", cada linha com a fonte. */
    seen: { source: UnitSeenSource; text: string }[];
    evidence: { purchases: UnitEvidencePurchase[]; packaging: UnitPackaging | null; recipes: any[]; contradictions: string[]; spend_90d_eur: number | null };
    proposal: UnitProposal;
    impact: UnitImpact;
    findings: { id: number; rule: string; title: string; severity: string; state: string; impact_cents: number | null; impact_pending_cents: number | null; assigned_to: string | null }[];
    ai: { available: boolean; reason: string | null; suggestion: null | { type: string; type_label: string; packaging: string; base: UnitBase; base_name: string;
        purchase_units: { unit: string; factor: number }[]; confidence: number; reason: string; label: string; model?: string } };
    apply: { proven: boolean; guard: { mode: string; reason: string } | null; steps: UnitApplyStep[]; manual: string[]; can_apply: boolean };
    recipes_conversion: { ready: boolean; needs_base_change: boolean; items: { product_id: string; label: string; bom_id: string; component_id: string; unit_id: string; qnt: number; before: string; after: string }[] };
    history: { action: string; user: string | null; reason: string | null; at: string | null }[];
    financial?: { visible: boolean; note?: string };
};

export type UnitWrite = { id: number; op: string; status: "queued" | "running" | "ok" | "mismatch" | "refused" | "failed"; note: string | null; error: string | null;
    progress: { steps: { step: string; status?: string }[]; current: string | null } | []; unit_id: string | null };

export type RecipeSubstitute = {
    found: boolean; reason: string | null;
    dish?: { product_id: string; label: string; sold_qty_90d: number; bom_id: string };
    component?: { product_id: string; label: string; last_purchase: string | null; qnt: number; unit: string; line_key: string };
    candidates: { product_id: string; code: string | null; description: string; family: string; why: string; last_purchase: string | null; price_per_base_eur: number | null;
        same_unit_family: boolean; cost_before_eur: number | null; cost_after_eur: number | null; monthly_before_eur: number | null; monthly_after_eur: number | null;
        note: string | null; line: { key: string | null; replaces_key: string; component_id: string; description: string; unit_id: string; qnt: number; units: any[] } }[];
    financial?: { visible: boolean };
};

export type AuditEurosRow = { key: string | null; label: string; cents: number | null; count: number };
export type AuditEurosSummary = {
    open_cents: number | null;
    month: { cents: number | null; count: number; from: string; by_person: AuditEurosRow[]; by_rule: AuditEurosRow[] };
    total: { cents: number | null; count: number; by_person: AuditEurosRow[]; by_rule: AuditEurosRow[] };
    financial?: { visible: boolean };
};

/** Auditoria v2.1 (U9): a unidade global incoerente com o nome, a unidade certa e os artigos ("1 de N"). */
export type GlobalUnitView = {
    unit: { id: string; name: string; now: string; says: string };
    right: { name: string; unit_id: string | null; reuse: boolean; create: { description: string; parent_qnt: number } | null; status: string | null; write_id: number | null; error: string | null };
    articles: { product_id: string; label: string; purchases_cents: number | null; impact_cents: number | null; lines: number; uses: string[]; confirmed: boolean; done: boolean }[];
    impact_cents: number | null;
    guard: { mode: string; reason: string } | null;
    context_product_id: string | null;
    financial?: { visible: boolean };
};
