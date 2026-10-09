/** XPLENDOR — F1: documentos de fornecedor do PingWin (lista "Documentos", só leitura). Valores em EUROS. */
export interface SupplierDocumentRow {
    id: number;
    docheader_id: string;
    doc_date: string | null;
    doc_time: string | null;
    document: string | null;
    docconfig_id: string;
    doctype: string | null;
    entity_pingwin_id: string | null;
    entity_name: string | null;
    tax_number: string | null;
    docreference_number: string | null;
    total: number;
    paid: boolean;
    docstatus_id: string | null;
    docstatus_description: string | null;
    employee_name: string | null;
    store_name: string | null;
}

export type SupplierDocumentsRunStatus = "queued" | "running" | "ok" | "failed";

export interface SupplierDocumentsRun {
    id: number;
    start_date: string;
    end_date: string;
    trigger: "nightly" | "manual";
    status: SupplierDocumentsRunStatus;
    docs_count: number | null;
    error: string | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string | null;
}

export interface SupplierDocumentsFacets {
    types: { id: string; label: string | null; count: number }[];
    suppliers: { id: string; name: string | null }[];
    default_types: string[];
}
