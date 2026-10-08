/** Bússola (marketing da restauração): o topo, as jogadas da semana e os blocos. */
export type PlayType = "weak_period" | "item_up" | "item_down" | "stale_item";

export interface CompassNumber {
    kind: "store_variation" | "revenue" | "family" | "same_day";
    label: string;
    value: number | null;
    format: "pct_signed" | "pct" | "eur";
    caption: string;
    icon: string;
}

export interface CompassPlay {
    key: string;
    type: PlayType;
    type_label: string;
    icon: string;
    color: "warning" | "success" | "danger" | "info";
    locations: string[];
    location_id: number;
    title: string;
    confidence: "alta" | "media";
    number: { value: string; caption: string };
    bars: { label: string; value: string; pct: number; color: string }[];
    what: { text: string; source: "ai" | "template" };
    /** Sempre Instagram e Facebook (multicanal); "connected": as redes da empresa estão ligadas. */
    where: { networks: { network: string; label: string; format_key: string; format_label: string; format_source: string | null }[]; connected: boolean };
    when: { date: string; is_today: boolean; label: string; target: string | null };
    detail: { sentences: string[]; sample: string; confidence: { title: string; confidence: "alta" | "media" }[] };
    signal_keys: string[];
    theme: string;
    format: string;
}

export interface ShiftGrid {
    location_id: number;
    name: string;
    from: string;
    to: string;
    mode: "hours" | "days";
    special_excluded: number;
    shifts: Record<string, { mean_cents: number; days: Record<string, { avg_cents: number; occurrences: number; closed: boolean; pct_vs_mean: number | null; weak: boolean }> }>;
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
    top: { title: string; period: { from: string; to: string; prev_from: string; prev_to: string }; numbers: CompassNumber[]; notes: string[] } | null;
    plays: CompassPlay[];
    blocks?: {
        days: { headline: string; grids: ShiftGrid[]; weeks: number };
        stars: { headline: string | null; stores: { location_id: number; name: string; items: { product_id: string; name: string; qty: number; net_cents: number; share_pct: number }[]; others_pct: number }[] };
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
    };
}

export const fmtPct = (v: number | null, signed = false) =>
    v === null ? "Sem dados" : `${signed ? (v > 0 ? "+" : v < 0 ? "−" : "") : ""}${Math.abs(v).toLocaleString("pt-PT", { maximumFractionDigits: 1 })}%`;
export const fmtEur = (cents: number) => (cents / 100).toLocaleString("pt-PT", { maximumFractionDigits: 0 }) + " €";
export const fmtDm = (d?: string) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit" }) : "");
