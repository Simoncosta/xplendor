/**
 * XPLENDOR — As ligações diretas ao Guião de unidades: o artigo (?artigo=) e o caminho de volta
 * (?voltar=, só caminhos internos da aplicação, a começar por /app/).
 */
export const UNIT_GUIDE = "/restauracao/auditoria/guiao";
/** A base da aplicação no browser (o "homepage" do package.json). */
const APP = "/app";

/** O Guião aberto no artigo; `back` é o caminho do ecrã de onde se vem (ex.: /restauracao/faturas/12). */
export function unitGuideLink(productId: string, back?: string | null): string {
    const q = new URLSearchParams({ artigo: productId });
    if (back) q.set("voltar", APP + back);
    return `${UNIT_GUIDE}?${q.toString()}`;
}

/**
 * O caminho de volta, já sem a base (para o router), ou null quando não é um caminho interno:
 * tem de começar por /app/ e não pode levar a outro sítio (//, \, esquema ou espaços).
 */
export function safeReturn(voltar: string | null | undefined): string | null {
    if (!voltar || !voltar.startsWith(APP + "/")) return null;
    const path = voltar.slice(APP.length);
    if (path.startsWith("//") || /[\\\s]/.test(path) || /^\/[a-z][a-z0-9+.-]*:/i.test(path)) return null;
    return path;
}

/** O texto do botão de volta, pelo ecrã de onde se veio. */
export function returnLabel(path: string): string {
    if (path.startsWith("/restauracao/faturas/")) return "Voltar à fatura";
    if (path.startsWith("/cadastros/artigos/")) return "Voltar ao artigo";
    return "Voltar";
}
