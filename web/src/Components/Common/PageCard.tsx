import React from "react";
import { Card, Spinner } from "reactstrap";
import InfoTip from "./InfoTip";
import Skeleton, { SkeletonVariant } from "./Skeleton";
import useDelayedFlag from "hooks/useDelayedFlag";

/**
 * O quadro de uma página (documents/design-system.md §2): o cabeçalho tem o título com o (i), o
 * ESTADO (por exemplo "Última sincronização…") por baixo do título, e as AÇÕES à direita
 * (secundárias, menu "...", e a principal no fim). Por baixo do cabeçalho, a zona de filtros;
 * depois o conteúdo (normalmente um DataTable) e o rodapé (paginação).
 * As ações nunca ficam a flutuar fora do cartão.
 *
 * Carregamento (Components/Common/Skeleton):
 *  · 1.ª carga (ainda sem dados): `loading` + `skeleton` → o corpo é o esqueleto (com as medidas
 *    do conteúdo final). Passe `skeleton` só enquanto não há dados (ex.: skeleton={data ? undefined : "chart"});
 *  · recarga (filtros, página, ordenação): `loading` sem `skeleton` → o conteúdo fica visível,
 *    esbatido, e aparece um indicador pequeno ao lado do título. Ambos só depois de ~200 ms.
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
    /** Carregamento em curso: com `skeleton` → esqueleto no corpo; sem → recarga (conteúdo esbatido + indicador no título). */
    loading?: boolean;
    /** O corpo na 1.ª carga (sem dados): uma variante do Skeleton ou um esqueleto próprio. */
    skeleton?: SkeletonVariant | React.ReactNode;
    className?: string;
    bodyClassName?: string;
    /** Sem padding no corpo (para tabelas encostadas às margens do cartão). */
    flush?: boolean;
    children?: React.ReactNode;
    "data-testid"?: string;
};

export default function PageCard({ title, info, status, actions, filters, footer, loading, skeleton, className = "mb-3", bodyClassName = "", flush = true, children, ...rest }: Props) {
    const firstLoad = !!loading && skeleton !== undefined && skeleton !== null && skeleton !== false;
    const reloading = !!loading && !firstLoad;
    const showIndicator = useDelayedFlag(reloading);
    const variants: SkeletonVariant[] = ["lines", "block", "kpi", "table", "chart", "calendar"];
    const skeletonBody = firstLoad
        ? (typeof skeleton === "string" && variants.includes(skeleton as SkeletonVariant) ? <Skeleton variant={skeleton as SkeletonVariant} /> : skeleton)
        : null;

    return (
        <Card className={`xp-page-card ${className}`} data-testid={rest["data-testid"] ?? "page-card"}>
            <div className="card-header xp-card-header">
                <div className="xp-card-heading">
                    <div className="d-flex align-items-center gap-1" style={{ minWidth: 0 }}>
                        <h5 className="card-title mb-0 text-truncate">{title}</h5>
                        {info && <InfoTip text={info} label="Sobre este quadro" />}
                        {showIndicator && <Spinner size="sm" className="ms-1" aria-label="A carregar" data-testid="card-reloading" />}
                    </div>
                    {status && <div className="xp-card-status" data-testid="card-status">{status}</div>}
                </div>
                {actions && <div className="xp-card-actions xp-no-print" data-testid="card-actions">{actions}</div>}
            </div>
            {filters && <div className="card-body border-bottom xp-card-filters xp-no-print">{filters}</div>}
            <div className={`${flush ? "" : "card-body"} ${bodyClassName} ${showIndicator ? "xp-reloading" : ""}`} aria-busy={loading ? true : undefined}>
                {firstLoad ? skeletonBody : children}
            </div>
            {footer && <div className="card-footer xp-card-footer xp-no-print">{footer}</div>}
        </Card>
    );
}
