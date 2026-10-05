/**
 * XPLENDOR — Linha Editorial (Publicações P1). Enums FIXOS espelhados do backend
 * (EditorialPost.php): FORMATS (18), STATUSES (4), CHANNELS (3, "site" liga ao blog). Mapas *_META no padrão
 * do projeto (como TASK_STATUS_META) para badges/ícones consistentes.
 */

export type PostStatus = "rascunho" | "revisao" | "publicada" | "otimizada";
export type PostChannel = "instagram" | "facebook" | "site";

export type EditorialPost = {
    id: number;
    publish_date: string;   // YYYY-MM-DD
    month_key: string;      // YYYY-MM
    title: string;
    /** Tipo de conteúdo (os 18 valores de POST_FORMATS). */
    format: string;
    /** Formato (vocabulário do publicador F2), por rede. */
    media_format: string | null;
    /** Há criativo aceite (Sugerir criativo). */
    has_creative: boolean;
    status: PostStatus;
    channel: PostChannel;
    keyword: string | null;
    anchor_id: number | null;
    own_anchor_id: number | null;
    linked_title: string | null;
    blog_id: number | null;
    // Canal "site": o artigo ligado; o estado mostrado vem dele.
    blog: { id: number; title: string; status: "draft" | "in_review" | "approved" | "published"; published_at: string | null } | null;
};

// Estados — definem o board futuro. color = variante Bootstrap (bg-*-subtle text-*).
export const EDITORIAL_POST_STATUS_META: Record<PostStatus, { label: string; color: string; icon: string }> = {
    rascunho:  { label: "Rascunho",  color: "secondary", icon: "ri-draft-line" },
    revisao:   { label: "Revisão",   color: "warning",   icon: "ri-eye-2-line" },
    publicada: { label: "Publicada", color: "success",   icon: "ri-checkbox-circle-line" },
    otimizada: { label: "Otimizada", color: "info",      icon: "ri-line-chart-line" },
};
export const POST_STATUS_ORDER: PostStatus[] = ["rascunho", "revisao", "publicada", "otimizada"];

export const POST_CHANNEL_META: Record<PostChannel, { label: string; icon: string }> = {
    instagram: { label: "Instagram", icon: "ri-instagram-line" },
    facebook:  { label: "Facebook",  icon: "ri-facebook-circle-line" },
    site:      { label: "Site (blog)", icon: "ri-article-line" },
};

/** Formato fixo do canal "site" (EditorialPost::SITE_FORMAT). */
export const SITE_FORMAT = "Artigo";

// FORMATO da publicação por rede (vocabulário do publicador F2; espelha
// EditorialPost::MEDIA_FORMATS e MEDIA_FORMAT_LABELS no backend).
export const MEDIA_FORMATS: Record<"instagram" | "facebook", { value: string; label: string }[]> = {
    instagram: [
        { value: "ig_feed_image", label: "Imagem (feed)" },
        { value: "ig_carousel", label: "Carrossel" },
        { value: "ig_reel", label: "Reel (vídeo)" },
        { value: "ig_story", label: "Story" },
    ],
    facebook: [
        { value: "fb_post", label: "Publicação (texto ou ligação)" },
        { value: "fb_photos", label: "Fotografias" },
        { value: "fb_video", label: "Vídeo" },
        { value: "fb_reel", label: "Reel" },
        { value: "fb_story", label: "Story" },
    ],
};
export const mediaFormatLabel = (key: string | null | undefined): string =>
    Object.values(MEDIA_FORMATS).flat().find((f) => f.value === key)?.label ?? (key ?? "");

// Tipos de conteúdo Insta/FB (antes chamados "formatos"; têm de coincidir EXATAMENTE com EditorialPost::FORMATS no backend).
export const POST_FORMATS: string[] = [
    "Carrossel", "Imagem única", "Reels", "Stories", "Vídeo", "Live",
    "Infográfico", "Citação", "Checklist", "Tutorial", "Antes e depois",
    "Bastidores", "Enquete/Interativo", "Depoimento", "Dica de expert",
    "Notícia", "Institucional", "Sazonal",
];
