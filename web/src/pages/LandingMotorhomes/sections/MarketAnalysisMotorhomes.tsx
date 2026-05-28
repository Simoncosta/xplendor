import React from 'react';
import { Col, Row } from 'reactstrap';
import Section from '../../Landing/components/Section';

const MarketAnalysisMotorhomes: React.FC = () => (
    <Section id="analise-mercado">
        <Row className="align-items-center g-5">
            <Col lg={6}>
                <p className="lp-label">Análise de mercado</p>
                <h2 className="lp-section-title">
                    Sabemos quanto vale cada autocaravana no mercado
                </h2>
                <p className="lp-section-sub mt-3">
                    Monitorizamos diariamente o preço de autocaravanas comparáveis no mercado
                    português. Cada viatura do seu stock recebe um indicador — abaixo, dentro ou
                    acima do mercado.
                </p>
            </Col>

            <Col lg={6}>
                <div className="lp-market-card">
                    <img
                        src="https://placehold.co/640x360/1a2d5a/4a7fc1?text=Análise+de+mercado"
                        alt="Painel de análise de mercado por autocaravana"
                        className="lp-market-img"
                        width={640}
                        height={360}
                        loading="lazy"
                    />
                    <div className="lp-market-indicator" aria-label="Exemplo de indicador de autocaravana">
                        <div className="lp-market-top-row">
                            <span className="lp-market-name">Adria Matrix Plus 670 · 2020 · Diesel</span>
                            <span className="lp-market-badge-status within">
                                <i className="ri-check-line" aria-hidden="true" />
                                dentro do mercado
                            </span>
                        </div>

                        <div className="lp-market-price-row">
                            <span className="lp-market-price">€54.900</span>
                            <span className="lp-market-ref">
                                Referência: €52.500 – €57.300
                            </span>
                        </div>

                        <div className="lp-ips-row" aria-label="Índice de Potencial de Venda: 72 de 100">
                            <span className="lp-ips-label">IPS</span>
                            <div className="lp-ips-track" role="presentation">
                                <div className="lp-ips-fill" style={{ width: '72%' }} />
                            </div>
                            <span className="lp-ips-score">72 / 100</span>
                        </div>
                    </div>
                </div>
            </Col>
        </Row>
    </Section>
);

export default MarketAnalysisMotorhomes;
