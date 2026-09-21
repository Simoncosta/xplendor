// XPLENDOR — OCR de faturas de fornecedor (Fase A). Valores em EUROS na API
// (a BD guarda cêntimos). NÃO escreve no PingWin (synced_to_pingwin=false).

export type OcrInvoiceStatus = "processing" | "por_validar" | "validada" | "erro";

export interface OcrInvoiceLine {
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
    lines: OcrInvoiceLine[];
    summary: OcrInvoiceSummary | null;
}

export interface OcrInvoiceListRow {
    id: number;
    supplier_name: string | null;
    supplier_nif: string | null;
    number: string | null;
    issue_date: string | null;
    status: OcrInvoiceStatus;
    confidence: number;
    total: number | null;
    created_at: string | null;
}

export interface OcrSupplierOption {
    id: number;
    name: string | null;
    tax_number: string | null;
}
