/**
 * Linha Editorial, F3a: etapas do fluxo de produção e aprovação, versões, decisões,
 * comentários e histórico. Espelha EditorialWorkflowService / EditorialWorkflowController.
 */
import type { PostChannel } from "./editorialPost.model";

export type Stage = "idea" | "planning" | "production" | "internal_review" | "client_review" | "scheduled" | "published" | "analysis";

export const STAGE_ORDER: Stage[] = ["idea", "planning", "production", "internal_review", "client_review", "scheduled", "published", "analysis"];

export const STAGE_META: Record<Stage, { label: string; short: string; color: string; hex: string; icon: string; hint: string }> = {
    idea:            { label: "Ideia",       short: "Ideia",  color: "secondary", hex: "#878a99", icon: "ri-lightbulb-line",       hint: "Por confirmar." },
    planning:        { label: "Planeamento", short: "Plan.",  color: "info",      hex: "#299cdb", icon: "ri-calendar-todo-line",   hint: "Data, tema e formato." },
    production:      { label: "Produção",    short: "Prod.",  color: "primary",   hex: "#f97316", icon: "ri-palette-line",         hint: "Legenda e materiais." },
    internal_review: { label: "Revisão",     short: "Rev.",   color: "dark",      hex: "#8b5cf6", icon: "ri-eye-line",             hint: "Revisão interna." },
    client_review:   { label: "Aprovação",   short: "Aprov.", color: "warning",   hex: "#eab308", icon: "ri-user-follow-line",     hint: "À espera do cliente." },
    scheduled:       { label: "Programado",  short: "Prog.",  color: "success",   hex: "#10b981", icon: "ri-calendar-check-line",  hint: "Aprovado, com data." },
    published:       { label: "Publicado",   short: "Publ.",  color: "primary",   hex: "#3b5bdb", icon: "ri-checkbox-circle-line", hint: "Já está na rede." },
    analysis:        { label: "Análise",     short: "Anál.",  color: "info",      hex: "#e83e8c", icon: "ri-line-chart-line",      hint: "Resultados e notas." },
};

/** Texto legível sobre a cor da etapa (o âmbar pede texto escuro). */
export const stageTextColor = (stage: Stage) => (stage === "client_review" ? "#212529" : "#fff");

/** Etapa equivalente de um artigo do blog (canal Site), só para a cor no calendário. */
export const BLOG_STATUS_STAGE: Record<string, Stage> = { draft: "production", in_review: "client_review", approved: "scheduled", published: "published" };

export type ProductionMode = "self" | "team";
export const PRODUCTION_MODE_LABEL: Record<ProductionMode, string> = {
    self: "Produção própria",
    team: "Produção pela equipa XPLENDOR",
};

export type VersionStatus = "draft" | "sent" | "approved" | "changes_requested" | "superseded";

export const VERSION_STATUS_LABEL: Record<VersionStatus, string> = {
    draft: "Em edição",
    sent: "Enviada ao cliente",
    approved: "Aprovada",
    changes_requested: "Alterações pedidas",
    superseded: "Substituída",
};

export interface BoardPost {
    id: number;
    title: string;
    channel: PostChannel;
    publish_date: string;
    stage: Stage;
    format: string;
    media_format: string | null;
    version: { number: number; status: VersionStatus } | null;
    changes_requested: boolean;
    comments_count: number;
    blog: { id: number; status: string } | null;
    moves: Stage[];
    can_approve: boolean;
    /** F3d */
    publish_time: string | null;
    overdue: boolean;
    results: { reach: number | null; engagement_rate: number | null } | null;
}

export interface BoardData {
    month: string;
    is_approver: boolean;
    is_team: boolean;
    can_produce: boolean;
    settings: { content_approval_required: boolean; internal_review_required: boolean; production_mode: ProductionMode };
    posts: BoardPost[];
}

/** Ficheiro de media (imagem ou vídeo) com URLs assinados de curta duração, relativos à API. */
export interface MediaAssetDto {
    id: number;
    kind: "image" | "video";
    status: "processing" | "ready" | "rejected";
    error: string | null;
    original_name: string | null;
    size_bytes: number;
    width: number | null;
    height: number | null;
    duration_ms: number | null;
    codec: string | null;
    thumb_url: string | null;
    preview_url: string | null;
    poster_url: string | null;
    original_url: string | null;
    original_available: boolean;
}

export interface VersionMedia { items: MediaAssetDto[]; cover: MediaAssetDto | null }

/** Os URLs assinados vêm relativos ("/api/media/..."): junta o endereço da API. */
export const mediaSrc = (url: string | null | undefined) => (url ? (/^https?:\/\//.test(url) ? url : `${process.env.REACT_APP_PUBLIC_URL ?? ""}${url}`) : undefined);

export const fmtDuration = (ms: number | null | undefined) => {
    if (!ms) return "";
    const s = Math.round(ms / 1000);
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, "0")}`;
};

export interface GridTile { id: number; title: string; publish_date: string; stage: Stage; media_format: string | null; items_count: number; image_url: string | null }

export interface PostVersion {
    id: number;
    number: number;
    status: VersionStatus;
    frozen: boolean;
    caption: string | null;
    hashtags: string[];
    cta: string | null;
    first_comment: string | null;
    media_format: string | null;
    media: VersionMedia;
    author: string | null;
    sent_at: string | null;
    created_at: string | null;
}

export interface PostWorkflow {
    post: {
        id: number; title: string; channel: PostChannel; publish_date: string; stage: Stage; format: string;
        media_format: string | null; keyword: string | null; changes_requested_at: string | null;
        current_version_id: number | null; approved_version_id: number | null; account_name: string | null;
        publish_time: string | null; pillar: string | null; overdue: boolean;
    };
    /** F3d: publicação e resultados à mão. */
    publishing: { url: string | null; published_at: string | null; by: string | null; due_at: string; can_mark: boolean };
    results: PostResults | null;
    versions: PostVersion[];
    reviews: { id: number; version_number: number | null; decision: "approved" | "changes_requested"; via: string; reviewer: string | null; message: string | null; created_at: string | null }[];
    comments: { id: number; author: string | null; body: string; visibility: "internal" | "shared"; version_number: number | null; mine: boolean; created_at: string | null }[];
    events: { type: string; from_stage: Stage | null; to_stage: Stage | null; message: string | null; who: string | null; created_at: string | null }[];
    creative: { caption: string | null; hashtags: string[]; cta: string | null; media_format: string | null } | null;
    media_validation: { errors: string[]; warnings: string[] };
    /** etapa → motivo (null = permitido) */
    moves: Partial<Record<Stage, string | null>>;
    permissions: { can_edit_content: boolean; can_approve: boolean; is_approver: boolean; is_team: boolean; can_produce: boolean };
    settings: { content_approval_required: boolean; internal_review_required: boolean; production_mode: ProductionMode };
}

export interface WorkflowSettings {
    content_approval_required: boolean;
    internal_review_required: boolean;
    production_mode: ProductionMode;
    can_change_mode: boolean;
    can_edit: boolean;
    can_manage_approvers: boolean;
    users: { id: number; name: string; role: string; is_approver: boolean; by_role: boolean }[];
}

export const fmtDateTimePt = (iso: string | null | undefined) =>
    iso ? new Date(iso).toLocaleString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "";

// ── F3d: Publicado e Análise ─────────────────────────────────────────────────

export type MetricKey = "reach" | "interactions" | "likes" | "comments" | "saves" | "shares" | "clicks" | "video_views";
export const METRICS: { key: MetricKey; label: string }[] = [
    { key: "reach", label: "Alcance" }, { key: "interactions", label: "Interações" }, { key: "likes", label: "Gostos" },
    { key: "comments", label: "Comentários" }, { key: "saves", label: "Guardados" }, { key: "shares", label: "Partilhas" },
    { key: "clicks", label: "Cliques" }, { key: "video_views", label: "Visualizações" },
];
export const METRIC_SOURCE_LABEL: Record<string, string> = { manual: "à mão", meta: "Meta" };

export interface PostResults {
    values: Record<MetricKey, { value: number; source: string; measured_on: string } | null>;
    measured_on: string | null;
    engagement_rate: number | null;
    is_video: boolean;
    worked: string | null;
    change: string | null;
    can_record: boolean;
}

export interface TodayPost { id: number; title: string; channel: PostChannel; media_format: string | null; publish_date: string; publish_time: string | null; overdue: boolean; can_mark: boolean }
export interface TodayData { date: string; today: TodayPost[]; overdue: TodayPost[] }

export interface ResultRow {
    id: number; title: string; channel: PostChannel; stage: Stage; date: string; media_format: string | null; format: string;
    pillar: string | null; reach: number | null; interactions: number | null; engagement_rate: number | null; published_url: string | null;
}

/**
 * Taxa de envolvimento sem arredondamentos enganadores: corta (não arredonda para cima),
 * mostra mais casas quando é pequena e nunca mostra 0% quando há interações.
 */
export function fmtRate(rate: number | null | undefined): string {
    if (rate === null || rate === undefined) return "";
    if (rate === 0) return "0%";
    const cut = (v: number, d: number) => Math.floor(v * 10 ** d) / 10 ** d;
    const fmt = (v: number, d: number) => v.toLocaleString("pt-PT", { minimumFractionDigits: d, maximumFractionDigits: d });
    if (rate < 0.01) return "< 0,01%";
    if (rate < 1) return `${fmt(cut(rate, 2), 2)}%`;
    return `${fmt(cut(rate, 1), 1)}%`;
}

export const fmtInt = (n: number | null | undefined) => (n === null || n === undefined ? "" : n.toLocaleString("pt-PT"));
