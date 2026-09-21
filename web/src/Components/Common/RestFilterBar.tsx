import { ReactNode, useState } from "react";
import { Offcanvas, OffcanvasHeader, OffcanvasBody } from "reactstrap";
import { useIsMobile } from "../../hooks/useIsMobile";

/**
 * XPLENDOR — Barra de filtros das telas de Restauração (Artigos, Documentos, …).
 * Segue o padrão do sistema (CarList): no DESKTOP os filtros ficam numa linha
 * alinhada; abaixo do breakpoint (mobile) a pesquisa fica visível e os restantes
 * filtros passam para um OFF-CANVAS (botão "Filtros (n)"). Cores via --vz-* →
 * legível em claro e escuro. Os campos filhos devem usar `flex: 1 1 180px` para
 * alinharem no desktop e ocuparem a largura toda no off-canvas.
 */
interface RestFilterBarProps {
    search: string;
    onSearchChange: (v: string) => void;
    searchPlaceholder?: string;
    activeCount: number;
    onClear: () => void;
    /** Campos de filtro (react-select, etc.). Só aparecem no off-canvas em mobile. */
    children?: ReactNode;
}

export default function RestFilterBar({
    search,
    onSearchChange,
    searchPlaceholder = "Pesquisar…",
    activeCount,
    onClear,
    children,
}: RestFilterBarProps) {
    const isMobile = useIsMobile();
    const [open, setOpen] = useState(false);

    const searchField = (
        <div className="position-relative" style={{ flex: "1 1 220px", minWidth: 0 }}>
            <i
                className="ri-search-line position-absolute text-muted"
                style={{ top: "50%", left: 12, transform: "translateY(-50%)", pointerEvents: "none" }}
            />
            <input
                type="search"
                className="form-control"
                style={{ paddingLeft: 34 }}
                placeholder={searchPlaceholder}
                value={search}
                onChange={(e) => onSearchChange(e.target.value)}
            />
        </div>
    );

    if (isMobile) {
        return (
            <>
                <div className="d-flex align-items-center gap-2">
                    {searchField}
                    <button
                        type="button"
                        className="btn btn-soft-secondary flex-shrink-0"
                        onClick={() => setOpen(true)}
                    >
                        <i className="ri-filter-3-line me-1" />
                        {activeCount > 0 ? `Filtros (${activeCount})` : "Filtros"}
                    </button>
                </div>

                <Offcanvas isOpen={open} toggle={() => setOpen(false)} direction="end" scrollable>
                    <OffcanvasHeader toggle={() => setOpen(false)}>
                        <span className="fw-semibold">Filtros</span>
                    </OffcanvasHeader>
                    <OffcanvasBody>
                        <div className="d-flex flex-column gap-3">{children}</div>
                        {activeCount > 0 && (
                            <button
                                type="button"
                                onClick={() => { onClear(); setOpen(false); }}
                                className="btn btn-link text-decoration-none p-0 fs-13 mt-3"
                            >
                                <i className="ri-close-circle-line me-1" /> Limpar filtros
                            </button>
                        )}
                    </OffcanvasBody>
                </Offcanvas>
            </>
        );
    }

    return (
        <div className="d-flex flex-wrap align-items-end gap-2 w-100">
            {searchField}
            {children}
            {activeCount > 0 && (
                <button
                    type="button"
                    onClick={onClear}
                    className="btn btn-link text-decoration-none p-0 fs-13 flex-shrink-0"
                    style={{ paddingBottom: 8 }}
                >
                    Limpar
                </button>
            )}
        </div>
    );
}
