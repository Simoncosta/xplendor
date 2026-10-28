// DMS — Tickets de suporte (lado stand).
// "site_change" é o tipo PAGO (Alteração ao site) — dispara a camada de orçamento.
// "onboarding" é de SISTEMA: o ticket de arranque de um orçamento aceite (nunca escolhido pelo cliente).
export type SupportTicketType = "idea" | "improvement" | "bug" | "suggestion" | "site_change" | "onboarding";
export type SupportTicketStatus = "open" | "in_review" | "resolved" | "closed";

// Fluxo de orçamento — só em tickets site_change (null nos grátis). Só a aprovação e o trabalho:
// a fatura e o pagamento vêm à parte (payment_status). "paid" é o valor antigo, já migrado.
export type QuoteStatus =
    | "awaiting_quote"
    | "quoted"
    | "approved"
    | "paid"
    | "completed"
    | "rejected";

export interface ISupportTicketMessage {
    id: number;
    /** null numa mensagem apagada (a cliente vê só a marca; a equipa continua a ver o texto). */
    body: string | null;
    is_staff: boolean;
    author_name?: string | null;
    edited_at?: string | null;
    deleted?: boolean;
    deleted_at?: string | null;
    created_at?: string;
    /** Só a equipa XPLENDOR: escrita na sessão como cliente, se a pode editar e o texto antes de cada edição. */
    team?: {
        impersonated: boolean;
        can_edit: boolean;
        versions: { body: string; edited_by_name: string | null; created_at: string | null }[];
    };
}

/** A fatura e o pagamento, à parte do trabalho: por faturar, fatura emitida (por pagar) ou pago. */
export type PaymentStatus = "pending" | "invoiced" | "paid";

// Uma mudança de tipo (só no lado admin): tipo anterior e novo e o orçamento que tinha.
export interface ISupportTicketTypeChange {
    id: number;
    from_type: SupportTicketType;
    to_type: SupportTicketType;
    previous_quote_status: QuoteStatus | null;
    previous_quoted_amount: number | null;
    previous_estimated_hours: number | null;
    changed_by_name: string | null;
    created_at: string | null;
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
    /** O valor, as horas e a fatura do orçamento só vêm a quem vê a faturação da XPLENDOR (o Administrador). */
    billing_visible?: boolean;
    payment_status?: PaymentStatus | null;
    invoiced_at?: string | null;
    paid_at?: string | null;
    /** Só a equipa XPLENDOR: quem escreveu de facto, a fatura e o pagamento, o apagado, e o que pode fazer. */
    team?: {
        impersonator_name: string | null;
        invoice_by_name: string | null;
        paid_by_name: string | null;
        deleted_at: string | null;
        deleted_by_name: string | null;
        can: { delete: boolean; delete_blocked_by: string | null; remove_screenshot: boolean; change_invoice: boolean } | null;
    };
    type_changes?: ISupportTicketTypeChange[]; // só no lado admin
    // Lista de tarefas (ticket de arranque).
    tasks?: ISupportTicketTask[];
    // Ticket de arranque: a empresa a configurar pelo link (null: escolhe-se no ecrã).
    setup_company_id?: number | null;
}

export interface ISupportTicketTask {
    id: number;
    group_label: string | null;
    title: string;
    // Chave fixa (social_access, meta_ads_access, ga4_access): marca-se sozinha pelo link de configuração.
    task_key?: string | null;
    done: boolean;
    done_at: string | null;
    done_by_name: string | null;
}

// Rótulos + ícones por tipo (pt-PT).
export const TICKET_TYPE_META: Record<SupportTicketType, { label: string; icon: string }> = {
    idea:        { label: "Ideia",           icon: "ri-lightbulb-line" },
    improvement: { label: "Melhoria",        icon: "ri-magic-line" },
    bug:         { label: "Bug",             icon: "ri-bug-line" },
    suggestion:  { label: "Sugestão",        icon: "ri-chat-smile-2-line" },
    site_change: { label: "Alteração ao site", icon: "ri-tools-line" },
    onboarding:  { label: "Arranque",        icon: "ri-rocket-2-line" },
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
    quoted:         { label: "Orçado, a aguardar aprovação", color: "warning" },
    approved:       { label: "Aprovado, em execução",        color: "info" },
    paid:           { label: "Aprovado, em execução",        color: "info" },
    completed:      { label: "Concluído",                   color: "success" },
    rejected:       { label: "Rejeitado",                   color: "danger" },
};

// A taxa é confirmada pelo backend (hourly_rate no ticket); este é o fallback
// de exibição no formulário de criação (aviso "serviço pago").
export const SITE_CHANGE_HOURLY_RATE = 25;

/** Formata euros pt-PT (ex.: 50 → "50,00 €"). */
export const formatEuro = (v: number): string =>
    new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR" }).format(v);

// A fatura e o pagamento (só com o orçamento aprovado), para o root e o Administrador da cliente.
export const PAYMENT_STATUS_META: Record<PaymentStatus, { label: string; color: string; icon: string }> = {
    pending:  { label: "Por faturar",               color: "secondary", icon: "ri-file-list-3-line" },
    invoiced: { label: "Fatura emitida, por pagar",  color: "warning",   icon: "ri-file-pdf-line" },
    paid:     { label: "Pago",                       color: "success",   icon: "ri-bank-card-line" },
};

/** Pipeline de orçamentos-em-tickets (site_change): por estado + total (valores SEM IVA). */
export interface IQuoteBucket { count: number; amount: number; hours: number; }
export interface ITicketQuotePipeline {
    by_status: Record<QuoteStatus, IQuoteBucket>;
    /** A fatura e o pagamento dos aprovados: por faturar, faturado por pagar e pago. */
    payment?: Record<"to_invoice" | "invoiced_unpaid" | "paid", { count: number; amount: number }>;
    total: IQuoteBucket;
}
