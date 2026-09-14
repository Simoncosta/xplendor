// DMS — Tickets de suporte (lado stand).
// "site_change" é o tipo PAGO (Alteração ao site) — dispara a camada de orçamento.
export type SupportTicketType = "idea" | "improvement" | "bug" | "suggestion" | "site_change";
export type SupportTicketStatus = "open" | "in_review" | "resolved" | "closed";

// Fluxo de orçamento — só em tickets site_change (null nos grátis).
export type QuoteStatus =
    | "awaiting_quote"
    | "quoted"
    | "approved"
    | "paid"
    | "completed"
    | "rejected";

export interface ISupportTicketMessage {
    id: number;
    body: string;
    is_staff: boolean;
    author_name?: string | null;
    created_at?: string;
}

export interface ISupportTicket {
    id: number;
    company_id: number;
    company_name?: string | null; // só no lado admin (várias empresas)
    type: SupportTicketType;
    title: string;
    description: string;
    status: SupportTicketStatus;
    screenshot_url: string | null;
    resolved_at?: string | null;
    author_name?: string | null;
    messages_count?: number;
    messages?: ISupportTicketMessage[];
    created_at?: string;
    updated_at?: string;
    // Camada de orçamento (site_change). null/ausente nos tipos grátis.
    quote_status?: QuoteStatus | null;
    estimated_hours?: number | null;
    quoted_amount?: number | null;
    invoice_url?: string | null;
    hourly_rate?: number | null;
}

// Rótulos + ícones por tipo (pt-PT).
export const TICKET_TYPE_META: Record<SupportTicketType, { label: string; icon: string }> = {
    idea:        { label: "Ideia",           icon: "ri-lightbulb-line" },
    improvement: { label: "Melhoria",        icon: "ri-magic-line" },
    bug:         { label: "Bug",             icon: "ri-bug-line" },
    suggestion:  { label: "Sugestão",        icon: "ri-chat-smile-2-line" },
    site_change: { label: "Alteração ao site", icon: "ri-tools-line" },
};

// Rótulos + cor (Bootstrap/Velzon) por estado.
export const TICKET_STATUS_META: Record<SupportTicketStatus, { label: string; color: string }> = {
    open:       { label: "Aberto",     color: "primary" },
    in_review:  { label: "Em análise", color: "warning" },
    resolved:   { label: "Resolvido",  color: "success" },
    closed:     { label: "Fechado",    color: "secondary" },
};

// Rótulos + cor por estado de ORÇAMENTO (só site_change).
export const QUOTE_STATUS_META: Record<QuoteStatus, { label: string; color: string }> = {
    awaiting_quote: { label: "A aguardar orçamento",        color: "secondary" },
    quoted:         { label: "Orçado — a aguardar aprovação", color: "warning" },
    approved:       { label: "Aprovado — a aguardar pagamento", color: "info" },
    paid:           { label: "Pago — em execução",          color: "primary" },
    completed:      { label: "Concluído",                   color: "success" },
    rejected:       { label: "Rejeitado",                   color: "danger" },
};

// A taxa é confirmada pelo backend (hourly_rate no ticket); este é o fallback
// de exibição no formulário de criação (aviso "serviço pago").
export const SITE_CHANGE_HOURLY_RATE = 25;

/** Formata euros pt-PT (ex.: 50 → "50,00 €"). */
export const formatEuro = (v: number): string =>
    new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR" }).format(v);
