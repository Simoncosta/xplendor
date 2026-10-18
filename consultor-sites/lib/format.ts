const eur = new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR", maximumFractionDigits: 0 });
export const euros = (v: number) => eur.format(v);
export const monthYear = (ym: string | null) => {
    if (!ym) return "";
    const [y, mth] = ym.split("-").map(Number);
    return new Date(Date.UTC(y, (mth ?? 1) - 1, 1)).toLocaleDateString("pt-PT", { month: "long", year: "numeric", timeZone: "UTC" });
};
export const shortDate = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString("pt-PT", { day: "numeric", month: "long", year: "numeric" }) : "");
/** "Consultor Chave Nova" → "Chave Nova": o nome da rede vem do endosso do perfil (nada fixo no código). */
export const networkName = (label: string | null) => (label ?? "").replace(/^consultora?\s+/i, "").trim();
/** Milhares separados por um espaço que não parte (3 424), igual no servidor e no navegador. */
export const thousands = (v: number) => String(Math.round(v)).replace(/\B(?=(\d{3})+(?!\d))/g, "\u00A0");
/** "92,1 m²" (sem zeros à direita, vírgula decimal, milhares com espaço que não parte). */
export const areaM2 = (v: number | null | undefined): string | null => {
    if (!v) return null;
    const [int, dec] = (Math.round(v * 100) / 100).toFixed(2).replace(/0+$/, "").replace(/\.$/, "").split(".");
    return `${thousands(Number(int))}${dec ? `,${dec}` : ""} m²`;
};
