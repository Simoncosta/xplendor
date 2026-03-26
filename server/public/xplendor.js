; (() => {
    const STORAGE_KEYS = {
        visitorId: "xpl_visitor_id",
        sessionId: "xpl_session_id",
        firstTouch: "xpl_first_touch",
        lastTouch: "xpl_last_touch",
        lastCarView: "xpl_last_car_view",
        activeCarView: "xpl_active_car_view",
    };

    const state = {
        inited: false,
        token: null,
        api_base: null,
        endpoint_path: "/api/public/track",
        debug: false,
        listenersBound: false,
    };

    const safeStorage = (storage) => {
        try {
            const testKey = "__xpl_test__";
            storage.setItem(testKey, "1");
            storage.removeItem(testKey);
            return storage;
        } catch {
            return {
                getItem: () => null,
                setItem: () => { },
                removeItem: () => { },
            };
        }
    };

    const ls = safeStorage(window.localStorage);
    const ss = safeStorage(window.sessionStorage);

    const uuid = () => {
        if (window.crypto?.randomUUID) return window.crypto.randomUUID();

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

            if (["cpc", "ppc", "paid", "ads", "paid_social", "paidsearch"].includes(m)) {
                return "paid";
            }

            if (["social", "bio", "organic_social"].includes(m)) {
                return "organic_social";
            }

            if (["email", "newsletter"].includes(m)) {
                return "email";
            }

            return "utm";
        }

        if (!referrer) return "direct";

        try {
            const host = new URL(referrer).hostname.toLowerCase();

            if (
                host.includes("google.") ||
                host.includes("bing.") ||
                host.includes("duckduckgo.")
            ) {
                return "organic_search";
            }

            if (
                host.includes("facebook.") ||
                host.includes("instagram.") ||
                host.includes("t.co") ||
                host.includes("linkedin.")
            ) {
                return "organic_social";
            }

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

        if (!ls.getItem(STORAGE_KEYS.firstTouch)) {
            ls.setItem(STORAGE_KEYS.firstTouch, JSON.stringify(touch));
        }

        ls.setItem(STORAGE_KEYS.lastTouch, JSON.stringify(touch));
    };

    const getTouch = (mode = "last") => {
        try {
            const key = mode === "first" ? STORAGE_KEYS.firstTouch : STORAGE_KEYS.lastTouch;
            const raw = ls.getItem(key);
            return raw ? JSON.parse(raw) : {};
        } catch {
            return {};
        }
    };

    const detectPageType = () => {
        const path = window.location.pathname;

        if (path === "/") return "home";
        if (path.startsWith("/car/")) return "car_detail";
        if (path.startsWith("/cars")) return "listing";
        if (path.startsWith("/stand/")) return "company_page";
        if (path.startsWith("/contact")) return "contact_page";

        return "other";
    };

    const getTrackingPayload = () => {
        const visitor_id = getOrCreate(STORAGE_KEYS.visitorId, ls);
        const session_id = getOrCreate(STORAGE_KEYS.sessionId, ss);
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

    const getPageContext = () => ({
        page_url: window.location.pathname + window.location.search,
        page_type: detectPageType(),
    });

    const saveLastCarView = (data = {}) => {
        if (!data.car_id) return;

        ls.setItem(
            STORAGE_KEYS.lastCarView,
            JSON.stringify({
                car_id: data.car_id,
                page_url: window.location.pathname + window.location.search,
                captured_at: new Date().toISOString(),
            })
        );
    };

    const getLastCarView = () => {
        try {
            const raw = ls.getItem(STORAGE_KEYS.lastCarView);
            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    };

    const clearLastCarView = () => {
        ls.removeItem(STORAGE_KEYS.lastCarView);
    };

    const saveActiveCarView = (data = {}) => {
        ss.setItem(STORAGE_KEYS.activeCarView, JSON.stringify(data));
    };

    const getActiveCarView = () => {
        try {
            const raw = ss.getItem(STORAGE_KEYS.activeCarView);
            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    };

    const shouldCount = (key, ttlMs) => {
        const k = `xpl_dedupe_${key}`;
        const last = ss.getItem(k);
        const now = Date.now();

        if (last && now - Number(last) < ttlMs) {
            return false;
        }

        ss.setItem(k, String(now));
        return true;
    };

    const mergeMeta = (baseMeta, extraMeta) => {
        return {
            ...(baseMeta && typeof baseMeta === "object" ? baseMeta : {}),
            ...(extraMeta && typeof extraMeta === "object" ? extraMeta : {}),
        };
    };

    const enrichInteractionData = (type, data = {}) => {
        const interactionData = {
            ...data,
            ...getPageContext(),
        };

        if (!interactionData.meta || typeof interactionData.meta !== "object") {
            interactionData.meta = {};
        }

        if (interactionData.car_id) {
            interactionData.meta = mergeMeta(interactionData.meta, {
                car_id_source: "explicit",
            });

            return interactionData;
        }

        const lastCar = getLastCarView();

        if (!lastCar) {
            interactionData.meta = mergeMeta(interactionData.meta, {
                car_id_source: "none",
            });

            return interactionData;
        }

        interactionData.car_id = lastCar.car_id;
        interactionData.meta = mergeMeta(interactionData.meta, {
            car_id_source: "last_car_view",
            inferred_from_page_url: lastCar.page_url || null,
            inferred_from_captured_at: lastCar.captured_at || null,
            inferred_for_interaction_type: type,
        });

        clearLastCarView();

        return interactionData;
    };

    const buildEventPayload = (type, data = {}) => ({
        type,
        data,
        tracking: getTrackingPayload(),
    });

    const post = async (payload, options = {}) => {
        if (!state.api_base || !state.token) return;

        const url = `${state.api_base}${state.endpoint_path}?token=${encodeURIComponent(state.token)}`;
        const preferBeacon = options.preferBeacon === true;

        if (preferBeacon && navigator.sendBeacon) {
            try {
                const body = new Blob([JSON.stringify(payload)], { type: "application/json" });
                navigator.sendBeacon(url, body);
                return;
            } catch (error) {
                if (state.debug) {
                    console.error("[xplendor] sendBeacon error", error, payload);
                }
            }
        }

        try {
            const res = await fetch(url, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                },
                keepalive: true,
                body: JSON.stringify(payload),
            });

            if (state.debug) {
                const txt = await res.text().catch(() => "");
                console.log("[xplendor] track response", res.status, txt, payload);
            }
        } catch (error) {
            if (state.debug) {
                console.error("[xplendor] track error", error, payload);
            }
        }
    };

    const createActiveCarView = (carId) => {
        const active = {
            car_id: carId,
            client_view_key: uuid(),
            page_url: window.location.pathname + window.location.search,
            started_at_ms: Date.now(),
            accumulated_seconds: 0,
            is_visible: !document.hidden,
        };

        saveActiveCarView(active);

        return active;
    };

    const flushActiveCarViewDuration = (reason) => {
        const active = getActiveCarView();
        const currentPageUrl = window.location.pathname + window.location.search;

        if (!active || !active.car_id || active.page_url !== currentPageUrl) {
            return;
        }

        const now = Date.now();
        const elapsedSeconds = active.is_visible && active.started_at_ms
            ? Math.max(0, Math.round((now - active.started_at_ms) / 1000))
            : 0;

        const totalSeconds = Math.max(0, (active.accumulated_seconds || 0) + elapsedSeconds);

        active.accumulated_seconds = totalSeconds;
        active.started_at_ms = now;
        active.is_visible = false;
        saveActiveCarView(active);

        post(buildEventPayload("car_view_duration", {
            car_id: active.car_id,
            client_view_key: active.client_view_key,
            view_duration_seconds: totalSeconds,
            meta: {
                flush_reason: reason,
            },
            ...getPageContext(),
        }), { preferBeacon: true });
    };

    const resumeActiveCarView = () => {
        const active = getActiveCarView();
        const currentPageUrl = window.location.pathname + window.location.search;

        if (!active || active.page_url !== currentPageUrl || active.is_visible) {
            return;
        }

        active.started_at_ms = Date.now();
        active.is_visible = true;
        saveActiveCarView(active);
    };

    const bindLifecycleEvents = () => {
        if (state.listenersBound) return;
        state.listenersBound = true;

        document.addEventListener("visibilitychange", () => {
            if (document.hidden) {
                flushActiveCarViewDuration("hidden");
            } else {
                resumeActiveCarView();
            }
        });

        window.addEventListener("pagehide", () => {
            flushActiveCarViewDuration("pagehide");
        });

        window.addEventListener("beforeunload", () => {
            flushActiveCarViewDuration("beforeunload");
        });
    };

    const handleInit = (cfg = {}) => {
        state.token = cfg.token || null;
        state.api_base = cfg.api_base || window.__XPLENDOR_API_BASE__ || "http://localhost:8001";
        state.endpoint_path = cfg.endpoint_path || "/api/public/track";
        state.debug = !!cfg.debug;
        state.inited = true;

        ensureTouch();
        bindLifecycleEvents();

        if (cfg.auto_page_view !== false) {
            const dedupeKey = `page_view_${window.location.pathname}${window.location.search}`;

            if (shouldCount(dedupeKey, 10000)) {
                post(
                    buildEventPayload("page_view", {
                        path: window.location.pathname,
                        qs: window.location.search || null,
                        ...getPageContext(),
                    })
                );
            }
        }
    };

    const handleEvent = (type, data = {}) => {
        if (!state.inited || !type) return;

        const payloadData = {
            ...data,
            ...getPageContext(),
        };

        if (type === "car_view") {
            const carId = payloadData.car_id;

            if (!carId) return;
            if (!shouldCount(`car_view_${carId}`, 60000)) return;

            const activeCarView = createActiveCarView(carId);
            payloadData.client_view_key = activeCarView.client_view_key;
            saveLastCarView(payloadData);
        }

        ensureTouch();

        post(buildEventPayload(type, payloadData));
    };

    const handleInteraction = (type, data = {}) => {
        if (!state.inited || !type) return;

        const payloadData = enrichInteractionData(type, data);

        ensureTouch();

        post(buildEventPayload(type, payloadData));
    };

    const api = (cmd, a, b) => {
        if (cmd === "init") {
            handleInit(a || {});
            return;
        }

        if (cmd === "event") {
            handleEvent(a, b || {});
            return;
        }

        if (cmd === "interaction") {
            handleInteraction(a, b || {});
            return;
        }

        if (cmd === "clear_last_car_view") {
            clearLastCarView();
            return;
        }

        if (cmd === "debug_last_car_view") {
            if (state.debug) {
                console.log("[xplendor] last_car_view", getLastCarView());
            }
        }
    };

    const previous = window.xplendor;

    window.xplendor = function () {
        api(arguments[0], arguments[1], arguments[2]);
    };

    const q = previous?.q || window.xplendor.q || [];
    q.forEach((args) => api(args[0], args[1], args[2]));
    window.xplendor.q = [];
})();
