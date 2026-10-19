// XPLENDOR — Finanças › Mês: resultado e tesouraria num só ecrã (só leitura). Valores em cêntimos.

export type FinanceGroup = "comida" | "bebida" | "pessoal" | "fse" | "outros" | "fora" | "por_classificar";

export type PaymentState = "pago" | "pago_no_ato" | "pago_em_parte" | "por_pagar" | "vencido" | "credito" | "descontado" | "fora_cc" | "pago_xplendor";

export type PaymentMethod = "transferencia" | "cartao_debito" | "cartao_credito" | "numerario" | "debito_direto" | "outro";
export const PAYMENT_METHODS: { value: PaymentMethod; label: string }[] = [
    { value: "transferencia", label: "Transferência" }, { value: "cartao_debito", label: "Cartão de débito" }, { value: "cartao_credito", label: "Cartão de crédito" },
    { value: "numerario", label: "Numerário" }, { value: "debito_direto", label: "Débito direto" }, { value: "outro", label: "Outro" },
];
export type SendMethod = "email" | "entregue" | "plataforma" | "outro";
export const SEND_METHODS: { value: SendMethod; label: string }[] = [
    { value: "email", label: "Email" }, { value: "entregue", label: "Entregue em mão" }, { value: "plataforma", label: "Plataforma do contabilista" }, { value: "outro", label: "Outro" },
];

/** O pagamento registado na XPLENDOR (enquanto não há nota de liquidação no PingWin). */
export interface RegisteredPayment { id: number; paid_on: string; method: PaymentMethod; method_label: string; reference: string | null; amount_cents: number }

export interface PaymentInfo {
    state: PaymentState;
    label: string;
    paid_on: string | null;
    paid_estimated: boolean;
    open_cents: number;
    days_overdue: number;
    payment?: RegisteredPayment | null;     // registado na XPLENDOR
    warning?: string | null;                // o registo da XPLENDOR não bate com o PingWin
    xplendor_superseded?: boolean;          // o PingWin já o dá como pago: ganha ao registo
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
    parts: { group: FinanceGroup; group_label: string; family: string; pingwin_family: string | null; rule: { id: number; label: string } | null;
        cents: number; source: string; reason: string | null }[];
    new_type: { docconfig_id: string; label: string } | null;   // tipo de documento novo: "Criar regra"
    tax_number: string | null;
    has_file: boolean;                                           // fatura (PDF) na XPLENDOR
    accounting: { situation: string | null; situation_manual: boolean; sent_on: string | null; send_method: SendMethod | null;
        send_method_label: string | null; note: string | null; sent: boolean };
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
    /** Mês provisório: faltam documentos habituais (o resultado é estimado). */
    provisional: { count: number; cents: number; text: string;
        missing: { entity_pingwin_id: string; supplier: string | null; months: number; avg_cents: number; last_document: string | null; last_date: string | null }[] } | null;
    /** As entidades tiradas dos habituais neste mês ("Não vem este mês" ou "Não é habitual"). */
    habitual_excluded?: HabitualExclusion[];
    stores: { id: number; name: string }[];
    store_id: number | null;
    cards: {
        sales: { cents: number; store_cents: number | null; days: number; days_in_month: number; source: string; prev_cents?: number };
        expenses: { cents: number; prev_cents?: number; open_cents: number; open_count: number; unclassified_cents: number; source: string };
        result: { cents: number; pct: number | null; prev_cents: number; prev_pct: number | null; estimated_cents: number | null; estimated_pct: number | null; source: string };
        cash: { paid_cents: number; due_by_month_end_cents: number; overdue_cents: number; source: string;
            /** O que saiu no mês anterior e o que entrou (as vendas com IVA) neste mês e no anterior. */
            paid_prev_cents?: number; in_cents?: number; in_prev_cents?: number };
    };
    result: {
        rows: ResultRow[];
        out_of_result: { label: string; cents: number; reason: string | null }[];
        food_cost: { pct: number | null; comida_pct: number | null; bebida_pct: number | null; avg3_pct: number | null; source: string };
        /** provisional: o mês ainda tem documentos habituais em falta (mais claro no gráfico). */
        chart: { month: string; label: string; sales_cents: number; expenses_cents: number; result_cents: number; provisional?: boolean }[];
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
            pingwin_balance_cents: number; auto_paid_difference_cents: number; note: string; registered_cents?: number; after_registers_cents?: number };
        registered_note?: string;
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

/** "Regularizar": por fornecedor, os documentos por pagar e vencidos da conta corrente. */
export interface PayableSupplier {
    supplier_id: number;
    supplier: string | null;
    entity_pingwin_id: string;
    total_cents: number;
    overdue_cents: number;
    documents: { docheader_id: string; document: string | null; doc_date: string | null; due_date: string | null; open_cents: number; overdue: boolean }[];
}

export interface FinanceRule {
    id: number;
    match_type: "artigo" | "entidade" | "tipo_documento" | "familia";
    match_values: string[];
    match_label: string | null;
    entity_pingwin_id: string | null;
    entity_name: string | null;
    group: FinanceGroup;
    active: boolean;
    priority: number;
    label: string;
    updated_at: string | null;
}

export interface FinanceRuleEvent { id: number; rule_id: number; action: string; before: any; after: any; user: string | null; reason: string | null; at: string | null }

export interface AccountantEmailPreview {
    to: string | null;
    subject: string;
    lines: string[];
    documents: { docheader_id: string; number: string; supplier: string; date: string; value: string }[];
    attachments: { name: string; bytes: number }[];
    missing: { docheader_id: string; document: string | null; supplier: string | null }[];
    bytes: number;
    can_send: boolean;
    problems: string[];
}

/** Uma entidade tirada dos "Documentos habituais em falta" (mes = só esse mês; sempre = desse mês em diante). */
export interface HabitualExclusion {
    id: number;
    entity_pingwin_id: string;
    supplier: string | null;
    scope: "mes" | "sempre";
    month: string;
    avg_cents: number | null;
    reason: string | null;
    by: string | null;
    at: string | null;
    active?: boolean;
    removed_at?: string | null;
    removed_by?: string | null;
}
