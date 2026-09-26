/**
 * XPLENDOR — Linha Editorial (Publicações P1). Enums FIXOS espelhados do backend
 * (EditorialPost.php): FORMATS (18), STATUSES (4), CHANNELS (2). Mapas *_META no padrão
 * do projeto (como TASK_STATUS_META) para badges/ícones consistentes.
 */

export type PostStatus = "rascunho" | "revisao" | "publicada" | "otimizada";
export type PostChannel = "instagram" | "facebook";

export type EditorialPost = {
    id: number;
    publish_date: string;   // YYYY-MM-DD
    month_key: string;      // YYYY-MM
    title: string;
    format: string;
    status: PostStatus;
    channel: PostChannel;
    keyword: string | null;
    anchor_id: number | null;
    own_anchor_id: number | null;
    linked_title: string | null;
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
};

// Formatos Insta/FB (têm de coincidir EXATAMENTE com EditorialPost::FORMATS no backend).
export const POST_FORMATS: string[] = [
    "Carrossel", "Imagem única", "Reels", "Stories", "Vídeo", "Live",
    "Infográfico", "Citação", "Checklist", "Tutorial", "Antes e depois",
    "Bastidores", "Enquete/Interativo", "Depoimento", "Dica de expert",
    "Notícia", "Institucional", "Sazonal",
];
