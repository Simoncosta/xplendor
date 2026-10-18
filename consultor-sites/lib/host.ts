/**
 * O host do pedido, sem a porta e em minúsculas (como a XPLENDOR o guarda). Em dev, o mapa
 * CONSULTOR_DEV_HOSTS ("localhost:3001=simoncosta.localhost,…") traduz o host local no host do site;
 * em produção o mapa nunca é lido.
 */
export function normalizeHost(raw: string | null | undefined): string {
    return (raw ?? "").trim().toLowerCase().replace(/\.$/, "");
}

export function resolveHost(raw: string | null | undefined, devMap: string): string {
    const host = normalizeHost(raw);
    if (devMap) {
        for (const pair of devMap.split(",")) {
            const [from, to] = pair.split("=").map((x) => normalizeHost(x));
            if (from && to && from === host) return to;
        }
    }
    return host.replace(/:\d+$/, "");
}
