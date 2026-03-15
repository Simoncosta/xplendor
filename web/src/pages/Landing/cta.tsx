import React from 'react';
import { Col, Container, Row } from 'reactstrap';

const Cta = () => {
    const whatsappNumber = "351938963526";
    const whatsappMessage = encodeURIComponent(
        "Olá! Vi a Xplendor e gostava de perceber como pode ajudar o meu stand."
    );
    const whatsappUrl = `https://wa.me/${whatsappNumber}?text=${whatsappMessage}`;

    return (
        <React.Fragment>
            <section className="py-5 bg-primary position-relative">
                <div className="bg-overlay bg-overlay-pattern opacity-50"></div>
                <Container>
                    <Row className="align-items-center gy-4">

                        <Col sm>
                            <div>
                                <h4 className="text-white fw-bold mb-1">
                                    Pronto para saber quais carros vender esta semana?
                                </h4>
                                <p className="text-white-50 mb-0" style={{ fontSize: 14 }}>
                                    Fala connosco hoje. Mostramos como funciona em 15 minutos com dados reais do teu stand.
                                </p>
                            </div>
                        </Col>

                        <Col sm="auto">
                            <div className="d-flex flex-column align-items-center gap-1">
                                <a
                                    href={whatsappUrl}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="btn btn-success btn-lg px-4"
                                >
                                    <i className="ri-whatsapp-line me-2 fs-16"></i>
                                    Falar no WhatsApp
                                </a>
                                <span className="text-white-50" style={{ fontSize: 11 }}>
                                    Sem compromisso · Respondemos em minutos
                                </span>
                            </div>
                        </Col>

                    </Row>
                </Container>
            </section>
        </React.Fragment>
    );
};

export default Cta;