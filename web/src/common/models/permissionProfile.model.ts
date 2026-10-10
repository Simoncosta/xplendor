/** ACL (F5): os perfis de permissão (GET /companies/{id}/permission-profiles). */
export type ProfileSide = "cliente" | "agencia" | "teto";

export interface AreaSummary { area: string; label: string; text: string; actions: string[]; admin_only?: boolean }

export interface PermissionProfile {
    id: number;
    name: string;
    description: string | null;
    side: ProfileSide;
    is_system: boolean;
    is_suggestion: boolean;
    is_admin: boolean;
    editable: boolean;
    assignable: boolean;
    only_assigned_clients: boolean;
    users: number;
    permissions: string[];
    summary: AreaSummary[];
}

export interface CatalogArea { area: string; label: string; module: string | null; actions: string[] }

export interface ProfilesPayload {
    catalog: CatalogArea[];
    allowed: Record<ProfileSide, string[]>;
    /** Só o perfil Administrador (a faturação da XPLENDOR): aparecem bloqueadas nos outros perfis. */
    admin_only?: string[];
    profiles: PermissionProfile[];
    users: { id: number; name: string; email: string; is_admin: boolean; profile_id: number | null; agency_profile_id: number | null; approver: boolean; active: boolean }[];
    is_agency: boolean;
    management: { agency: string; guest_profile_id: number | null } | null;
    can_manage: boolean;
    can_set_ceiling: boolean;
}

export interface ProfilePreview {
    summary: AreaSummary[];
    effective: string[];
    ignored: string[];
    inactive_modules: string[];
    note: string | null;
}

export const ACTION_LABEL: Record<string, string> = {
    ver: "Ver", criar: "Criar", editar: "Editar", aprovar: "Aprovar", apagar: "Apagar", configurar: "Configurar",
};

export const SIDE_LABEL: Record<ProfileSide, string> = {
    cliente: "Pessoas da empresa",
    agencia: "Pessoas da agência, dentro dos clientes",
    teto: "Teto da agência gestora",
};
