import type { CompassPlay } from "common/models/bussola.model";

/** Bússola do stand (veículos: carros e autocaravanas): o topo, as jogadas e os blocos. Só contagens, nunca dados pessoais. */
export type StandType = "all" | "car" | "motorhome";

export interface StandNumber {
    kind: "stock_count" | "stock_value" | "avg_days" | "above_market" | "ad_spend" | "cost_per_paid_lead";
    label: string;
    value: number | null;
    format: "int" | "pct" | "eur";
    caption: string;
    icon: string;
    /** Sem Finanças: o valor não vem (null) e o ecrã mostra um número fictício desfocado. */
    hidden?: boolean;
}

export interface StandCompassData {
    enabled: boolean;
    provider: "stand";
    company: string;
    type: StandType;
    types: { key: StandType; label: string }[];
    computed_at: string | null;
    data_until?: string;
    window?: { from: string; to: string };
    can_act?: boolean;
    can_act_reason?: string | null;
    formats?: string[];
    financial?: { visible: boolean; note: string | null };
    top: { title: string; context: string; numbers: StandNumber[]; notes: string[] } | null;
    plays: CompassPlay[];
    blocks?: {
        aging: { headline: string; groups: { group: string; label: string; threshold: number; total: number; bands: { label: string; count: number }[] }[];
            oldest: { car_id: number; label: string; days: number | null; threshold: number; stale: boolean }[] };
        price: { headline: string; eligible: number; counts: { below: number; aligned: number; above: number };
            top_above: { car_id: number; label: string; diff_pct: number; price: number | null; median: number; comparables: number; days: number | null }[];
            outside: Partial<Record<"sem_tipologia" | "sem_mercado" | "sem_comparaveis" | "aproximada" | "confianca_baixa", number>> };
        ads: { headline: string; note?: string; rows: AdRow[]; without_contacts: AdRow[] };
        demand: { headline: string; rows: { car_id: number; label: string; views: number; contacts: number }[] };
        forgotten: { headline: string; items: { car_id: number; label: string; days: number | null }[]; total: number };
        footer: string[];
    };
}

/** Por veículo com gasto: o gasto, as leads pagas e o custo por lead paga (com origem) e os contactos de todas as origens (sem divisão). */
export interface AdRow { car_id: number; label: string; sold: boolean; contacts: number; paid_leads: number; spend: number | null; cost_per_paid_lead: number | null; hidden?: boolean }

export const OUTSIDE_LABELS: Record<string, string> = {
    sem_tipologia: "sem tipologia", sem_mercado: "sem mercado (caravanas)", sem_comparaveis: "sem comparáveis",
    aproximada: "comparação aproximada", confianca_baixa: "confiança baixa",
};

export const fmtInt = (v: number | null) => (v === null ? "Sem dados" : v.toLocaleString("pt-PT"));
export const fmtEuro = (v: number | null) => (v === null ? "Sem dados" : `${Math.round(v).toLocaleString("pt-PT")} €`);
