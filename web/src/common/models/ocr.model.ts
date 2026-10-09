// XPLENDOR — OCR de faturas de fornecedor (Fase A). Valores em EUROS na API
// (a BD guarda cêntimos). NÃO escreve no PingWin (synced_to_pingwin=false).

export type OcrInvoiceStatus = "processing" | "por_validar" | "validada" | "erro" | "nao_desta_empresa";
// F2a: origem das linhas e conferência pelo QR da AT.
export type OcrSource = "qr+texto" | "qr+imagem" | "sem_qr" | "qr";
export type OcrCheckStatus = "confere" | "nao_confere" | "sem_qr";

export interface OcrInvoiceLine {
    supplier_code: string | null; // código do artigo na fatura do fornecedor
    item: string | null;
    quantity: number | null;
    unit: string | null;
    unit_price: number | null;   // euros
    discount_pct: number | null; // 0..100
    line_total: number | null;   // euros
    vat_rate: number | null;     // 6|13|23
}

export interface OcrVatBreakdownRow {
    rate: number | null;
    base: number | null; // euros
    vat: number | null;  // euros
}

export interface OcrInvoiceSummary {
    goods_total: number;
    commercial_discount: number;
    taxable_base: number;
    vat_total: number;
    withholding: number;
    financial_discount: number;
    total: number;
    vat_breakdown: OcrVatBreakdownRow[];
}

export interface OcrInvoiceDetail {
    id: number;
    status: OcrInvoiceStatus;
    confidence: number;
    model: string | null;
    prompt_version: string | null;
    synced_to_pingwin: boolean;
    error_message: string | null;
    supplier_id: number | null;
    supplier_name: string | null;
    supplier_nif: string | null;
    number: string | null;
    issue_date: string | null;
    lines_total: number;          // soma das linhas calculada pela Xplendor (referência)
    // F2a — QR primeiro + conferência
    buyer_nif: string | null;
    atcud: string | null;
    doc_type: string | null;
    qr_ok: boolean | null;        // null = fatura anterior ao F2a
    source: OcrSource | null;
    lines_source: "texto" | "imagem" | null;
    pages: number | null;
    attempts: number | null;
    tokens_in: number | null;
    tokens_out: number | null;
    cost_usd: number | null;
    duration_ms: number | null;
    check_status: OcrCheckStatus | null;
    check_diff: OcrCheckDiffRow[];
    lines: OcrInvoiceLine[];
    summary: OcrInvoiceSummary | null;
}

export interface OcrCheckDiffRow {
    rate: number | null; // null = linhas sem taxa
    qr: number;          // base do QR (euros)
    lines: number;       // soma das linhas (euros)
    diff: number;        // linhas − QR
    ok: boolean;
}

export interface OcrInvoiceListRow {
    id: number;
    supplier_name: string | null;
    supplier_nif: string | null;
    number: string | null;
    issue_date: string | null;
    status: OcrInvoiceStatus;
    confidence: number;
    source: OcrSource | null;
    check_status: OcrCheckStatus | null;
    doc_type: string | null;              // F3: tipo do QR (D)
    link_status: OcrLinkStatus | null;    // F3: estado no PingWin
    paid: boolean | null;                 // F3: do(s) documento(s) ligado(s)
    store: string | null;                 // F3: do(s) documento(s) ligado(s)
    total: number | null;
    created_at: string | null;
}

export interface OcrSupplierOption {
    id: number;
    name: string | null;
    tax_number: string | null;
}

// ── F3: ligação Fatura OCR ↔ documento(s) do PingWin (só espelhos) ─────────────
export type OcrLinkStatus = "fornecedor_em_falta" | "nao_lancada" | "possivel" | "lancada" | "lancada_guias" | "duplicada";
export type OcrLinkMethod = "numero" | "total_data" | "guias" | "manual";

export interface OcrPingwinDoc {
    docheader_id: string;
    document: string | null;
    doctype: string | null;
    doc_date: string | null;             // data de LANÇAMENTO
    docreference_number: string | null;
    docreference_date: string | null;
    total: number;                       // euros
    paid: boolean;
    store_name: string | null;
    voided: boolean;
    linked_to_invoice: number | null;    // já ligado a OUTRA fatura carregada
}

export interface OcrPingwinLinkedDoc extends OcrPingwinDoc {
    method: OcrLinkMethod;
    confirmed: boolean;
    confirmed_at: string | null;
}

export interface OcrPingwinCompareLine {
    code: string | null;
    description: string | null;
    quantity: number | null;
    total: number;
}

export interface OcrPingwinBlock {
    status: OcrLinkStatus | null;
    diff: number | null;                 // Σ documentos − total da fatura (euros)
    invoice_total: number | null;
    checked_at: string | null;
    note: string | null;
    search_pending: boolean;
    supplier: { nif: string | null; own_nif: boolean; name: string | null; id: number | null; found: boolean; prefill: { nif: string | null; name: string | null } };
    linked: OcrPingwinLinkedDoc[];
    candidates_mode: "total_data" | "guias" | null;
    candidates: OcrPingwinDoc[];
    period: { from: string; to: string; source: "guias" | "35_dias" } | null;
    guides: { ref: string; date: string | null }[];
    choices: OcrPingwinDoc[];
    duplicate_of: { id: number; number: string | null; status: string } | null;
    compare: {
        ocr_count: number; pw_count: number; ocr_sum: number; pw_sum: number; pw_synced: boolean;
        unmatched_ocr: OcrPingwinCompareLine[]; unmatched_pw: OcrPingwinCompareLine[];
    } | null;
}

/** F3: estado da fatura no PingWin (badges da lista e da validação). */
export const OCR_LINK_STATUS: Record<OcrLinkStatus, { label: string; cls: string; title: string }> = {
    lancada: { label: "Lançada", cls: "bg-success-subtle text-success", title: "Está lançada no PingWin" },
    lancada_guias: { label: "Lançada (guias)", cls: "bg-success-subtle text-success", title: "Lançada no PingWin em vários documentos (guias)" },
    possivel: { label: "Possível", cls: "bg-info-subtle text-info", title: "Há documentos no PingWin que podem ser esta fatura: escolha no detalhe" },
    nao_lancada: { label: "Falta lançar", cls: "bg-warning-subtle text-warning", title: "Não foi encontrada no PingWin" },
    fornecedor_em_falta: { label: "Fornecedor em falta", cls: "bg-danger-subtle text-danger", title: "O fornecedor (NIF) não existe no PingWin" },
    duplicada: { label: "Duplicada", cls: "bg-secondary-subtle text-secondary", title: "Esta fatura já foi carregada antes" },
};
