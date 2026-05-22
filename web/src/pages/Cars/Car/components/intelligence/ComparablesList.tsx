import type { MarketComparable } from "../../../../../types/api";

interface Props {
    comparables: MarketComparable[];
}

export default function ComparablesList({ comparables }: Props) {
    if (comparables.length === 0) {
        return (
            <p className="text-muted fs-13 mb-0 mt-2">
                Sem comparáveis disponíveis para apresentar.
            </p>
        );
    }

    return (
        <div className="mt-3" style={{ borderTop: "1px solid #eef0f2", paddingTop: 12 }}>
            <p className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                Comparáveis mais próximos
            </p>
            <div className="d-flex flex-column gap-2">
                {comparables.map((item, i) => (
                    <div
                        key={i}
                        className="d-flex align-items-center justify-content-between gap-3 flex-wrap rounded-3 px-3 py-2"
                        style={{ background: "#f8fafc", border: "1px solid #eef0f2" }}
                    >
                        <div className="fs-13 text-body">
                            <span className="fw-semibold">{item.brand} {item.model}</span>
                            <span className="text-muted ms-2">
                                {item.year}
                                {item.km != null ? ` · ${item.km.toLocaleString("pt-PT")} km` : ""}
                            </span>
                        </div>
                        <div className="d-flex align-items-center gap-3">
                            <span className="fw-semibold fs-14">
                                {item.price.toLocaleString("pt-PT", { style: "currency", currency: "EUR", maximumFractionDigits: 0 })}
                            </span>
                            <a
                                href={item.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="btn btn-sm btn-outline-secondary py-1 px-2 fs-12"
                            >
                                Ver anúncio ↗
                            </a>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
