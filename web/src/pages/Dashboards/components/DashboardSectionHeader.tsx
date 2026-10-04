import type { ReactNode } from "react";
import { Card, CardBody } from "reactstrap";

/**
 * Cabeçalho de secção dos dashboards (padrão Velzon): título, subtítulo e os
 * controlos da secção dentro de um Card (fundo de cartão só à volta do cabeçalho).
 * Os cartões das métricas continuam por baixo, sobre o fundo da página (nunca
 * cartão dentro de cartão). Em mobile os controlos passam para baixo do título.
 * Dentro de um separador o título pode ser omitido (o separador já o diz).
 */
export default function DashboardSectionHeader({
    title, subtitle, children, className = "",
}: {
    title?: string;
    subtitle?: string;
    children?: ReactNode;
    className?: string;
}) {
    return (
        <Card className={`mb-3 ${className}`}>
            <CardBody className="py-3">
                <div className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        {title && <h5 className="mb-1 fw-semibold">{title}</h5>}
                        {subtitle && <p className="text-muted fs-13 mb-0">{subtitle}</p>}
                    </div>
                    {children && <div className="d-flex flex-wrap align-items-center gap-2">{children}</div>}
                </div>
            </CardBody>
        </Card>
    );
}
