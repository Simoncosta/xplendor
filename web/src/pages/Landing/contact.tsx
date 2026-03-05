import React from 'react';
import { Col, Container, Form, Row } from 'reactstrap';

const Contact = () => {
    return (
        <React.Fragment>
            <section className="section" id="contact">
                <Container>
                    <Row className="justify-content-center">
                        <Col lg={8}>
                            <div className="text-center mb-5">
                                <h3 className="mb-3 fw-semibold">Fale connosco</h3>
                                <p className="text-muted mb-4 ff-secondary">
                                    Tem dúvidas sobre a Xplendor ou quer testar a plataforma no seu stand?
                                    Envie-nos uma mensagem e entraremos em contacto rapidamente.
                                </p>
                            </div>
                        </Col>
                    </Row>

                    <Row className="gy-4">
                        <Col lg={4}>
                            <div>

                                <div className="mt-4">
                                    <h5 className="fs-13 text-muted text-uppercase">Localização</h5>
                                    <div className="fw-semibold">
                                        Rua Camilo de Oliveira, 101<br />
                                        Rio Tinto – Gondomar<br />
                                        Portugal
                                    </div>
                                </div>

                                <div className="mt-4">
                                    <h5 className="fs-13 text-muted text-uppercase">Email</h5>
                                    <div className="fw-semibold">
                                        {/* contact@xplendor.tech */}
                                        xplendorcar@gmail.com
                                    </div>
                                </div>

                                <div className="mt-4">
                                    <h5 className="fs-13 text-muted text-uppercase">Horário</h5>
                                    <div className="fw-semibold">
                                        Segunda a Sexta<br />
                                        09:00 – 19:00
                                    </div>
                                </div>

                            </div>
                        </Col>

                        <Col lg={8}>
                            <div>
                                <Form>
                                    <Row>
                                        <Col lg={6}>
                                            <div className="mb-4">
                                                <label htmlFor="name" className="form-label fs-13">Nome</label>
                                                <input name="name" id="name" type="text"
                                                    className="form-control bg-light border-light" placeholder="Your name*" />
                                            </div>
                                        </Col>
                                        <Col lg={6}>
                                            <div className="mb-4">
                                                <label htmlFor="email" className="form-label fs-13">Email</label>
                                                <input name="email" id="email" type="email"
                                                    className="form-control bg-light border-light" placeholder="Your email*" />
                                            </div>
                                        </Col>
                                    </Row>
                                    <Row>
                                        <Col lg={12}>
                                            <div className="mb-4">
                                                <label htmlFor="subject" className="form-label fs-13">Assunto</label>
                                                <input type="text" className="form-control bg-light border-light" id="subject"
                                                    name="subject" placeholder="Your Subject.." />
                                            </div>
                                        </Col>
                                    </Row>
                                    <Row>
                                        <Col lg={12}>
                                            <div className="mb-3">
                                                <label htmlFor="comments" className="form-label fs-13">Mensagem</label>
                                                <textarea name="comments" id="comments" rows={3}
                                                    className="form-control bg-light border-light"
                                                    placeholder="Your message..."></textarea>
                                            </div>
                                        </Col>
                                    </Row>
                                    <Row>
                                        <Col lg={12} className="text-end">
                                            <input type="submit" id="submit" name="send" className="submitBnt btn btn-primary"
                                                value="Enviar mensagem" />
                                        </Col>
                                    </Row>
                                </Form>
                            </div>
                        </Col>
                    </Row>
                </Container>
            </section>
        </React.Fragment>
    );
};

export default Contact;