import React, { createContext, useContext, useEffect, useState } from "react";
import { getMyModules } from "helpers/laravel_helper";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { getHomeCompanyId } from "helpers/workingCompany";

/**
 * XPLENDOR — Fonte ÚNICA (frontend) dos módulos ATIVOS da empresa em que se trabalha.
 * Consumida pelo menu (esconder — Fase 2) E pelo guard de rotas (Fase 3), para
 * menu e rotas ficarem alinhados. É só UX/conveniência — a segurança real é o
 * middleware EnsureModuleActive no backend.
 *
 * `modules === null` = ainda não sabido / root / falha → tratar como "vê tudo".
 *
 * O root vê tudo só na PRÓPRIA empresa. No contexto de um cliente, o ecrã (menu, dashboard,
 * rotas) segue os módulos desse cliente, para o root ver como o cliente vê; o servidor
 * continua a deixá-lo passar (suporte).
 */
interface ModulesState {
    modules: string[] | null;
    /** Vê tudo: o root na própria empresa (não num cliente). */
    isRoot: boolean;
    loading: boolean;
    /** true se o módulo está ativo OU se ainda não sabemos/root (fail-open UX). */
    has: (module?: string) => boolean;
}

const ModulesContext = createContext<ModulesState>({
    modules: null, isRoot: false, loading: true, has: () => true,
});

/** O root na própria empresa vê tudo; noutra empresa, o ecrã segue os módulos dela. */
export const seesAllModules = (role: string | undefined, workingId: number, homeId: number): boolean =>
    role === "root" && (!workingId || workingId === homeId);

export const ModulesProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
    const [modules, setModules] = useState<string[] | null>(null);
    const [isRoot, setIsRoot] = useState(false);
    const [loading, setLoading] = useState(true);

    // Os módulos da empresa em que se trabalha (contexto de trabalho): a agência vê o que a
    // empresa gerida tem ativo; muda ao trocar de empresa.
    const companyId = useWorkingCompanyId();

    useEffect(() => {
        let alive = true;
        let role: string | undefined;
        try {
            const raw = sessionStorage.getItem("authUser");
            if (raw) role = JSON.parse(raw).role;
        } catch { /* ignore */ }
        const all = seesAllModules(role, companyId, getHomeCompanyId());
        setIsRoot(all);
        setModules(null);
        setLoading(true);

        if (all || !companyId) { setLoading(false); return; } // root na própria empresa vê tudo

        getMyModules(companyId)
            .then((r: any) => { if (alive) setModules(r?.data?.modules ?? null); })
            .catch(() => { if (alive) setModules(null); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [companyId]);

    const has = (module?: string): boolean =>
        !module || isRoot || modules === null || modules.includes(module);

    return (
        <ModulesContext.Provider value={{ modules, isRoot, loading, has }}>
            {children}
        </ModulesContext.Provider>
    );
};

export const useModules = () => useContext(ModulesContext);
