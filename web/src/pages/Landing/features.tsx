import React from 'react';
import { Link } from 'react-router-dom';
import { Col, Container, Row } from 'reactstrap';

// Import Images
import img1 from "../../assets/images/landing/features/img-1.png";
import img2 from "../../assets/images/landing/features/img-2.png";
import img3 from "../../assets/images/landing/features/img-3.png";

const Features = () => {
    return (
        <React.Fragment>

            <section className="section bg-light py-5" id="features">
                <Container>
                    <Row className="align-items-center gy-4">
                        <Col lg={6} sm={7} className="mx-auto">
                            <div>
                                <img src={img1} alt="" className="img-fluid mx-auto" />
                            </div>
                        </Col>
                        <Col lg={6}>
                            <div className="text-muted">
                                <div className="avatar-sm icon-effect mb-4">
                                    <div className="avatar-title bg-transparent rounded-circle text-success h1">
                                        <i className="ri-collage-line fs-36"></i>
                                    </div>
                                </div>
                                <h3 className="mb-3 fs-20">Gestão completa de leads</h3>
                                <p className="mb-4 fs-16">
                                    Centralize todos os contactos recebidos nas suas viaturas, acompanhe a origem de cada lead e tenha controlo total sobre o processo de venda.
                                </p>

                                <Row className="pt-3">
                                    <Col className="col-4">
                                        <div className="text-center">
                                            <h4>+1000</h4>
                                            <p>Viaturas analisadas</p>
                                        </div>
                                    </Col>
                                    <Col className="col-4">
                                        <div className="text-center">
                                            <h4>+300</h4>
                                            <p>Leads registadas</p>
                                        </div>
                                    </Col>
                                    <Col className="col-4">
                                        <div className="text-center">
                                            <h4>+10</h4>
                                            <p>Stands em piloto</p>
                                        </div>
                                    </Col>
                                </Row>
                            </div>
                        </Col>
                    </Row>
                </Container>
            </section>

            <section className="py-5 bg-primary position-relative">
                <div className="bg-overlay bg-overlay-pattern opacity-50"></div>
                <Container>
                    <Row className="align-items-center gy-4">
                        <Col className="col-sm">
                            <div>
                                <h4 className="text-white mb-0 fw-semibold">
                                    Comece a vender carros com dados reais e controlo total do seu stand
                                </h4>
                            </div>
                        </Col>
                        <Col className="col-sm-auto">
                            <div>
                                <Link to="/register" className="btn bg-gradient btn-danger">
                                    <i className="ri-rocket-line align-middle me-1"></i> Criar Conta
                                </Link>
                            </div>
                        </Col>
                    </Row>
                </Container>
            </section>


            <section className="section">
                <Container>
                    <Row className="align-items-center gy-4">
                        <Col lg={6} className="order-2 order-lg-1">
                            <div className="text-muted">
                                <h5 className="fs-12 text-uppercase text-success">Plataforma</h5>
                                <h4 className="mb-3">Gestão inteligente de viaturas</h4>
                                <p className="mb-4">
                                    A Xplendor permite aos stands gerir o stock de viaturas, acompanhar visualizações e leads,
                                    e tomar decisões baseadas em dados reais para vender mais e com maior controlo.
                                </p>

                                <Row>
                                    <Col sm={5}>
                                        <div className="vstack gap-2">
                                            <div className="d-flex align-items-center">
                                                <div className="flex-shrink-0 me-2">
                                                    <div className="avatar-xs icon-effect">
                                                        <div className="avatar-title bg-transparent text-success rounded-circle h2">
                                                            <i className="ri-check-fill"></i>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="flex-grow-1">
                                                    <h5 className="fs-14 mb-0">Gestão completa de viaturas</h5>
                                                </div>
                                            </div>

                                            <div className="d-flex align-items-center">
                                                <div className="flex-shrink-0 me-2">
                                                    <div className="avatar-xs icon-effect">
                                                        <div className="avatar-title bg-transparent text-success rounded-circle h2">
                                                            <i className="ri-check-fill"></i>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="flex-grow-1">
                                                    <h5 className="fs-14 mb-0">Controlo de leads em tempo real</h5>
                                                </div>
                                            </div>

                                            <div className="d-flex align-items-center">
                                                <div className="flex-shrink-0 me-2">
                                                    <div className="avatar-xs icon-effect">
                                                        <div className="avatar-title bg-transparent text-success rounded-circle h2">
                                                            <i className="ri-check-fill"></i>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="flex-grow-1">
                                                    <h5 className="fs-14 mb-0">Análise de performance dos anúncios</h5>
                                                </div>
                                            </div>
                                        </div>
                                    </Col>

                                    <Col sm={5}>
                                        <div className="vstack gap-2">
                                            <div className="d-flex align-items-center">
                                                <div className="flex-shrink-0 me-2">
                                                    <div className="avatar-xs icon-effect">
                                                        <div className="avatar-title bg-transparent text-success rounded-circle h2">
                                                            <i className="ri-check-fill"></i>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="flex-grow-1">
                                                    <h5 className="fs-14 mb-0">Relatórios de vendas e margem</h5>
                                                </div>
                                            </div>

                                            <div className="d-flex align-items-center">
                                                <div className="flex-shrink-0 me-2">
                                                    <div className="avatar-xs icon-effect">
                                                        <div className="avatar-title bg-transparent text-success rounded-circle h2">
                                                            <i className="ri-check-fill"></i>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="flex-grow-1">
                                                    <h5 className="fs-14 mb-0">Plataforma orientada a dados</h5>
                                                </div>
                                            </div>
                                        </div>
                                    </Col>
                                </Row>

                                <div className="mt-4">
                                    <Link to="/register" className="btn btn-primary">
                                        Começar agora <i className="ri-arrow-right-line align-middle ms-1"></i>
                                    </Link>
                                </div>
                            </div>
                        </Col>

                        <Col lg={6} sm={7} className="col-10 ms-auto order-1 order-lg-2">
                            <div>
                                <img src={img2} alt="" className="img-fluid" />
                            </div>
                        </Col>
                    </Row>

                    <Row className="align-items-center mt-5 pt-lg-5 gy-4">
                        <Col lg={6} sm={7} className="col-10 mx-auto">
                            <div>
                                <img src={img3} alt="" className="img-fluid" />
                            </div>
                        </Col>
                        <Col lg={6}>
                            <div className="text-muted ps-lg-5">
                                <h5 className="fs-12 text-uppercase text-success">plataforma</h5>
                                <h4 className="mb-3">Gestão inteligente do stock automóvel</h4>
                                <p className="mb-4">
                                    A Xplendor foi criada para stands que querem controlar vendas, leads e performance dos carros num único sistema orientado a dados.
                                </p>

                                <div className="vstack gap-2">
                                    <div className="d-flex align-items-center">
                                        <div className="flex-shrink-0 me-2">
                                            <div className="avatar-xs icon-effect">
                                                <div className="avatar-title bg-transparent text-success rounded-circle h2">
                                                    <i className="ri-check-fill"></i>
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex-grow-1">
                                            <p className="mb-0">Gestão centralizada de viaturas</p>
                                        </div>
                                    </div>

                                    <div className="d-flex align-items-center">
                                        <div className="flex-shrink-0 me-2">
                                            <div className="avatar-xs icon-effect">
                                                <div className="avatar-title bg-transparent text-success rounded-circle h2">
                                                    <i className="ri-check-fill"></i>
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex-grow-1">
                                            <p className="mb-0">Controlo de leads e contactos</p>
                                        </div>
                                    </div>

                                    <div className="d-flex align-items-center">
                                        <div className="flex-shrink-0 me-2">
                                            <div className="avatar-xs icon-effect">
                                                <div className="avatar-title bg-transparent text-success rounded-circle h2">
                                                    <i className="ri-check-fill"></i>
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex-grow-1">
                                            <p className="mb-0">Relatórios de vendas e performance</p>
                                        </div>
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

export default Features;