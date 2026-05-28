import React from 'react';
import { Col, Row } from 'reactstrap';
import Section from '../components/Section';
import PricingCard from '../components/PricingCard';
import { PRICING_PLANS } from '../data/pricing';
import { CTA_WHATSAPP_URL } from '../data/constants';

const Pricing: React.FC = () => (
    <Section id="pacotes" variant="alt">
        <div className="text-center mb-5">
            <p className="lp-label">Pacotes</p>
            <h2 className="lp-section-title">Preços sem letra pequena</h2>
            <p className="lp-section-sub mx-auto mt-2">
                Período mínimo de 6 meses. Pré-aviso de 30 dias para sair. Budget de ads
                não incluído nos valores indicados. Valores sem IVA à taxa legal em vigor (23%).
            </p>
        </div>

        <Row className="g-4 align-items-stretch justify-content-center">
            {PRICING_PLANS.map((plan) => (
                <Col md={6} lg={4} key={plan.id} className="d-flex">
                    <PricingCard plan={plan} ctaHref={CTA_WHATSAPP_URL} />
                </Col>
            ))}
        </Row>
    </Section>
);

export default Pricing;
