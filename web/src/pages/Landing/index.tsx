import React, { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import './landing.css';

import { isStandalone } from '../../helpers/pwa';
import { initMetaPixel } from './lib/metaPixel';
import LandingNav from './components/LandingNav';
import LandingFooter from './components/LandingFooter';
import Hero from './sections/Hero';
import ProblemSection from './sections/ProblemSection';
import HowItWorks from './sections/HowItWorks';
import MarketAnalysis from './sections/MarketAnalysis';
import Differentiators from './sections/Differentiators';
import Pricing from './sections/Pricing';
import CustomPlan from './sections/CustomPlan';
// SocialProof: reactivar quando tivermos 3+ logos autorizados de clientes reais
// import SocialProof from './sections/SocialProof';
import FAQSection from './sections/FAQSection';
import FinalCTA from './sections/FinalCTA';

const Landing: React.FC = () => {
    const navigate = useNavigate();

    // PWA: se a app está instalada (standalone), nunca mostramos a landing —
    // vamos direto para /dashboard, que encaminha para /login se não autenticado.
    // (Reforça o start_url do manifest; o browser normal continua a ver a landing.)
    useEffect(() => {
        if (isStandalone()) {
            navigate('/dashboard', { replace: true });
        }
    }, [navigate]);

    useEffect(() => {
        document.title =
            'XPLENDOR — Marketing digital para stands automóveis em Portugal';
    }, []);

    // Meta Pixel: só na landing, só após consentimento. Inicializa se já
    // consentido; senão fica à escuta do evento disparado pelo CookieBanner.
    useEffect(() => {
        if (localStorage.getItem('xplendor_cookie_consent') === 'granted') {
            initMetaPixel();
        }

        const onConsentGranted = () => initMetaPixel();
        window.addEventListener('xplendor-consent-granted', onConsentGranted);
        return () =>
            window.removeEventListener('xplendor-consent-granted', onConsentGranted);
    }, []);

    useEffect(() => {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        const elements = document.querySelectorAll<HTMLElement>('.lp-reveal');

        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('lp-revealed');
                        observer.unobserve(entry.target);
                    }
                });
            },
            { threshold: 0.07, rootMargin: '0px 0px -32px 0px' }
        );

        elements.forEach((el) => {
            el.classList.add('lp-hidden');
            observer.observe(el);
        });

        return () => observer.disconnect();
    }, []);

    return (
        <div className="xplndor-landing">
            <LandingNav />
            <main>
                <Hero />
                <ProblemSection />
                <HowItWorks />
                <MarketAnalysis />
                <Differentiators />
                <Pricing />
                <CustomPlan />
                {/* SocialProof: reactivar quando tivermos 3+ logos autorizados de clientes reais */}
                {/* <SocialProof /> */}
                <FAQSection />
                <FinalCTA />
            </main>
            <LandingFooter />
        </div>
    );
};

export default Landing;
