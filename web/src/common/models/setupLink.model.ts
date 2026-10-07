/** Link de configuração do cliente (F1c): estado do link e de cada passo, para a equipa e para a página pública. */
export type SetupStepKey = "social" | "meta_ads" | "ga4";
export type SetupStepStatus = "pending" | "done" | "error" | "not_approved";

export interface SetupStep {
    key: SetupStepKey;
    label: string;
    status: SetupStepStatus;
    done_at: string | null;
    error: string | null;
    detail: { facebook?: string[]; instagram?: string[]; account_id?: string; account_name?: string | null; property_id?: string } | null;
}

export interface SetupLink {
    id: number;
    state: "open" | "expired" | "revoked";
    url: string | null;
    expires_at: string | null;
    revoked_at: string | null;
    revoked_reason: "manual" | "replaced" | null;
    completed_at: string | null;
    created_at: string | null;
    created_by: string | null;
    open_count: number;
    last_opened_at: string | null;
    support_ticket_id: number | null;
    steps: SetupStep[];
}

export interface SetupLinkPayload {
    company_name: string;
    link: SetupLink | null;
    steps: { key: SetupStepKey; label: string }[];
    validity_days: number;
    meta_app_review_pending: boolean;
}

export const STEP_ICON: Record<SetupStepKey, string> = {
    social: "ri-facebook-circle-line",
    meta_ads: "ri-advertisement-line",
    ga4: "ri-bar-chart-box-line",
};

export const STEP_STATUS: Record<SetupStepStatus, { label: string; color: string }> = {
    pending: { label: "Por fazer", color: "light" },
    done: { label: "Feito", color: "success" },
    error: { label: "Com erro", color: "danger" },
    not_approved: { label: "A aguardar a Meta", color: "warning" },
};

/** Resumo do que ficou ligado num passo (para a equipa e para o cliente). */
export function stepDetailText(step: SetupStep): string | null {
    const d = step.detail;
    if (!d) return null;
    if (step.key === "social") {
        const parts = [...(d.facebook ?? []), ...(d.instagram ?? [])];
        return parts.length ? parts.join(", ") : null;
    }
    if (step.key === "meta_ads") return d.account_name ? `${d.account_name} (${d.account_id})` : d.account_id ?? null;
    return d.property_id ? `Propriedade ${d.property_id}` : null;
}

export const META_REVIEW_NOTICE = "Até a Meta aprovar a app, o passo do Facebook só funciona para contas de teste.";
