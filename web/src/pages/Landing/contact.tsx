import React from 'react';
import { Col, Container, Row } from 'reactstrap';

const whatsappNumber = "351938963526"; // ← o teu número aqui
const whatsappMessage = encodeURIComponent(
    "Olá! Gostava de saber mais sobre a Xplendor para o meu stand."
);
const whatsappUrl = `https://wa.me/${whatsappNumber}?text=${whatsappMessage}`;

const Contact = () => {
    return (
        <React.Fragment>
            <section className="section" id="contact">
                <Container>
                    <Row className="justify-content-center">
                        <Col lg={7}>
                            <div className="text-center mb-5">
                                <h2 className="fw-bold mb-3">Fale connosco</h2>
                                <p className="text-muted fs-15">
                                    Tem dúvidas ou quer ver uma demo com dados reais do seu stand?
                                    Respondemos em minutos — sem compromisso.
                                </p>
                            </div>
                        </Col>
                    </Row>

                    <Row className="gy-4 justify-content-center">

                        {/* Canal principal: WhatsApp */}
                        <Col lg={4} md={6}>
                            <a
                                href={whatsappUrl}
                                target="_blank"
                                rel="noreferrer"
                                className="d-block text-decoration-none h-100"
                            >
                                <div
                                    className="p-4 rounded h-100 text-center"
                                    style={{
                                        border: "2px solid #25d366",
                                        background: "#f0fff4",
                                        transition: "box-shadow .2s",
                                    }}
                                    onMouseEnter={e => (e.currentTarget.style.boxShadow = "0 4px 20px rgba(37,211,102,.15)")}
                                    onMouseLeave={e => (e.currentTarget.style.boxShadow = "none")}
                                >
                                    <i className="ri-whatsapp-line text-success" style={{ fontSize: 40 }}></i>
                                    <h5 className="fw-bold mt-3 mb-2 text-dark">WhatsApp</h5>
                                    <p className="text-muted fs-14 mb-3">
                                        A forma mais rápida de falar connosco. Respondemos em minutos durante o horário comercial.
                                    </p>
                                    <span className="btn btn-success btn-sm">
                                        <i className="ri-whatsapp-line me-1"></i>
                                        Iniciar conversa
                                    </span>
                                </div>
                            </a>
                        </Col>

                        {/* Email */}
                        <Col lg={4} md={6}>
                            <a
                                href="mailto:xplendorcar@gmail.com"
                                className="d-block text-decoration-none h-100"
                            >
                                <div
                                    className="p-4 rounded h-100 text-center"
                                    style={{
                                        border: "1px dashed #e9ebec",
                                        background: "#fff",
                                        transition: "box-shadow .2s",
                                    }}
                                    onMouseEnter={e => (e.currentTarget.style.boxShadow = "0 4px 20px rgba(0,0,0,.07)")}
                                    onMouseLeave={e => (e.currentTarget.style.boxShadow = "none")}
                                >
                                    <i className="ri-mail-line text-primary" style={{ fontSize: 40 }}></i>
                                    <h5 className="fw-bold mt-3 mb-2 text-dark">Email</h5>
                                    <p className="text-muted fs-14 mb-3">
                                        Para questões mais detalhadas ou pedidos formais. Respondemos em 24 horas.
                                    </p>
                                    <span className="btn btn-outline-primary btn-sm">
                                        xplendorcar@gmail.com
                                    </span>
                                </div>
                            </a>
                        </Col>

                        {/* Info */}
                        <Col lg={4} md={6}>
                            <div
                                className="p-4 rounded h-100"
                                style={{ border: "1px dashed #e9ebec", background: "#fff" }}
                            >
                                <i className="ri-map-pin-2-line text-warning" style={{ fontSize: 40 }}></i>
                                <h5 className="fw-bold mt-3 mb-3 text-dark">Informação</h5>

                                <div className="vstack gap-3">
                                    <div>
                                        <p className="text-muted fs-12 mb-1 text-uppercase fw-semibold" style={{ letterSpacing: ".5px" }}>Localização</p>
                                        <p className="fw-medium mb-0 fs-14">Rio Tinto – Gondomar, Portugal</p>
                                    </div>
                                    <div>
                                        <p className="text-muted fs-12 mb-1 text-uppercase fw-semibold" style={{ letterSpacing: ".5px" }}>Horário</p>
                                        <p className="fw-medium mb-0 fs-14">Segunda a Sexta · 09:00 – 19:00</p>
                                    </div>
                                    <div>
                                        <p className="text-muted fs-12 mb-1 text-uppercase fw-semibold" style={{ letterSpacing: ".5px" }}>Resposta média</p>
                                        <p className="fw-medium mb-0 fs-14 text-success">Menos de 30 minutos no WhatsApp</p>
                                    </div>
                                </div>
                            </div>
                        </Col>

                    </Row>
                </Container>
            </section>
        </React.Fragment>
    );
};

export default Contact;