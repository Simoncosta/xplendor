// XPLENDOR — Orçamentos avulsos (gestão comercial, área /admin, só root).
// Podem ligar-se a uma empresa cadastrada (aparece no painel dela) ou ficar só
// com nome livre (cliente fora da plataforma, tipo Spacedrive).
export type QuoteStatus = "pending" | "approved" | "rejected" | "paid" | "completed";

export interface IQuote {
    id: number;
    company_id: number | null;   // preenchido quando ligado a uma empresa cadastrada
    company_name?: string | null;
    is_linked?: boolean;         // ligado a empresa (vs. nome livre)
    client_name: string;
    client_contact: string | null;
    description: string;
    amount: number;
    status: QuoteStatus;
    notes: string | null;
    created_at?: string;
    updated_at?: string;
}

/** Empresa para o campo creatable de cliente. */
export interface IQuoteCompanyOption {
    id: number;
    name: string;
}

export interface IQuoteSummary {
    pending: number;
    approved: number;
    rejected: number;
    total: number;
    approved_value: number;
}

// Rótulos + cor (Bootstrap/Velzon) por estado.
export const QUOTE_STATUS_META: Record<QuoteStatus, { label: string; color: string }> = {
    pending:   { label: "Em validação", color: "warning" },
    approved:  { label: "Aprovado",     color: "info" },
    rejected:  { label: "Rejeitado",    color: "danger" },
    paid:      { label: "Pago",         color: "primary" },
    completed: { label: "Concluído",    color: "success" },
};

export const QUOTE_STATUSES: QuoteStatus[] = ["pending", "approved", "rejected", "paid", "completed"];

/** Formata euros pt-PT (ex.: 1500 → "1500,00 €"). */
export const formatQuoteEuro = (v: number): string =>
    new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR" }).format(v);
