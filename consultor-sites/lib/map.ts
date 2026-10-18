/**
 * O fornecedor de mosaicos do mapa vem do ambiente (MAP_TILE_URL, MAP_ATTRIBUTION), decidido antes
 * do deploy: o servidor público do OpenStreetMap não é para uso comercial intensivo. Sem variável,
 * o site mostra uma imagem estática (e em dev e nos testes nunca pede mosaicos a ninguém).
 */
export type MapConfig = { tileUrl: string; attribution: string } | null;

export function mapConfig(env: Record<string, string | undefined> = process.env): MapConfig {
    const tileUrl = (env.MAP_TILE_URL ?? "").trim();
    if (!/^https:\/\/[^\s]+\{z\}[^\s]*\{x\}[^\s]*\{y\}/.test(tileUrl)) return null;
    return { tileUrl, attribution: (env.MAP_ATTRIBUTION ?? "").trim() };
}
