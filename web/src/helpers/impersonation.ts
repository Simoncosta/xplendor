import { startImpersonation, stopImpersonation, getCurrentImpersonation } from "./laravel_helper";

/**
 * XPLENDOR — IMPERSONATION (frontend). O authUser é SÓ o que a UI mostra; a segurança é o
 * token/backend. Guardamos o authUser ROOT em `rootAuthUser` para voltar. A VERDADE é do
 * backend (getCurrentImpersonation) — o reconcile alinha o sessionStorage pelo backend.
 */

const AUTH = "authUser";
const ROOT = "rootAuthUser";
// Base da app (basename do CRA = PUBLIC_URL, ex.: "/app"). Os redirects abaixo usam
// window.location (fora do react-router → sem basename automático) → prefixar à mão.
const BASE = process.env.PUBLIC_URL || "";

export type AuthUser = any;

export const readAuthUser = (): AuthUser | null => {
    try { const r = sessionStorage.getItem(AUTH); return r ? JSON.parse(r) : null; } catch { return null; }
};
const writeAuthUser = (u: AuthUser) => { try { sessionStorage.setItem(AUTH, JSON.stringify(u)); } catch { /* ignore */ } };

export const isImpersonating = (): boolean => !!readAuthUser()?.impersonating;

/**
 * INICIAR: chama o backend (só passa se for root), guarda o root de lado, põe o authUser do
 * impersonado (token de impersonation) e recarrega → o ModulesContext re-inicializa com B.
 */
export async function startImpersonationFlow(userId: number, reason?: string): Promise<void> {
    const r: any = await startImpersonation(userId, reason);
    const d = r?.data ?? {};
    const current = readAuthUser();

    // Guarda o root só se ainda não estivermos já em impersonation (evita perder o root real).
    if (current && !current.impersonating) {
        try { sessionStorage.setItem(ROOT, JSON.stringify(current)); } catch { /* ignore */ }
    }

    writeAuthUser({
        token: d.token,
        id: d.user?.id,
        name: d.user?.name,
        email: d.user?.email,
        role: d.user?.role,
        company_id: d.user?.company_id,
        avatar: d.user?.avatar,
        impersonating: true,
        impersonator: d.impersonator ?? null,
        impersonation_company: d.company ?? null,
        impersonation_expires_at: d.expires_at ?? null,
    });

    window.location.assign(BASE + "/dashboard");
}

/** SAIR: termina no backend, restaura o root e recarrega. Robusto mesmo se o stop falhar. */
export async function stopImpersonationFlow(): Promise<void> {
    try { await stopImpersonation(); } catch { /* mesmo que falhe, restauramos o root localmente */ }
    restoreRootLocally();
    window.location.assign(BASE + "/dashboard");
}

/** Restaura o authUser do root (do rootAuthUser) e limpa o estado de impersonation. */
function restoreRootLocally(): void {
    try {
        const root = sessionStorage.getItem(ROOT);
        if (root) {
            sessionStorage.setItem(AUTH, root);
            sessionStorage.removeItem(ROOT);
        } else {
            // Sem root guardado: limpa a marca de impersonation do authUser atual.
            const u = readAuthUser();
            if (u?.impersonating) {
                delete u.impersonating; delete u.impersonator; delete u.impersonation_company; delete u.impersonation_expires_at;
                writeAuthUser(u);
            }
        }
    } catch { /* ignore */ }
}

/**
 * RECONCILIAÇÃO pelo backend (verdade). Chamado ao carregar o Layout. Se o backend disser
 * "sem sessão ativa" mas o frontend achar que está em impersonation (token expirou/sessão
 * terminada noutro lado) → restaura o root e recarrega. Nunca confia só no sessionStorage.
 * Devolve true se reconciliou (a app vai recarregar).
 */
export async function reconcileImpersonation(): Promise<boolean> {
    const local = isImpersonating();
    let backendActive = false;
    try {
        const r: any = await getCurrentImpersonation();
        backendActive = !!r?.data?.impersonating;
    } catch {
        // 401/erro → o token de impersonation já não é válido → tratar como sem sessão.
        backendActive = false;
    }

    if (local && !backendActive) {
        restoreRootLocally();
        window.location.assign(BASE + "/dashboard");
        return true;
    }
    return false;
}
