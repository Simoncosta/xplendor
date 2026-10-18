/**
 * A origem da visita (utm_*, gclid, fbclid, a página de entrada e o site de onde veio), guardada
 * no armazenamento da sessão do separador (xp_origin) só para a juntar a um pedido que o
 * visitante envie. Sem identificador, sem cookie, apagada ao fechar o separador.
 */
export const ORIGIN_KEY = "xp_origin";
const UTM = ["utm_source", "utm_medium", "utm_campaign", "utm_content", "utm_term", "gclid", "fbclid"] as const;

export type Origin = Partial<Record<(typeof UTM)[number] | "landing_path" | "referrer_host", string>>;

export function captureOrigin(): void {
    try {
        const url = new URL(window.location.href);
        const fromUrl: Origin = {};
        for (const k of UTM) {
            const v = url.searchParams.get(k);
            if (v) fromUrl[k] = v.slice(0, k.endsWith("clid") ? 255 : 120);
        }
        const existing = sessionStorage.getItem(ORIGIN_KEY);
        // A primeira página da sessão fica; uma campanha nova (com utm) substitui-a.
        if (existing && Object.keys(fromUrl).length === 0) return;
        let referrer: string | undefined;
        try {
            const r = document.referrer ? new URL(document.referrer).host : "";
            referrer = r && r !== url.host ? r.slice(0, 120) : undefined;
        } catch { referrer = undefined; }
        const origin: Origin = { ...fromUrl, landing_path: url.pathname.slice(0, 255), ...(referrer ? { referrer_host: referrer } : {}) };
        sessionStorage.setItem(ORIGIN_KEY, JSON.stringify(origin));
    } catch {
        /* sem armazenamento: sem origem */
    }
}

export function readOrigin(): Origin {
    try {
        return JSON.parse(sessionStorage.getItem(ORIGIN_KEY) ?? "{}") as Origin;
    } catch {
        return {};
    }
}
