// Redes sociais (Instagram e Facebook). Espelha GET /companies/{id}/integrations/social.

export type SocialStatus = "pending_selection" | "active" | "expired" | "permission_removed" | "not_approved" | "revoked";

export interface SocialAccount {
    id: number;
    platform: "facebook" | "instagram";
    external_id: string;
    name: string | null;
    username: string | null;
    is_primary: boolean;
    last_followers_count: number | null;
    last_read_at: string | null;
    last_error_at: string | null;
    last_error_kind: string | null;
}

export interface SocialConnectionState {
    status: SocialStatus | null;
    connected_at: string | null;
    token_expires_at: string | null;
    last_read_at: string | null;
    last_error_at: string | null;
    last_error_kind: string | null;
    accounts: SocialAccount[];
    has_automatic_history: boolean;
    can_manage: boolean;
}

export interface SocialCandidatePage {
    id: string;
    name: string;
    selected: boolean;
    is_primary: boolean;
    instagram: { id: string; username: string | null; name: string | null; selected: boolean; is_primary: boolean } | null;
}

export const SOCIAL_NOT_APPROVED_TEXT =
    "A leitura automática fica disponível depois da aprovação da Meta. Pode continuar a registar os seguidores manualmente.";

const dateTime = (iso: string) =>
    new Date(iso).toLocaleString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" });

export type SocialStateView = { tone: "success" | "warning" | "danger" | "info" | "secondary"; badge: string; text: string; reconnect: boolean };

/**
 * Estado honesto da leitura automática: "Automático"; "A última leitura falhou em …";
 * "Ligação expirada: voltar a ligar"; à espera da aprovação da Meta; por escolher.
 */
export function socialStateView(status: SocialStatus | null, lastReadAt: string | null, lastErrorAt: string | null, lastErrorKind: string | null): SocialStateView | null {
    switch (status) {
        case "pending_selection":
            return { tone: "warning", badge: "Por concluir", text: "Falta escolher as Páginas e as contas de Instagram.", reconnect: false };
        case "expired":
            return { tone: "danger", badge: "Ligação expirada", text: "Ligação expirada: voltar a ligar.", reconnect: true };
        case "permission_removed":
            return { tone: "danger", badge: "Autorização retirada", text: "A autorização foi retirada no Facebook: voltar a ligar.", reconnect: true };
        case "not_approved":
            return { tone: "info", badge: "A aguardar a Meta", text: SOCIAL_NOT_APPROVED_TEXT, reconnect: false };
        case "active":
            if (lastErrorKind && lastErrorAt) {
                return { tone: "warning", badge: "Automático", text: `A última leitura falhou em ${dateTime(lastErrorAt)}. Volta a tentar na próxima leitura diária.`, reconnect: false };
            }
            return {
                tone: "success",
                badge: "Automático",
                text: lastReadAt ? `Última leitura em ${dateTime(lastReadAt)}.` : "A aguardar a primeira leitura.",
                reconnect: false,
            };
        default:
            return null;
    }
}
