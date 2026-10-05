// XPLENDOR — Orçamentos de serviços (só a equipa XPLENDOR, área /admin).
// Totais MENSAL e VALOR ÚNICO sempre separados, sem IVA. O servidor é a fonte dos
// valores; quoteTotals() repete as mesmas regras só para a pré-visualização no ecrã.
export type QuoteStatus = "draft" | "sent" | "accepted" | "refused" | "expired";
export type QuoteUnit = "month" | "project" | "hour";
export type QuoteBilling = "monthly" | "one_off";
export type QuoteDiscountType = "percent" | "amount";

export interface IQuoteLine {
    id?: number;
    catalog_item_id?: number | null;
    name: string;
    description?: string | null;
    unit: QuoteUnit;
    billing_type: QuoteBilling;
    quantity: number;
    unit_price: number;
    discount_type?: QuoteDiscountType | null;
    discount_value?: number | null;
    line_total?: number;
}

export interface IQuoteBucket { subtotal: number; discount: number; total: number; count: number; }

export interface IQuoteVersion { version: number; number: string; sent_at: string | null; valid_until: string | null; }

export interface IQuote {
    id: number;
    number: string | null;
    display_number: string;
    version: number;
    status: QuoteStatus;
    legacy_status?: string | null;
    company_id: number | null;
    company_name?: string | null;
    is_linked?: boolean;
    customer_id: number | null;
    client_name: string;
    client_email: string | null;
    client_phone: string | null;
    client_contact: string | null;
    title: string | null;
    intro: string | null;
    description: string;
    total_monthly: number;
    total_one_off: number;
    global_discount_type: QuoteDiscountType | null;
    global_discount_value: number | null;
    global_discount_target: QuoteBilling | null;
    global_discount_label: string | null;
    minimum_contract_months: number | null;
    monthly_start_terms: string | null;     // só com linhas mensais
    payment_terms_monthly: string | null;   // só com linhas mensais
    payment_terms_one_off: string | null;   // só com linhas de valor único
    sent_at: string | null;
    valid_until: string | null;
    decided_at: string | null;
    expired_at: string | null;
    notes?: string | null;
    lines?: IQuoteLine[];
    buckets?: Record<QuoteBilling, IQuoteBucket>;
    versions?: IQuoteVersion[];
    created_at?: string;
    updated_at?: string;
}

export interface IQuoteCompanyOption { id: number; name: string; }
export interface IQuoteCustomer { id: number; name: string; email: string | null; phone: string | null; nif?: string | null; }

export interface ICatalogItem {
    id: number;
    name: string;
    description: string | null;
    unit_price: number;
    unit: QuoteUnit;
    billing_type: QuoteBilling;
    active: boolean;
    sort: number;
}

interface IMoneyBucket { count: number; monthly: number; one_off: number; }
export interface IQuoteSummary {
    open: IMoneyBucket & { expiring_7d: number };
    accepted_year: IMoneyBucket & { year: number };
    accepted_all: IMoneyBucket;
    by_status: Record<QuoteStatus, number>;
    total: number;
}

export const QUOTE_STATUS_META: Record<QuoteStatus, { label: string; color: string }> = {
    draft:    { label: "Rascunho",  color: "secondary" },
    sent:     { label: "Enviado",   color: "warning" },
    accepted: { label: "Aceite",    color: "success" },
    refused:  { label: "Recusado",  color: "danger" },
    expired:  { label: "Expirado",  color: "dark" },
};
export const QUOTE_STATUSES: QuoteStatus[] = ["draft", "sent", "accepted", "refused", "expired"];

export const UNIT_LABEL: Record<QuoteUnit, string> = { month: "por mês", project: "por projeto", hour: "por hora" };
export const BILLING_LABEL: Record<QuoteBilling, string> = { monthly: "Mensal", one_off: "Valor único" };
/** Unidade por omissão para cada tipo de cobrança (editável na linha). */
export const DEFAULT_UNIT: Record<QuoteBilling, QuoteUnit> = { monthly: "month", one_off: "project" };

export const VAT_NOTE = "Acresce IVA à taxa legal em vigor.";
export const ADS_NOTE = "O orçamento de anúncios é pago diretamente pelo cliente às plataformas e não está incluído neste orçamento.";

/** Formata euros pt-PT (ex.: 1500 → "1500,00 €"). */
export const formatQuoteEuro = (v: number): string =>
    new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR" }).format(Number(v || 0));

/** Data por extenso (ex.: "4 de novembro de 2026"). */
export const longDate = (iso?: string | null): string =>
    iso ? new Date(iso.length === 10 ? `${iso}T12:00:00` : iso).toLocaleDateString("pt-PT", { day: "numeric", month: "long", year: "numeric" }) : "";

const round2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;

/** Mesmas regras do QuoteCalculator do servidor, só para pré-visualizar no ecrã. */
export function quoteTotals(
    lines: IQuoteLine[],
    global: { type?: QuoteDiscountType | null; value?: number | null; target?: QuoteBilling | null },
): { lineTotals: number[]; buckets: Record<QuoteBilling, IQuoteBucket> } {
    const buckets: Record<QuoteBilling, IQuoteBucket> = {
        monthly: { subtotal: 0, discount: 0, total: 0, count: 0 },
        one_off: { subtotal: 0, discount: 0, total: 0, count: 0 },
    };
    const lineTotals = lines.map((l) => {
        const sub = round2(Number(l.quantity || 0) * Number(l.unit_price || 0));
        const v = Number(l.discount_value || 0);
        const disc = !v ? 0 : l.discount_type === "percent" ? round2(sub * Math.min(v, 100) / 100) : Math.min(v, sub);
        const total = Math.max(0, round2(sub - disc));
        const b = buckets[l.billing_type];
        b.subtotal = round2(b.subtotal + total);
        b.count += 1;
        return total;
    });
    const gv = Number(global.value || 0);
    if (global.type === "percent" && gv > 0) {
        (Object.keys(buckets) as QuoteBilling[]).forEach((k) => { buckets[k].discount = round2(buckets[k].subtotal * Math.min(gv, 100) / 100); });
    } else if (global.type === "amount" && gv > 0 && global.target && buckets[global.target].count > 0) {
        buckets[global.target].discount = Math.min(gv, buckets[global.target].subtotal);
    }
    (Object.keys(buckets) as QuoteBilling[]).forEach((k) => { buckets[k].total = Math.max(0, round2(buckets[k].subtotal - buckets[k].discount)); });
    return { lineTotals, buckets };
}
