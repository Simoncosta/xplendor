// XPLENDOR — Finanças › Mês: resultado e tesouraria num só ecrã (só leitura). Valores em cêntimos.

export type FinanceGroup = "comida" | "bebida" | "pessoal" | "fse" | "outros" | "fora" | "por_classificar";

export type PaymentState = "pago" | "pago_no_ato" | "pago_em_parte" | "por_pagar" | "vencido" | "credito" | "descontado" | "fora_cc";

export interface PaymentInfo {
    state: PaymentState;
    label: string;
    paid_on: string | null;
    paid_estimated: boolean;
    open_cents: number;
    days_overdue: number;
}

export interface CloseProblem { key: string; message: string }

export interface FinanceDocumentRow {
    docheader_id: string;
    doc_date: string;
    supplier: string | null;
    entity_pingwin_id: string | null;
    doctype: string | null;
    docconfig_id: string;
    document: string | null;
    supplier_doc: string | null;
    group: FinanceGroup | null;
    group_label: string | null;
    family: string | null;
    families: string[];
    net_cents: number;
    vat_cents: number | null;
    total_cents: number;
    parts: { group: FinanceGroup; group_label: string; family: string; cents: number; source: string; reason: string | null }[];
    unclassified_cents: number;
    unclassified_reason: string | null;
    out_of_result: boolean;
    docstatus_id: string | null;
    docstatus: string | null;
    open: boolean;
    due_date: string | null;
    payment: PaymentInfo;
    ocr_invoice_id: number | null;
    close_problems: CloseProblem[] | null;
}

export interface ResultRow {
    kind: "grupo" | "familia" | "total";
    group: FinanceGroup | null;
    label: string;
    cents: number;
    pct: number | null;
    prev_cents: number;
    prev_pct: number | null;
    avg3_cents: number;
    avg3_pct: number | null;
}

export interface PaymentRow extends PaymentInfo {
    docheader_id: string;
    supplier: string | null;
    document: string | null;
    doctype: string | null;
    doc_date: string | null;
    due_date: string | null;
    total_cents: number;
}

export interface FinanceMonth {
    month: string;
    month_label: string;
    today: string;
    sentence: string;
    explain: string;
    warnings: string[];
    stores: { id: number; name: string }[];
    store_id: number | null;
    cards: {
        sales: { cents: number; store_cents: number | null; days: number; days_in_month: number; source: string };
        expenses: { cents: number; open_cents: number; open_count: number; unclassified_cents: number; source: string };
        result: { cents: number; pct: number | null; prev_cents: number; prev_pct: number | null; source: string };
        cash: { paid_cents: number; due_by_month_end_cents: number; overdue_cents: number; source: string };
    };
    result: {
        rows: ResultRow[];
        out_of_result: { label: string; cents: number; reason: string | null }[];
        food_cost: { pct: number | null; comida_pct: number | null; bebida_pct: number | null; avg3_pct: number | null; source: string };
        chart: { month: string; label: string; sales_cents: number; expenses_cents: number; result_cents: number }[];
        vat: { sales_vat_cents: number; purchases_vat_cents: number; estimate_cents: number; docs_without_vat: number; note: string };
        store_note: string;
    };
    cash: {
        weeks: { from: string; to: string; in_cents: number; out_cents: number; due_cents: number }[];
        overdue_before_month_cents: number;
        entries_note: string;
        payments: PaymentRow[];
        outflows: { docheader_id: string; document: string | null; doctype: string | null; supplier: string | null; date: string; cents: number }[];
        next_30_days: { from: string; to: string; cents: number }[];
        overdue_cents: number;
        total_payable_cents: number;
        reconciliation: { screen_total_cents: number; cc_real_balance_cents: number; ok: boolean; mismatches: { supplier: string; screen_cents: number; cc_cents: number }[];
            pingwin_balance_cents: number; auto_paid_difference_cents: number; note: string };
        paid_date_note: string;
        cc_synced_at: string | null;
    };
    documents: {
        rows: FinanceDocumentRow[];
        unclassified: FinanceDocumentRow[];
        open: { count: number; total_cents: number; net_cents: number };
    };
    groups: { key: FinanceGroup; label: string }[];
    can: { classify: boolean; close: boolean };
}

/** Um "Fechar no PingWin" de um documento (um lote fecha um de cada vez). */
export interface DocumentClose {
    id: number;
    batch: string;
    position: number;
    docheader_id: string;
    document: string | null;
    supplier: string | null;
    total_cents: number;
    status: "pendente" | "ok" | "erro" | "erro_confirmacao" | "nao_feito";
    status_before: string | null;
    status_after: string | null;
    error: string | null;
    user: string | null;
    created_at: string | null;
    finished_at: string | null;
}
