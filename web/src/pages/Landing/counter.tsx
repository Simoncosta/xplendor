import React from 'react';
import { Col, Container, Row } from 'reactstrap';
import CountUp from "react-countup";

// Números baseados em dados reais da plataforma
const counters = [
    {
        end: 1871,
        suffix: "+",
        label: "Visualizações de viaturas",
        sublabel: "nos últimos 30 dias",
        icon: "ri-eye-line",
        color: "text-primary",
    },
    {
        end: 40,
        suffix: "",
        label: "Viaturas com tracking activo",
        sublabel: "dados reais em tempo real",
        icon: "ri-car-line",
        color: "text-success",
    },
    {
        end: 5,
        suffix: "",
        label: "Canais de tráfego identificados",
        sublabel: "pago, orgânico, direto, social, referral",
        icon: "ri-bar-chart-2-line",
        color: "text-info",
    },
    {
        end: 8,
        suffix: " dias",
        label: "Média até primeira lead",
        sublabel: "após publicação da viatura",
        icon: "ri-timer-line",
        color: "text-warning",
    },
];

const Counter = () => {
    return (
        <React.Fragment>
            <section className="py-5 position-relative bg-light">
                <Container>
                    <Row className="justify-content-center mb-4">
                        <Col lg={6}>
                            <div className="text-center">
                                <p className="text-muted fs-14 mb-0">
                                    Números reais da plataforma em funcionamento
                                </p>
                            </div>
                        </Col>
                    </Row>

                    <Row className="text-center gy-4">
                        {counters.map((c, idx) => (
                            <Col lg={3} xs={6} key={idx}>
                                <div>
                                    <div className={`${c.color} mb-2`}>
                                        <i className={`${c.icon} fs-28`}></i>
                                    </div>
                                    <h2 className="mb-1 fw-bold">
                                        <CountUp start={0} end={c.end} duration={3} />
                                        {c.suffix}
                                    </h2>
                                    <div className="fw-medium text-dark mb-1" style={{ fontSize: 14 }}>
                                        {c.label}
                                    </div>
                                    <div className="text-muted" style={{ fontSize: 12 }}>
                                        {c.sublabel}
                                    </div>
                                </div>
                            </Col>
                        ))}
                    </Row>
                </Container>
            </section>
        </React.Fragment>
    );
};

export default Counter;