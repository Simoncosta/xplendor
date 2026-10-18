/**
 * O consentimento dos cookies, no navegador. A escolha fica num cookie necessário (xp_consent,
 * 6 meses) com a versão da política, e é exposta numa camada de dados (window.dataLayer, Consent
 * Mode v2: tudo recusado por omissão). O GA4 e o Pixel só entram no F6 e só leem daqui.
 */
export const CONSENT_COOKIE = "xp_consent";
const MAX_AGE = 60 * 60 * 24 * 182;

export type Consent = { v: string; s: boolean; m: boolean; t: string };

declare global {
    interface Window {
        dataLayer?: unknown[];
        gtag?: (...args: unknown[]) => void;
        xpConsent?: Consent | null;
    }
}

export function readConsent(): Consent | null {
    if (typeof document === "undefined") return null;
    return parseConsent(document.cookie.split("; ").find((c) => c.startsWith(`${CONSENT_COOKIE}=`))?.slice(CONSENT_COOKIE.length + 1));
}

/** O valor do cookie (no navegador ou no servidor). */
export function parseConsent(raw: string | undefined): Consent | null {
    if (!raw) return null;
    try {
        const c = JSON.parse(decodeURIComponent(raw)) as Consent;
        return typeof c.s === "boolean" && typeof c.m === "boolean" && typeof c.v === "string" ? c : null;
    } catch {
        return null;
    }
}

export function writeConsent(version: string, statistics: boolean, marketing: boolean): Consent {
    const c: Consent = { v: version, s: statistics, m: marketing, t: new Date().toISOString() };
    const secure = location.protocol === "https:" ? "; Secure" : "";
    document.cookie = `${CONSENT_COOKIE}=${encodeURIComponent(JSON.stringify(c))}; Max-Age=${MAX_AGE}; Path=/; SameSite=Lax${secure}`;
    publishConsent(c, true);
    return c;
}

/** Atualiza a camada de dados (e avisa quem a ouve: o GA4 e o Pixel no F6). */
export function publishConsent(c: Consent | null, changed: boolean): void {
    window.xpConsent = c;
    window.dataLayer = window.dataLayer || [];
    if (c) {
        window.gtag?.("consent", "update", {
            analytics_storage: c.s ? "granted" : "denied",
            ad_storage: c.m ? "granted" : "denied",
            ad_user_data: c.m ? "granted" : "denied",
            ad_personalization: c.m ? "granted" : "denied",
        });
        if (changed) window.dataLayer.push({ event: "cookie_consent", consent_statistics: c.s, consent_marketing: c.m, policy_version: c.v });
    }
    window.dispatchEvent(new CustomEvent("xp:consent", { detail: c }));
}

/** O arranque da camada de dados, antes de tudo: o Consent Mode v2 com tudo recusado. */
export const CONSENT_BOOTSTRAP = `window.dataLayer=window.dataLayer||[];window.gtag=function(){window.dataLayer.push(arguments)};window.gtag('consent','default',{analytics_storage:'denied',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',wait_for_update:500});`;

export const OPEN_PREFERENCES_EVENT = "xp:open-cookie-preferences";
