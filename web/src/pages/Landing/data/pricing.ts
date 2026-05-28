export interface PricingFeature {
    text: string;
}

export interface PricingPlan {
    id: string;
    name: string;
    priceFrom: number;
    pricePeriod: string;
    priceNote: string | null;
    description: string;
    featured: boolean;
    badge: string | null;
    features: PricingFeature[];
    adsMinBudget: number | null;
    adsBudgetNote: string;
    ctaLabel: string;
}

export const VAT_RATE = 0.23;

/** Formata o número do preço no formato tradicional português (ponto de
 *  milhar, vírgula decimal: 1.094,70). Sem IVA: inteiro (490). Com IVA:
 *  valor × 1.23 com 2 decimais (602,70). Devolve só o número — o "€" fica
 *  no markup. Fonte única do cálculo de IVA.
 *
 *  Usa 'de-DE' (não 'pt-PT') de propósito: o CLDR moderno de pt-PT usa
 *  espaço estreito como separador de milhar e suprime grupos de 4 dígitos;
 *  'de-DE' garante deterministicamente ponto de milhar + vírgula decimal. */
export const formatPlanPrice = (priceFrom: number, withVat: boolean): string => {
    const value = withVat ? priceFrom * (1 + VAT_RATE) : priceFrom;
    return value.toLocaleString('de-DE', {
        minimumFractionDigits: withVat ? 2 : 0,
        maximumFractionDigits: withVat ? 2 : 0,
    });
};

export const PRICING_PLANS: PricingPlan[] = [
    {
        id: 'digital',
        name: 'Stand Digital',
        priceFrom: 490,
        pricePeriod: '/mês',
        priceNote: null,
        description: 'Presença digital consistente e profissional.',
        featured: false,
        badge: null,
        features: [
            { text: 'Site próprio optimizado para SEO' },
            { text: 'Ficha de viatura com tracking de visitas' },
            { text: 'Google Business Profile gerido' },
            { text: 'Relatório mensal de visitas e leads' },
            { text: 'Acompanhamento mensal por contacto directo' },
        ],
        adsMinBudget: 150,
        adsBudgetNote: 'Investimento mínimo de €150/mês em boost de publicações orgânicas',
        ctaLabel: 'Quero saber mais',
    },
    {
        id: 'profissional',
        name: 'Stand Profissional',
        priceFrom: 890,
        pricePeriod: '/mês',
        priceNote: '+ budget de ads',
        description: 'Site, campanhas e criativos geridos de raiz.',
        featured: true,
        badge: 'Mais popular',
        features: [
            { text: 'Tudo do Stand Digital' },
            { text: 'Campanhas Facebook e Instagram geridas' },
            { text: 'Criativos mensais (foto + copy)' },
            { text: 'Tracking de custo por lead, viatura a viatura' },
            { text: 'Reunião mensal de resultados' },
        ],
        adsMinBudget: 600,
        adsBudgetNote: 'Investimento mínimo de €300/mês em campanhas pagas Meta',
        ctaLabel: 'Quero saber mais',
    },
    {
        id: 'performance',
        name: 'Stand Performance',
        priceFrom: 1490,
        pricePeriod: '/mês',
        priceNote: '+ budget de ads',
        description: 'Todos os canais, máxima cobertura.',
        featured: false,
        badge: null,
        features: [
            { text: 'Tudo do Stand Profissional' },
            { text: 'Campanhas Google Ads geridas' },
            { text: 'Análise comparativa de mercado por viatura' },
            { text: 'Scoring de potencial de venda por viatura' },
            { text: 'Acesso à plataforma XPLENDOR' },
        ],
        adsMinBudget: 1500,
        adsBudgetNote: 'Investimento mínimo de €750/mês em campanhas Meta + Google',
        ctaLabel: 'Quero saber mais',
    },
];
