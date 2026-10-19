/** XPLENDOR — Configurações: as definições que saíram do .env (por empresa e da plataforma). */

export type SettingType = "pct" | "eur" | "number" | "bool" | "store" | "month_day" | "family_pct";
export type SettingValue = number | boolean | string | Record<string, number>;

export type SettingItem = {
    key: string;
    section: string | null;
    type: SettingType;
    label: string;
    info: string;
    min: number | null;
    max: number | null;
    owner: boolean;
    consent: string | null;
    value: SettingValue;
    default: SettingValue;
    /** De onde vem o valor: gravado na XPLENDOR, do .env antigo ou o defeito. */
    source: "base_de_dados" | "env" | "defeito";
};

export type SettingHistory = { key: string; label: string; before: unknown; after: unknown; user: string | null; reason: string | null; at: string | null; company?: string | null };

export type RestaurantSettingsResponse = {
    sections: Record<string, string>;
    settings: SettingItem[];
    stores: { id: string; name: string }[];
    history: SettingHistory[];
    owner_note: string;
    can_voids: boolean;
};

export type PingwinAdminResponse = {
    global_writes_enabled: boolean;
    platform: SettingItem[];
    companies: { id: number; name: string; settings: SettingItem[]; legacy: boolean; block: { mode: string; reason: string } | null }[];
    history: SettingHistory[];
};

/** Um valor no histórico, em linguagem simples. */
export function fmtSettingValue(v: unknown): string {
    if (v === null || v === undefined || v === "") return "vazio";
    if (typeof v === "boolean") return v ? "ligado" : "desligado";
    if (typeof v === "number") return v.toLocaleString("pt-PT", { maximumFractionDigits: 2 });
    if (typeof v === "object") {
        const e = Object.entries(v as Record<string, number>);
        return e.length ? e.map(([k, n]) => `${k}: ${n.toLocaleString("pt-PT")}%`).join("; ") : "nenhum";
    }
    return String(v);
}
