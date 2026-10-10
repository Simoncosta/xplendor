import React from "react";
import { Navigate } from "react-router-dom";
import { Spinner } from "reactstrap";
import { useModules } from "contexts/ModulesContext";

/**
 * XPLENDOR — guarda de rota por MÓDULO e, com o ACL (F4), por PERMISSÃO. Impede navegar
 * (pelo URL) para uma página cujo módulo não está ativo, ou que o perfil da pessoa não
 * permite ver → volta ao dashboard.
 *
 * FALHA FECHADA: enquanto as permissões carregam, mostra "a carregar" (nunca a página).
 * A fronteira real é o backend (middleware permission e ensure_module), que recusa com 403.
 */
const RequireModule = ({ module, permission, children }: { module?: string; permission?: string; children: React.ReactNode }) => {
    const { has, can, loading } = useModules();

    if (loading) {
        return (
            <div className="page-content d-flex justify-content-center align-items-center py-5">
                <Spinner color="primary" size="sm" className="me-2">A carregar</Spinner><span className="text-muted" aria-hidden>A carregar</span>
            </div>
        );
    }
    if (!has(module) || !can(permission)) {
        return <Navigate to="/dashboard" replace />;
    }

    return <>{children}</>;
};

export default RequireModule;
