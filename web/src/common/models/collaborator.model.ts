// Colaboradores (equipa) e departamentos de uma empresa. Um colaborador pode ter, ou
// não, acesso à plataforma; aparece na secção Equipa do site só se estiver ativo,
// marcado para o site e com autorização de publicação registada.
export type PhoneType = "fixed" | "mobile";
export type ContactMode = "department" | "personal";
export type AccessStatus = "none" | "invited" | "active" | "revoked";

export interface IDepartment {
    id: number;
    company_id: number;
    name: string;
    sort: number;
    active: boolean;
    whatsapp: string | null;
    phone: string | null;
    phone_type: PhoneType | null;
    email: string | null;
    collaborators_count?: number;
}

export interface ICollaborator {
    id: number;
    company_id: number;
    department_id: number | null;
    department?: { id: number; name: string } | null;
    name: string;
    role_title: string | null;
    bio: string | null;
    photo_path: string | null;
    photo_url: string | null;
    whatsapp: string | null;
    phone: string | null;
    phone_type: PhoneType | null;
    email: string | null;
    contact_mode: ContactMode;
    show_on_site: boolean;
    publish_consent_at: string | null;
    personal_contact_consent_at: string | null;
    on_site: boolean;
    sort: number;
    active: boolean;
    deactivated_at: string | null;
    access_status: AccessStatus;
    user?: { id: number; email: string; role: string; deactivated_at: string | null } | null;
    invite?: { email: string; expires_at: string } | null;
}

export const ACCESS_META: Record<AccessStatus, { label: string; color: string }> = {
    none:    { label: "Sem acesso",       color: "light" },
    invited: { label: "Convite enviado",  color: "info" },
    active:  { label: "Com acesso",       color: "success" },
    revoked: { label: "Acesso retirado",  color: "warning" },
};

export const PHONE_TYPE_LABEL: Record<PhoneType, string> = { fixed: "Fixo", mobile: "Móvel" };

const PUBLIC_URL = process.env.REACT_APP_PUBLIC_URL ?? "";
/** Foto do colaborador (400x400 WebP) pelo servidor de ficheiros, como os avatares. */
export const collaboratorPhoto = (c: Pick<ICollaborator, "photo_path">) => (c.photo_path ? `${PUBLIC_URL}/storage/${c.photo_path}` : null);

export const initials = (name: string) =>
    name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join("");
