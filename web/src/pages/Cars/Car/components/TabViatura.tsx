import { Col, Row } from "reactstrap";
import CarPriceDisplay from "Components/Common/CarPriceDisplay";

interface Props {
    car: any;
    ips?: any;
    timeline?: any[];
    fmtDate?: (d: string) => string;
    fmtTime?: (d: string) => string;
    timelineDesc?: (item: any) => string;
}

export default function TabViatura({ car, ips, timeline = [], fmtDate, fmtTime, timelineDesc }: Props) {
    return (
        <Row className="g-3">

            {/* Specs técnicas */}
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

            {/* Estado & documentação */}
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

            {/* Preço + descrição */}
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

            <Col xl={6}>
                <h6 className="fs-13 fw-semibold mb-3">
                    <i className="ri-scales-3-line me-2 text-info" />
                    Preço vs mercado
                </h6>
                <div className="vstack gap-2">
                    <div className="d-flex align-items-center justify-content-between fs-13" style={{ border: "1px dashed #e9ebec", borderRadius: "0.4rem", padding: "0.75rem", background: "#fff" }}>
                        <span className="text-muted">IPS atual</span>
                        <span className="fw-semibold">{ips?.score ? `${ips.score}/100` : "—"}</span>
                    </div>
                    <div className="d-flex align-items-center justify-content-between fs-13" style={{ border: "1px dashed #e9ebec", borderRadius: "0.4rem", padding: "0.75rem", background: "#fff" }}>
                        <span className="text-muted">Posição vs mercado</span>
                        <span className={`fw-semibold ${Number(ips?.price_vs_market) <= 0 ? "text-success" : "text-danger"}`}>
                            {ips?.price_vs_market !== null && ips?.price_vs_market !== undefined ? `${Number(ips.price_vs_market) > 0 ? "+" : ""}${Number(ips.price_vs_market).toFixed(1)}%` : "—"}
                        </span>
                    </div>
                    <div className="d-flex align-items-center justify-content-between fs-13" style={{ border: "1px dashed #e9ebec", borderRadius: "0.4rem", padding: "0.75rem", background: "#fff" }}>
                        <span className="text-muted">Último cálculo</span>
                        <span className="fw-semibold">{ips?.calculated_at && fmtDate ? fmtDate(ips.calculated_at) : "—"}</span>
                    </div>
                </div>
            </Col>

            <Col xl={6}>
                <h6 className="fs-13 fw-semibold mb-3">
                    <i className="ri-time-line me-2 text-primary" />
                    Histórico
                </h6>
                {!timeline.length || !fmtDate || !fmtTime || !timelineDesc ? (
                    <div className="text-center py-4 text-muted bg-light-subtle rounded">
                        <i className="ri-time-line fs-1 d-block mb-2" />
                        <p className="fs-13 mb-0">Ainda sem histórico registado</p>
                    </div>
                ) : (
                    <div className="vstack gap-2" style={{ maxHeight: 360, overflowY: "auto" }}>
                        {timeline.slice(0, 12).map((item: any, idx: number) => (
                            <div key={idx} className="d-flex align-items-start gap-3" style={{ border: "1px dashed #e9ebec", borderRadius: "0.5rem", padding: "0.75rem", background: "#fff" }}>
                                <div className={`avatar-title rounded-circle bg-${item.color}-subtle text-${item.color}`} style={{ width: 34, height: 34, display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0 }}>
                                    <i className={`${item.icon} fs-16`} />
                                </div>
                                <div style={{ minWidth: 0 }}>
                                    <div className="d-flex align-items-center gap-2 flex-wrap mb-1">
                                        <span className="fw-semibold fs-13">{item.label}</span>
                                        <span className="text-muted fs-12">{fmtDate(item.created_at)} às {fmtTime(item.created_at)}</span>
                                    </div>
                                    <p className="text-muted fs-12 mb-0">{timelineDesc(item)}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </Col>

            {/* Imagens */}
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
    );
}
