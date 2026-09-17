import React from 'react';
import { Link } from 'react-router-dom';
import { Container } from 'reactstrap';
import { Reveal } from '../lib/motion';
import CTAButton from '../components/CTAButton';
import { CTA_WHATSAPP_URL } from '../data/constants';

/**
 * CTA FINAL — fecho forte antes do rodapé. Fundo escuro (marca), 2 caminhos:
 * falar connosco (serviços) ou entrar/ver a plataforma.
 */
const FinalCTANew: React.FC = () => (
    <section id="contacto" className="lp-finalcta">
        <div className="lp-finalcta-glow" aria-hidden="true" />
        <Container>
            <Reveal className="lp-finalcta-inner">
                <h2 className="lp-finalcta-title">Pronto para o seu stand vender mais?</h2>
                <p className="lp-finalcta-sub">
                    Uma conversa de 15 minutos chega para perceber se fazemos sentido para si.
                    Sem compromisso.
                </p>
                <div className="lp-hero2-cta lp-finalcta-actions">
                    <CTAButton href={CTA_WHATSAPP_URL}>
                        <i className="ri-whatsapp-line" aria-hidden="true" />
                        Falar connosco
                    </CTAButton>
                    <Link to="/login" className="lp-cta-btn lp-cta-btn-outline lp-cta-btn-ondark">
                        <i className="ri-login-circle-line" aria-hidden="true" />
                        Entrar na plataforma
                    </Link>
                </div>
            </Reveal>
        </Container>
    </section>
);

export default FinalCTANew;
