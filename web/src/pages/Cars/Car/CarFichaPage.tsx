import { useEffect, useMemo, useState } from "react";
import { useParams } from "react-router-dom";
import { Col, Container, Row, Spinner } from "reactstrap";
import { ToastContainer } from "react-toastify";
import CarPriceDisplay from "Components/Common/CarPriceDisplay";

import CarAnalyticsHeader from "./components/CarAnalyticsHeader";
import CarPageNav from "./components/CarPageNav";

import { fetchCarSpecs } from "helpers/carSpecs_helper";
import { fmtDate, ipsClassBadge } from "./helpers/CarAnalyticsData";
import type { CarSpecs } from "types/api";

export default function CarFichaPage() {
    document.title = "Ficha Técnica | Xplendor";

    const { id } = useParams();

    const [specs, setSpecs] = useState<CarSpecs | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);

    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return 0;
        return Number(JSON.parse(authUser).company_id);
    }, []);

    useEffect(() => {
        if (!companyId || !id) return;
        setLoading(true);
        setError(false);
        fetchCarSpecs(companyId, Number(id))
            .then((res: any) => setSpecs(res?.data ?? null))
            .catch(() => setError(true))
            .finally(() => setLoading(false));
    }, [companyId, id]);

    if (loading) {
        return (
            <div className="page-content">
                <div className="d-flex justify-content-center align-items-center" style={{ minHeight: 200 }}>
                    <Spinner color="primary" />
                </div>
            </div>
        );
    }

    if (error || !specs) {
        return (
            <div className="page-content">
                <Container fluid>
                    <div className="text-center py-5 text-muted">
                        <i className="ri-error-warning-line fs-1 d-block mb-2" />
                        <p className="mb-0 fs-13">Não foi possível carregar a ficha desta viatura.</p>
                    </div>
                </Container>
            </div>
        );
    }

    // Shape adapter for CarAnalyticsHeader (expects flat car object)
    const carForHeader = {
        id: specs.id,
        brand: specs.brand,
        model: specs.model,
        version: specs.version,
        price_gross: specs.price.gross,
        promo_price_gross: specs.price.promo_gross,
        promo_discount_pct: specs.price.promo_discount_pct,
        license_plate: specs.identification.license_plate,
        created_at: specs.created_at,
    };

    return (
        <div className="page-content mb-3">
            <ToastContainer />
            <Container fluid>

                <Row className="mb-2">
                    <Col>
                        <CarAnalyticsHeader
                            car={carForHeader}
                            ips={specs.header_meta.potential_score}
                            ai={specs.header_meta.analyses?.analysis ?? null}
                            aiMeta={specs.header_meta.analyses}
                            fmtDate={fmtDate}
                            ipsClassBadge={ipsClassBadge}
                        />
                    </Col>
                </Row>

                <Row className="mb-3">
                    <Col>
                        <CarPageNav active="ficha" />
                    </Col>
                </Row>

                <Row className="g-3">

                    <Col md={6} xl={4}>
                        <h6 className="fs-13 fw-semibold mb-3">
                            <i className="ri-settings-3-line me-2 text-primary" />
                            Informação Técnica
                        </h6>
                        <div className="vstack gap-2">
                            {[
                                { icon: "ri-oil-line", label: "Combustível", val: specs.specs.fuel_type },
                                { icon: "ri-settings-2-line", label: "Caixa", val: specs.specs.transmission },
                                { icon: "ri-flashlight-line", label: "Potência", val: specs.specs.power_hp ? `${specs.specs.power_hp} cv` : null },
                                { icon: "ri-speed-up-line", label: "Cilindrada", val: specs.specs.engine_capacity_cc ? `${specs.specs.engine_capacity_cc} cc` : null },
                                { icon: "ri-door-open-line", label: "Portas", val: specs.specs.doors },
                                { icon: "ri-group-line", label: "Lugares", val: specs.specs.seats },
                                { icon: "ri-palette-line", label: "Cor exterior", val: specs.specs.exterior_color },
                                { icon: "ri-layout-grid-line", label: "Segmento", val: specs.specs.segment },
                            ].map((row, idx) => row.val && (
                                <div key={idx} className="d-flex align-items-center gap-2 fs-13" style={{ border: "1px dashed #e9ebec", borderRadius: "0.4rem", padding: "0.55rem 0.75rem", background: "#fff" }}>
                                    <i className={`${row.icon} text-primary fs-16 flex-shrink-0`} style={{ width: 20 }} />
                                    <span className="text-muted flex-grow-1">{row.label}</span>
                                    <span className="fw-medium">{row.val}</span>
                                </div>
                            ))}
                        </div>
                    </Col>

                    <Col md={6} xl={4}>
                        <h6 className="fs-13 fw-semibold mb-3">
                            <i className="ri-file-shield-2-line me-2 text-success" />
                            Estado & Documentação
                        </h6>
                        <div className="vstack gap-2">
                            {[
                                { icon: "ri-calendar-2-line", label: "Registo", val: specs.registration.month && specs.registration.year ? `${specs.registration.month}/${specs.registration.year}` : null },
                                { icon: "ri-hashtag", label: "Matrícula", val: specs.identification.license_plate },
                                { icon: "ri-shield-star-line", label: "Condição", val: specs.state.condition, badge: true },
                                { icon: "ri-key-2-line", label: "Chave extra", val: specs.state.has_spare_key ? "Sim" : "Não", badge: true, bColor: specs.state.has_spare_key ? "success" : "danger" },
                                { icon: "ri-book-open-line", label: "Livro revisões", val: specs.state.has_manuals ? "Sim" : "Não", badge: true, bColor: specs.state.has_manuals ? "success" : "danger" },
                                { icon: "ri-truck-line", label: "Origem", val: specs.state.origin },
                                { icon: "ri-global-line", label: "Quilometragem", val: specs.state.mileage_km ? `${specs.state.mileage_km.toLocaleString("pt-PT")} km` : null },
                            ].map((row, idx) => row.val != null && (
                                <div key={idx} className="d-flex align-items-center gap-2 fs-13" style={{ border: "1px dashed #e9ebec", borderRadius: "0.4rem", padding: "0.55rem 0.75rem", background: "#fff" }}>
                                    <i className={`${row.icon} text-success fs-16 flex-shrink-0`} style={{ width: 20 }} />
                                    <span className="text-muted flex-grow-1">{row.label}</span>
                                    {(row as any).badge
                                        ? <span className={`badge badge-soft-${(row as any).bColor || "secondary"}`}>{row.val}</span>
                                        : <span className="fw-medium">{row.val}</span>}
                                </div>
                            ))}
                        </div>
                    </Col>

                    <Col xl={4}>
                        <h6 className="fs-13 fw-semibold mb-3">
                            <i className="ri-price-tag-3-line me-2 text-warning" />
                            Preço & Notas
                        </h6>
                        <div className="bg-primary-subtle rounded p-3 mb-3 text-center">
                            <p className="text-muted fs-12 mb-1">Preço de venda</p>
                            <CarPriceDisplay
                                priceGross={specs.price.gross}
                                promoPriceGross={specs.price.promo_gross}
                                promoDiscountPct={specs.price.promo_discount_pct}
                                hidePriceOnline={specs.price.hide_price_online}
                                align="center"
                                size="lg"
                                badgeLabel="Em promoção"
                            />
                        </div>
                        {specs.description && (
                            <>
                                <h6 className="fs-13 fw-semibold mb-2">Descrição</h6>
                                <div
                                    className="fs-13 text-muted bg-light rounded p-3"
                                    style={{ maxHeight: 280, overflowY: "auto", lineHeight: 1.7 }}
                                    dangerouslySetInnerHTML={{ __html: specs.description }}
                                />
                            </>
                        )}
                    </Col>

                    {specs.images.length > 0 && (
                        <Col xs={12}>
                            <h6 className="fs-13 fw-semibold mb-3">
                                <i className="ri-image-line me-2 text-info" />
                                Imagens ({specs.images.length})
                            </h6>
                            <div className="d-flex flex-wrap gap-2">
                                {specs.images.map((img) => (
                                    <img
                                        key={img.id}
                                        src={`${process.env.REACT_APP_PUBLIC_URL ?? ""}${img.url}`}
                                        alt=""
                                        style={{ width: 100, height: 70, objectFit: "cover", borderRadius: 6, border: img.is_primary ? "2px solid #405189" : "1px solid #e9ebec", cursor: "pointer" }}
                                    />
                                ))}
                            </div>
                        </Col>
                    )}

                </Row>

            </Container>
        </div>
    );
}
