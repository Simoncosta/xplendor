import React from 'react';
import { Col, Container, Row } from 'reactstrap';

const services = [
    {
        icon: "ri-award-line",
        color: "text-primary",
        title: "Índice de Potencial de Venda",
        description: "Cada carro recebe um score de 0 a 100. Sabes ao abrir o dashboard quais vender primeiro, quais ajustar o preço e quais promover.",
        highlight: "IPS calculado automaticamente",
    },
    {
        icon: "ri-line-chart-line",
        color: "text-success",
        title: "Tracking por viatura e por canal",
        description: "Views, cliques de WhatsApp, chamadas e leads — tudo atribuído ao canal que gerou. Sem achismos, sem depender de relatórios externos.",
        highlight: "Google, Meta, Direto, Orgânico",
    },
    {
        icon: "ri-user-follow-line",
        color: "text-info",
        title: "Leads centralizados e com histórico",
        description: "Cada lead tem a viatura, o canal de origem, o histórico de contacto e o estado da negociação. Follow-up rápido, sem perder nenhum contacto.",
        highlight: "Resposta mais rápida = mais vendas",
    },
    {
        icon: "ri-bar-chart-box-line",
        color: "text-warning",
        title: "Dashboard do stand completo",
        description: "Inventário, capital parado, carros sem views, carros quentes e performance de marketing — tudo numa única vista, todas as manhãs.",
        highlight: "Decisões em segundos, não horas",
    },
    {
        icon: "ri-robot-2-line",
        color: "text-danger",
        title: "Inteligência automática",
        description: "A plataforma analisa os dados todas as noites e envia alertas quando um carro precisa de atenção — preço, anúncio ou promoção.",
        highlight: "Zero trabalho manual de análise",
    },
    {
        icon: "ri-shield-check-line",
        color: "text-primary",
        title: "Independência dos marketplaces",
        description: "O teu site, os teus dados, os teus clientes. Constrói uma audiência própria e reduz a dependência de plataformas que ficam com a comissão.",
        highlight: "Os teus dados são teus",
    },
];

const Services = () => {
    return (
        <React.Fragment>
            <section className="section" id="services">
                <Container>
                    <Row className="justify-content-center">
                        <Col lg={8}>
                            <div className="text-center mb-5">
                                <h2 className="mb-3 fw-bold lh-base">
                                    Tudo o que um stand precisa para{" "}
                                    <span className="text-primary">vender com inteligência</span>
                                </h2>
                                <p className="text-muted fs-15">
                                    A Xplendor centraliza os dados do teu stand e diz-te exactamente onde focar energia — sem precisar de agência, sem depender de marketplace.
                                </p>
                            </div>
                        </Col>
                    </Row>

                    <Row className="g-4">
                        {services.map((service, idx) => (
                            <Col lg={4} md={6} key={idx}>
                                <div
                                    className="p-4 h-100 rounded"
                                    style={{
                                        border: "1px dashed #e9ebec",
                                        background: "#fff",
                                        transition: "box-shadow .2s",
                                    }}
                                    onMouseEnter={e => (e.currentTarget.style.boxShadow = "0 4px 20px rgba(0,0,0,.07)")}
                                    onMouseLeave={e => (e.currentTarget.style.boxShadow = "none")}
                                >
                                    <div className="mb-3">
                                        <i className={`${service.icon} ${service.color}`} style={{ fontSize: 36 }}></i>
                                    </div>
                                    <h5 className="fw-semibold mb-2">{service.title}</h5>
                                    <p className="text-muted mb-3" style={{ fontSize: 14, lineHeight: 1.6 }}>
                                        {service.description}
                                    </p>
                                    <span
                                        className="badge bg-primary-subtle text-primary rounded-pill"
                                        style={{ fontSize: 11 }}
                                    >
                                        {service.highlight}
                                    </span>
                                </div>
                            </Col>
                        ))}
                    </Row>
                </Container>
            </section>
        </React.Fragment>
    );
};

export default Services;