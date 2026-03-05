import React from 'react';
import { Col, Container, Row } from 'reactstrap';

// Import Images
import processArrow from "../../assets/images/landing/process-arrow-img.png";

const WorkProcess = () => {
    return (
        <React.Fragment>
            <section className="section">
                <Container>

                    <Row className="justify-content-center">
                        <Col lg={8}>
                            <div className="text-center mb-5">
                                <h3 className="mb-3 fw-semibold">Como funciona a Xplendor</h3>
                                <p className="text-muted mb-4 ff-secondary">
                                    Começar a usar a Xplendor é simples. Em poucos minutos pode publicar as suas viaturas e começar a receber contactos de compradores interessados.
                                </p>
                            </div>
                        </Col>
                    </Row>


                    <Row className="text-center">

                        <Col lg={4}>
                            <div className="process-card mt-4">
                                <div className="avatar-sm icon-effect mx-auto mb-4">
                                    <div className="avatar-title bg-transparent text-success rounded-circle h1">
                                        <i className="ri-car-line"></i>
                                    </div>
                                </div>

                                <h5>Adicione as suas viaturas</h5>
                                <p className="text-muted ff-secondary">
                                    Registe facilmente o seu stock com todas as informações, fotos e características das viaturas.
                                </p>

                            </div>
                        </Col>


                        <Col lg={4}>
                            <div className="process-card mt-4">

                                <div className="process-arrow-img d-none d-lg-block"
                                    style={{ transform: "rotateX(180deg) scaleY(-1)" }}
                                >
                                    <img src={processArrow} alt="" className="img-fluid" />
                                </div>

                                <div className="avatar-sm icon-effect mx-auto mb-4">
                                    <div className="avatar-title bg-transparent text-success rounded-circle h1">
                                        <i className="ri-line-chart-line"></i>
                                    </div>
                                </div>

                                <h5>Promova as viaturas</h5>
                                <p className="text-muted ff-secondary">
                                    Envie tráfego diretamente para páginas otimizadas de cada carro e acompanhe visitas e interesse dos compradores.
                                </p>

                            </div>
                        </Col>


                        <Col lg={4}>
                            <div className="process-card mt-4">
                                <div className="process-arrow-img d-none d-lg-block"
                                    style={{ transform: "rotateX(180deg) scaleY(-1)" }}
                                >
                                    <img src={processArrow} alt="" className="img-fluid" />
                                </div>

                                <div className="avatar-sm icon-effect mx-auto mb-4">
                                    <div className="avatar-title bg-transparent text-success rounded-circle h1">
                                        <i className="ri-customer-service-2-line"></i>
                                    </div>
                                </div>

                                <h5>Receba contactos qualificados</h5>
                                <p className="text-muted ff-secondary">
                                    Todos os contactos ficam registados na plataforma para acompanhar leads e fechar mais vendas.
                                </p>

                            </div>
                        </Col>

                    </Row>

                </Container>
            </section>
        </React.Fragment>
    );
};

export default WorkProcess;