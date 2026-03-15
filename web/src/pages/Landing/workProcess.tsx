import React from 'react';
import { Col, Container, Row } from 'reactstrap';

import processArrow from "../../assets/images/landing/process-arrow-img.png";

const steps = [
    {
        icon: "ri-code-s-slash-line",
        color: "text-primary",
        step: "01",
        title: "Instala a X-TAG no teu site",
        description: "Uma linha de código no teu site. Demora 5 minutos. A partir daí, a Xplendor começa a registar views, cliques de WhatsApp, leads e canal de origem de cada viatura — automaticamente.",
    },
    {
        icon: "ri-database-2-line",
        color: "text-info",
        step: "02",
        title: "Os dados chegam sozinhos",
        description: "Não precisas de configurar nada. Todas as noites o sistema agrega os dados, calcula o IPS de cada carro e actualiza os alertas. Acordas com o dashboard pronto.",
    },
    {
        icon: "ri-lightbulb-flash-line",
        color: "text-warning",
        step: "03",
        title: "Sabes exactamente o que fazer",
        description: "O dashboard mostra quais carros vender primeiro, quais ajustar o preço e quais promover. Sem achismos, sem relatórios manuais — só decisões claras.",
    },
];

const WorkProcess = () => {
    return (
        <React.Fragment>
            <section className="section" id="platform">
                <Container>
                    <Row className="justify-content-center">
                        <Col lg={7}>
                            <div className="text-center mb-5">
                                <h2 className="fw-bold mb-3">Como funciona</h2>
                                <p className="text-muted fs-15">
                                    Em 5 minutos está instalado. Em 24 horas tens os primeiros dados.
                                    Sem formação, sem consultores, sem reuniões.
                                </p>
                            </div>
                        </Col>
                    </Row>

                    <Row className="text-center justify-content-center">
                        {steps.map((step, idx) => (
                            <Col lg={4} key={idx}>
                                <div className="process-card mt-4 px-3">

                                    {/* Seta entre passos */}
                                    {idx > 0 && (
                                        <div
                                            className="process-arrow-img d-none d-lg-block"
                                            style={{ transform: "rotateX(180deg) scaleY(-1)" }}
                                        >
                                            <img src={processArrow} alt="" className="img-fluid" />
                                        </div>
                                    )}

                                    {/* Número do passo */}
                                    <div className="d-inline-flex align-items-center justify-content-center mb-3 position-relative">
                                        <div className="avatar-sm icon-effect mx-auto">
                                            <div className={`avatar-title bg-transparent ${step.color} rounded-circle h1`}>
                                                <i className={`${step.icon}`}></i>
                                            </div>
                                        </div>
                                    </div>

                                    <div
                                        className="badge bg-primary-subtle text-primary rounded-pill mb-2"
                                        style={{ fontSize: 11 }}
                                    >
                                        Passo {step.step}
                                    </div>

                                    <h5 className="fw-semibold mb-2">{step.title}</h5>
                                    <p className="text-muted fs-14" style={{ lineHeight: 1.7 }}>
                                        {step.description}
                                    </p>
                                </div>
                            </Col>
                        ))}
                    </Row>

                    {/* Nota técnica para rassegurar */}
                    <Row className="justify-content-center mt-5">
                        <Col lg={8}>
                            <div
                                className="d-flex align-items-center gap-3 p-3 rounded"
                                style={{ background: "#f8f9fa", border: "1px dashed #e9ebec" }}
                            >
                                <i className="ri-shield-check-line text-success fs-24 flex-shrink-0"></i>
                                <div>
                                    <p className="mb-0 fs-13 text-muted">
                                        <span className="fw-semibold text-dark">Compatível com qualquer site</span> — WordPress, custom, Webflow ou qualquer outra plataforma.
                                        A X-TAG é uma linha de JavaScript que não afecta a performance do teu site.
                                    </p>
                                </div>
                            </div>
                        </Col>
                    </Row>

                </Container>
            </section>
        </React.Fragment>
    );
};

export default WorkProcess;