// XPLENDOR — Finanças › Mês: formatação (cêntimos → euros, percentagens, datas DD/MM).

export const eur = (cents: number | null | undefined) =>
    cents === null || cents === undefined ? "—" : (cents / 100).toLocaleString("pt-PT", { style: "currency", currency: "EUR" });
export const pct = (v: number | null | undefined) => (v === null || v === undefined ? "—" : `${v.toLocaleString("pt-PT", { minimumFractionDigits: 1, maximumFractionDigits: 1 })} %`);
export const dm = (d: string | null | undefined) => (d ? `${d.slice(8, 10)}/${d.slice(5, 7)}` : "—");
export const dmy = (d: string | null | undefined) => (d ? `${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}` : "—");
/** A diferença em pontos percentuais, com sinal ("+1,2 p.p."). */
export const pp = (a: number | null | undefined, b: number | null | undefined) =>
    a === null || a === undefined || b === null || b === undefined ? "—" : `${a - b > 0 ? "+" : a - b < 0 ? "−" : ""}${Math.abs(a - b).toLocaleString("pt-PT", { minimumFractionDigits: 1, maximumFractionDigits: 1 })} p.p.`;
export const ucfirst = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);
/** Euros sem cêntimos (o Resumo); as tabelas usam eur(), com cêntimos. */
export const eur0 = (cents: number | null | undefined) =>
    cents === null || cents === undefined ? "—" : Math.round(cents / 100).toLocaleString("pt-PT", { style: "currency", currency: "EUR", minimumFractionDigits: 0, maximumFractionDigits: 0 });
/** A mesma cor para cada grupo em todos os gráficos (o vermelho fica para o vencido e o resultado negativo). */
export const GROUP_COLORS: Record<string, string> = {
    comida: "#3fa66b", bebida: "#3b7dd8", pessoal: "#8e5bd6", fse: "#e0912f", outros: "#a0785a", por_classificar: "#b8c0c9",
};
/** O nome curto do grupo nos gráficos e o que quer dizer (sem siglas por explicar). */
export const GROUP_NAMES: Record<string, { short: string; hint?: string }> = {
    comida: { short: "Comida" }, bebida: { short: "Bebidas" }, pessoal: { short: "Pessoal" },
    fse: { short: "FSE", hint: "Fornecimentos e serviços externos" }, outros: { short: "Outros" }, por_classificar: { short: "Por classificar" },
};
const MONTHS_SHORT = ["jan", "fev", "mar", "abr", "mai", "jun", "jul", "ago", "set", "out", "nov", "dez"];
/** "2026-09" → "set 26" (o eixo do gráfico dos 6 meses). */
export const monthShort = (ym: string) => `${MONTHS_SHORT[Number(ym.slice(5, 7)) - 1]} ${ym.slice(2, 4)}`;
/** "2026-09" → "agosto" (o nome do mês anterior, para as comparações). */
export const prevMonthName = (ym: string) => {
    const d = new Date(Number(ym.slice(0, 4)), Number(ym.slice(5, 7)) - 2, 1);
    return d.toLocaleDateString("pt-PT", { month: "long" });
};
/** A comparação com o mês anterior numa frase ("Mais 12 % do que em agosto."). */
export function compare(cur: number | null | undefined, prev: number | null | undefined, prevName: string, nouns: { more: string; less: string } = { more: "Mais", less: "Menos" }): string | null {
    if (cur === null || cur === undefined || prev === null || prev === undefined) return null;
    if (prev === 0) return cur === 0 ? `Igual a ${prevName}.` : `Sem valores em ${prevName} para comparar.`;
    const d = Math.round(((cur - prev) / Math.abs(prev)) * 100);
    if (d === 0) return `Igual a ${prevName}.`;
    return `${d > 0 ? nouns.more : nouns.less} ${Math.abs(d).toLocaleString("pt-PT")} % do que em ${prevName}.`;
}
