import React, { useEffect } from 'react';
import '../Landing/landing.css';

import { initMetaPixel } from '../Landing/lib/metaPixel';
import LandingNav from '../Landing/components/LandingNav';
import LandingFooter from '../Landing/components/LandingFooter';
import HowItWorks from '../Landing/sections/HowItWorks';
import Pricing from '../Landing/sections/Pricing';
import CustomPlan from '../Landing/sections/CustomPlan';
import FAQSection from '../Landing/sections/FAQSection';
import FinalCTA from '../Landing/sections/FinalCTA';

import HeroMotorhomes from './sections/HeroMotorhomes';
import ProblemSectionMotorhomes from './sections/ProblemSectionMotorhomes';
import MarketAnalysisMotorhomes from './sections/MarketAnalysisMotorhomes';
import DifferentiatorsMotorhomes from './sections/DifferentiatorsMotorhomes';

const LandingMotorhomes: React.FC = () => {
    useEffect(() => {
        document.title =
            'XPLENDOR — Marketing digital para stands de autocaravanas em Portugal';
    }, []);

    // Meta Pixel: só após consentimento. Inicializa se já consentido; senão
    // fica à escuta do evento disparado pelo CookieBanner.
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
                <HeroMotorhomes />
                <ProblemSectionMotorhomes />
                <HowItWorks />
                <MarketAnalysisMotorhomes />
                <DifferentiatorsMotorhomes />
                <Pricing />
                <CustomPlan />
                <FAQSection />
                <FinalCTA />
            </main>
            <LandingFooter />
        </div>
    );
};

export default LandingMotorhomes;
