// Meta Pixel — carregado por código (NÃO no index.html), só na landing
// e só após consentimento. Ver CookieBanner.tsx + Landing/index.tsx.

interface FbqFn {
    (...args: unknown[]): void;
    callMethod?: (...args: unknown[]) => void;
    queue?: unknown[];
    push?: unknown;
    loaded?: boolean;
    version?: string;
}

declare global {
    interface Window {
        fbq?: FbqFn;
        _fbq?: FbqFn;
    }
}

const PIXEL_ID = "987402273689566";

let initialized = false;

/** Injecta o snippet oficial do Meta Pixel (fbq) e dispara PageView.
 *  Idempotente — só corre uma vez por sessão de página. */
export function initMetaPixel(): void {
    if (initialized || window.fbq) {
        initialized = true;
        return;
    }

    const n: FbqFn = function (...args: unknown[]): void {
        if (n.callMethod) {
            n.callMethod.apply(n, args);
        } else {
            n.queue?.push(args);
        }
    };
    n.push = n;
    n.loaded = true;
    n.version = "2.0";
    n.queue = [];

    if (!window._fbq) {
        window._fbq = n;
    }
    window.fbq = n;

    const script = document.createElement("script");
    script.async = true;
    script.src = "https://connect.facebook.net/en_US/fbevents.js";
    const first = document.getElementsByTagName("script")[0];
    first?.parentNode?.insertBefore(script, first);

    n("init", PIXEL_ID);
    n("track", "PageView");

    initialized = true;
}
