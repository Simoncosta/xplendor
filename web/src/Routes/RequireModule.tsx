import React from "react";
import { Navigate } from "react-router-dom";
import { useModules } from "contexts/ModulesContext";

/**
 * XPLENDOR — Fase 3: guard de rota por MÓDULO. Impede navegar (pelo URL) para uma
 * página cujo módulo não está ativo na empresa → redireciona para o dashboard.
 *
 * ⚠️ É conveniência de UX. A fronteira REAL é o middleware EnsureModuleActive no
 * backend (que devolve 403). Enquanto os módulos ainda carregam, deixa passar
 * (fail-open) — o backend recusa na mesma se não for permitido.
 */
const RequireModule = ({ module, children }: { module: string; children: React.ReactNode }) => {
    const { has, loading, modules, isRoot } = useModules();

    // Ainda a carregar (e não é root nem já temos os módulos) → não redireciona já.
    if (loading && modules === null && !isRoot) {
        return <>{children}</>;
    }

    if (!has(module)) {
        return <Navigate to="/dashboard" replace />;
    }

    return <>{children}</>;
};

export default RequireModule;
