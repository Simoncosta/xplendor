/** Cobranças da XPLENDOR (a XPLENDOR não emite faturas: mostra a fatura em PDF e gere o estado). */

export type ChargeStatus = "open" | "payment_indicated" | "paid" | "cancelled";

export interface ClientCharge {
    id: number;
    company: string;
    description: string;
    amount: number;
    invoice_date: string | null;
    due_date: string;
    status: ChargeStatus;
    overdue: boolean;
    invoice_name: string | null;
    payment_indicated_at: string | null;
    paid_at: string | null;
    refuse_note: string | null;
    can_indicate_payment: boolean;
}

export interface AdminCharge extends ClientCharge {
    company_id: number;
    reminders_enabled: boolean;
    recipients: string[];
    cancel_reason: string | null;
    cancelled_at: string | null;
    payment_indicated_via: "link" | "app" | null;
    payment_note: string | null;
    has_proof: boolean;
    proof_name: string | null;
    refused_at: string | null;
    last_refuse_note: string | null;
    last_reminder_on: string | null;
    reminders_sent: number;
    open_count: number;
    last_opened_at: string | null;
    link: string | null;
    created_at: string | null;
}

export const CHARGE_STATUS_META: Record<ChargeStatus, { label: string; color: string }> = {
    open: { label: "Em aberto", color: "warning" },
    payment_indicated: { label: "Pagamento indicado", color: "info" },
    paid: { label: "Paga", color: "success" },
    cancelled: { label: "Anulada", color: "secondary" },
};

/** Os ficheiros que o comprovativo aceita (o servidor confirma o tipo real e o tamanho). */
export const PROOF_ACCEPT = "application/pdf,image/jpeg,image/png,image/webp";
export const PROOF_MAX_MB = 10;

export const euro = (n: number) => new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR" }).format(n);
export const dmy = (iso: string | null) => (iso ? new Date(iso.length === 10 ? `${iso}T12:00:00` : iso).toLocaleDateString("pt-PT") : "");
