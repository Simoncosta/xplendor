import React from 'react';
import { Link } from 'react-router-dom';
import { Col, Container, Row } from 'reactstrap';
import { CTA_WHATSAPP_URL } from '../data/constants';

/**
 * Rodapé rico: marca + navegação do produto + contacto/redes + legal.
 * Só links REAIS (WhatsApp, LinkedIn). Instagram/Facebook ficam como TODO para
 * o Simon colar os URLs reais — não inventamos handles.
 */
const LandingFooter: React.FC = () => (
    <footer className="lp-footer2">
        <Container>
            <Row className="g-4 g-lg-5">
                <Col lg={4} md={12}>
                    <p className="lp-footer2-brand">XPLENDOR</p>
                    <p className="lp-footer2-blurb">
                        Agência de marketing e plataforma de gestão para stands automóveis em Portugal.
                    </p>
                    <a href={CTA_WHATSAPP_URL} target="_blank" rel="noopener noreferrer" className="lp-footer2-cta">
                        <i className="ri-whatsapp-line" aria-hidden="true" />
                        Falar connosco
                    </a>
                </Col>

                <Col lg={3} md={4} xs={6}>
                    <p className="lp-footer2-heading">Explorar</p>
                    <ul className="lp-footer2-links">
                        <li><a href="#o-que-fazemos">O que fazemos</a></li>
                        <li><a href="#plataforma">A plataforma</a></li>
                        <li><a href="#precos">Preços</a></li>
                        <li><Link to="/login">Entrar</Link></li>
                    </ul>
                </Col>

                <Col lg={3} md={4} xs={6}>
                    <p className="lp-footer2-heading">Contacto</p>
                    <ul className="lp-footer2-links">
                        <li>
                            <a href="https://wa.me/351938963526" target="_blank" rel="noopener noreferrer">
                                <i className="ri-whatsapp-line me-2" aria-hidden="true" />+351 938 963 526
                            </a>
                        </li>
                        <li>
                            <a href="https://linkedin.com/company/xplendorpt" target="_blank" rel="noopener noreferrer">
                                <i className="ri-linkedin-box-line me-2" aria-hidden="true" />LinkedIn
                            </a>
                        </li>
                        {/* TODO Simon: colar URLs reais e reativar
                        <li><a href="#"><i className="ri-instagram-line me-2" />Instagram</a></li>
                        <li><a href="#"><i className="ri-facebook-box-line me-2" />Facebook</a></li>
                        */}
                    </ul>
                </Col>

                <Col lg={2} md={4}>
                    <p className="lp-footer2-heading">Legal</p>
                    <ul className="lp-footer2-links">
                        <li><a href="/privacy">Privacidade</a></li>
                        <li><a href="/terms">Termos</a></li>
                        <li>
                            <a href="https://www.livroreclamacoes.pt/Inicio/" target="_blank" rel="noopener noreferrer">
                                Livro de Reclamações
                            </a>
                        </li>
                    </ul>
                </Col>
            </Row>

            <hr className="lp-footer2-divider" />
            <p className="lp-footer2-copy">
                © {new Date().getFullYear()} XPLENDOR. Todos os direitos reservados.
            </p>
        </Container>
    </footer>
);

export default LandingFooter;
