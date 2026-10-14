import type { CompassPlay } from "./bussola.model";
import type { StandNumber } from "./standCompass.model";

/** Bússola do imobiliário (Bússola Master, R3): a mesma forma da do stand. */
export type ImoveisType = "all" | "venda" | "arrendamento";

export interface ImoveisCompassData {
    enabled: boolean;
    provider: "imobiliario";
    company: string;
    type: ImoveisType;
    types: { key: ImoveisType; label: string }[];
    computed_at: string | null;
    data_until?: string;
    window?: { from: string; to: string };
    top: { title: string; context: string; numbers: StandNumber[]; notes: string[] } | null;
    plays: CompassPlay[];
    blocks?: {
        aging: { headline: string; groups: { group: string; label: string; threshold: number; total: number; bands: { label: string; count: number }[] }[];
            oldest: { property_id: number; label: string; days: number; threshold: number; stale: boolean }[] };
        price: { headline: string; eligible: number; counts: { below: number; aligned: number; above: number };
            top_above: { property_id: number; label: string; diff_pct: number; price_m2: number; median: number; period: string; days: number }[];
            outside: Record<string, number>; attribution: string | null };
        zones: { headline: string; rows: { parish_id: number; label: string; municipality: string | null; properties: number; median: number | null; period: string | null; posted_30: boolean }[] };
        interest: { headline: string; note: string; rows: { property_id: number; label: string; contacts: number; visits: number; updated_days: number | null }[] };
        closed: { headline: string; rows: { property_id: number; label: string; listing: string; closed_days: number; testimonial: boolean }[] };
        forgotten: { headline: string; items: { property_id: number; label: string; days: number }[]; total: number };
        footer: string[];
    };
    financial: { visible: boolean; note: string | null };
    can_act: boolean;
    can_act_reason: string | null;
    formats: string[];
}

export const IMOVEIS_OUTSIDE_LABELS: Record<string, string> = {
    sem_referencia: "Freguesia sem referência do INE", sem_area: "Sem área útil", sem_freguesia: "Sem freguesia", arrendamento: "Arrendamento (a referência é de vendas)",
};

/** Um imóvel da carteira (a página "Imóveis"). */
export interface Property {
    id: number; reference: string; title: string; kind: string; kind_label: string; typology: string | null; area_m2: number | null;
    listing: "venda" | "arrendamento"; price: number; energy_class: string | null; energy_class_missing: boolean; status: "disponivel" | "reservado" | "fechado";
    parish_id: number | null; parish: string | null; municipality_id: number | null; municipality: string | null; district_id: number | null;
    listed_at: string | null; closed_at: string | null; days: number | null; contacts_30: number; visits_30: number; interest_updated_at: string | null;
    ine: { median_m2: number; period: string; attribution: string } | null;
}

export interface PropertyOptions {
    kinds: Record<string, string>; typologies: string[]; listings: Record<string, string>; statuses: Record<string, string>; energy_classes: string[];
}
