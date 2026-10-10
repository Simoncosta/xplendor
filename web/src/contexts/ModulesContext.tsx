import React, { createContext, useContext, useEffect, useState } from "react";
import { getMyAccess } from "helpers/laravel_helper";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { getHomeCompanyId } from "helpers/workingCompany";
import { isRootRole, sessionUser } from "helpers/roles";

/**
 * XPLENDOR — Fonte ÚNICA (frontend) do que a pessoa pode fazer na empresa em que trabalha:
 * os módulos ATIVOS e as permissões efetivas, com o motivo de cada recusa (ACL, F4:
 * GET /companies/{id}/my-access). Consumida pelo menu, pelos guardas das rotas e pelos
 * botões (useCan). É só o ecrã: a segurança é o backend (middleware permission e
 * ensure_module), que decide com as mesmas regras.
 *
 * FALHA FECHADA: enquanto carrega, ou se o pedido falhar, nenhum módulo nem permissão conta
 * como dado (os guardas mostram "a carregar" e o menu fica só com o que é base).
 *
 * O root vê todos os módulos só na PRÓPRIA empresa. No contexto de um cliente, o ecrã segue
 * os módulos desse cliente, para o root ver como o cliente vê; o servidor continua a deixá-lo
 * passar (suporte).
 */
export interface AccessState {
    modules: string[] | null;
    /** Vê todos os módulos: o root na própria empresa (não num cliente). */
    isRoot: boolean;
    loading: boolean;
    /** O pedido falhou: tudo fechado. */
    failed: boolean;
    /** O módulo está ativo (ou é o root na própria empresa). Sem módulo = base. */
    has: (module?: string) => boolean;
    /** A pessoa tem a permissão ("area.acao"). Sem permissão = base. */
    can: (permission?: string) => boolean;
    /** O motivo da recusa (do backend), para o ReasonButton. null se pode. */
    reason: (permission: string) => string | null;
    /** Administra a própria agência (painel da agência). */
    agencyAdmin: boolean;
    /** O nome do perfil da pessoa nesta empresa. */
    profileName: string | null;
}

const LOADING_REASON = "A carregar as permissões…";

const ModulesContext = createContext<AccessState>({
    modules: null, isRoot: false, loading: true, failed: false, agencyAdmin: false, profileName: null,
    has: (m) => !m, can: (p) => !p, reason: () => LOADING_REASON,
});

/** O root na própria empresa vê todos os módulos; noutra empresa, o ecrã segue os módulos dela. */
export const seesAllModules = (role: string | undefined, workingId: number, homeId: number): boolean =>
    isRootRole(role) && (!workingId || workingId === homeId);

type Payload = {
    modules: string[];
    permissions: Record<string, boolean>;
    reasons: Record<string, string>;
    agency_admin?: boolean;
    profile?: { name: string } | null;
    agency_profile?: { name: string } | null;
};

export const ModulesProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
    const [data, setData] = useState<Payload | null>(null);
    const [isRoot, setIsRoot] = useState(false);
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);

    // A empresa em que se trabalha (contexto de trabalho): a agência vê o que a empresa gerida
    // tem ativo e o que o cliente lhe permite; muda ao trocar de empresa.
    const companyId = useWorkingCompanyId();

    useEffect(() => {
        let alive = true;
        const all = seesAllModules(sessionUser()?.role, companyId, getHomeCompanyId());
        setIsRoot(all);
        setData(null);
        setFailed(false);
        setLoading(true);
        if (!companyId) { setLoading(false); return; }

        getMyAccess(companyId)
            .then((r: any) => { if (alive) setData(r?.data ?? null); })
            .catch(() => { if (alive) { setData(null); setFailed(true); } })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [companyId]);

    const modules = data?.modules ?? null;
    const has = (module?: string): boolean => !module || isRoot || (!!modules && modules.includes(module));
    const can = (permission?: string): boolean => !permission || !!data?.permissions?.[permission];
    const reason = (permission: string): string | null => {
        if (can(permission)) return null;
        if (loading) return LOADING_REASON;
        if (!data) return "Não foi possível carregar as permissões. Atualize a página.";
        return data.reasons?.[permission] ?? "Não tem permissão para esta ação.";
    };

    return (
        <ModulesContext.Provider value={{
            modules, isRoot, loading, failed, has, can, reason,
            agencyAdmin: !!data?.agency_admin,
            profileName: data?.agency_profile?.name ?? data?.profile?.name ?? null,
        }}>
            {children}
        </ModulesContext.Provider>
    );
};

export const useModules = () => useContext(ModulesContext);
/** O mesmo contexto, com o nome do ACL. */
export const useAccess = () => useContext(ModulesContext);

/** ACL: pode fazer isto nesta empresa? Devolve [pode, motivo] (o motivo vem do backend). */
export const useCan = (permission: string): [boolean, string | null] => {
    const ctx = useContext(ModulesContext);
    return [ctx.can(permission), ctx.reason(permission)];
};
