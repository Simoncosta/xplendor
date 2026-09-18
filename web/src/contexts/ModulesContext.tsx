import React, { createContext, useContext, useEffect, useState } from "react";
import { getMyModules } from "helpers/laravel_helper";

/**
 * XPLENDOR — Fonte ÚNICA (frontend) dos módulos ATIVOS da empresa do utilizador.
 * Consumida pelo menu (esconder — Fase 2) E pelo guard de rotas (Fase 3), para
 * menu e rotas ficarem alinhados. É só UX/conveniência — a segurança real é o
 * middleware EnsureModuleActive no backend.
 *
 * `modules === null` = ainda não sabido / root / falha → tratar como "vê tudo".
 */
interface ModulesState {
    modules: string[] | null;
    isRoot: boolean;
    loading: boolean;
    /** true se o módulo está ativo OU se ainda não sabemos/root (fail-open UX). */
    has: (module?: string) => boolean;
}

const ModulesContext = createContext<ModulesState>({
    modules: null, isRoot: false, loading: true, has: () => true,
});

export const ModulesProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
    const [modules, setModules] = useState<string[] | null>(null);
    const [isRoot, setIsRoot] = useState(false);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let alive = true;
        let companyId = 0; let root = false;
        try {
            const raw = sessionStorage.getItem("authUser");
            if (raw) { const o = JSON.parse(raw); companyId = Number(o.company_id || 0); root = o.role === "root"; }
        } catch { /* ignore */ }
        setIsRoot(root);

        if (root || !companyId) { setLoading(false); return; } // root vê tudo

        getMyModules(companyId)
            .then((r: any) => { if (alive) setModules(r?.data?.modules ?? null); })
            .catch(() => { if (alive) setModules(null); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, []);

    const has = (module?: string): boolean =>
        !module || isRoot || modules === null || modules.includes(module);

    return (
        <ModulesContext.Provider value={{ modules, isRoot, loading, has }}>
            {children}
        </ModulesContext.Provider>
    );
};

export const useModules = () => useContext(ModulesContext);
