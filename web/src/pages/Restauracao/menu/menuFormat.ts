import { Confidence, MenuClass, MenuItem } from "common/models/menuEngineering.model";

/** XPLENDOR — Fatia 3: rótulos, cores e contas pequenas da engenharia de menu (funções puras, testadas). */
/**
 * As cores das classes vêm dos tokens do tema (uma só fonte para badges, legenda, bolhas, tabela e
 * Bússola): Estrela = sucesso, Cavalo de trabalho = primária, Quebra-cabeças = aviso, Cão = perigo.
 * `cssVar` serve para o SVG (funciona no claro e no escuro).
 */
export const CLASS_META: Record<MenuClass, { label: string; color: "success" | "primary" | "warning" | "danger"; icon: string; cssVar: string }> = {
    estrela: { label: "Estrela", color: "success", icon: "ri-star-fill", cssVar: "var(--vz-success)" },
    cavalo: { label: "Cavalo de trabalho", color: "primary", icon: "ri-run-line", cssVar: "var(--vz-primary)" },
    quebra_cabecas: { label: "Quebra-cabeças", color: "warning", icon: "ri-puzzle-line", cssVar: "var(--vz-warning)" },
    cao: { label: "Cão", color: "danger", icon: "ri-thumb-down-line", cssVar: "var(--vz-danger)" },
};
export const CLASS_ORDER: MenuClass[] = ["estrela", "cavalo", "quebra_cabecas", "cao"];

export const CONFIDENCE_META: Record<Confidence, { label: string; color: string; cssVar: string; text: string }> = {
    confirmado: { label: "Confirmado", color: "success", cssVar: "var(--vz-success)", text: "Ficha e todos os ingredientes com preço e conversão." },
    parcial: { label: "Parcial", color: "warning", cssVar: "var(--vz-warning)", text: "Falta o preço de algum ingrediente: o custo mostrado é um mínimo." },
    sem_custo: { label: "Sem custo", color: "secondary", cssVar: "var(--vz-secondary-color)", text: "Sem ficha técnica em vigor: não se sabe o custo." },
};

/** O valor real de um token do tema (para o ApexCharts, que não aceita var(...)). */
export function tokenColor(cssVarExpr: string, fallback = "#878a99"): string {
    const name = cssVarExpr.replace(/^var\((--[^)]+)\)$/, "$1");
    try {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
    } catch {
        return fallback;
    }
}

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

/** Eixo com valores redondos: o passo (1, 2, 2,5, 5 × 10ⁿ) que dá até `maxTicks` intervalos, e o máximo arredondado acima. */
export function niceAxis(min: number, max: number, maxTicks = 6): { min: number; max: number; step: number; ticks: number[] } {
    if (!(max > min)) max = min + 1;
    const raw = (max - min) / maxTicks;
    const pow = Math.pow(10, Math.floor(Math.log10(raw)));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * pow).find((st) => st >= raw) ?? 10 * pow;
    const lo = Math.floor(min / step + 1e-9) * step;
    const hi = Math.ceil(max / step - 1e-9) * step;
    const ticks: number[] = [];
    for (let v = lo; v <= hi + step / 2; v += step) ticks.push(Math.round(v * 1e6) / 1e6);
    return { min: lo, max: hi, step, ticks };
}

/** Encurta um nome para o rótulo das bolhas. */
export const shortName = (s: string, n = 18) => (s.length > n ? `${s.slice(0, n - 1).trimEnd()}…` : s);

type Work = { product_id: string; revenue_share_pct: number | null; revenue_eur: number | null; family: string; description: string; actions: { type: string; product_id: string; label: string; text: string }[] };

/** O que uma ação pede, numa expressão: "o rendimento de MOLHO FRANCESINHA 30LT". */
export function actionPhrase(a: { type: string; text: string }, dish?: string): string {
    const name = a.text.split(":")[0].trim();
    if (a.type === "criar_ficha") return `a ficha de ${dish ?? name}`;
    if (a.type === "confirmar_fator") return `o fator de ${name}`;
    if (a.type === "ver_auditoria") return `o ingrediente ${name} (sem compras)`;
    if (/rendimento/.test(a.text)) return `o rendimento de ${name}`;
    return `a ficha de ${name}`;
}

/**
 * As 2 ações com mais receita por trás (da lista de trabalho) e até onde levam o Confirmado: os artigos
 * cujas ações ficam todas resolvidas passam a Confirmado. Sem a lista, null.
 */
export function topActions(worklist: Work[], confirmedPct: number | null, family?: string): { phrases: string[]; targetPct: number | null } | null {
    const list = worklist.filter((w) => !family || w.family === family);
    const byKey = new Map<string, { a: Work["actions"][number]; dish: string; share: number }>();
    list.forEach((w) => w.actions.forEach((a) => {
        const k = `${a.type}|${a.product_id}`;
        const cur = byKey.get(k) ?? { a, dish: w.description, share: 0 };
        cur.share += w.revenue_share_pct ?? 0;
        byKey.set(k, cur);
    }));
    const top = Array.from(byKey.entries()).sort((x, y) => y[1].share - x[1].share).slice(0, 2);
    if (!top.length) return null;
    const keys = new Set(top.map(([k]) => k));
    const gained = list.filter((w) => w.actions.length > 0 && w.actions.every((a) => keys.has(`${a.type}|${a.product_id}`)))
        .reduce((n, w) => n + (w.revenue_share_pct ?? 0), 0);
    return { phrases: top.map(([, v]) => actionPhrase(v.a, v.dish)), targetPct: confirmedPct === null ? null : Math.min(100, confirmedPct + gained) };
}

/** "a e b"; "a, b e c". */
export const joinPt = (parts: string[]) => (parts.length <= 1 ? parts[0] ?? "" : `${parts.slice(0, -1).join(", ")} e ${parts[parts.length - 1]}`);

/**
 * "O que isto quer dizer" de uma família, em frases geradas dos dados (sem IA): a estrela principal, a
 * maior oportunidade (o cavalo de trabalho mais vendido), o cão com mais receita e os custos parciais.
 */
export function familyInsights(items: MenuItem[], family: string, days: number, worklist: Work[], hidden: boolean): string[] {
    const inFam = items.filter((i) => i.family === family && !i.excluded);
    const out: string[] = [];
    const star = inFam.filter((i) => i.class === "estrela").sort((a, b) => (b.revenue_eur ?? b.qty) - (a.revenue_eur ?? a.qty))[0];
    if (star) {
        out.push(`${star.description} é a estrela: ${fmtPct(star.share_pct)} das vendas da família${!hidden && star.margin_eur !== null ? ` e ${fmtEur(star.margin_eur)} de margem por unidade` : ""}.`);
    }
    const horse = inFam.filter((i) => i.class === "cavalo").sort((a, b) => b.qty - a.qty)[0];
    if (horse) {
        const perMonth = (horse.qty / Math.max(1, days)) * 30;
        out.push(`Subir 0,50 € no preço de ${horse.description} vale ≈ ${fmtEur(Math.round(perMonth * 0.5), 0)} por mês (${fmtQty(perMonth)} unidades por mês).`);
    }
    const dog = inFam.filter((i) => i.class === "cao").sort((a, b) => (b.revenue_eur ?? b.qty) - (a.revenue_eur ?? a.qty))[0];
    if (dog) out.push(`${dog.description} vende pouco e deixa pouca margem: rever a receita e o preço.`);
    if (inFam.some((i) => i.confidence === "parcial")) {
        const t = topActions(worklist, null, family);
        out.push(t && t.phrases.length ? `Os custos ainda são parciais: confirmar ${t.phrases[0]} fecha os números.` : "Os custos ainda são parciais: há ingredientes sem preço.");
    }
    if (!out.length) out.push("Esta família ainda não tem artigos com custo: a matriz precisa das fichas técnicas.");
    return out;
}
