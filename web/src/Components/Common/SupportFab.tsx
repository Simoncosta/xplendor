import React from "react";
import { Link } from "react-router-dom";
import { useModules } from "contexts/ModulesContext";

/**
 * DMS — Botão flutuante de acesso ao Suporte. Sempre visível no painel
 * autenticado (montado no Layout). Navega para a tela de suporte (/support).
 *
 * Posição: canto inferior direito. Convive com futuros FABs (ex.: WhatsApp) e
 * com o back-to-top do Velzon (bottom:100px, atualmente desmontado): se um FAB
 * de WhatsApp for adicionado, empilhar acima deste (ex.: bottom: 92px). z-index
 * abaixo dos modais (1050) para não os tapar.
 *
 * ACL (F4): só aparece com o módulo "Suporte / Tarefas" ativo e a permissão suporte.ver
 * (o backend recusa as rotas do suporte sem eles, decisão D8).
 */
const SupportFab = () => {
    const { has, can } = useModules();
    if (!has("support_tasks") || !can("suporte.ver")) return null;

    return (
        <Link
            to="/support"
            title="Suporte"
            aria-label="Suporte"
            style={{
                position: "fixed",
                right: 24,
                bottom: 24,
                width: 52,
                height: 52,
                borderRadius: "50%",
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
                background: "var(--vz-primary, var(--vz-primary))",
                color: "#fff",
                boxShadow: "0 8px 24px rgba(64,81,137,.4)",
                zIndex: 1030,
                fontSize: 24,
                textDecoration: "none",
            }}
        >
            <i className="ri-customer-service-2-line" />
        </Link>
    );
};

export default SupportFab;
