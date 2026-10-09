import React from "react";
import { Card, Spinner } from "reactstrap";
import InfoTip from "./InfoTip";

/**
 * O quadro de uma página (documents/design-system.md §2): o cabeçalho tem o título com o (i), o
 * ESTADO (por exemplo "Última sincronização…") por baixo do título, e as AÇÕES à direita
 * (secundárias, menu "...", e a principal no fim). Por baixo do cabeçalho, a zona de filtros;
 * depois o conteúdo (normalmente um DataTable) e o rodapé (paginação).
 * As ações nunca ficam a flutuar fora do cartão.
 */
type Props = {
    title: React.ReactNode;
    /** Explicação curta, num (i) ao lado do título. */
    info?: React.ReactNode;
    /** Estado (sincronização, contagens, gravação em curso): texto pequeno por baixo do título. */
    status?: React.ReactNode;
    /** Ações do quadro: vistas/colunas e secundárias primeiro, "..." e a principal no fim. */
    actions?: React.ReactNode;
    /** Zona de filtros, por baixo do cabeçalho. */
    filters?: React.ReactNode;
    /** Rodapé (paginação, totais). */
    footer?: React.ReactNode;
    /** Indicador de carregamento discreto ao lado do título. */
    loading?: boolean;
    className?: string;
    bodyClassName?: string;
    /** Sem padding no corpo (para tabelas encostadas às margens do cartão). */
    flush?: boolean;
    children?: React.ReactNode;
    "data-testid"?: string;
};

export default function PageCard({ title, info, status, actions, filters, footer, loading, className = "mb-3", bodyClassName = "", flush = true, children, ...rest }: Props) {
    return (
        <Card className={`xp-page-card ${className}`} data-testid={rest["data-testid"] ?? "page-card"}>
            <div className="card-header xp-card-header">
                <div className="xp-card-heading">
                    <div className="d-flex align-items-center gap-1" style={{ minWidth: 0 }}>
                        <h5 className="card-title mb-0 text-truncate">{title}</h5>
                        {info && <InfoTip text={info} label="Sobre este quadro" />}
                        {loading && <Spinner size="sm" className="ms-1" aria-label="A carregar" />}
                    </div>
                    {status && <div className="xp-card-status" data-testid="card-status">{status}</div>}
                </div>
                {actions && <div className="xp-card-actions xp-no-print" data-testid="card-actions">{actions}</div>}
            </div>
            {filters && <div className="card-body border-bottom xp-card-filters xp-no-print">{filters}</div>}
            <div className={`${flush ? "" : "card-body"} ${bodyClassName}`}>{children}</div>
            {footer && <div className="card-footer xp-card-footer xp-no-print">{footer}</div>}
        </Card>
    );
}
