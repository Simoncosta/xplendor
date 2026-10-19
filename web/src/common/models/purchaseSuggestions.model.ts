/** XPLENDOR — Compras sugeridas do Economato (só leitura). */
export type SuggestionLine = {
    product_id: string; code: string | null; description: string; suggested: number; unit: string; pack_factor: number; pack_confirmed: boolean;
    weekly_median_base: number; need_base: number; stock_base: number | null; base_unit: string;
    last_purchase: { date: string; qty: number; unit: string; document: string };
    price_cents: number | null; total_cents: number | null;
};
export type SupplierSuggestion = { key: string; name: string; next_delivery: string; usual_days: number[]; interval_days: number; lines: SuggestionLine[]; total_cents: number | null };
export type PurchaseSuggestions = {
    today: string;
    suppliers: SupplierSuggestion[];
    forecast: { factor: number; raw_factor?: number; predicted: number | null; average: number | null; note: string; holidays: Record<string, string> };
    stock: { date: string; articles: number } | null;
    error: { pct: number | null; weeks: { week: string; error_pct: number | null; bought_cents: number | null; note?: string }[] };
    financial?: { visible: boolean; note: string | null };
};
