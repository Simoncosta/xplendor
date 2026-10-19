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
