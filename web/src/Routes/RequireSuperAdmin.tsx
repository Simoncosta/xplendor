import React from "react";
import { Navigate } from "react-router-dom";

/**
 * DMS — Guard de rota do super-admin (role 'root').
 *
 * ⚠️ Isto é conveniência de UX (esconde/redireciona no cliente). A fronteira de
 * segurança REAL é o middleware EnsureSuperAdmin no backend — o frontend nunca
 * é a barreira. Redireciona não-root para o dashboard.
 */
const RequireSuperAdmin = ({ children }: { children: React.ReactNode }) => {
    let role: string | null = null;
    try {
        const raw = sessionStorage.getItem("authUser");
        role = raw ? JSON.parse(raw)?.role ?? null : null;
    } catch {
        role = null;
    }

    if (role !== "root") {
        return <Navigate to="/dashboard" replace />;
    }
    return <>{children}</>;
};

export default RequireSuperAdmin;
