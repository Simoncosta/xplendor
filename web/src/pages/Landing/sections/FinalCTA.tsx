import React from 'react';
import { Container } from 'reactstrap';
import CTAButton from '../components/CTAButton';
import { CTA_WHATSAPP_URL } from '../data/constants';

const FinalCTA: React.FC = () => (
    <section className="lp-final-cta lp-reveal" aria-labelledby="final-cta-heading">
        <Container>
            <h2 id="final-cta-heading">Vamos conversar 15 minutos?</h2>
            <p>
                15 minutos chegam para perceber se faz sentido para o seu stand.
                Sem compromisso, sem proposta na primeira conversa.
            </p>
            <CTAButton href={CTA_WHATSAPP_URL}>
                <i className="ri-whatsapp-line" aria-hidden="true" />
                Marcar conversa
            </CTAButton>
        </Container>
    </section>
);

export default FinalCTA;
