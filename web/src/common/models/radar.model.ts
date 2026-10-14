/**
 * Radar de oportunidade (R1): o painel do root (Administração › Radar). Só título, ligação, fonte,
 * hora e volume aproximado de cada item, com o veredicto do filtro de segurança; nunca o artigo.
 */

export type RadarVerdict = "passed" | "blocked";
export type RadarReason = "tragedia" | "morte" | "acidente" | "crime" | "doenca" | "politica" | "religiao" | "conflito" | "vida_pessoal";
export type RadarFlag = "nome_pessoa" | "marca_protegida" | "desporto";
export type RadarSourceType = "trends" | "news" | "bce" | "ipma";
export type RadarTopicKind = "trend" | "news" | "weather" | "bce";

export interface RadarRunSummary {
    started_at: string;
    status: "ok" | "erro";
    dry_run: boolean;
    http_status: number | null;
    items_seen: number;
    items_new: number;
    items_blocked: number;
    error: string | null;
}

export interface RadarSourceSummary {
    key: string;
    label: string;
    url: string;
    type: RadarSourceType;
    enabled: boolean;
    disabled_reason: string | null;
    interval_minutes: number;
    attribution: string;
    terms_url: string | null;
    terms_checked_at: string | null;
    terms_note: string | null;
    requests_last_hour: number;
    last_run: RadarRunSummary | null;
    last_ok_at: string | null;
    items_24h: number;
}

export interface RadarTopicSummary {
    id: number;
    kind: RadarTopicKind;
    title: string;
    first_seen_at: string;
    last_seen_at: string;
    window_until: string;
    open: boolean;
    approx_volume: number | null;
    approx_volume_label: string | null;
    districts: string[];
    verdict: RadarVerdict;
    reasons: RadarReason[];
    flags: RadarFlag[];
    items_count: number;
    source_names: string[];
}

export interface RadarSummary {
    /** RADAR_ENABLED: desligado, nada é recolhido nem avaliado. */
    radar_enabled: boolean;
    sources: RadarSourceSummary[];
    relevance?: RadarAdminSector[];
    signals?: RadarAdminCompanySignals[];
    companies?: RadarAdminCompany[];
    totals: { items_24h: number; blocked_24h: number; items_30d: number; blocked_30d: number; topics_open: number; topics_open_passed: number };
    blocked_by_reason: { reason: RadarReason; label: string; count: number }[];
    flags: { flag: RadarFlag; label: string; count: number }[];
    topics: RadarTopicSummary[];
    labels: Record<string, string>;
    limits: { max_requests_per_hour: number; retention_days: number };
}

export interface RadarItem {
    id: number;
    title: string;
    url: string | null;
    source_key: string;
    source_type: RadarSourceType | null;
    source_name: string;
    published_at: string | null;
    fetched_at: string;
    approx_volume: number | null;
    approx_volume_label: string | null;
    verdict: RadarVerdict;
    reasons: RadarReason[];
    flags: RadarFlag[];
    topic: { id: number; kind: RadarTopicKind; title: string; verdict: RadarVerdict } | null;
}

export interface RadarItemsPage {
    items: RadarItem[];
    pagination: { current_page: number; last_page: number; per_page: number; total: number; from: number; to: number };
    labels: Record<string, string>;
}

export interface RadarItemsQuery {
    verdict?: RadarVerdict;
    source?: string;
    days?: number;
    page?: number;
    per_page?: number;
}

// ── R2: o cartão do Radar na Bússola e o painel "Agora" da Linha Editorial ──

export interface RadarSource {
    title: string | null;
    source_name: string;
    url: string | null;
    published_at: string | null;
}

export interface RadarWhen {
    date: string;
    is_today: boolean;
    label: string;
    target: string | null;
}

export interface RadarSignal {
    id: number;
    title: string;
    kind: "trend" | "news" | "bce" | "weather";
    since: string | null;
    since_label: string | null;
    volume_label: string | null;
    why: string;
    why_source: "ai" | "template";
    valid_until: string;
    when: RadarWhen;
    sources: RadarSource[];
    flags: string[];
    notices: string[];
    status: string;
    editorial_post_id: number | null;
    attribution: string;
}

/** Camada 2 (R3): uma data do calendário com a fonte oficial. */
export interface RadarCalendarEntry {
    key: string;
    occurrence: string;
    title: string;
    starts_on: string;
    ends_on: string;
    note: string | null;
    source: { name: string; url: string; format?: string };
    confirmed_on: string;
    confirmed_by: "machine" | "person";
    when: RadarWhen;
    reacted?: boolean;
}

export interface RadarSettings {
    sports_enabled: boolean;
    sports_clubs: string[];
    muted: { index: number; title: string | null; theme: string | null; muted_at: string | null }[];
}

export interface RadarPageData {
    enabled: boolean;
    company: string;
    sector: { slug: string; name: string } | null;
    sector_missing: boolean;
    signals: RadarSignal[];
    calendar: RadarCalendarEntry[];
    settings: RadarSettings;
    can_act: boolean;
    can_act_reason: string | null;
    formats: string[];
    refreshed_at: string | null;
}

/** Painel do root (R2): relevância por ramo, sinais por empresa e o interruptor de cada empresa. */
export interface RadarAdminSector { sector: string; evaluated: number; relevant: number; cost_month_usd: number }
export interface RadarAdminCompanySignals { company_id: number; company: string | null; today: number; by_status: Record<string, number>; cost_month_usd: number }
export interface RadarAdminCompany { id: number; name: string; sector: string | null; radar_enabled: boolean }
