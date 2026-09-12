// React
import { useEffect, useState } from "react";
import { Card, CardBody, CardHeader, Row, Col } from "reactstrap";
// Helpers / models
import { getCarMargin } from "helpers/laravel_helper";
import { marginLabels, marginNotCalculableText } from "helpers/margin";
import { CarMargin } from "types/api";

interface CarMarginCardProps {
    companyId: number;
    carId: number;
}

const eur = (v: number) =>
    new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR", maximumFractionDigits: 2 }).format(v || 0);

/**
 * DMS Fase 2A — bloco de margem da viatura vendida.
 * Venda − Compra − Despesas. Rótulo honesto conforme uses_vat; nulos honestos.
 */
export default function CarMarginCard({ companyId, carId }: CarMarginCardProps) {
    const [data, setData] = useState<CarMargin | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        if (!companyId || !carId) return;
        let active = true;
        setLoading(true);
        getCarMargin(companyId, carId)
            .then((res: any) => { if (active) setData((res?.data as CarMargin) ?? null); })
            .catch(() => { if (active) setData(null); })
            .finally(() => { if (active) setLoading(false); });
        return () => { active = false; };
    }, [companyId, carId]);

    if (loading) {
        return (
            <Card className="mt-3"><CardBody className="text-center text-muted py-4">A carregar margem…</CardBody></Card>
        );
    }

    if (!data) return null;

    const labels = marginLabels(data.uses_vat);

    return (
        <Card className="mt-3">
            <CardHeader>
                <h5 className="mb-0">{labels.title}</h5>
                <small className="text-muted">Venda − Compra − Despesas da viatura</small>
            </CardHeader>
            <CardBody>
                <Row className="g-3">
                    <Col md={3}>
                        <p className="text-muted fs-12 mb-1">Preço de venda</p>
                        <h5 className="mb-0">{data.sale_price !== null ? eur(data.sale_price) : "—"}</h5>
                    </Col>
                    <Col md={3}>
                        <p className="text-muted fs-12 mb-1">Preço de compra</p>
                        <h5 className="mb-0">
                            {data.purchase_price !== null
                                ? eur(data.purchase_price)
                                : <span className="text-warning fs-14"><i className="ri-information-line me-1" />não registado</span>}
                        </h5>
                    </Col>
                    <Col md={3}>
                        <p className="text-muted fs-12 mb-1">Despesas ({data.expenses_count})</p>
                        <h5 className="mb-0">{eur(data.expenses_total)}</h5>
                    </Col>
                    <Col md={3}>
                        <p className="text-muted fs-12 mb-1">{labels.title}</p>
                        {data.calculable && data.margin !== null ? (
                            <h4 className="mb-0 fw-bold" style={{ color: data.margin >= 0 ? "#0ab39c" : "#f06548" }}>
                                {eur(data.margin)}
                            </h4>
                        ) : (
                            <span className="badge bg-light text-muted fs-13">Não calculável</span>
                        )}
                    </Col>
                </Row>

                {/* Avisos honestos. */}
                {!data.calculable && (
                    <div className="alert alert-warning mt-3 mb-0 py-2 px-3 fs-13">
                        <i className="ri-information-line me-1" />
                        {marginNotCalculableText(data.reason)}
                    </div>
                )}
                {data.calculable && labels.warning && (
                    <div className="alert alert-warning mt-3 mb-0 py-2 px-3 fs-13">
                        <i className="ri-information-line me-1" />
                        {labels.warning}
                    </div>
                )}
            </CardBody>
        </Card>
    );
}
