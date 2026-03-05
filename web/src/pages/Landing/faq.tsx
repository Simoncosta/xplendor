import React, { useState } from 'react';
import { Col, Container, Row, Collapse } from 'reactstrap';
import classnames from "classnames";

const Faqs = () => {

    const [col1, setcol1] = useState(true);
    const [col2, setcol2] = useState(false);
    const [col3, setcol3] = useState(false);
    const [col4, setcol4] = useState(false);

    const [col9, setcol5] = useState(false);
    const [col10, setcol6] = useState(true);
    const [col11, setcol7] = useState(false);
    const [col12, setcol8] = useState(false);

    const t_col1 = () => {
        setcol1(!col1);
        setcol2(false);
        setcol3(false);
        setcol4(false);

    };

    const t_col2 = () => {
        setcol2(!col2);
        setcol1(false);
        setcol3(false);
        setcol4(false);

    };

    const t_col3 = () => {
        setcol3(!col3);
        setcol1(false);
        setcol2(false);
        setcol4(false);

    };

    const t_col4 = () => {
        setcol4(!col4);
        setcol1(false);
        setcol2(false);
        setcol3(false);
    };

    const t_col5 = () => {
        setcol5(!col9);
        setcol6(false);
        setcol7(false);
        setcol8(false);

    };

    const t_col6 = () => {
        setcol6(!col10);
        setcol7(false);
        setcol8(false);
        setcol5(false);

    };

    const t_col7 = () => {
        setcol7(!col11);
        setcol5(false);
        setcol6(false);
        setcol8(false);

    };

    const t_col8 = () => {
        setcol8(!col12);
        setcol5(false);
        setcol6(false);
        setcol7(false);
    };

    return (
        <React.Fragment>
            <section className="section" id='faqs'>
                <Container>
                    <Row className="justify-content-center">
                        <Col lg={8}>
                            <div className="text-center mb-5">
                                <h3 className="mb-3 fw-bold">Perguntas Frequentes</h3>
                                <p className="text-muted mb-4">
                                    Tire as principais dúvidas sobre como a Xplendor ajuda stands a vender mais e a controlar melhor o seu stock.
                                </p>

                                <div>
                                    <button type="button" className="btn btn-primary btn-label rounded-pill me-1">
                                        <i className="ri-mail-line label-icon align-middle rounded-pill fs-16 me-2"></i>
                                        Falar com a equipa
                                    </button>

                                    <button type="button" className="btn btn-success btn-label rounded-pill">
                                        <i className="ri-whatsapp-line label-icon align-middle rounded-pill fs-16 me-2"></i>
                                        WhatsApp
                                    </button>
                                </div>
                            </div>
                        </Col>
                    </Row>

                    <Row className="g-lg-5 g-4">
                        <Col lg={6}>
                            <div className="d-flex align-items-center mb-2">
                                <div className="flex-shrink-0 me-1">
                                    <i className="ri-question-line fs-24 align-middle text-success me-1"></i>
                                </div>
                                <div className="flex-grow-1">
                                    <h5 className="mb-0 fw-bold">Plataforma</h5>
                                </div>
                            </div>
                            <div className="accordion custom-accordionwithicon custom-accordion-border accordion-border-box"
                                id="genques-accordion">
                                <div className="accordion-item">
                                    <h2 className="accordion-header" id="genques-headingOne">
                                        <button
                                            className={classnames(
                                                "accordion-button",
                                                "fw-semibold fs-14",
                                                { collapsed: !col1 }
                                            )}
                                            type="button"
                                            onClick={t_col1}
                                            style={{ cursor: "pointer" }}
                                        >
                                            Como funciona a Xplendor?
                                        </button>
                                    </h2>
                                    <Collapse isOpen={col1} className="accordion-collapse">
                                        <div className="accordion-body ff-secondary">
                                            A Xplendor é uma plataforma criada para stands automóveis gerirem viaturas,
                                            leads e vendas num único sistema. Cada carro tem uma página própria otimizada
                                            para conversão e pode ser promovido através de tráfego pago.
                                        </div>
                                    </Collapse>
                                </div>
                                <div className="accordion-item">
                                    <h2 className="accordion-header" id="genques-headingTwo">
                                        <button
                                            className={classnames(
                                                "accordion-button",
                                                "fw-semibold fs-14",
                                                { collapsed: !col2 }
                                            )}
                                            type="button"
                                            onClick={t_col2}
                                            style={{ cursor: "pointer" }}
                                        >
                                            A Xplendor substitui os marketplaces como Standvirtual ou OLX?
                                        </button>
                                    </h2>
                                    <Collapse isOpen={col2} className="accordion-collapse">
                                        <div className="accordion-body ff-secondary">
                                            Não necessariamente. A Xplendor funciona como a sua própria plataforma de vendas,
                                            permitindo gerar tráfego direto para as páginas das viaturas e controlar
                                            todos os dados dos clientes.
                                        </div>
                                    </Collapse>
                                </div>
                                <div className="accordion-item">
                                    <h2 className="accordion-header" id="genques-headingThree">
                                        <button
                                            className={classnames(
                                                "accordion-button",
                                                "fw-semibold fs-14",
                                                { collapsed: !col3 }
                                            )}
                                            type="button"
                                            onClick={t_col3}
                                            style={{ cursor: "pointer" }}
                                        >
                                            Preciso de ter um website para usar a Xplendor?
                                        </button>
                                    </h2>
                                    <Collapse isOpen={col3} className="accordion-collapse">
                                        <div className="accordion-body ff-secondary">
                                            Não. A Xplendor cria páginas de viaturas otimizadas automaticamente.
                                            No entanto, pode também integrar facilmente com o seu site atual.
                                        </div>
                                    </Collapse>
                                </div>
                                <div className="accordion-item">
                                    <h2 className="accordion-header" id="genques-headingFour">
                                        <button
                                            className={classnames(
                                                "accordion-button",
                                                "fw-semibold fs-14",
                                                { collapsed: !col4 }
                                            )}
                                            type="button"
                                            onClick={t_col4}
                                            style={{ cursor: "pointer" }}
                                        >
                                            Posso promover as viaturas com anúncios pagos?
                                        </button>
                                    </h2>
                                    <Collapse isOpen={col4} className="accordion-collapse">
                                        <div className="accordion-body ff-secondary">
                                            Sim. A Xplendor foi criada para funcionar perfeitamente com tráfego pago.
                                            Pode enviar anúncios diretamente para as páginas das viaturas e acompanhar
                                            resultados como visitas, leads e conversões.
                                        </div>
                                    </Collapse>
                                </div>
                            </div>
                        </Col>

                        <Col lg={6}>
                            <div className="d-flex align-items-center mb-2">
                                <div className="flex-shrink-0 me-1">
                                    <i className="ri-shield-keyhole-line fs-24 align-middle text-success me-1"></i>
                                </div>
                                <div className="flex-grow-1">
                                    <h5 className="mb-0 fw-bold">Dados e Segurança</h5>
                                </div>
                            </div>

                            <div className="accordion custom-accordionwithicon custom-accordion-border accordion-border-box"
                                id="privacy-accordion">
                                <div className="accordion-item">
                                    <h2 className="accordion-header" id="privacy-headingOne">
                                        <button
                                            className={classnames(
                                                "accordion-button",
                                                "fw-semibold fs-14",
                                                { collapsed: !col9 }
                                            )}
                                            type="button"
                                            onClick={t_col5}
                                            style={{ cursor: "pointer" }}
                                        >
                                            A Xplendor guarda os contactos dos clientes?
                                        </button>
                                    </h2>
                                    <Collapse isOpen={col9} className="accordion-collapse">
                                        <div className="accordion-body ff-secondary">
                                            Sim. Todos os contactos gerados nas páginas das viaturas
                                            ficam registados no sistema para acompanhamento e gestão de leads.
                                        </div>
                                    </Collapse>
                                </div>
                                <div className="accordion-item">
                                    <h2 className="accordion-header" id="privacy-headingTwo">
                                        <button
                                            className={classnames(
                                                "accordion-button",
                                                "fw-semibold fs-14",
                                                { collapsed: !col10 }
                                            )}
                                            type="button"
                                            onClick={t_col6}
                                            style={{ cursor: "pointer" }}
                                        >
                                            Consigo ver quais carros geram mais interesse?
                                        </button>
                                    </h2>
                                    <Collapse isOpen={col10} className="accordion-collapse">
                                        <div className="accordion-body ff-secondary">
                                            Sim. A plataforma mostra estatísticas como visualizações de carros,
                                            contactos recebidos e performance das campanhas.
                                        </div>
                                    </Collapse>
                                </div>
                                <div className="accordion-item">
                                    <h2 className="accordion-header" id="privacy-headingThree">
                                        <button
                                            className={classnames(
                                                "accordion-button",
                                                "fw-semibold fs-14",
                                                { collapsed: !col11 }
                                            )}
                                            type="button"
                                            onClick={t_col7}
                                            style={{ cursor: "pointer" }}
                                        >
                                            Quantas viaturas posso adicionar?
                                        </button>
                                    </h2>
                                    <Collapse isOpen={col11} className="accordion-collapse">
                                        <div className="accordion-body ff-secondary">
                                            Depende do plano escolhido. Pode começar com um número reduzido
                                            e aumentar conforme o crescimento do seu stock.
                                        </div>
                                    </Collapse>
                                </div>
                                <div className="accordion-item">
                                    <h2 className="accordion-header" id="privacy-headingFour">
                                        <button
                                            className={classnames(
                                                "accordion-button",
                                                "fw-semibold fs-14",
                                                { collapsed: !col12 }
                                            )}
                                            type="button"
                                            onClick={t_col8}
                                            style={{ cursor: "pointer" }}
                                        >
                                            É difícil começar a usar?
                                        </button>
                                    </h2>
                                    <Collapse isOpen={col12} className="accordion-collapse">
                                        <div className="accordion-body ff-secondary">
                                            Não. O processo é simples: cria a conta, adiciona as viaturas e começa a receber contactos em minutos.
                                        </div>
                                    </Collapse>
                                </div>
                            </div>

                            {/* <!--end accordion--> */}
                        </Col>
                    </Row>
                </Container>
            </section>
        </React.Fragment>
    );
};

export default Faqs;