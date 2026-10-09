/** XPLENDOR — Conta Corrente de Fornecedor (S2). Valores em CÊNTIMOS; positivo = em dívida. */
export type SupplierCcStatus = "never" | "syncing" | "failed" | "not_reconciled" | "ok";

export interface SupplierCcRow {
    supplier_id: number;
    code: string | null;
    name: string | null;
    tax_number: string | null;
    pingwin_balance_cents: number | null;
    real_balance_cents: number;
    difference_cents: number;
    overdue_cents: number;
    open_docs: number;
    next_due_date: string | null;
    status: SupplierCcStatus;
    sync_status: string | null;
    last_error: string | null;
    synced_at: string | null;
}

export interface SupplierCcCards {
    real_balance_cents: number;
    overdue_cents: number;
    difference_cents: number;
    pingwin_balance_cents: number;
    problem_suppliers: number;
}

export interface SupplierCcOpenDoc {
    docheader_id: string;
    doc_date: string | null;
    document: string | null;
    doctype: string | null;
    docreference_number: string | null;
    docreference_date: string | null;
    due_date: string | null;
    due_is_doc_date: boolean;
    days_overdue: number;
    total_cents: number;
    ca_signal: number;
    open_cents: number;
    store_name: string | null;
}

export interface SupplierCcStatementLine {
    store: string | null;
    date: string | null;
    document: string | null;
    supplier_doc: string | null;
    debit_cents: number;
    credit_cents: number;
    balance_cents: number;
    due_date: string | null;
    auto_paid: boolean;
    /** Fatura auto-paga com valor: débito = crédito no próprio documento ("pago no ato"). */
    paid_on_issue: boolean;
    docheader_id: string;
}

export interface SupplierCcStatement {
    opening_cents: number;
    lines: SupplierCcStatementLine[];
    total_debit_cents: number;
    total_credit_cents: number;
    closing_cents: number;
}

export interface SupplierCcSyncState {
    sync_status: string | null;
    reconciled: boolean;
    last_error: string | null;
    synced_at: string | null;
}
