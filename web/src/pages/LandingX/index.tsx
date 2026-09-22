import React, { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import './xland.css';

import { isStandalone } from '../../helpers/pwa';
import { initMetaPixel } from '../Landing/lib/metaPixel';
import XNav from './components/XNav';
import XFooter from './components/XFooter';
import XWhatsApp from './components/XWhatsApp';
import XHero from './sections/XHero';
import XVertentes from './sections/XVertentes';
import XFeatures from './sections/XFeatures';
import XServices from './sections/XServices';
import XFinalCTA from './sections/XFinalCTA';

/**
 * XPLENDOR — Landing PREMIUM (/plataforma). Rota pública NOVA, sob NonAuthLayout
 * (sem shell Velzon). Estilo 100% isolado sob #xland (tokens dark próprios).
 * NÃO toca na landing atual "/" nem na auth. Slot 3D reservado no herói.
 */
const LandingX: React.FC = () => {
    const navigate = useNavigate();

    // PWA: se instalada (standalone), salta a landing → /dashboard (que reencaminha).
    useEffect(() => {
        if (isStandalone()) {
            navigate('/dashboard', { replace: true });
        }
    }, [navigate]);

    useEffect(() => {
        document.title = 'XPLENDOR — A plataforma para Automotivo e Restauração';
    }, []);

    // Meta Pixel: só após consentimento (mesmo padrão da landing atual).
    useEffect(() => {
        if (localStorage.getItem('xplendor_cookie_consent') === 'granted') {
            initMetaPixel();
        }
        const onConsentGranted = () => initMetaPixel();
        window.addEventListener('xplendor-consent-granted', onConsentGranted);
        return () =>
            window.removeEventListener('xplendor-consent-granted', onConsentGranted);
    }, []);

    return (
        <div id="xland">
            <div className="x-page">
                <XNav />
                <main>
                    <XHero />
                    <XVertentes />
                    <XFeatures />
                    <XServices />
                    <XFinalCTA />
                </main>
                <XFooter />
                <XWhatsApp />
            </div>
        </div>
    );
};

export default LandingX;
