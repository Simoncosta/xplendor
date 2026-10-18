/** Um limite simples por chave, em memória do processo (o limite a sério é o da XPLENDOR). */
const hits = new Map<string, number[]>();

export function allow(key: string, max: number, windowMs: number): boolean {
    const now = Date.now();
    const list = (hits.get(key) ?? []).filter((t) => now - t < windowMs);
    if (list.length >= max) { hits.set(key, list); return false; }
    list.push(now);
    hits.set(key, list);
    if (hits.size > 5000) for (const [k, v] of hits) if (v.every((t) => now - t >= windowMs)) hits.delete(k);
    return true;
}
