/**
 * O ÚNICO sítio do ecrã que lê o papel (role) de uma pessoa. As permissões vêm do backend
 * (GET /companies/{id}/my-access, ACL: documents/ACL-DESENHO.md) e usam-se com useCan();
 * aqui ficam só as regras da plataforma (o root) e a leitura do papel de outras pessoas para
 * mostrar (por exemplo, "(Admin)" ao lado do nome). O teste roles.test.ts falha se outro
 * ficheiro comparar o papel.
 */
type WithRole = { role?: string | null; impersonating?: boolean | null } | null | undefined;

export const isRootRole = (role?: string | null): boolean => role === "root";
export const isAdminRole = (role?: string | null): boolean => role === "admin";

/** O root "de verdade": fora de uma sessão como cliente. */
export const isPlatformRoot = (user: WithRole): boolean => isRootRole(user?.role) && !user?.impersonating;

/** A pessoa autenticada, lida da sessão (null se não houver ou não se conseguir ler). */
export const sessionUser = (): { id?: number; role?: string; impersonating?: boolean; company_id?: number } | null => {
    try {
        const raw = sessionStorage.getItem("authUser");
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
};

/** A pessoa autenticada é o root fora de uma sessão como cliente. */
export const sessionIsPlatformRoot = (): boolean => isPlatformRoot(sessionUser());
