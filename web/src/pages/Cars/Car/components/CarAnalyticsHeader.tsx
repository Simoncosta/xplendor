import { Link } from "react-router-dom";
import { Card, CardBody } from "reactstrap";

interface Props {
    car: any;
    ips: any;
    ai: any;
    aiMeta: any;
    fmt: (n: number) => string;
    fmtDate: (d: string) => string;
    ipsClassBadge: (cls: string) => string;
}

export default function CarAnalyticsHeader({ car, ips, ai, aiMeta, fmt, fmtDate, ipsClassBadge }: Props) {
    return (
        <Card className="mb-0">
            <CardBody className="py-3">
                <div className="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <h5 className="mb-1 fw-semibold">
                            {car?.brand?.name} {car?.model?.name}
                            {car?.version && (
                                <span className="badge bg-primary-subtle text-primary ms-2 fw-medium" style={{ fontSize: "12px", verticalAlign: "middle" }}>
                                    {car.version}
                                </span>
                            )}
                        </h5>
                        <div className="hstack gap-3 flex-wrap">
                            <span className="text-muted fs-13">
                                Preço: <span className="text-dark fw-semibold">€{fmt(car?.price_gross ?? car?.price)}</span>
                            </span>
                            <span className="vr" />
                            <span className="text-muted fs-13">
                                Publicado: <span className="text-dark fw-semibold">{fmtDate(car?.created_at)}</span>
                            </span>
                            {car?.license_plate && (
                                <>
                                    <span className="vr" />
                                    <span className="text-muted fs-13">
                                        Matrícula: <span className="text-dark fw-semibold">{car.license_plate}</span>
                                    </span>
                                </>
                            )}
                            {ai && (
                                <>
                                    <span className="vr" />
                                    <span className={`badge ${aiMeta?.urgency_level === "Alta" ? "badge-soft-danger" : "badge-soft-warning"} rounded-pill`}>
                                        <i className="ri-alarm-warning-line me-1" />
                                        Urgência {aiMeta?.urgency_level}
                                    </span>
                                    {aiMeta?.price_alert && (
                                        <span className="badge badge-soft-warning rounded-pill">
                                            <i className="ri-price-tag-3-line me-1" />
                                            Alerta de preço
                                        </span>
                                    )}
                                </>
                            )}
                            {ips && (
                                <span className={`badge ${ipsClassBadge(ips.classification)} rounded-pill`}>
                                    <i className="ri-award-line me-1" />
                                    IPS {ips.score}/100
                                </span>
                            )}
                        </div>
                    </div>
                    <div className="d-flex gap-2">
                        <Link to={`/cars/${car?.id}`} className="btn btn-soft-primary btn-sm">
                            <i className="ri-pencil-fill me-1" /> Editar viatura
                        </Link>
                    </div>
                </div>
            </CardBody>
        </Card>
    );
}