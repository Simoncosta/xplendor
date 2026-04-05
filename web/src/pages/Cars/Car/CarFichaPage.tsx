import { useEffect } from "react";
import { useDispatch, useSelector } from "react-redux";
import { useParams } from "react-router-dom";
import { Col, Container, Row } from "reactstrap";
import { createSelector } from "reselect";
import { ToastContainer } from "react-toastify";
import CarPriceDisplay from "Components/Common/CarPriceDisplay";

import { analyticsCar } from "slices/cars/thunk";

import CarAnalyticsHeader from "./components/CarAnalyticsHeader";
import CarPageNav from "./components/CarPageNav";

import { fmtDate, ipsClassBadge } from "./helpers/CarAnalyticsData";

const selectCarState = (state: any) => state.Car;
const selectViewModel = createSelector(
    [selectCarState],
    (carState) => ({
        carAnalytics: carState.data.carAnalytics,
        loading: carState.loading.analytics,
    })
);

export default function CarFichaPage() {
    document.title = "Ficha Técnica | Xplendor";

    const dispatch: any = useDispatch();
    const { id } = useParams();

    const { carAnalytics, loading } = useSelector(selectViewModel);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return;
        const { company_id } = JSON.parse(authUser);
        dispatch(analyticsCar({ companyId: company_id, id: Number(id) }));
    }, [dispatch, id]);

    const car = carAnalytics?.car;
    const ai = car?.analyses?.analysis;
    const aiMeta = car?.analyses;
    const ips = carAnalytics?.potential_score;

    if (loading || !carAnalytics) return null;

    return (
        <div className="page-content mb-3">
            <ToastContainer />
            <Container fluid>

                <Row className="mb-2">
                    <Col>
                        <CarAnalyticsHeader
                            car={car}
                            ips={ips}
                            ai={ai}
                            aiMeta={aiMeta}
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
                                { icon: "ri-oil-line", label: "Combustível", val: car?.fuel_type },
                                { icon: "ri-settings-2-line", label: "Caixa", val: car?.transmission },
                                { icon: "ri-flashlight-line", label: "Potência", val: car?.power_hp ? `${car.power_hp} cv` : null },
                                { icon: "ri-speed-up-line", label: "Cilindrada", val: car?.engine_capacity_cc ? `${car.engine_capacity_cc} cc` : null },
                                { icon: "ri-door-open-line", label: "Portas", val: car?.doors },
                                { icon: "ri-group-line", label: "Lugares", val: car?.seats },
                                { icon: "ri-palette-line", label: "Cor exterior", val: car?.exterior_color },
                                { icon: "ri-layout-grid-line", label: "Segmento", val: car?.segment },
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
                                { icon: "ri-calendar-2-line", label: "Registo", val: car?.registration_month && car?.registration_year ? `${car.registration_month}/${car.registration_year}` : null },
                                { icon: "ri-hashtag", label: "Matrícula", val: car?.license_plate },
                                { icon: "ri-shield-star-line", label: "Condição", val: car?.condition, badge: true },
                                { icon: "ri-key-2-line", label: "Chave extra", val: car?.has_spare_key ? "Sim" : "Não", badge: true, bColor: car?.has_spare_key ? "success" : "danger" },
                                { icon: "ri-book-open-line", label: "Livro revisões", val: car?.has_manuals ? "Sim" : "Não", badge: true, bColor: car?.has_manuals ? "success" : "danger" },
                                { icon: "ri-truck-line", label: "Origem", val: car?.origin },
                                { icon: "ri-global-line", label: "Quilometragem", val: car?.mileage_km ? `${car.mileage_km.toLocaleString("pt-PT")} km` : null },
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
                                priceGross={car?.price_gross}
                                promoPriceGross={car?.promo_price_gross}
                                promoDiscountPct={car?.promo_discount_pct}
                                align="center"
                                size="lg"
                                badgeLabel="Em promoção"
                            />
                        </div>
                        {car?.description_website_pt && (
                            <>
                                <h6 className="fs-13 fw-semibold mb-2">Descrição</h6>
                                <div
                                    className="fs-13 text-muted bg-light rounded p-3"
                                    style={{ maxHeight: 280, overflowY: "auto", lineHeight: 1.7 }}
                                    dangerouslySetInnerHTML={{ __html: car.description_website_pt }}
                                />
                            </>
                        )}
                    </Col>

                    {car?.images?.length > 0 && (
                        <Col xs={12}>
                            <h6 className="fs-13 fw-semibold mb-3">
                                <i className="ri-image-line me-2 text-info" />
                                Imagens ({car.images.length})
                            </h6>
                            <div className="d-flex flex-wrap gap-2">
                                {car.images.map((img: any) => (
                                    <img
                                        key={img.id}
                                        src={`${process.env.REACT_APP_PUBLIC_URL ?? ""}${img.image}`}
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
