import type { ReactNode } from "react";

/**
 * Cabeçalho de secção dos dashboards (padrão Velzon): título e subtítulo
 * diretamente sobre o fundo da página, sem cartão à volta, com os controlos da
 * secção à direita (perto do conteúdo que controlam). Em mobile os controlos
 * passam para baixo do título. Dentro de um separador o título pode ser omitido
 * (o próprio separador já o diz): fica só a descrição e os controlos.
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
        <div className={`d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3 ${className}`}>
            <div>
                {title && <h5 className="mb-1 fw-semibold">{title}</h5>}
                {subtitle && <p className="text-muted fs-13 mb-0">{subtitle}</p>}
            </div>
            {children && <div className="d-flex flex-wrap align-items-end gap-2">{children}</div>}
        </div>
    );
}
