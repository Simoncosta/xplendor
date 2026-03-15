import React from 'react';
import { Col, Container, Row } from 'reactstrap';
import { Link } from 'react-router-dom';

import logolight from "../../assets/images/logo-light.png";

const whatsappNumber = "351938963526";
const whatsappUrl = `https://wa.me/${whatsappNumber}?text=${encodeURIComponent("Olá! Gostava de saber mais sobre a Xplendor.")}`;

const Footer = () => {
    return (
        <React.Fragment>
            <footer className="custom-footer bg-dark py-5 position-relative">
                <Container>
                    <Row className="gy-4">

                        {/* Marca */}
                        <Col lg={4}>
                            <img src={logolight} alt="Xplendor" height="20" />
                            <p className="text-muted mt-3 mb-3" style={{ fontSize: 14, lineHeight: 1.7 }}>
                                Plataforma de inteligência para stands automóveis. Sabe quais carros vender, quanto gastar em anúncios e onde estás a perder leads.
                            </p>
                            <div className="d-flex gap-2">
                                <a
                                    href={whatsappUrl}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="btn btn-success btn-sm"
                                >
                                    <i className="ri-whatsapp-line me-1"></i>
                                    Falar connosco
                                </a>
                            </div>
                        </Col>

                        {/* Links */}
                        <Col lg={2} xs={6}>
                            <h6 className="text-white fw-semibold mb-3">Plataforma</h6>
                            <ul className="list-unstyled vstack gap-2">
                                {[
                                    { label: "Funcionalidades", href: "#services" },
                                    { label: "Como funciona", href: "#features" },
                                    { label: "Planos", href: "#plans" },
                                    { label: "FAQ", href: "#faqs" },
                                ].map((item, idx) => (
                                    <li key={idx}>
                                        <a href={item.href} className="text-muted text-decoration-none" style={{ fontSize: 14 }}>
                                            {item.label}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </Col>

                        <Col lg={2} xs={6}>
                            <h6 className="text-white fw-semibold mb-3">Conta</h6>
                            <ul className="list-unstyled vstack gap-2">
                                {[
                                    { label: "Entrar", to: "/login" },
                                    { label: "Contacto", href: "#contact" },
                                ].map((item, idx) => (
                                    <li key={idx}>
                                        {item.to ? (
                                            <Link to={item.to} className="text-muted text-decoration-none" style={{ fontSize: 14 }}>
                                                {item.label}
                                            </Link>
                                        ) : (
                                            <a href={item.href} className="text-muted text-decoration-none" style={{ fontSize: 14 }}>
                                                {item.label}
                                            </a>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </Col>

                        {/* Contacto */}
                        <Col lg={4}>
                            <h6 className="text-white fw-semibold mb-3">Contacto</h6>
                            <ul className="list-unstyled vstack gap-2 text-muted" style={{ fontSize: 14 }}>
                                <li>
                                    <i className="ri-map-pin-line me-2"></i>
                                    Rio Tinto – Gondomar, Portugal
                                </li>
                                <li>
                                    <i className="ri-mail-line me-2"></i>
                                    xplendorcar@gmail.com
                                </li>
                                <li>
                                    <i className="ri-time-line me-2"></i>
                                    Segunda a Sexta · 09:00 – 19:00
                                </li>
                            </ul>
                        </Col>

                    </Row>

                    <Row className="text-center text-sm-start align-items-center mt-5 pt-3" style={{ borderTop: "1px solid rgba(255,255,255,.1)" }}>
                        <Col sm={6}>
                            <p className="text-muted mb-0" style={{ fontSize: 13 }}>
                                {new Date().getFullYear()} © Xplendor · Todos os direitos reservados
                            </p>
                        </Col>
                        <Col sm={6}>
                            <div className="text-sm-end mt-3 mt-sm-0">
                                <ul className="list-inline mb-0 footer-social-link">
                                    <li className="list-inline-item">
                                        <a href="#" className="avatar-xs d-block">
                                            <div className="avatar-title rounded-circle">
                                                <i className="ri-linkedin-fill"></i>
                                            </div>
                                        </a>
                                    </li>
                                    <li className="list-inline-item">
                                        <a href="https://www.instagram.com/xplendor.pt/" target="_blank" rel="noreferrer" className="avatar-xs d-block">
                                            <div className="avatar-title rounded-circle">
                                                <i className="ri-instagram-line"></i>
                                            </div>
                                        </a>
                                    </li>
                                    <li className="list-inline-item">
                                        <a href={whatsappUrl} target="_blank" rel="noreferrer" className="avatar-xs d-block">
                                            <div className="avatar-title rounded-circle bg-success">
                                                <i className="ri-whatsapp-line"></i>
                                            </div>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </Col>
                    </Row>
                </Container>
            </footer>
        </React.Fragment>
    );
};

export default Footer;