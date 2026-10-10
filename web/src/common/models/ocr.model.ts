// XPLENDOR — OCR de faturas de fornecedor (Fase A). Valores em EUROS na API
// (a BD guarda cêntimos). NÃO escreve no PingWin (synced_to_pingwin=false).

export type OcrInvoiceStatus = "processing" | "por_validar" | "validada" | "erro" | "nao_desta_empresa";
// F2a: origem das linhas e conferência pelo QR da AT.
export type OcrSource = "qr+texto" | "qr+imagem" | "sem_qr" | "qr";
export type OcrCheckStatus = "confere" | "nao_confere" | "sem_qr";

export interface OcrInvoiceLine {
    id?: number;                   // F2b: linha já guardada (mantém a ligação ao artigo ao validar)
    supplier_code: string | null; // código do artigo na fatura do fornecedor
    item: string | null;
    quantity: number | null;
    unit: string | null;
    unit_price: number | null;   // euros, 6 casas (F2b: sem arredondar)
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
    lines: (OcrInvoiceLine & OcrLineLink)[];
    summary: OcrInvoiceSummary | null;
    articles_summary: OcrArticlesSummary;
    delete_block: string | null;          // F2c
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
    pingwin_doc_status: string | null;    // FB-1: lançada pela XPLENDOR → "8001" rascunho, "8002" fechada
    deleted_at: string | null;            // F2c: apagada (lista "Mostrar apagadas")
    delete_block: string | null;          // F2c: motivo para não se poder apagar (null = pode)
    restore_block: string | null;         // F2c: motivo para não se poder repor (ex.: ficheiro já purgado)
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

// ── F2b: artigos nas linhas da fatura ───────────────────────────────────────
export type OcrLineLinkState = "ligada" | "sugerida" | "por_ligar";
export type OcrLineLinkMethod = "mapa" | "pingwin" | "descricao" | "sugestao" | "manual" | "criado";

export interface OcrLineArticle {
    id: number;
    code: string | null;
    description: string | null;
    unit: string | null;
}

export interface OcrLineLink {
    article: OcrLineArticle | null;
    link_state: OcrLineLinkState;
    link_method: OcrLineLinkMethod | null;
    link_confidence: number | null;
    suggestions: (OcrLineArticle & { score: number })[];
    creating: { write_id: number; status: string; error: string | null } | null; // "Criar artigo" em curso
    supplier_code_status: "pendente" | "ok" | "erro" | "conflito" | null;        // código do fornecedor no artigo (PingWin)
    supplier_code_error: string | null;
}

export interface OcrArticlesSummary {
    total: number;
    linked: number;
    suggested: number;
    unlinked: number;
    ready: boolean; // "pronta para lançar": todas as linhas ligadas
}

export interface OcrLineLinksPayload {
    articles_summary: OcrArticlesSummary;
    line_links: (OcrLineLink & { id: number })[];
}

export interface OcrArticleSearchResult {
    id: number;
    code: string | null;
    description: string | null;
    unit: string | null;
    family: string | null;
    last_purchase: { price: number; unit: string | null; date: string; supplier: string | null } | null;
    bought_from_supplier: boolean;
    supplier_codes: string[];
    approximate: boolean; // nenhum artigo tinha todas as palavras: são os mais parecidos
}

export interface OcrArticleFormOptions {
    families: { value: string; label: string }[];
    taxgroups: { value: string; label: string; rate: number }[];
    units: { value: string; label: string; code: string }[];
}

export const OCR_LINK_METHOD_LABEL: Record<OcrLineLinkMethod, string> = {
    mapa: "Aprendido",
    pingwin: "Do PingWin",
    descricao: "Descrição igual",
    sugestao: "Sugestão aceite",
    manual: "Associado à mão",
    criado: "Artigo criado",
};

// ── FB-1: "Lançar no PingWin" (rascunho 8001 → fechar 8002 / anular 8003) ────────────
export interface OcrLaunchGuard {
    key: "tipo" | "qr" | "conferencia" | "fornecedor" | "nao_lancada" | "artigos" | "unidades" | "iva" | "valores" | "acerto" | "escrita";
    ok: boolean;
    label: string;
    message: string | null;
}

export interface OcrLaunchLine {
    id: number;
    position: number;
    item: string | null;
    ocr_unit: string | null;
    quantity: number | null;
    article: { id: number; code: string | null; description: string | null; pingwin_id: string } | null;
    unit_options: { value: string; label: string }[];
    unit_id: string | null;
    unit_source: "escolhida" | "fatura" | null;
    unit_ok: boolean;
}

export interface OcrDocWrite {
    id: number;
    action: "launch" | "close" | "void";
    status: "pendente" | "ok" | "erro" | "erro_confirmacao";
    document: string | null;
    docheader_id: string | null;
    error: string | null;
    adjustment: string | null;
    tax_overrides: number;
    created_at: string | null;
    finished_at: string | null;
}

export interface OcrLaunchPreview {
    can_launch: boolean;
    guards: OcrLaunchGuard[];
    lines: OcrLaunchLine[];
    estimate: { target_cents: number | null; computed_cents: number; net_cents: number; tax_cents: number; adjustment_cents: number | null };
    supplier: { id: number; name: string | null; pingwin_id: string } | null;
    docreference: { number: string; truncated: boolean; date: string | null };
    defaults: { store: string | null; store_code: string | null; serie: string | null; at: string | null };
    draft: { docheader_id: string; document: string | null; docstatus_id: string; docstatus_label: string; total: number; store_name: string | null; doc_date: string | null } | null;
    writes: OcrDocWrite[];
}

// ── F2c: apagar várias ───────────────────────────────────────────────────────
export interface OcrBulkDeleteResult {
    deleted: number[];
    skipped: { id: number; number: string | null; reason: string }[];
}
