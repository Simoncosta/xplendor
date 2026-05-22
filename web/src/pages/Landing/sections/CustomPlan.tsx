import React from 'react';
import { Container } from 'reactstrap';
import CTAButton from '../components/CTAButton';
import { CTA_WHATSAPP_URL } from '../data/constants';

const CustomPlan: React.FC = () => (
    <section className="lp-custom-plan lp-reveal">
        <Container>
            <div className="lp-custom-plan-box">
                <h3 className="lp-custom-plan-title">Precisa de algo diferente?</h3>
                <p className="lp-custom-plan-text">
                    Cada stand tem a sua realidade. Se quer combinar de forma diferente —
                    só site, só redes sociais, com ou sem tráfego pago — falamos consigo e
                    desenhamos uma proposta à medida. Os pacotes acima são o nosso ponto de
                    partida, não uma camisa de força.
                </p>
                <CTAButton href={CTA_WHATSAPP_URL} size="sm">
                    <i className="ri-whatsapp-line" aria-hidden="true" />
                    Falar connosco
                </CTAButton>
            </div>
        </Container>
    </section>
);

export default CustomPlan;
