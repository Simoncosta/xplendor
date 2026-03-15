import React from 'react';
import { Card, CardBody, Col, Container, Row } from 'reactstrap';

const whatsappNumber = "351938963526";

const getWhatsappUrl = (plan: string) => {
    const message = encodeURIComponent(
        `Olá! Tenho interesse no plano ${plan} da Xplendor. Podem explicar-me como funciona?`
    );
    return `https://wa.me/${whatsappNumber}?text=${message}`;
};

const plans = [
    {
        name: "Starter",
        description: "Para stands a começar com dados",
        price: "49",
        icon: "ri-book-mark-line",
        popular: false,
        features: [
            { text: "Até 20 viaturas activas", included: true },
            { text: "Dashboard do stand", included: true },
            { text: "Gestão de leads", included: true },
            { text: "X-TAG de tracking", included: true },
            { text: "Views e canais por carro", included: true },
            { text: "IPS (Índice de Potencial de Venda)", included: false },
            { text: "Analytics IA por viatura", included: false },
        ],
    },
    {
        name: "Growth",
        description: "Para stands que querem vender com inteligência",
        price: "99",
        icon: "ri-medal-fill",
        popular: true,
        features: [
            { text: "Até 60 viaturas activas", included: true },
            { text: "Dashboard do stand completo", included: true },
            { text: "CRM de leads com histórico", included: true },
            { text: "X-TAG de tracking avançado", included: true },
            { text: "IPS (Índice de Potencial de Venda)", included: true },
            { text: "Analytics IA por viatura", included: true },
            { text: "Alertas automáticos de stock", included: true },
        ],
    },
    {
        name: "Pro",
        description: "Para stands que querem escalar",
        price: "199",
        icon: "ri-stack-fill",
        popular: false,
        features: [
            { text: "Viaturas ilimitadas", included: true },
            { text: "Tudo do Growth", included: true },
            { text: "XPLDR Intelligence (consultor IA)", included: true },
            { text: "Integração Meta Ads + Google Ads", included: true },
            { text: "Automação de conteúdo (SmartAds)", included: true },
            { text: "API e integrações personalizadas", included: true },
            { text: "Suporte prioritário", included: true },
        ],
    },
];

const Plans = () => {
    return (
        <React.Fragment>
            <section className="section bg-light" id="plans">
                <div className="bg-overlay bg-overlay-pattern"></div>
                <Container>
                    <Row className="justify-content-center">
                        <Col lg={7}>
                            <div className="text-center mb-5">
                                <h2 className="fw-bold mb-3">
                                    Preços simples, valor real
                                </h2>
                                <p className="text-muted fs-15">
                                    Sem contratos anuais forçados. Começa quando quiseres, cancela quando quiseres.
                                    O primeiro mês é gratuito — sem cartão.
                                </p>
                                <div className="d-inline-flex align-items-center gap-2 badge bg-success-subtle text-success rounded-pill px-3 py-2 fs-13">
                                    <i className="ri-shield-check-line"></i>
                                    30 dias grátis · Sem cartão de crédito
                                </div>
                            </div>
                        </Col>
                    </Row>

                    <Row className="gy-4 justify-content-center">
                        {plans.map((p, idx) => (
                            <Col lg={4} key={idx}>
                                <Card
                                    className="plan-box mb-0 h-100"
                                    style={p.popular ? {} : {}}
                                >
                                    {p.popular && (
                                        <div className="text-center py-1 bg-primary rounded-top" style={{ fontSize: 12, color: "#fff", fontWeight: 600 }}>
                                            Mais escolhido
                                        </div>
                                    )}
                                    <CardBody className="p-4 d-flex flex-column">
                                        <div className="d-flex align-items-center mb-3">
                                            <div className="flex-grow-1">
                                                <h5 className="mb-1 fw-bold">{p.name}</h5>
                                                <p className="text-muted mb-0 fs-13">{p.description}</p>
                                            </div>
                                            <div className="avatar-sm">
                                                <div className="avatar-title bg-primary-subtle rounded-circle text-primary">
                                                    <i className={`${p.icon} fs-20`}></i>
                                                </div>
                                            </div>
                                        </div>

                                        <div className="py-3 border-top border-bottom mb-3">
                                            <h1 className="mb-0">
                                                <span className="fw-bold">{p.price}</span>
                                                <sup><small>€</small></sup>
                                                <span className="fs-13 text-muted fw-normal"> /mês</span>
                                            </h1>
                                            <p className="text-muted mb-0 fs-12 mt-1">
                                                Primeiro mês grátis
                                            </p>
                                        </div>

                                        <ul className="list-unstyled text-muted vstack gap-2 mb-4 flex-grow-1">
                                            {p.features.map((f, fidx) => (
                                                <li key={fidx}>
                                                    <div className="d-flex align-items-center gap-2">
                                                        <i className={`fs-15 ${f.included ? "ri-checkbox-circle-fill text-success" : "ri-close-circle-fill text-danger"}`}></i>
                                                        <span className={f.included ? "" : "text-decoration-line-through opacity-50"}>
                                                            {f.text}
                                                        </span>
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>

                                        <a
                                            href={getWhatsappUrl(p.name)}
                                            target="_blank"
                                            rel="noreferrer"
                                            className={`btn w-100 ${p.popular ? "btn-primary" : "btn-outline-primary"}`}
                                        >
                                            <i className="ri-whatsapp-line me-2"></i>
                                            Começar no WhatsApp
                                        </a>
                                    </CardBody>
                                </Card>
                            </Col>
                        ))}
                    </Row>

                    {/* Nota de transparência */}
                    <Row className="justify-content-center mt-4">
                        <Col lg={8}>
                            <div className="text-center text-muted" style={{ fontSize: 13 }}>
                                <i className="ri-information-line me-1"></i>
                                Não sabes qual plano escolher? Fala connosco — analisamos o teu stand e recomendamos o melhor caminho.
                                <a
                                    href={getWhatsappUrl("adequado ao meu stand")}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="text-primary ms-1 fw-medium"
                                >
                                    Falar no WhatsApp
                                </a>
                            </div>
                        </Col>
                    </Row>

                </Container>
            </section>
        </React.Fragment>
    );
};

export default Plans;