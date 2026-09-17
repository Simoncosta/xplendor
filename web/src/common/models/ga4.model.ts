// XPLENDOR — Tráfego do site do cliente (GA4 Data API). Mostra a propriedade GA4
// do CLIENTE (o site dele) — NÃO confundir com o G-KMK84KG99K, que é da própria
// app XPLENDOR. Multi-cliente: cada empresa liga o SEU property_id.

export interface Ga4Overview {
    active_users: number;
    new_users: number;
    sessions: number;
    page_views: number;
    avg_session_duration: number; // segundos
    engagement_rate: number;      // 0..1
    bounce_rate: number;          // 0..1
}

export interface Ga4TopPage { path: string; title: string; views: number; }
export interface Ga4Source { channel: string; sessions: number; }
export interface Ga4Device { device: string; sessions: number; }
export interface Ga4Geo { country: string; city: string; sessions: number; }
export interface Ga4TrendPoint { date: string; active_users: number; sessions: number; }

export interface Ga4Demographics {
    available: boolean;
    reason: "ok" | "no_data" | "thresholded";
    age: { bracket: string; users: number }[];
    gender: { gender: string; users: number }[];
}

export interface Ga4Traffic {
    range: { start: string; end: string; days: number };
    overview: Ga4Overview;
    top_pages: Ga4TopPage[];
    traffic_sources: Ga4Source[];
    devices: Ga4Device[];
    geo: Ga4Geo[];
    trend: Ga4TrendPoint[];
    demographics: Ga4Demographics;
    generated_at: string;
}

// Resposta do endpoint /analytics/ga4/traffic (dentro de `data`).
export interface Ga4TrafficResponse {
    connected: boolean;
    property_id?: string;
    sa_email?: string | null;   // quando não ligado — mostrar nas instruções
    traffic?: Ga4Traffic | null;
    error?: string;             // erro gracioso (SA sem acesso, ID errado…)
    error_detail?: string;      // erro REAL do Google (só em debug) — classe + mensagem
}

// Rótulos pt-PT dos canais e dispositivos (fallback: o próprio valor).
export const CHANNEL_LABELS: Record<string, string> = {
    "Organic Search": "Pesquisa orgânica",
    "Direct": "Direto",
    "Referral": "Referência",
    "Organic Social": "Redes sociais",
    "Paid Search": "Pesquisa paga",
    "Paid Social": "Social pago",
    "Email": "Email",
    "Display": "Display",
    "(other)": "Outro",
};

export const DEVICE_LABELS: Record<string, string> = {
    mobile: "Telemóvel",
    desktop: "Computador",
    tablet: "Tablet",
};

export const GENDER_LABELS: Record<string, string> = {
    male: "Homens",
    female: "Mulheres",
    unknown: "Desconhecido",
};

/** Segundos → "2m 05s". */
export const fmtDuration = (s: number): string => {
    const m = Math.floor(s / 60);
    const sec = Math.round(s % 60);
    return m > 0 ? `${m}m ${String(sec).padStart(2, "0")}s` : `${sec}s`;
};

/** 0..1 → "72%". */
export const fmtPct = (v: number): string => `${Math.round(v * 100)}%`;
