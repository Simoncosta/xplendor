import React from 'react';
import { Container } from 'reactstrap';
import CTAButton from '../components/CTAButton';
import { Reveal, RevealGroup, RevealItem } from '../lib/motion';
import { CTA_WHATSAPP_URL, buildWhatsAppUrl } from '../data/constants';
import { PRICING_PLANS } from '../data/pricing';

/**
 * PREÇOS — clareza sobre o modelo:
 *  1) PLATAFORMA: produto com preço PRÓPRIO (pode ser subscrita isolada).
 *  2) SERVIÇOS de agência: pacotes mensais (números reais de data/pricing.ts).
 *
 * O preço exato da plataforma fica em placeholder ("sob consulta") — o Simon
 * decide se publica número; a estrutura já está pronta para o receber.
 */

// Placeholder do preço da plataforma. Trocar por número quando o Simon decidir.
const PLATFORM_PRICE_LABEL: string | null = null; // ex.: '49' → mostra "49€/mês"
const PLATFORM_FEATURES = [
    'Gestão de stock e fichas de viatura',
    'Leads com origem + centro de ações',
    'Pós-venda e relatório de satisfação',
    'Documentos de venda automáticos',
];

const PricingSection: React.FC = () => (
    <section id="precos" className="lp-section2 lp-section2-alt">
        <Container>
            <Reveal className="lp-section2-head">
                <span className="lp-kicker">Preços</span>
                <h2 className="lp-h2">Serviços de agência e plataforma — separados e claros</h2>
                <p className="lp-lead">
                    A plataforma tem preço próprio e pode ser usada sozinha. Os serviços de
                    marketing são pacotes mensais à parte. Junta os dois se fizer sentido.
                </p>
            </Reveal>

            {/* 1) A PLATAFORMA como produto — SEMPRE visível (sem gate de
                   scroll-reveal, que a deixava presa em opacity:0 e "invisível"). */}
            <div className="lp-platform-price">
                <div className="lp-platform-price-main">
                    <span className="lp-offer-tag lp-offer-tag-platform">Plataforma</span>
                    <h3 className="lp-h3">Só queres a plataforma? Podes tê-la sozinha.</h3>
                    <p className="lp-feature-text">
                        A plataforma XPLENDOR tem preço próprio e contrata-se de forma
                        independente — <strong>não precisas de contratar serviços para a usar</strong>.
                    </p>
                    <div className="lp-platform-amount">
                        {PLATFORM_PRICE_LABEL ? (
                            <><span className="lp-platform-value">{PLATFORM_PRICE_LABEL}€</span><span className="lp-platform-period">/mês</span></>
                        ) : (
                            <span className="lp-platform-consult">Sob consulta</span>
                        )}
                    </div>
                    <CTAButton href={CTA_WHATSAPP_URL} size="sm">
                        <i className="ri-whatsapp-line" aria-hidden="true" />
                        Pedir preço da plataforma
                    </CTAButton>
                </div>
                <ul className="lp-platform-features">
                    {PLATFORM_FEATURES.map((f) => (
                        <li key={f}><i className="ri-checkbox-circle-fill" aria-hidden="true" />{f}</li>
                    ))}
                </ul>
            </div>

            {/* 2) Os pacotes de serviços */}
            <div className="lp-services-head">
                <h3 className="lp-h3">Pacotes de serviços de marketing</h3>
                <p className="lp-feature-text">Valores mensais, sem fidelização longa. O budget de anúncios é à parte.</p>
            </div>

            <RevealGroup className="lp-plans-grid">
                {PRICING_PLANS.map((plan) => (
                    <RevealItem key={plan.id} className={`lp-plan-card${plan.featured ? ' is-featured' : ''}`}>
                        {plan.badge && <span className="lp-plan-badge">{plan.badge}</span>}
                        <h4 className="lp-plan-name">{plan.name}</h4>
                        <p className="lp-plan-desc">{plan.description}</p>
                        <div className="lp-plan-price">
                            <span className="lp-plan-from">desde</span>
                            <span className="lp-plan-value">{plan.priceFrom}€</span>
                            <span className="lp-plan-period">{plan.pricePeriod}</span>
                        </div>
                        {plan.priceNote && <span className="lp-plan-note">{plan.priceNote}</span>}
                        <ul className="lp-plan-features">
                            {plan.features.map((feat) => (
                                <li key={feat.text}><i className="ri-check-line" aria-hidden="true" />{feat.text}</li>
                            ))}
                        </ul>
                        <CTAButton
                            href={buildWhatsAppUrl(plan.name)}
                            size="sm"
                            variant={plan.featured ? 'primary' : 'outline'}
                            className="w-100 justify-content-center"
                        >
                            {plan.ctaLabel}
                        </CTAButton>
                    </RevealItem>
                ))}
            </RevealGroup>

            <p className="lp-price-vat-note">Valores sem IVA. O investimento em anúncios (budget) é pago diretamente às plataformas.</p>
        </Container>
    </section>
);

export default PricingSection;
