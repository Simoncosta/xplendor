import React from 'react';
import { Col, Container, Row } from 'reactstrap';
import CTAButton from '../components/CTAButton';
import { CTA_WHATSAPP_URL } from '../data/constants';

interface BarItem {
    name: string;
    color: string;
    width: number;
    count: number;
}

const BARS: BarItem[] = [
    { name: 'Meta Ads',  color: 'var(--lp-accent)', width: 78, count: 32 },
    { name: 'Google',    color: '#4285f4',           width: 45, count: 8  },
    { name: 'WhatsApp',  color: '#25d366',           width: 28, count: 4  },
    { name: 'Orgânico',  color: '#adb5bd',           width: 16, count: 2  },
    { name: 'Directo',   color: '#ced4da',           width: 9,  count: 1  },
];

const HeroDashboard: React.FC = () => (
    <div className="lp-hero-dashboard" aria-hidden="true">
        <div className="lp-dash-header">
            <span className="lp-dash-title">Performance do stock</span>
            <span className="lp-dash-trend">↑ +18% vs mês anterior</span>
        </div>

        <div className="lp-dash-kpis">
            <div className="lp-dash-kpi">
                <span className="lp-dash-kpi-value">47</span>
                <span className="lp-dash-kpi-label">Leads (mês)</span>
            </div>
            <div className="lp-dash-kpi lp-dash-kpi-mid">
                <span className="lp-dash-kpi-value">€8,20</span>
                <span className="lp-dash-kpi-label">Custo/lead</span>
            </div>
            <div className="lp-dash-kpi">
                <span className="lp-dash-kpi-value">32</span>
                <span className="lp-dash-kpi-label">Viaturas activas</span>
            </div>
        </div>

        <div className="lp-dash-chart">
            <p className="lp-dash-chart-title">Leads por canal</p>
            <div className="lp-dash-bars">
                {BARS.map((bar) => (
                    <div key={bar.name} className="lp-dash-bar-row">
                        <span className="lp-dash-bar-name">{bar.name}</span>
                        <div className="lp-dash-bar-track">
                            <div
                                className="lp-dash-bar-fill"
                                style={{ width: `${bar.width}%`, background: bar.color }}
                            />
                        </div>
                        <span className="lp-dash-bar-count">{bar.count}</span>
                    </div>
                ))}
            </div>
        </div>
    </div>
);

const Hero: React.FC = () => (
    <section id="hero" className="lp-hero">
        <Container>
            <Row className="align-items-center g-5">
                <Col lg={6}>
                    <h1 className="lp-hero-title">
                        Marketing digital especializado em stands automóveis.
                    </h1>
                    <p className="lp-hero-sub">
                        Tratamos do seu site, das campanhas no Facebook, Instagram e Google,
                        dos criativos e do tracking — para o seu stand vender mais e depender
                        menos dos marketplaces.
                    </p>
                    <CTAButton href={CTA_WHATSAPP_URL}>
                        <i className="ri-whatsapp-line" aria-hidden="true" />
                        Marcar conversa de 15 minutos
                    </CTAButton>
                </Col>

                <Col lg={6}>
                    <HeroDashboard />
                </Col>
            </Row>
        </Container>
    </section>
);

export default Hero;
