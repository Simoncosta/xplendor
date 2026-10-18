/** Bússola comum (restauração e stand): o topo, as jogadas da semana e os blocos. */
export type PlayType = "weak_period" | "item_up" | "item_down" | "stale_item"
    | "stop_ad" | "review_price" | "renew_stock" | "use_interest" | "give_stage"
    // Imobiliário (Bússola Master).
    | "bce_day" | "publish_new" | "ask_testimonial" | "show_zone";

export interface CompassNumber {
    kind: "store_variation" | "revenue" | "family" | "same_day";
    label: string;
    value: number | null;
    format: "pct_signed" | "pct" | "eur";
    caption: string;
    icon: string;
    /** Sem Finanças: o valor em euros não vem (value null) e o ecrã mostra um número fictício desfocado. */
    hidden?: boolean;
}

export interface CompassPlay {
    key: string;
    type: PlayType;
    type_label: string;
    icon: string;
    color: "warning" | "success" | "danger" | "info" | "primary";
    locations: string[];
    location_id: number;
    title: string;
    confidence: "alta" | "media";
    number: { value: string; caption: string };
    bars: { label: string; value: string | null; pct: number; color: string; hidden?: boolean }[];
    what: { text: string; source: "ai" | "template" };
    /** Sempre Instagram e Facebook (multicanal); "connected": as redes da empresa estão ligadas. Stand: "ad" (o anúncio, no tráfego pago). */
    where: { networks: { network: string; label: string; format_key: string; format_label: string; format_source: string | null }[]; connected: boolean; ad?: string | null };
    when: { date: string; is_today: boolean; label: string; target: string | null };
    detail: { sentences: string[]; sample: string; confidence: { title: string; confidence: "alta" | "media" }[] };
    signal_keys: string[];
    theme: string;
    format: string;
    /** Stand: os veículos da jogada (marca, modelo e ano; sem dados pessoais). */
    items?: { car_id?: number; property_id?: number; label: string; detail: string | null }[];
    /** Stand: o veículo único da jogada (para "Ver veículo" e "Rever preço"). */
    car_id?: number | null;
    /** Stand: os botões desta jogada. */
    actions?: ("create" | "suggest" | "view_car" | "review_price" | "view_property")[];
    /** Imobiliário: o imóvel único da jogada, a freguesia (jogada da zona) e a ligação oficial (jogada do BCE). */
    property_id?: number | null;
    parish_id?: number | null;
    link?: string | null;
    /** Stand: o que acontece às fotografias no "Criar publicação". */
    post_note?: string | null;
}

export interface ShiftGrid {
    location_id: number;
    name: string;
    from: string;
    to: string;
    mode: "hours" | "days";
    special_excluded: number;
    shifts: Record<string, { mean_cents: number | null; days: Record<string, { avg_cents: number | null; occurrences: number; closed: boolean; pct_vs_mean: number | null; weak: boolean }> }>;
}

export interface ChangeRow { key: string; name: string; location: string; category: string; before: number; now: number; variation_pct: number; confidence: "alta" | "media" }

export interface CompassData {
    enabled: boolean;
    company: string;
    locations: { id: number; name: string }[];
    location_id: number;
    categories_pending: number;
    computed_at: string | null;
    data_until?: string;
    can_act?: boolean;
    can_act_reason?: string | null;
    formats?: string[];
    /** Sem a permissão de ver as Finanças, os valores em euros não vêm (visible false). */
    financial?: { visible: boolean; note: string | null };
    top: { title: string; period: { from: string; to: string; prev_from: string; prev_to: string }; numbers: CompassNumber[]; notes: string[] } | null;
    plays: CompassPlay[];
    blocks?: {
        days: { headline: string; grids: ShiftGrid[]; weeks: number };
        stars: { headline: string | null; stores: { location_id: number; name: string; items: { product_id: string; name: string; qty: number; net_cents: number | null; share_pct: number }[]; others_pct: number }[] };
        changes: {
            headline: string;
            context: { period_now: { from: string; to: string }; period_before: { from: string; to: string }; stores: { name: string; variation_pct: number | null }[]; special_days: string[] };
            up: ChangeRow[];
            down: ChangeRow[];
            visible: number;
        };
        decide: { headline: string | null; same_day_pct: number | null; stores: { name: string; total: number; buckets: { key: string; label: string; pct: number }[] }[] };
        channels: { headline: string | null; stores: { name: string; total: number; channels: { key: string; label: string; reservations: number; pct: number }[] }[] };
        forgotten: { headline: string; items: { key: string; name: string; location: string; days: number; qty_before: number; theme: string | null; date: string }[] };
        /** Fatia 3: a jogada da engenharia de menu dos artigos com mais receita (pode não vir em respostas antigas). */
        menu?: { headline: string | null; calculated_at: string | null; items: { product_id: string; name: string; family: string; class: string; class_label: string; confidence: string; fragile: boolean; play: string | null }[] };
    };
}

export const fmtPct = (v: number | null, signed = false) =>
    v === null ? "Sem dados" : `${signed ? (v > 0 ? "+" : v < 0 ? "−" : "") : ""}${Math.abs(v).toLocaleString("pt-PT", { maximumFractionDigits: 1 })}%`;
export const fmtEur = (cents: number) => (cents / 100).toLocaleString("pt-PT", { maximumFractionDigits: 0 }) + " €";
export const fmtDm = (d?: string) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit" }) : "");
