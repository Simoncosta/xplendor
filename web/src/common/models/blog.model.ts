/**
 * Blog (Ticket 11). Estados: rascunho → em revisão → aprovado (agendado) → publicado.
 * O checklist de SEO é calculado aqui, ao vivo; no servidor só "[VERIFICAR]" bloqueia
 * a aprovação (o resto são avisos).
 */

export type BlogStatus = "draft" | "in_review" | "approved" | "published";

export interface IBlogPermissions {
    can_edit: boolean;
    can_delete: boolean;
    can_submit: boolean;
    can_approve: boolean;
    can_request_changes: boolean;
    can_unpublish: boolean;
    is_approver: boolean;
    slug_locked: boolean;
}

export interface IBlogPost {
    id: number;
    company_id: number;
    title: string;
    subtitle: string | null;
    slug: string;
    banner: string | null;
    excerpt: string | null;
    content: string;
    tags: string[];
    category: string | null;
    status: BlogStatus;
    published_at: string | null;
    first_published_at: string | null;
    read_time: number | null;
    meta_title: string | null;
    meta_description: string | null;
    focus_keyword: string | null;
    seo_answer_first_ok: boolean;
    og_image: string | null;
    review_note: string | null;
    submitted_at: string | null;
    approved_at: string | null;
    author_name: string | null;
    submitted_by_name: string | null;
    approved_by_name: string | null;
    site_url: string | null;
    /** Publicação da Linha Editorial ligada (canal "Site"). */
    editorial_post: { id: number; title: string; publish_date: string } | null;
    unresolved_markers: string[];
    permissions: IBlogPermissions | null;
    created_at: string;
    updated_at: string;
}

export interface IBlogListItem {
    id: number;
    title: string;
    slug: string;
    banner: string | null;
    excerpt: string | null;
    status: BlogStatus;
    published_at: string | null;
    focus_keyword: string | null;
    submitted_at: string | null;
    has_review_note: boolean;
    author_name: string | null;
    updated_at: string;
}

export const BLOG_STATUS_META: Record<BlogStatus, { label: string; color: string; icon: string }> = {
    draft:     { label: "Rascunho",   color: "secondary", icon: "ri-draft-line" },
    in_review: { label: "Em revisão", color: "warning",   icon: "ri-eye-2-line" },
    approved:  { label: "Agendado",   color: "info",      icon: "ri-calendar-check-line" },
    published: { label: "Publicado",  color: "success",   icon: "ri-checkbox-circle-line" },
};
export const BLOG_STATUS_ORDER: BlogStatus[] = ["draft", "in_review", "approved", "published"];

export const blogImage = (path: string | null | undefined) =>
    path ? (path.startsWith("http") ? path : (process.env.REACT_APP_PUBLIC_URL || "") + path) : null;

export const fmtDateTime = (iso: string | null | undefined) =>
    iso ? new Date(iso).toLocaleString("pt-PT", { day: "numeric", month: "long", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "";

/** Slug como o servidor o gera (minúsculas, sem acentos, hífenes). */
export const slugify = (s: string) =>
    s.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 180);

/** Texto simples a partir do HTML do editor. */
export const plainText = (html: string | null | undefined) => {
    if (!html) return "";
    const el = document.createElement("div");
    el.innerHTML = html.replace(/<\s*(br|\/p|\/li|\/h[1-6])\b[^>]*>/gi, " ");
    return (el.textContent || "").replace(/\s+/g, " ").trim();
};

// Palavras com acentos ("Ação", "pós-venda"); construtor para o alvo ES5 do tsconfig aceitar a flag "u".
const WORD_RE = new RegExp("[\\p{L}\\p{N}]+(?:['’-][\\p{L}\\p{N}]+)*", "gu");
export const wordCount = (html: string | null | undefined) => (plainText(html).match(WORD_RE) || []).length;

const norm = (s: string) => s.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
const firstParagraph = (html: string) => {
    const m = (html || "").match(/<p[^>]*>([\s\S]*?)<\/p>/i);
    return plainText(m ? m[1] : html).slice(0, 600);
};

export const SEO_LIMITS = { titleMin: 30, titleMax: 60, descMin: 70, descMax: 155, minWords: 300 };

export type SeoCheck = { key: string; label: string; ok: boolean; blocking?: boolean; manual?: boolean; hint?: string };

export interface SeoInput {
    title: string; meta_title: string; meta_description: string; excerpt: string; focus_keyword: string;
    slug: string; content: string; subtitle: string; hasBanner: boolean; seo_answer_first_ok: boolean;
}

export const hasMarker = (s: string | null | undefined) => /\[\s*verificar/i.test(plainText(s || ""));

export const seoChecklist = (v: SeoInput): SeoCheck[] => {
    const title = (v.meta_title || v.title || "").trim();
    const desc = (v.meta_description || "").trim();
    const kw = norm(v.focus_keyword.trim());
    const has = (s: string) => !!kw && norm(s).includes(kw);
    const kwSlug = slugify(v.focus_keyword);
    const markers = [v.title, v.subtitle, v.excerpt, v.content, v.meta_title, v.meta_description].some(hasMarker);
    const words = wordCount(v.content);

    return [
        { key: "markers", label: "Sem \"[VERIFICAR]\" por resolver", ok: !markers, blocking: true, hint: "Bloqueia a aprovação." },
        { key: "title", label: `Título SEO entre ${SEO_LIMITS.titleMin} e ${SEO_LIMITS.titleMax} caracteres (${title.length})`, ok: title.length >= SEO_LIMITS.titleMin && title.length <= SEO_LIMITS.titleMax },
        { key: "desc", label: `Meta description entre ${SEO_LIMITS.descMin} e ${SEO_LIMITS.descMax} caracteres (${desc.length})`, ok: desc.length >= SEO_LIMITS.descMin && desc.length <= SEO_LIMITS.descMax },
        { key: "kw", label: "Palavra-chave principal definida", ok: !!kw },
        { key: "kw_title", label: "Palavra-chave no título", ok: has(title) },
        { key: "kw_desc", label: "Palavra-chave na meta description", ok: has(desc) },
        { key: "kw_first", label: "Palavra-chave no primeiro parágrafo", ok: has(firstParagraph(v.content)) },
        { key: "kw_slug", label: "Palavra-chave no endereço (slug)", ok: !!kwSlug && v.slug.includes(kwSlug) },
        { key: "banner", label: "Imagem de destaque (banner)", ok: v.hasBanner },
        { key: "h2", label: "Pelo menos um subtítulo (H2) no texto", ok: /<h[23][\s>]/i.test(v.content || "") },
        { key: "words", label: `Pelo menos ${SEO_LIMITS.minWords} palavras (${words})`, ok: words >= SEO_LIMITS.minWords },
        { key: "answer_first", label: "A primeira frase responde diretamente à pergunta", ok: v.seo_answer_first_ok, manual: true, hint: "Confirmação manual." },
    ];
};

export type AudienceSource = { usable: boolean; reason: string; volume?: number; minimum?: number; age: { label: string; pct: number }[]; gender: { label: string; pct: number }[] };
export interface IBlogAiContext {
    used: number;
    cap: number;
    has_brand_profile: boolean;
    sector: string | null;
    audience: { sources: Record<"ga4" | "meta" | "sales", AudienceSource>; has_data: boolean; warning: string | null };
}
export interface IBlogAiResult {
    title: string; slug: string; meta_title: string; meta_description: string; excerpt: string; content: string;
    review_notes: string[]; has_markers: boolean;
}
export interface IBlogAiDraft {
    id: number; mode: "topic" | "from_post"; status: "queued" | "processing" | "done" | "error";
    result: IBlogAiResult | null; audience_warning: string | null; error_message: string | null; used: number; cap: number;
    /** Pedido parado há mais de 3 minutos (com a frase a mostrar). */
    stalled?: boolean; stalled_message?: string | null;
    blog_id?: number | null;
    input?: { topic?: string; source_text?: string; keyword?: string; secondary_keywords?: string[]; notes?: string };
}

export const AUDIENCE_REASON: Record<string, string> = {
    ok: "com dados",
    not_connected: "não ligado",
    thresholded: "escondido pelo Google por volume baixo",
    no_data: "sem dados",
    below_minimum: "abaixo do mínimo",
    error: "indisponível",
};

export type EmojiPolicy = "none" | "light" | "free";

export interface BrandPillar {
    name: string;
    description: string | null;
}

/** Perfil da Marca (campos com os nomes previstos para brand_profiles na F1). */
export interface IBrandProfile {
    tone_of_voice: string | null;
    audience: string | null;
    words_to_use: string[];
    words_to_avoid: string[];
    topics_to_avoid: string[];
    pillars: BrandPillar[];
    hashtags_default: string[];
    cta_default: string | null;
    emoji_policy: EmojiPolicy | null;
    notes: string | null;
    language: string;
    updated_at: string | null;
    is_empty?: boolean;
    can_edit?: boolean;
}
