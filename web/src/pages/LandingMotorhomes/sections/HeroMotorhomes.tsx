import React, { useEffect, useState } from 'react';
import { Col, Container, Row } from 'reactstrap';
import CountUp from 'react-countup';
import CTAButton from '../../Landing/components/CTAButton';
import { CTA_WHATSAPP_URL_MOTORHOMES } from '../../Landing/data/constants';

// Mockup do dashboard — duplicado do Hero principal de propósito, para manter
// a landing principal 100% intacta. Mantém as animações da Fase 3 (contadores
// + barras com stagger) e o respeito por prefers-reduced-motion.

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

interface KpiItem {
    end: number;
    label: string;
    prefix?: string;
    decimals?: number;
    staticText: string;
}

const KPIS: KpiItem[] = [
    { end: 47, label: 'Leads (mês)', staticText: '47' },
    { end: 8.2, label: 'Custo/lead', prefix: '€', decimals: 2, staticText: '€8,20' },
    { end: 32, label: 'Viaturas activas', staticText: '32' },
];

const prefersReducedMotion = (): boolean =>
    typeof window !== 'undefined' &&
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const HeroDashboard: React.FC = () => {
    const [reducedMotion] = useState(prefersReducedMotion);
    const [barsGrown, setBarsGrown] = useState(reducedMotion);

    useEffect(() => {
        if (reducedMotion) return;
        let inner = 0;
        const outer = requestAnimationFrame(() => {
            inner = requestAnimationFrame(() => setBarsGrown(true));
        });
        return () => {
            cancelAnimationFrame(outer);
            cancelAnimationFrame(inner);
        };
    }, [reducedMotion]);

    return (
        <div className="lp-hero-dashboard" aria-hidden="true">
            <div className="lp-dash-header">
                <span className="lp-dash-title">Performance do stock</span>
                <span className="lp-dash-trend">↑ +18% vs mês anterior</span>
            </div>

            <div className="lp-dash-kpis">
                {KPIS.map((kpi, i) => (
                    <div
                        key={kpi.label}
                        className={`lp-dash-kpi${i === 1 ? ' lp-dash-kpi-mid' : ''}`}
                    >
                        <span className="lp-dash-kpi-value">
                            {reducedMotion ? (
                                kpi.staticText
                            ) : (
                                <CountUp
                                    end={kpi.end}
                                    duration={1.6}
                                    delay={0.2}
                                    decimals={kpi.decimals}
                                    decimal=","
                                    prefix={kpi.prefix}
                                />
                            )}
                        </span>
                        <span className="lp-dash-kpi-label">{kpi.label}</span>
                    </div>
                ))}
            </div>

            <div className="lp-dash-chart">
                <p className="lp-dash-chart-title">Leads por canal</p>
                <div className="lp-dash-bars">
                    {BARS.map((bar, i) => (
                        <div key={bar.name} className="lp-dash-bar-row">
                            <span className="lp-dash-bar-name">{bar.name}</span>
                            <div className="lp-dash-bar-track">
                                <div
                                    className="lp-dash-bar-fill"
                                    style={{
                                        width: barsGrown ? `${bar.width}%` : '0%',
                                        background: bar.color,
                                        transitionDelay: reducedMotion ? undefined : `${i * 80}ms`,
                                    }}
                                />
                            </div>
                            <span className="lp-dash-bar-count">{bar.count}</span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
};

const HeroMotorhomes: React.FC = () => (
    <section id="hero" className="lp-hero">
        <Container>
            <Row className="align-items-center g-5">
                <Col lg={6}>
                    <h1 className="lp-hero-title">
                        Marketing digital especializado em stands de autocaravanas.
                    </h1>
                    <p className="lp-hero-sub">
                        Site próprio com filtros de habitação que os marketplaces não têm,
                        campanhas geridas e análise de mercado por viatura — para o seu stand
                        vender mais e depender menos do Standvirtual.
                    </p>
                    <CTAButton href={CTA_WHATSAPP_URL_MOTORHOMES}>
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

export default HeroMotorhomes;
