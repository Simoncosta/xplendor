import React from 'react';
import { PricingPlan, formatPlanPrice } from '../data/pricing';
import CTAButton from './CTAButton';

interface PricingCardProps {
    plan: PricingPlan;
    ctaHref: string;
    withVat: boolean;
}

const PricingCard: React.FC<PricingCardProps> = ({ plan, ctaHref, withVat }) => (
    <div className={`lp-pricing-card${plan.featured ? ' featured' : ''}`}>
        {plan.badge && (
            <span className="lp-pricing-badge" aria-label="Plano mais popular">
                {plan.badge}
            </span>
        )}

        <p className="lp-pricing-name">{plan.name}</p>
        <p className="lp-pricing-desc">{plan.description}</p>

        <div className="lp-pricing-price">
            <sup>€</sup>
            {formatPlanPrice(plan.priceFrom, withVat)}
        </div>
        <p className="lp-pricing-suffix">
            desde{plan.pricePeriod}
            {plan.priceNote ? <span className="lp-pricing-note"> {plan.priceNote}</span> : null}
        </p>

        <ul className="lp-pricing-features" aria-label={`Incluído no plano ${plan.name}`}>
            {plan.features.map((feature, i) => (
                <li key={i}>
                    <i className="ri-check-line" aria-hidden="true" />
                    {feature.text}
                </li>
            ))}
        </ul>

        {plan.adsMinBudget !== null && (
            <div className="lp-pricing-ads-budget" aria-label="Investimento publicitário adicional">
                <span className="lp-pricing-ads-label">
                    <i className="ri-add-line" aria-hidden="true" />
                    Investimento publicitário
                </span>
                <p className="lp-pricing-ads-note">{plan.adsBudgetNote}</p>
            </div>
        )}

        <CTAButton href={ctaHref} className="w-100 justify-content-center">
            {plan.ctaLabel}
        </CTAButton>
    </div>
);

export default PricingCard;
