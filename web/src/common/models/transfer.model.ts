/** XPLENDOR — Fatia 7c: transferências do Economato para os restaurantes. */
export type TransferStatus = "a_enviar" | "enviado" | "recebido" | "recebido_com_diferenca" | "falhou_envio" | "falhou_rececao" | "anulado";

export type TransferAddress = { address?: string; postalcode?: string; postalcode_description?: string; city?: string } | null;

export type TransferRoute = {
    id: number; name: string; origin_warehouse_id: string; origin_name: string; dest_warehouse_id: string; dest_name: string;
    origin_store_id: string; dest_store_id: string; exit_docconfig_id: string; exit_doc_label: string; auto_receive: boolean; active: boolean;
    load_address: TransferAddress; unload_address: TransferAddress; addresses_ok: boolean;
};

export type TransferRequestLine = {
    id: number; product_id: string | null; code: string | null; description: string | null; qnt: number; unit_desc: string | null;
    unit_value: number | null; qnt_base: number | null; qnt_delivered: number | null;
};

export type TransferRequest = {
    id: number; pingwin_doc_id: string; kind: "encomenda" | "requisicao"; document: string | null; doctype: string | null; store_id: string | null;
    store_name: string | null; doc_date: string | null; employee_name: string | null; age_days: number | null; route_id: number | null;
    lines_status: string | null; lines: TransferRequestLine[]; label?: string;
};

export type TransferSummary = {
    id: number; status: TransferStatus; method: "conversao" | "nova" | null; route_id: number | null; auto_receive: boolean;
    origin_warehouse_id: string; origin_name: string; dest_warehouse_id: string; dest_name: string;
    request: { id: number; document: string | null; doc_date?: string | null; store_name?: string | null; employee_name?: string | null } | null;
    exit_doc_number: string | null; entry_doc_number: string | null; total_value_eur: number | null; error: string | null; created_by: string | null;
    created_at: string | null; sent_at: string | null; received_at: string | null; voided_at: string | null; lines_count: number;
    lines: TransferLine[] | null;
};

export type TransferLine = {
    id: number; product_id: string; code: string | null; description: string | null; qty: number; unit: string | null; factor: number; qty_base: number;
    received_qty_base: number | null; origin_pcm_eur: number | null; price_per_unit_eur: number | null; sent_price_eur: number | null;
    received_price_eur: number | null; value_eur: number | null;
};

export type PrepIssue = { code: string; text: string };

export type PrepLine = {
    index: number; product_id: string; code: string | null; description: string | null; request_line_id: number | null; qty: number; unit: string;
    stock_unit: string; stock_unit_id: string | null; units: { unit: string; factor: number; source: string }[]; factor: number | null;
    pingwin_factor: number | null; qty_base: number | null; origin_pcm_eur: number | null; price_per_unit_eur: number | null;
    last_purchase_price_eur: number | null; origin_stock: number | null; value_eur: number | null;
    blocks: PrepIssue[]; confirm: PrepIssue | null; price_confirmed: boolean; warning: PrepIssue | null;
};

export type TransferPrepare = {
    lines: PrepLine[]; total_value_eur: number | null; blocked: boolean; needs_confirmation: boolean; warnings: number; send_block: string | null;
};
