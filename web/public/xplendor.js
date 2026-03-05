; (() => {
    const STORAGE_KEYS = {
        visitorId: "xpl_visitor_id",
        sessionId: "xpl_session_id",
        firstTouch: "xpl_first_touch",
        lastTouch: "xpl_last_touch",
    };

    const state = {
        inited: false,
        token: null,
        api_base: null,
        debug: false,
    };

    const uuid = () => {
        if (crypto?.randomUUID) return crypto.randomUUID();
        return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (c) => {
            const r = (Math.random() * 16) | 0;
            const v = c === "x" ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    };

    const getOrCreate = (key, storage) => {
        const existing = storage.getItem(key);
        if (existing) return existing;
        const id = uuid();
        storage.setItem(key, id);
        return id;
    };

    const pickUtm = () => {
        const sp = new URLSearchParams(window.location.search);
        const g = (k) => sp.get(k) || null;
        return {
            utm_source: g("utm_source"),
            utm_medium: g("utm_medium"),
            utm_campaign: g("utm_campaign"),
            utm_content: g("utm_content"),
            utm_term: g("utm_term"),
        };
    };

    const inferChannel = ({ utm_medium, referrer }) => {
        if (utm_medium) {
            const m = String(utm_medium).toLowerCase();
            if (["cpc", "ppc", "paid", "ads", "paid_social", "paidsearch"].includes(m)) return "paid";
            if (["social", "bio", "organic_social"].includes(m)) return "organic_social";
            if (["email", "newsletter"].includes(m)) return "email";
            return "utm";
        }
        if (!referrer) return "direct";
        try {
            const host = new URL(referrer).hostname.toLowerCase();
            if (host.includes("google.") || host.includes("bing.") || host.includes("duckduckgo.")) return "organic_search";
            if (host.includes("facebook.") || host.includes("instagram.") || host.includes("t.co") || host.includes("linkedin.")) return "organic_social";
            return "referral";
        } catch {
            return "referral";
        }
    };

    const buildTouch = () => {
        const utm = pickUtm();
        const referrer = document.referrer || null;
        const landing_path = window.location.pathname + window.location.search;
        const channel = inferChannel({ utm_medium: utm.utm_medium, referrer });

        return {
            referrer,
            landing_path,
            channel,
            ...utm,
            captured_at: new Date().toISOString(),
        };
    };

    const ensureTouch = () => {
        const touch = buildTouch();

        if (!localStorage.getItem(STORAGE_KEYS.firstTouch)) {
            localStorage.setItem(STORAGE_KEYS.firstTouch, JSON.stringify(touch));
        }
        localStorage.setItem(STORAGE_KEYS.lastTouch, JSON.stringify(touch));
    };

    const getTouch = (mode = "last") => {
        const key = mode === "first" ? STORAGE_KEYS.firstTouch : STORAGE_KEYS.lastTouch;
        const raw = localStorage.getItem(key);
        return raw ? JSON.parse(raw) : {};
    };

    const getTrackingPayload = () => {
        const visitor_id = getOrCreate(STORAGE_KEYS.visitorId, localStorage);
        const session_id = getOrCreate(STORAGE_KEYS.sessionId, sessionStorage);

        const t = getTouch("last");
        return {
            visitor_id,
            session_id,
            referrer: t.referrer ?? (document.referrer || null),
            landing_path: t.landing_path ?? (window.location.pathname + window.location.search),
            channel: t.channel ?? null,
            utm_source: t.utm_source ?? null,
            utm_medium: t.utm_medium ?? null,
            utm_campaign: t.utm_campaign ?? null,
            utm_content: t.utm_content ?? null,
            utm_term: t.utm_term ?? null,
        };
    };

    const post = async (payload) => {
        if (!state.api_base || !state.token) return;

        const url = `${state.api_base}/api/public/track?token=${encodeURIComponent(state.token)}`;
        const res = await fetch(url, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            keepalive: true, // importante para eventos no unload
            body: JSON.stringify(payload),
        });

        if (state.debug) {
            const txt = await res.text().catch(() => "");
            console.log("[xplendor] track response", res.status, txt);
        }
    };

    const shouldCount = (key, ttlMs) => {
        const k = `xpl_dedupe_${key}`;
        const last = sessionStorage.getItem(k);
        const now = Date.now();
        if (last && now - Number(last) < ttlMs) return false;
        sessionStorage.setItem(k, String(now));
        return true;
    };

    const api = (cmd, a, b) => {
        if (cmd === "init") {
            const cfg = a || {};
            state.token = cfg.token;
            state.api_base = cfg.api_base || (window.__XPLENDOR_API_BASE__ || "http://localhost:8000");
            state.debug = !!cfg.debug;
            state.inited = true;

            ensureTouch();

            // opcional: page_view automático (dedupe 10s)
            if (cfg.auto_page_view !== false) {
                if (shouldCount(`page_view_${location.pathname}${location.search}`, 10_000)) {
                    post({
                        type: "page_view",
                        data: { path: location.pathname, qs: location.search || null },
                        tracking: getTrackingPayload(),
                    });
                }
            }

            return;
        }

        if (cmd === "event") {
            const type = a;
            const data = b || {};

            if (!state.inited) return;

            // dedupe views por car_id (60s)
            if (type === "car_view") {
                const carId = data.car_id;
                if (!carId) return;
                if (!shouldCount(`car_view_${carId}`, 60_000)) return;
            }

            ensureTouch();

            post({
                type,
                data,
                tracking: getTrackingPayload(),
            });
            return;
        }
    };

    // expõe
    window.xplendor = function () {
        api(arguments[0], arguments[1], arguments[2]);
    };

    // drena queue criada antes do script carregar
    const q = window.xplendor.q || [];
    q.forEach((args) => api(args[0], args[1], args[2]));
    window.xplendor.q = [];
})();