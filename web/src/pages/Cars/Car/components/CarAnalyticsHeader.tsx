import { Link } from "react-router-dom";
import CarPriceDisplay from "Components/Common/CarPriceDisplay";
import { formatIpsBadge } from "helpers/ips";

interface Props {
    car: any;
    ips: any;
    ai: any;
    aiMeta: any;
    fmtDate: (d: string) => string;
    ipsClassBadge: (cls: string) => string;
}

export default function CarAnalyticsHeader({ car, ips, ai, aiMeta, fmtDate }: Props) {
    const ipsBadge = ips ? formatIpsBadge(ips.score, ips.classification) : null;

    return (
        <div
            style={{
                position: "sticky",
                top: "72px",
                zIndex: 10,
                border: "1px solid #e9ebec",
                borderRadius: "18px",
                background: "#fff",
            }}
        >
            <div className="d-flex align-items-center justify-content-between flex-wrap gap-3" style={{ padding: "16px 18px" }}>
                <div style={{ minWidth: 0 }}>
                    <div className="d-flex align-items-center flex-wrap gap-2 mb-2">
                        <h5 className="mb-0 fw-semibold text-truncate" style={{ minWidth: 0 }}>
                            {car?.brand?.name} {car?.model?.name}
                        </h5>
                        {car?.version && (
                            <span className="badge bg-primary-subtle text-primary fw-medium" style={{ fontSize: "12px" }}>
                                {car.version}
                            </span>
                        )}
                    </div>
                    <div className="d-flex align-items-center gap-3 flex-wrap fs-13 text-muted">
                        <span className="d-inline-flex align-items-center gap-2">
                            <span>Preço</span>
                            <span className="d-inline-flex align-middle">
                                <CarPriceDisplay
                                    priceGross={car?.price_gross ?? car?.price}
                                    promoPriceGross={car?.promo_price_gross}
                                    promoDiscountPct={car?.promo_discount_pct}
                                    hidePriceOnline={car?.hide_price_online}
                                    size="sm"
                                    badgeLabel="Oportunidade"
                                />
                            </span>
                        </span>
                        {car?.created_at && (
                            <span className="d-none d-md-inline">
                                Publicado <span className="text-dark fw-semibold">{fmtDate(car.created_at)}</span>
                            </span>
                        )}
                        {car?.license_plate && (
                            <span className="d-none d-md-inline">
                                Matrícula <span className="text-dark fw-semibold">{car.license_plate}</span>
                            </span>
                        )}
                        {ipsBadge && (
                            ipsBadge.isPending ? (
                                <span className={`badge ${ipsBadge.className} rounded-pill d-none d-md-inline-flex`} title="Ainda não há visitas nem dados de mercado suficientes para calcular a probabilidade de venda.">
                                    <i className="ri-time-line me-1" />
                                    {ipsBadge.label}
                                </span>
                            ) : (
                                <>
                                    <span className={`badge ${ipsBadge.className} rounded-pill d-none d-md-inline-flex`}>
                                        <i className="ri-award-line me-1" />
                                        Probabilidade de venda {ipsBadge.label}
                                    </span>
                                    <span className={`badge ${ipsBadge.className} rounded-pill d-none d-md-inline-flex`}>
                                        {ipsBadge.executiveLabel}
                                    </span>
                                </>
                            )
                        )}
                        {ai && (
                            <span className={`badge ${aiMeta?.urgency_level === "Alta" ? "bg-danger-subtle text-danger" : "bg-warning-subtle text-warning"} rounded-pill d-none d-md-inline-flex`}>
                                <i className="ri-alarm-warning-line me-1" />
                                {aiMeta?.urgency_level === "Alta" ? "Precisa de atenção agora" : "Acompanhar esta semana"}
                            </span>
                        )}
                        {aiMeta?.price_alert && (
                            <span className="badge bg-warning-subtle text-warning rounded-pill d-none d-md-inline-flex">
                                <i className="ri-price-tag-3-line me-1" />
                                Alerta de preço
                            </span>
                        )}
                    </div>
                </div>
                <div className="d-flex gap-2 flex-wrap">
                    <Link to={`/cars/${car?.id}`} className="btn btn-soft-primary btn-sm">
                        <i className="ri-pencil-fill" />
                        <span className="d-none d-md-inline ms-1">Editar viatura</span>
                    </Link>
                </div>
            </div>
        </div>
    );
}
