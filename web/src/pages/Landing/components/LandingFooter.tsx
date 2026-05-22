import React from 'react';
import { Col, Container, Row } from 'reactstrap';

const LandingFooter: React.FC = () => (
    <footer className="lp-footer">
        <Container>
            <Row className="g-4">
                <Col md={4}>
                    <p className="lp-footer-brand">XPLENDOR</p>
                    <p className="mb-1">Marketing digital para stands automóveis.</p>
                    <p className="mb-0">Portugal.</p>
                </Col>

                <Col md={4}>
                    <p className="lp-footer-heading">Contacto</p>
                    <ul className="list-unstyled mb-0 d-flex flex-column gap-2">
                        <li>
                            <a href="https://wa.me/351938963526" target="_blank" rel="noopener noreferrer">
                                <i className="ri-whatsapp-line me-2" aria-hidden="true" />
                                +351 938 963 526
                            </a>
                        </li>
                        <li>
                            <a
                                href="https://linkedin.com/company/xplendor"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <i className="ri-linkedin-box-line me-2" aria-hidden="true" />
                                LinkedIn
                            </a>
                        </li>
                    </ul>
                </Col>

                <Col md={4}>
                    <p className="lp-footer-heading">Legal</p>
                    <ul className="list-unstyled mb-0 d-flex flex-column gap-2">
                        <li>
                            <a href="/privacy">Política de privacidade</a>
                        </li>
                        <li>
                            <a href="/terms">Termos e condições</a>
                        </li>
                    </ul>
                </Col>
            </Row>

            <hr className="lp-footer-divider" />

            <p className="lp-footer-copy">
                © {new Date().getFullYear()} XPLENDOR. Todos os direitos reservados.
            </p>
        </Container>
    </footer>
);

export default LandingFooter;
