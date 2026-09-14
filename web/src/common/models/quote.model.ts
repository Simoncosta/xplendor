// XPLENDOR — Orçamentos avulsos (gestão comercial, área /admin, só root).
// Transversais (não scoped por company). Base do futuro mini-CRM comercial.
export type QuoteStatus = "pending" | "approved" | "rejected";

export interface IQuote {
    id: number;
    company_id: number | null;   // ligação opcional a um stand (futuro); null hoje
    company_name?: string | null;
    client_name: string;
    client_contact: string | null;
    description: string;
    amount: number;
    status: QuoteStatus;
    notes: string | null;
    created_at?: string;
    updated_at?: string;
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
    pending:  { label: "Em validação", color: "warning" },
    approved: { label: "Aprovado",     color: "success" },
    rejected: { label: "Rejeitado",    color: "danger" },
};

export const QUOTE_STATUSES: QuoteStatus[] = ["pending", "approved", "rejected"];

/** Formata euros pt-PT (ex.: 1500 → "1500,00 €"). */
export const formatQuoteEuro = (v: number): string =>
    new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR" }).format(v);
