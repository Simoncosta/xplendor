import React, { useEffect, useState } from "react";
import "./CookieBanner.css";

declare global {
    interface Window {
        gtag?: (...args: unknown[]) => void;
    }
}

const CONSENT_KEY = "xplendor_cookie_consent";

const GRANT_ALL = {
    analytics_storage: "granted",
    ad_storage: "granted",
    ad_user_data: "granted",
    ad_personalization: "granted",
} as const;

function updateConsent(): void {
    if (typeof window.gtag === "function") {
        window.gtag("consent", "update", GRANT_ALL);
    }
}

const CookieBanner: React.FC = () => {
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        const stored = localStorage.getItem(CONSENT_KEY);
        if (stored === "granted") {
            updateConsent();
        } else if (!stored) {
            setVisible(true);
        }
    }, []);

    const handleAccept = (): void => {
        localStorage.setItem(CONSENT_KEY, "granted");
        updateConsent();
        // Sinal para quem carrega tracking condicional (ex: Meta Pixel na
        // landing). O banner monta em toda a app, por isso só emite o evento
        // — quem o ouve (a landing) decide carregar o pixel.
        window.dispatchEvent(new Event("xplendor-consent-granted"));
        setVisible(false);
    };

    const handleReject = (): void => {
        localStorage.setItem(CONSENT_KEY, "denied");
        setVisible(false);
    };

    if (!visible) {
        return null;
    }

    return (
        <div className="xplndor-cookie-banner" role="dialog" aria-live="polite" aria-label="Consentimento de cookies">
            <div className="xplndor-cookie-banner__inner">
                <p className="xplndor-cookie-banner__text">
                    Utilizamos cookies para analisar o tráfego e melhorar a sua experiência. Pode
                    aceitar ou rejeitar. Saiba mais na nossa{" "}
                    <a href="/privacy">Política de privacidade</a>.
                </p>
                <div className="xplndor-cookie-banner__actions">
                    <button
                        type="button"
                        className="xplndor-cookie-banner__btn xplndor-cookie-banner__btn--reject"
                        onClick={handleReject}
                    >
                        Rejeitar
                    </button>
                    <button
                        type="button"
                        className="xplndor-cookie-banner__btn xplndor-cookie-banner__btn--accept"
                        onClick={handleAccept}
                    >
                        Aceitar
                    </button>
                </div>
            </div>
        </div>
    );
};

export default CookieBanner;
