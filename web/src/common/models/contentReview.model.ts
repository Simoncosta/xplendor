import type { PostChannel } from "./editorialPost.model";
import type { MediaAssetDto } from "./editorialWorkflow.model";

/** Link de aprovação por lote (F3c): página pública (/aprovar#token) e "Ver como o cliente". */

export type ReviewLinkState = "open" | "expired" | "revoked";
export type ReviewItemState = "pending" | "approved" | "changes_requested" | "outdated";

export interface ReviewAccount { name: string | null; username: string | null; avatar_url: string | null }

export interface ReviewItem {
    id: number;
    post_id: number;
    title: string;
    channel: PostChannel;
    media_format: string | null;
    publish_date: string | null;
    version_number: number | null;
    caption: string;
    hashtags: string[];
    cta: string | null;
    first_comment: string | null;
    media: { items: MediaAssetDto[]; cover: MediaAssetDto | null };
    media_available: boolean;
    state: ReviewItemState;
    decision: { decision: "approved" | "changes_requested"; reviewer_name: string | null; message: string | null; via: string; created_at: string | null } | null;
    can_act: boolean;
    comments: { id: number; author: string | null; body: string; from_link: boolean; version_number: number | null; created_at: string | null }[];
}

export interface ReviewPayload {
    preview: boolean;
    state: ReviewLinkState;
    state_message: string | null;
    can_act: boolean;
    title: string;
    recipient_name: string | null;
    expires_at: string;
    company: { name: string; logo_url: string | null };
    accounts: { instagram: ReviewAccount | null; facebook: ReviewAccount | null };
    counts: Record<ReviewItemState, number>;
    items: ReviewItem[];
}

export interface ReviewLinkSummary {
    id: number;
    title: string;
    state: ReviewLinkState;
    recipient_name: string | null;
    recipient_email: string | null;
    url: string | null;
    share_message: string | null;
    created_at: string | null;
    last_sent_at: string | null;
    expires_at: string;
    revoked_at: string | null;
    opens: { count: number; first_at: string | null; last_at: string | null };
    counts: Record<ReviewItemState, number>;
    items: { id: number; post_id: number; title: string; channel: PostChannel; publish_date: string | null; state: ReviewItemState }[];
}

export interface ReviewCandidate {
    id: number;
    title: string;
    channel: PostChannel;
    publish_date: string;
    media_format: string | null;
    version_number: number | null;
    thumb_url: string | null;
    in_links: { id: number; title: string }[];
}

export const ITEM_STATE_META: Record<ReviewItemState, { label: string; color: string; icon: string }> = {
    pending: { label: "À espera da sua decisão", color: "warning", icon: "ri-time-line" },
    approved: { label: "Aprovada", color: "success", icon: "ri-checkbox-circle-line" },
    changes_requested: { label: "Alterações pedidas", color: "danger", icon: "ri-chat-1-line" },
    outdated: { label: "Atualizada pela equipa", color: "secondary", icon: "ri-refresh-line" },
};

export const LINK_STATE_META: Record<ReviewLinkState, { label: string; color: string }> = {
    open: { label: "Válido", color: "success" },
    expired: { label: "Expirado", color: "secondary" },
    revoked: { label: "Revogado", color: "danger" },
};

/** "https://wa.me/?text=…" com a mensagem e o link. */
export const whatsappUrl = (message: string) => `https://wa.me/?text=${encodeURIComponent(message)}`;
