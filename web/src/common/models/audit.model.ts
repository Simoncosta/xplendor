/** XPLENDOR — Auditoria (Fatia 2): o motor comum da plataforma (regras, corridas, achados). */

export type AuditSeverity = "alta" | "media" | "baixa" | "info";
export type AuditState = "aberto" | "aceite" | "ignorado" | "corrigido" | "resolvido";
export type AuditArea = "restauracao" | "automovel" | "tracking";
export type AuditOrigin = "central" | "artigos" | "familias" | "faturas" | "fornecedores" | "fichas" | "stock" | "armazens" | "transferencias" | "menu";

export type AuditFixAction =
    | { type: "confirmar_fator"; product_id: string; unit_key: string; description: string }
    | { type: "escolher_principal"; group_key: string; label: string; members: string[] };

export type AuditFinding = {
    id: number;
    rule: string;
    rule_title: string;
    area: AuditArea;
    area_label: string;
    severity: AuditSeverity;
    entity_type: string;
    entity_id: string;
    entity_label: string | null;
    title: string;
    impact_cents: number | null;
    /** O impacto "a confirmar": mostra-se, mas não entra no Pareto. */
    impact_pending_cents: number | null;
    weight_cents: number | null;
    pareto_pct: number | null;
    state: AuditState;
    state_by: string | null;
    state_at: string | null;
    state_reason: string | null;
    note: string | null;
    first_seen_at: string | null;
    last_seen_at: string | null;
    resolved_at: string | null;
    fix: { url: string | null; label: string | null; action: AuditFixAction | null };
};

export type AuditEvidenceFormat = "eur" | "eur4" | "cents" | "pct" | "qty" | "date";
export type AuditEvidence = {
    facts?: { label: string; value: string | number | null; format?: AuditEvidenceFormat; hidden?: boolean; tip?: string }[];
    table?: {
        title?: string;
        columns: { key: string; label: string; align?: "start" | "end"; format?: AuditEvidenceFormat }[];
        rows: Record<string, any>[];
        hidden_columns?: string[];
    } | null;
    note?: string;
    group?: { key: string; main: string | null };
};

export type AuditFindingEvent = { from: AuditState | null; to: AuditState; user: string | null; run_id: number | null; reason: string | null; at: string | null };

export type AuditFindingDetail = AuditFinding & {
    /** Os quatro blocos do painel: O que vimos, Porque importa, O que fazer e Quanto está em jogo. */
    seen: string | null;
    why: string | null;
    suggestion: string | null;
    stake: string | null;
    evidence: AuditEvidence;
    rule_description: string | null;
    refs: { type: string; id: string }[];
    events: AuditFindingEvent[];
    can_edit: boolean;
};

export type AuditRunStep = {
    rule: string; title: string; status: "pending" | "running" | "ok" | "failed" | "skipped";
    found?: number; new?: number; resolved?: number; open?: number; ms?: number; error?: string; note?: string;
};

export type AuditRun = {
    id: number; area: AuditArea; origin: string; rules: string[]; status: "queued" | "running" | "done" | "failed";
    steps: AuditRunStep[]; new_count: number; resolved_count: number; open_count: number; error: string | null;
    requested_by: string | null; created_at: string | null; started_at: string | null; finished_at: string | null;
};

export type AuditRuleInfo = { code: string; title: string; description: string; severity: AuditSeverity; screens: string[]; applies: boolean };
export type AuditMeta = {
    areas: { key: AuditArea; label: string; can_edit: boolean; rules: AuditRuleInfo[] }[];
    last_runs: AuditRun[];
    financial: { visible: boolean; note: string | null };
};

export type AuditListResponse = {
    findings: { data: AuditFinding[]; current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };
    summary: {
        total: number; by_severity: Partial<Record<AuditSeverity, number>>; with_impact: number;
        pareto_n80: number | null; pareto_total_cents: number | null; pending_total_cents: number | null;
    };
    financial: { visible: boolean; note: string | null };
};

export type AuditGroup = {
    rule: string; rule_title: string; rule_description: string | null; area: AuditArea; area_label: string; count: number;
    impact_cents: number | null; pending_cents: number | null; weight_cents: number | null; severity: AuditSeverity;
};

export type AuditCounts = Record<string, { open: number; severity: AuditSeverity }>;
