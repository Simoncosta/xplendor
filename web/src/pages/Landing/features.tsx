import React from 'react';
import { Col, Container, Row } from 'reactstrap';

// Substituir pelas imagens reais do teu dashboard
import dashboard from "../../assets/images/xpledor/dashboard-do-stand.png";
import dashboardCar from "../../assets/images/xpledor/dashboard-do-carro.png";

const Features = () => {
    return (
        <React.Fragment>
            <section className="section bg-light py-5" id="features">
                <Container>

                    {/* ── Bloco 1: Dashboard do stand ── */}
                    <Row className="align-items-center gy-4 mb-5 pb-lg-5">
                        <Col lg={6} sm={8} className="mx-auto order-1 order-lg-2">
                            <div
                                className="shadow rounded d-flex align-items-center justify-content-center bg-primary-subtle"
                                style={{ height: 340 }}
                            >
                                <img src={dashboard} alt="Dashboard do Stand" className="img-fluid" />
                            </div>
                        </Col>

                        <Col lg={6} className="order-2 order-lg-1">
                            <div className="pe-lg-5">
                                <span className="badge bg-primary-subtle text-primary rounded-pill px-3 py-2 mb-3 fs-12">
                                    Inventory Intelligence
                                </span>
                                <h3 className="fw-bold mb-3">
                                    O stand completo numa única vista
                                </h3>
                                <p className="text-muted mb-4 fs-15">
                                    Abre o dashboard de manhã e sabes imediatamente: quais carros têm procura, quais estão parados há demasiado tempo, e onde está o capital imobilizado.
                                </p>

                                <div className="vstack gap-3">
                                    {[
                                        { icon: "ri-award-line", color: "text-primary", text: "IPS (Índice de Potencial de Venda) por carro — score 0 a 100" },
                                        { icon: "ri-alarm-warning-line", color: "text-danger", text: "Alertas automáticos: carros parados, conversão baixa, capital imobilizado" },
                                        { icon: "ri-bar-chart-line", color: "text-success", text: "Ranking de carros quentes vs. carros mortos — em tempo real" },
                                    ].map((item, idx) => (
                                        <div key={idx} className="d-flex align-items-start gap-3">
                                            <div
                                                className="rounded-circle bg-white d-flex align-items-center justify-content-center flex-shrink-0"
                                                style={{ width: 36, height: 36, border: "1px solid #e9ebec" }}
                                            >
                                                <i className={`${item.icon} ${item.color} fs-16`}></i>
                                            </div>
                                            <p className="text-muted mb-0 fs-14 pt-1">{item.text}</p>
                                        </div>
                                    ))}
                                </div>

                                <div className="mt-4 pt-2">
                                    <div className="d-flex gap-4 flex-wrap">
                                        <div className="text-center">
                                            <h4 className="text-primary fw-bold mb-0">56 dias</h4>
                                            <p className="text-muted fs-12 mb-0">tempo médio em stock</p>
                                        </div>
                                        <div className="text-center">
                                            <h4 className="text-danger fw-bold mb-0">€83k</h4>
                                            <p className="text-muted fs-12 mb-0">capital parado identificado</p>
                                        </div>
                                        <div className="text-center">
                                            <h4 className="text-success fw-bold mb-0">3 dias</h4>
                                            <p className="text-muted fs-12 mb-0">para primeiro alerta</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </Col>
                    </Row>

                    {/* ── Bloco 2: Analytics por viatura ── */}
                    <Row className="align-items-center gy-4">
                        <Col lg={6} sm={8} className="mx-auto">
                            <div
                                className="shadow rounded d-flex align-items-center justify-content-center bg-success-subtle"
                                style={{ height: 340 }}
                            >
                                <img src={dashboardCar} alt="Dashboard do Carro" className="img-fluid" />
                            </div>
                        </Col>

                        <Col lg={6}>
                            <div className="ps-lg-5">
                                <span className="badge bg-success-subtle text-success rounded-pill px-3 py-2 mb-3 fs-12">
                                    Car Analytics
                                </span>
                                <h3 className="fw-bold mb-3">
                                    Cada carro tem o seu próprio relatório
                                </h3>
                                <p className="text-muted mb-4 fs-15">
                                    Clica num carro e vês tudo: views por canal, leads geradas, taxa de engajamento ponderada, score de potencial e histórico de evolução.
                                </p>

                                <div className="vstack gap-3">
                                    {[
                                        { icon: "ri-eye-line", color: "text-primary", text: "Views por canal: pago, orgânico, direto, social — atribuição exacta" },
                                        { icon: "ri-whatsapp-line", color: "text-success", text: "Cliques de WhatsApp e chamadas registados como intenção real de compra" },
                                        { icon: "ri-cpu-line", color: "text-info", text: "Inteligência com público-alvo, copy sugerido e canais recomendados" },
                                    ].map((item, idx) => (
                                        <div key={idx} className="d-flex align-items-start gap-3">
                                            <div
                                                className="rounded-circle bg-white d-flex align-items-center justify-content-center flex-shrink-0"
                                                style={{ width: 36, height: 36, border: "1px solid #e9ebec" }}
                                            >
                                                <i className={`${item.icon} ${item.color} fs-16`}></i>
                                            </div>
                                            <p className="text-muted mb-0 fs-14 pt-1">{item.text}</p>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </Col>
                    </Row>

                </Container>
            </section>
        </React.Fragment>
    );
};

export default Features;