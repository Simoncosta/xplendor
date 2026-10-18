import { Confidence, MenuClass, MenuItem } from "common/models/menuEngineering.model";

/** XPLENDOR — Fatia 3: rótulos, cores e contas pequenas da engenharia de menu (funções puras, testadas). */
export const CLASS_META: Record<MenuClass, { label: string; color: string; icon: string; hex: string }> = {
    estrela: { label: "Estrela", color: "success", icon: "ri-star-fill", hex: "#0ab39c" },
    cavalo: { label: "Cavalo de trabalho", color: "primary", icon: "ri-run-line", hex: "#405189" },
    quebra_cabecas: { label: "Quebra-cabeças", color: "warning", icon: "ri-puzzle-line", hex: "#f7b84b" },
    cao: { label: "Cão", color: "danger", icon: "ri-thumb-down-line", hex: "#f06548" },
};
export const CLASS_ORDER: MenuClass[] = ["estrela", "cavalo", "quebra_cabecas", "cao"];

export const CONFIDENCE_META: Record<Confidence, { label: string; color: string; hex: string; text: string }> = {
    confirmado: { label: "Confirmado", color: "success", hex: "#0ab39c", text: "Ficha e todos os ingredientes com preço e conversão." },
    parcial: { label: "Parcial", color: "warning", hex: "#f7b84b", text: "Falta o preço de algum ingrediente: o custo mostrado é um mínimo." },
    sem_custo: { label: "Sem custo", color: "dark", hex: "#878a99", text: "Sem ficha técnica em vigor: não se sabe o custo." },
};

export const MISSING_TEXT: Record<string, string> = {
    sem_conversao: "sem fator confirmado na compra",
    sem_compras: "sem compras",
    semi_sem_rendimento: "rendimento do semi-produto por confirmar",
    semi_parcial: "o semi-produto tem ingredientes sem custo",
    semi_sem_custo: "o semi-produto não tem custo",
    ciclo: "ciclo ou demasiados níveis de semi-produtos",
    loja_sem_ficha: "uma das lojas vendeu sem ficha em vigor",
};

const nf = (v: number, d = 1) => v.toLocaleString("pt-PT", { minimumFractionDigits: 0, maximumFractionDigits: d });
export const fmtPct = (v: number | null | undefined, d = 1) => (v === null || v === undefined ? "—" : `${nf(v, d)}%`);
export const fmtQty = (v: number | null | undefined) => (v === null || v === undefined ? "—" : nf(v, 0));
export const fmtEur = (v: number | null | undefined, d = 2) =>
    v === null || v === undefined ? "—" : `${v.toLocaleString("pt-PT", { minimumFractionDigits: d, maximumFractionDigits: d })} €`;

/** "pelo menos 4,54 €" no Parcial; o valor no Confirmado; "—" sem custo. */
export function costLabel(item: Pick<MenuItem, "cost_eur" | "confidence">): string {
    if (item.confidence === "sem_custo" || item.cost_eur === null) return "—";
    return item.confidence === "parcial" ? `pelo menos ${fmtEur(item.cost_eur)}` : fmtEur(item.cost_eur);
}

/** O que falta, numa frase: "MOLHO FRANCESINHA 30LT (rendimento do semi-produto por confirmar)". */
export function missingLabel(item: Pick<MenuItem, "missing">): string {
    return item.missing.map((m) => `${m.description} (${MISSING_TEXT[m.reason] ?? m.reason})`).join("; ");
}

/** Os pontos da matriz de uma família: x = quota (%), y = margem unitária (€); os sem custo vão à parte. */
export function matrixPoints(items: MenuItem[], family: string) {
    const inFamily = items.filter((i) => i.family === family && !i.excluded);
    return {
        costed: inFamily.filter((i) => i.class !== null && i.margin_eur !== null && i.share_pct !== null),
        noCost: inFamily.filter((i) => i.class === null),
    };
}

/** As famílias com artigos na matriz, pela receita. */
export function familiesByRevenue(items: MenuItem[]): string[] {
    const rev = new Map<string, number>();
    items.filter((i) => !i.excluded).forEach((i) => rev.set(i.family, (rev.get(i.family) ?? 0) + (i.revenue_eur ?? 0)));
    return Array.from(rev.entries()).sort((a, b) => b[1] - a[1]).map(([f]) => f);
}

/** Os artigos da matriz agrupados por classe (a lista do telemóvel), pela receita. */
export function byClass(items: MenuItem[]): Record<MenuClass | "sem_custo", MenuItem[]> {
    const out: Record<MenuClass | "sem_custo", MenuItem[]> = { estrela: [], cavalo: [], quebra_cabecas: [], cao: [], sem_custo: [] };
    items.filter((i) => !i.excluded).forEach((i) => out[i.class ?? "sem_custo"].push(i));
    (Object.keys(out) as (MenuClass | "sem_custo")[]).forEach((k) => out[k].sort((a, b) => (b.revenue_eur ?? 0) - (a.revenue_eur ?? 0)));
    return out;
}
