import type { ReactNode } from "react";
import { Card, CardBody } from "reactstrap";
import InfoTip from "Components/Common/InfoTip";

/**
 * Cabeçalho de secção dos dashboards (padrão Velzon): título, subtítulo e os
 * controlos da secção dentro de um Card (fundo de cartão só à volta do cabeçalho).
 * Os cartões das métricas continuam por baixo, sobre o fundo da página (nunca
 * cartão dentro de cartão). Em mobile os controlos passam para baixo do título.
 * Dentro de um separador o título pode ser omitido (o separador já o diz).
 * UI-2d: o subtítulo (frase solta) passa ao (i) ao lado do título; os controlos são uma
 * barra de filtros (sm, design-system §7).
 */
export default function DashboardSectionHeader({
    title, subtitle, children, className = "", controlsStart = false,
}: {
    title?: string;
    subtitle?: string;
    children?: ReactNode;
    className?: string;
    /** Os controlos (filtros) por baixo do texto, alinhados à esquerda, como as barras de filtros da app. */
    controlsStart?: boolean;
}) {
    const heading = (title || subtitle) && (
        <div className="d-flex align-items-center gap-1" style={{ minWidth: 0 }}>
            {title && <h5 className="mb-0 fw-semibold">{title}</h5>}
            {subtitle && <InfoTip text={subtitle} label="Sobre esta secção" />}
        </div>
    );

    if (controlsStart) {
        return (
            <Card className={`mb-3 ${className}`}>
                <CardBody className="py-2">
                    {heading && <div className="mb-2">{heading}</div>}
                    {children && <div className="d-flex flex-wrap align-items-center justify-content-start gap-2">{children}</div>}
                </CardBody>
            </Card>
        );
    }

    return (
        <Card className={`mb-3 ${className}`}>
            <CardBody className="py-2">
                <div className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    {heading}
                    {children && <div className="d-flex flex-wrap align-items-center gap-2 ms-auto">{children}</div>}
                </div>
            </CardBody>
        </Card>
    );
}
