/**
 * Contexto de trabalho: a empresa em que a pessoa está a trabalhar neste separador.
 * É o ÚNICO sítio que lê authUser.company_id (a empresa da própria pessoa). Todas as
 * páginas e pedidos usam getWorkingCompanyId() / useWorkingCompanyId(), para nada se
 * gravar na empresa errada quando a agência (ou o root) trabalha num cliente.
 * Guarda-se no sessionStorage (por separador) e só vale para a pessoa que o escolheu.
 */

const KEY = "xp-working-company";
export const WORKING_COMPANY_EVENT = "xp-working-company";

export type WorkingCompany = { id: number; name: string };

const readAuth = (): any => {
    try { const r = sessionStorage.getItem("authUser"); return r ? JSON.parse(r) : null; } catch { return null; }
};

/** A empresa da própria pessoa (a "casa"). */
export const getHomeCompanyId = (): number => Number(readAuth()?.company_id || 0);

/** A empresa escolhida no seletor (null = a própria). */
export const getWorkingCompany = (): WorkingCompany | null => {
    try {
        const raw = sessionStorage.getItem(KEY);
        const v = raw ? JSON.parse(raw) : null;
        const auth = readAuth();
        if (!v || !auth || Number(v.userId) !== Number(auth.id) || Number(v.id) === getHomeCompanyId()) return null;
        return { id: Number(v.id), name: String(v.name ?? "") };
    } catch { return null; }
};

/** A empresa em que a pessoa está a trabalhar (a escolhida, ou a própria). */
export const getWorkingCompanyId = (): number => getWorkingCompany()?.id ?? getHomeCompanyId();

/** Muda o contexto (null = voltar à própria empresa) e avisa a aplicação. */
export const setWorkingCompany = (c: WorkingCompany | null): void => {
    try {
        const auth = readAuth();
        if (c && auth && c.id !== getHomeCompanyId()) sessionStorage.setItem(KEY, JSON.stringify({ ...c, userId: auth.id }));
        else sessionStorage.removeItem(KEY);
    } catch { /* sem armazenamento: fica na própria empresa */ }
    window.dispatchEvent(new Event(WORKING_COMPANY_EVENT));
};

export const clearWorkingCompany = (): void => {
    try { sessionStorage.removeItem(KEY); } catch { /* ignore */ }
};
