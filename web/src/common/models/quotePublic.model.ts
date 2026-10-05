// Página pública do orçamento. Espelha GET /api/public/quotes/{token}.

export type QuotePublicState = "open" | "expired" | "superseded" | "under_revision" | "accepted" | "refused";
export type Bucket = "monthly" | "one_off";

export interface QuotePublicLine {
    key: number;
    name: string;
    description: string | null;
    unit: "month" | "project" | "hour";
    billing_type: Bucket;
    quantity: number;
    unit_price: number;
    discount_type: "percent" | "amount" | null;
    discount_value: number | null;
    is_optional: boolean;
    in_package: boolean;
    line_subtotal: number | null;
    line_discount: number;
    line_total: number | null;
}

export interface GlobalDiscount {
    type: "percent" | "amount" | null;
    value: number | null;
    target: Bucket | null;
    label: string | null;
}

export interface BucketTotals { subtotal: number; discount: number; total: number; count: number }

export interface SelectionDiscount {
    label: string;
    applies: boolean;
    reason: string | null;
    type?: string | null;
    value?: number | null;
}

export interface QuotePublicData {
    preview: boolean;
    state: QuotePublicState;
    state_message: string | null;
    can_respond: boolean;
    number: string;
    version: number;
    valid_until: string | null;
    document: {
        title: string;
        legal: { brand: string; brand_line: string; contact_line: string; socials: { title: string; url: string; label: string }[] };
        number: string;
        version_label: string;
        issued_at: string;
        valid_until: string;
        minimum_contract: string | null;
        customer: { name: string; lines: string[] };
        title_text: string | null;
        intro: string | null;
        vat_note: string;
        conditions: { key: string; value: string }[];
    };
    lines: QuotePublicLine[];
    buckets: Record<Bucket, BucketTotals>;
    global_discount: GlobalDiscount | null;
    has_optional: boolean;
    changes_requested: boolean;
    acceptance: {
        name: string;
        accepted_at: string;
        accepted_keys: number[];
        buckets: Record<Bucket, BucketTotals> | null;
        discount: SelectionDiscount | null;
    } | null;
    contact: { brand: string; email: string | null; phone: string | null; website: string | null };
}

export const BUCKETS: { key: Bucket; title: string; subtitle: string; totalLabel: string; suffix: string }[] = [
    { key: "monthly", title: "Serviços mensais", subtitle: "cobrados todos os meses", totalLabel: "Total mensal", suffix: "/mês" },
    { key: "one_off", title: "Serviços de valor único", subtitle: "pagos uma só vez", totalLabel: "Total valor único", suffix: "" },
];

const UNIT_SUFFIX: Record<string, string> = { month: "/mês", hour: "/hora", project: "" };
const MINUS = "−";

/** "1.234,56 €" (igual ao PDF). */
export const money = (v: number): string => {
    const [int, dec] = Math.abs(v).toFixed(2).split(".");
    return `${v < 0 ? "-" : ""}${int.replace(/\B(?=(\d{3})+(?!\d))/g, ".")},${dec} €`;
};

const plainNumber = (v: number): string => {
    const [int, dec] = v.toFixed(2).split(".");
    const out = `${int.replace(/\B(?=(\d{3})+(?!\d))/g, ".")},${dec}`;
    return out.replace(/0+$/, "").replace(/,$/, "");
};

export const lineQuantity = (l: QuotePublicLine) => plainNumber(l.quantity) + (l.unit === "hour" ? " h" : "");
export const linePrice = (l: QuotePublicLine) => money(l.unit_price) + (UNIT_SUFFIX[l.unit] ?? "");
export const lineDiscount = (l: QuotePublicLine) => {
    if (!l.line_discount || l.line_discount <= 0) return "";
    return l.discount_type === "percent"
        ? `${MINUS}${plainNumber(Number(l.discount_value))}%`
        : `${MINUS}${money(l.line_discount)}${l.billing_type === "monthly" ? "/mês" : ""}`;
};

const joinNames = (names: string[]) => (names.length === 1 ? names[0] : `${names.slice(0, -1).join(", ")} e ${names[names.length - 1]}`);

/**
 * Totais da escolha do cliente, só para mostrar (o servidor recalcula sempre ao aceitar).
 * Mesmas regras e mesmos textos do QuoteCalculator::computeSelection.
 */
export function computeSelection(lines: QuotePublicLine[], g: GlobalDiscount | null, included: Set<number>) {
    const selected = lines.filter((l) => !l.is_optional || included.has(l.key));
    const excluded = lines.filter((l) => l.is_optional && !included.has(l.key));
    const buckets: Record<Bucket, BucketTotals> = {
        monthly: { subtotal: 0, discount: 0, total: 0, count: 0 },
        one_off: { subtotal: 0, discount: 0, total: 0, count: 0 },
    };
    selected.forEach((l) => {
        const b = buckets[l.billing_type === "monthly" ? "monthly" : "one_off"];
        b.subtotal = Math.round((b.subtotal + Number(l.line_total ?? 0)) * 100) / 100;
        b.count += 1;
    });

    const label = g?.label || "Desconto de pacote";
    const discount: SelectionDiscount = { label, applies: false, reason: null, type: g?.type, value: g?.value };
    const value = Number(g?.value ?? 0);
    if (g?.type && value > 0) {
        const missing = excluded.filter((l) => l.in_package).map((l) => l.name);
        if (missing.length > 0) {
            discount.reason = missing.length === 1
                ? `${label}: deixa de se aplicar porque o serviço ${missing[0]} não foi incluído.`
                : `${label}: deixa de se aplicar porque os serviços ${joinNames(missing)} não foram incluídos.`;
        } else if (g.type === "percent") {
            (Object.keys(buckets) as Bucket[]).forEach((k) => { buckets[k].discount = Math.round(buckets[k].subtotal * Math.min(value, 100)) / 100; });
            discount.applies = true;
        } else if (g.type === "amount") {
            const target = g.target;
            const targetLabel = target === "monthly" ? "serviços mensais" : "serviços de valor único";
            if (!target || buckets[target].count === 0) {
                discount.reason = `${label}: aplica-se aos ${targetLabel}, que não foram incluídos.`;
            } else if (value > buckets[target].subtotal) {
                discount.reason = `${label}: deixa de se aplicar porque o total dos ${targetLabel} ficou abaixo do valor do desconto.`;
            } else {
                buckets[target].discount = Math.round(value * 100) / 100;
                discount.applies = true;
            }
        }
    }
    (Object.keys(buckets) as Bucket[]).forEach((k) => {
        buckets[k].total = Math.max(0, Math.round((buckets[k].subtotal - buckets[k].discount) * 100) / 100);
    });

    return { selected, excluded, buckets, discount };
}

export const discountLabel = (d: SelectionDiscount | GlobalDiscount | null) => {
    if (!d) return "";
    const base = d.label || "Desconto de pacote";
    return d.type === "percent" && d.value ? `${base} (${plainNumber(Number(d.value))}%)` : base;
};
