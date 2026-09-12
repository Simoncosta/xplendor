// DMS — Tickets de suporte (lado stand).
export type SupportTicketType = "idea" | "improvement" | "bug" | "suggestion";
export type SupportTicketStatus = "open" | "in_review" | "resolved" | "closed";

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
}

// Rótulos + ícones por tipo (pt-PT).
export const TICKET_TYPE_META: Record<SupportTicketType, { label: string; icon: string }> = {
    idea:        { label: "Ideia",    icon: "ri-lightbulb-line" },
    improvement: { label: "Melhoria", icon: "ri-magic-line" },
    bug:         { label: "Bug",      icon: "ri-bug-line" },
    suggestion:  { label: "Sugestão", icon: "ri-chat-smile-2-line" },
};

// Rótulos + cor (Bootstrap/Velzon) por estado.
export const TICKET_STATUS_META: Record<SupportTicketStatus, { label: string; color: string }> = {
    open:       { label: "Aberto",     color: "primary" },
    in_review:  { label: "Em análise", color: "warning" },
    resolved:   { label: "Resolvido",  color: "success" },
    closed:     { label: "Fechado",    color: "secondary" },
};
