import { toast } from "react-toastify";
import { Table } from "reactstrap";
import type { MarketComparable } from "../../../../../types/api";
import { labelOf, MARKET_SOURCE_LABELS } from "../../../../../helpers/labels";

// MS1.e (2026-06-10) — o ↗ abre o anúncio individual directamente em nova aba.
// Comportamento:
//   - url do comparável não vazio → abre directamente. Sem pré-flight.
//   - url vazio (snapshot legacy) → abre searchUrl + toast informativo.
// (Polish Fase B) — apresentação migrada de linhas à mão para uma Table Velzon
// standard; a lógica de clique/fallback mantém-se.

interface Props {
    comparables: MarketComparable[];
    effectivePrice: number | null;
    searchUrl: string | null;
}

const FUEL_LABELS: Record<string, string> = {
    gasoline:      "Gasolina",
    diesel:        "Diesel",
    electric:      "Elétrico",
    hybrid:        "Híbrido",
    plugin_hybrid: "Plug-in",
    lpg:           "GPL",
    cng:           "GNV",
};

const GEARBOX_LABELS: Record<string, string> = {
    manual:         "Manual",
    automatic:      "Auto",
    semi_automatic: "Semi-auto",
};

function formatCurrency(value: number): string {
    return new Intl.NumberFormat("pt-PT", {
        style: "currency",
        currency: "EUR",
        maximumFractionDigits: 0,
    }).format(value);
}

function openSearchFallback(searchUrl: string | null): void {
    const target = searchUrl ?? "https://www.standvirtual.com/";
    window.open(target, "_blank", "noopener,noreferrer");
    toast.info(
        "Sem link direto para este anúncio. Abrimos uma pesquisa por viaturas similares no Standvirtual.",
        { autoClose: 6000 }
    );
}

export default function ComparablesList({
    comparables,
    effectivePrice,
    searchUrl,
}: Props) {
    if (comparables.length === 0) {
        return (
            <p className="text-muted fs-13 mb-0 mt-3">
                Sem comparáveis disponíveis para apresentar.
            </p>
        );
    }

    return (
        <div className="table-responsive mt-3">
            <Table className="table-sm align-middle mb-0">
                <thead className="text-muted">
                    <tr>
                        <th scope="col">Anúncio</th>
                        <th scope="col" className="text-end">Preço</th>
                        <th scope="col" className="text-end">vs o seu preço</th>
                    </tr>
                </thead>
                <tbody>
                    {comparables.map((item, i) => {
                        const sourceLabel = labelOf(item.source, MARKET_SOURCE_LABELS);
                        const chips = [
                            item.fuel    ? (FUEL_LABELS[item.fuel]       ?? item.fuel)    : null,
                            item.gearbox ? (GEARBOX_LABELS[item.gearbox] ?? item.gearbox) : null,
                            item.region  ?? null,
                            item.year    ? String(item.year) : null,
                            sourceLabel,
                        ].filter(Boolean).join(" · ");

                        const diffPct = effectivePrice && effectivePrice > 0
                            ? ((item.price - effectivePrice) / effectivePrice) * 100
                            : null;

                        const hasUrl = !!item.url;

                        return (
                            <tr key={i}>
                                <td>
                                    <div className="d-flex align-items-center gap-2">
                                        <span className="fw-semibold text-body text-truncate" style={{ maxWidth: 280 }} title={item.title}>
                                            {item.title}
                                        </span>
                                        {hasUrl ? (
                                            <a
                                                href={item.url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-muted flex-shrink-0"
                                                aria-label="Ver anúncio"
                                            >
                                                <i className="ri-external-link-line" />
                                            </a>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={() => openSearchFallback(searchUrl)}
                                                className="btn btn-link p-0 text-muted flex-shrink-0 lh-1"
                                                aria-label="Pesquisar viaturas similares no Standvirtual"
                                            >
                                                <i className="ri-external-link-line" />
                                            </button>
                                        )}
                                    </div>
                                    {chips && <span className="text-muted fs-12">{chips}</span>}
                                </td>
                                <td className="text-end fw-semibold">{formatCurrency(item.price)}</td>
                                <td className="text-end">
                                    {diffPct !== null ? (
                                        <span className={`badge ${diffPct > 0 ? "bg-danger-subtle text-danger" : "bg-success-subtle text-success"}`}>
                                            {diffPct > 0 ? "+" : ""}{diffPct.toFixed(1)}%
                                        </span>
                                    ) : (
                                        <span className="text-muted">—</span>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </Table>
        </div>
    );
}
