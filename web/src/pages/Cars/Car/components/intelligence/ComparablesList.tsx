import type { MarketComparable } from "../../../../../types/api";

interface Props {
    comparables: MarketComparable[];
    effectivePrice: number | null;
}

const FUEL_LABELS: Record<string, string> = {
    gasoline:     "Gasolina",
    diesel:       "Diesel",
    electric:     "Elétrico",
    hybrid:       "Híbrido",
    plugin_hybrid:"Plug-in",
    lpg:          "GPL",
    cng:          "GNV",
};

const GEARBOX_LABELS: Record<string, string> = {
    manual:        "Manual",
    automatic:     "Auto",
    semi_automatic:"Semi-auto",
};

function formatCurrency(value: number): string {
    return new Intl.NumberFormat("pt-PT", {
        style: "currency",
        currency: "EUR",
        maximumFractionDigits: 0,
    }).format(value);
}

export default function ComparablesList({ comparables, effectivePrice }: Props) {
    if (comparables.length === 0) {
        return (
            <p className="text-muted fs-13 mb-0 mt-2">
                Sem comparáveis disponíveis para apresentar.
            </p>
        );
    }

    return (
        <div className="mt-3" style={{ borderTop: "1px solid #eef0f2", paddingTop: 12 }}>
            <div className="d-flex flex-column gap-2">
                {comparables.map((item, i) => {
                    const chips = [
                        item.fuel    ? (FUEL_LABELS[item.fuel]    ?? item.fuel)    : null,
                        item.gearbox ? (GEARBOX_LABELS[item.gearbox] ?? item.gearbox) : null,
                        item.region  ?? null,
                        item.year    ? String(item.year) : null,
                    ].filter(Boolean).join(" · ");

                    const diffPct = effectivePrice && effectivePrice > 0
                        ? ((item.price - effectivePrice) / effectivePrice) * 100
                        : null;

                    return (
                        <div
                            key={i}
                            className="d-flex align-items-start justify-content-between gap-3 rounded-3 px-3 py-2"
                            style={{ background: "#f8fafc", border: "1px solid #eef0f2" }}
                        >
                            <div style={{ minWidth: 0, flex: 1 }}>
                                <div className="d-flex align-items-center gap-2">
                                    <span
                                        className="fw-semibold fs-13 text-body"
                                        style={{ overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap", maxWidth: "100%" }}
                                        title={item.title}
                                    >
                                        {item.title}
                                    </span>
                                    <a
                                        href={item.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-muted fs-11 text-decoration-none flex-shrink-0"
                                        style={{ lineHeight: 1 }}
                                    >
                                        ↗
                                    </a>
                                </div>
                                {chips && (
                                    <span className="text-muted fs-12">{chips}</span>
                                )}
                            </div>

                            <div className="text-end flex-shrink-0">
                                <div className="fw-semibold fs-14">{formatCurrency(item.price)}</div>
                                {diffPct !== null && (
                                    <span className={`fs-11 ${diffPct > 0 ? "text-danger" : "text-success"}`}>
                                        {diffPct > 0 ? "+" : ""}{diffPct.toFixed(1)}% vs teu preço
                                    </span>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
