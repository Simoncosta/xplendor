import React from "react";
import { Link } from "react-router-dom";
import { Col, Container, Row } from "reactstrap";

const Services = () => {
    return (
        <React.Fragment>
            <section className="section" id="services">
                <Container>
                    <Row className="justify-content-center">
                        <Col lg={9}>
                            <div className="text-center mb-5">
                                <h1 className="mb-3 ff-secondary fw-bold lh-base">
                                    A plataforma premium para stands venderem mais, com controlo total
                                </h1>
                                <p className="text-muted">
                                    A Xplendor foi criada para eliminar dependência de marketplaces e dar ao stand um site rápido,
                                    moderno e com dados de marketing e leads centralizados.
                                </p>
                            </div>
                        </Col>
                    </Row>

                    <Row className="g-3">
                        {/* 1 */}
                        <Col lg={4}>
                            <div className="d-flex p-3">
                                <div className="flex-shrink-0 me-3">
                                    <div className="avatar-sm icon-effect">
                                        <div className="avatar-title bg-transparent text-success rounded-circle">
                                            <i className="ri-car-line fs-36"></i>
                                        </div>
                                    </div>
                                </div>
                                <div className="flex-grow-1">
                                    <h5 className="fs-18">Anúncios premium que convertem</h5>
                                    <p className="text-muted my-3">
                                        Páginas de viatura rápidas, limpas e focadas em conversão (contacto, WhatsApp, proposta).
                                    </p>
                                    {/* <div>
                                        <Link to="#contact" className="fs-13 fw-medium">
                                            Ver demo <i className="ri-arrow-right-s-line align-bottom"></i>
                                        </Link>
                                    </div> */}
                                </div>
                            </div>
                        </Col>

                        {/* 2 */}
                        <Col lg={4}>
                            <div className="d-flex p-3">
                                <div className="flex-shrink-0 me-3">
                                    <div className="avatar-sm icon-effect">
                                        <div className="avatar-title bg-transparent text-success rounded-circle">
                                            <i className="ri-line-chart-line fs-36"></i>
                                        </div>
                                    </div>
                                </div>
                                <div className="flex-grow-1">
                                    <h5 className="fs-18">Tracking e atribuição por viatura</h5>
                                    <p className="text-muted my-3">
                                        Saber qual campanha e canal gerou cada visita e lead — sem depender de “achismos”.
                                    </p>
                                    {/* <div>
                                        <Link to="#features" className="fs-13 fw-medium">
                                            Como funciona <i className="ri-arrow-right-s-line align-bottom"></i>
                                        </Link>
                                    </div> */}
                                </div>
                            </div>
                        </Col>

                        {/* 3 */}
                        <Col lg={4}>
                            <div className="d-flex p-3">
                                <div className="flex-shrink-0 me-3">
                                    <div className="avatar-sm icon-effect">
                                        <div className="avatar-title bg-transparent text-success rounded-circle">
                                            <i className="ri-user-voice-line fs-36"></i>
                                        </div>
                                    </div>
                                </div>
                                <div className="flex-grow-1">
                                    <h5 className="fs-18">Leads centralizados e qualificados</h5>
                                    <p className="text-muted my-3">
                                        Guarda contactos, origem, intenção e histórico. Ideal para follow-up e vendas consistentes.
                                    </p>
                                    {/* <div>
                                        <Link to="#contact" className="fs-13 fw-medium">
                                            Pedir acesso <i className="ri-arrow-right-s-line align-bottom"></i>
                                        </Link>
                                    </div> */}
                                </div>
                            </div>
                        </Col>

                        {/* 4 */}
                        <Col lg={4}>
                            <div className="d-flex p-3">
                                <div className="flex-shrink-0 me-3">
                                    <div className="avatar-sm icon-effect">
                                        <div className="avatar-title bg-transparent text-success rounded-circle">
                                            <i className="ri-refresh-line fs-36"></i>
                                        </div>
                                    </div>
                                </div>
                                <div className="flex-grow-1">
                                    <h5 className="fs-18">Gestão simples de stock</h5>
                                    <p className="text-muted my-3">
                                        Rascunho, ativo e vendido. Atualizações rápidas sem “gambiarras” nem fricção.
                                    </p>
                                    {/* <div>
                                        <Link to="#plans" className="fs-13 fw-medium">
                                            Ver planos <i className="ri-arrow-right-s-line align-bottom"></i>
                                        </Link>
                                    </div> */}
                                </div>
                            </div>
                        </Col>

                        {/* 5 */}
                        <Col lg={4}>
                            <div className="d-flex p-3">
                                <div className="flex-shrink-0 me-3">
                                    <div className="avatar-sm icon-effect">
                                        <div className="avatar-title bg-transparent text-success rounded-circle">
                                            <i className="ri-search-eye-line fs-36"></i>
                                        </div>
                                    </div>
                                </div>
                                <div className="flex-grow-1">
                                    <h5 className="fs-18">SEO e tráfego próprio</h5>
                                    <p className="text-muted my-3">
                                        Estrutura preparada para Google: páginas indexáveis, performance e conteúdos para autoridade.
                                    </p>
                                    {/* <div>
                                        <Link to="#features" className="fs-13 fw-medium">
                                            Ver detalhes <i className="ri-arrow-right-s-line align-bottom"></i>
                                        </Link>
                                    </div> */}
                                </div>
                            </div>
                        </Col>

                        {/* 6 */}
                        <Col lg={4}>
                            <div className="d-flex p-3">
                                <div className="flex-shrink-0 me-3">
                                    <div className="avatar-sm icon-effect">
                                        <div className="avatar-title bg-transparent text-success rounded-circle">
                                            <i className="ri-robot-2-line fs-36"></i>
                                        </div>
                                    </div>
                                </div>
                                <div className="flex-grow-1">
                                    <h5 className="fs-18">Automação e integrações</h5>
                                    <p className="text-muted my-3">
                                        Conecta com ferramentas do stand (ex.: CRM, WhatsApp, n8n). Menos manual, mais escala.
                                    </p>
                                    {/* <div>
                                        <Link to="#contact" className="fs-13 fw-medium">
                                            Falar connosco <i className="ri-arrow-right-s-line align-bottom"></i>
                                        </Link>
                                    </div> */}
                                </div>
                            </div>
                        </Col>
                    </Row>
                </Container>
            </section>
        </React.Fragment>
    );
};

export default Services;