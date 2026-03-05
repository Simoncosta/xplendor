import React from 'react';
import { Link } from 'react-router-dom';
import { Col, Container, Row } from 'reactstrap';

// Import Images
import logolight from "../../assets/images/logo-light.png";

const Footer = () => {
    return (
        <React.Fragment>
            <footer className="custom-footer bg-dark py-5 position-relative">
                <Container>
                    <Row>
                        <Col lg={4} className="mt-4">
                            <div>
                                <div>
                                    <img src={logolight} alt="Xplendor logo" height="20" />
                                </div>
                                <div className="mt-4">
                                    <p>Plataforma para gestão e venda de viaturas.</p>

                                    <p className="ff-secondary">
                                        A Xplendor ajuda stands automóveis a gerir o seu stock, acompanhar leads e
                                        analisar a performance das viaturas num único sistema orientado a dados.
                                    </p>
                                </div>
                            </div>
                        </Col>



                    </Row>

                    <Row className="text-center text-sm-start align-items-center mt-5">

                        <Col sm={6}>
                            <div>
                                <p className="copy-rights mb-0">
                                    {new Date().getFullYear()} © Xplendor — Plataforma para gestão e venda de viaturas
                                </p>
                            </div>
                        </Col>

                        <Col sm={6}>
                            <div className="text-sm-end mt-3 mt-sm-0">

                                <ul className="list-inline mb-0 footer-social-link">

                                    <li className="list-inline-item">
                                        <Link to="#" className="avatar-xs d-block">
                                            <div className="avatar-title rounded-circle">
                                                <i className="ri-linkedin-fill"></i>
                                            </div>
                                        </Link>
                                    </li>

                                    <li className="list-inline-item">
                                        <Link to="#" className="avatar-xs d-block">
                                            <div className="avatar-title rounded-circle">
                                                <i className="ri-instagram-line"></i>
                                            </div>
                                        </Link>
                                    </li>

                                    <li className="list-inline-item">
                                        <Link to="#" className="avatar-xs d-block">
                                            <div className="avatar-title rounded-circle">
                                                <i className="ri-whatsapp-line"></i>
                                            </div>
                                        </Link>
                                    </li>

                                </ul>

                            </div>
                        </Col>

                    </Row>
                </Container>
            </footer>
        </React.Fragment >
    );
};

export default Footer;