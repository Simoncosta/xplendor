import type { MarketingComparison, RestaurantMarketing } from "common/models/restaurantMarketing.model";

/**
 * XPLENDOR — Textos do bloco "marketing e resultados" (dashboard de restauração).
 *
 * Regras de redação (para quem não é técnico):
 *   · português de Portugal, registo formal, SEM travessões;
 *   · frases DESCRITIVAS, lado a lado ("no mesmo período…"), NUNCA causais (nada
 *     de "porque", "devido a", "graças a", "por causa de", "resultou em");
 *   · cada número diz contra o que está a ser comparado.
 * Funções puras (sem React) para poderem ser testadas.
 */

const MONTHS_LONG = ["janeiro", "fevereiro", "março", "abril", "maio", "junho", "julho", "agosto", "setembro", "outubro", "novembro", "dezembro"];
const MONTHS_SHORT = ["jan.", "fev.", "mar.", "abr.", "mai.", "jun.", "jul.", "ago.", "set.", "out.", "nov.", "dez."];

const parseYm = (ym: string) => {
    const [y, m] = ym.split("-").map(Number);
    return { y, m };
};

/** "setembro de 2025" */
export const monthLong = (ym: string, withYear = true) => {
    const { y, m } = parseYm(ym);
    return withYear ? `${MONTHS_LONG[m - 1]} de ${y}` : MONTHS_LONG[m - 1];
};

/** "set. 2025" */
export const monthShort = (ym: string) => {
    const { y, m } = parseYm(ym);
    return `${MONTHS_SHORT[m - 1]} ${y}`;
};

export const eur0 = (v: number) =>
    new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR", maximumFractionDigits: 0 }).format(v);

export const int = (v: number) => new Intl.NumberFormat("pt-PT").format(Math.round(v));

/** "+12%" / "-3,5%" (hífen, nunca travessão). */
export const signedPct = (v: number) => `${v > 0 ? "+" : v < 0 ? "-" : ""}${Math.abs(v).toLocaleString("pt-PT", { maximumFractionDigits: 1 })}%`;

/** "+0,5 p.p." */
export const signedPp = (v: number) => `${v > 0 ? "+" : v < 0 ? "-" : ""}${Math.abs(v).toLocaleString("pt-PT", { maximumFractionDigits: 1 })} p.p.`;

/** Referência da comparação para as frases: ano anterior → "outubro de 2025";
 *  mês anterior → "setembro" (com o ano se mudar de ano). */
export const referenceLong = (cmp: MarketingComparison, month: string): string | null => {
    if (cmp.tier === null) return null;
    const ref = parseYm(cmp.reference_month);
    const cur = parseYm(month);
    return monthLong(cmp.reference_month, ref.y !== cur.y);
};

/** Etiqueta curta da comparação para os cartões: "vs set. 2025" ou "sem histórico". */
export const comparisonTag = (cmp: MarketingComparison | undefined | null): string =>
    !cmp || cmp.tier === null ? "sem histórico" : `vs ${monthShort(cmp.reference_month)}`;

/** Período do mês em linguagem simples. */
const periodPhrase = (data: RestaurantMarketing) => {
    const name = monthLong(data.month, false);
    return data.is_current_month
        ? `Entre 1 e ${data.period.days} de ${name}`
        : `Em ${name}`;
};

/** "(+40% face a agosto)" ou "" quando não há comparação em %. */
const vsPct = (cmp: MarketingComparison, month: string) => {
    if (cmp.tier === null || cmp.delta_pct === null || cmp.delta_pct === undefined) return "";
    return ` (${signedPct(cmp.delta_pct)} face a ${referenceLong(cmp, month)})`;
};

/**
 * 2 a 4 frases descritivas a partir dos deltas. Nunca causais.
 */
export function buildInsights(data: RestaurantMarketing): string[] {
    const m = data.metrics;
    const out: string[] = [];
    if (data.period.days === 0) return out;

    const when = periodPhrase(data);
    const metaRef = m.meta_spend?.comparison;

    // 1) Investimento (o que se pôs em marketing pago).
    if (m.meta_spend) {
        out.push(
            m.meta_spend.total > 0
                ? `${when}, investiu ${eur0(m.meta_spend.total)} na Meta${vsPct(m.meta_spend.comparison, data.month)}.`
                : `${when}, não houve investimento registado na Meta.`
        );
    }

    // 2) Negócio, LADO A LADO ("no mesmo período"), nunca "por isso".
    if (m.revenue) {
        const rev = m.revenue.comparison;
        const cov = m.covers?.has_data ? m.covers.comparison : null;
        const parts: string[] = [];
        if (rev.tier !== null && rev.delta_pct != null) parts.push(`a faturação variou ${signedPct(rev.delta_pct)}`);
        if (cov && cov.tier !== null && cov.delta_pct != null) parts.push(`as pessoas ${signedPct(cov.delta_pct)}`);

        const lead = m.meta_spend ? "No mesmo período, " : `${when}, `;
        if (parts.length > 0) {
            // Só repete a referência se for diferente da usada na frase da Meta.
            const ref = referenceLong(rev, data.month);
            const sameRef = metaRef && metaRef.tier !== null && metaRef.reference_month === (rev.tier !== null ? rev.reference_month : null);
            const refText = sameRef || !ref ? "" : ` face a ${ref}`;
            out.push(capitalize(`${lead}${parts.join(" e ")}${refText}.`));
        } else {
            const covers = m.covers?.has_data ? ` e recebeu ${int(m.covers.total)} pessoas` : "";
            out.push(capitalize(`${lead}faturou ${eur0(m.revenue.total)}${covers}.`));
        }
    }

    // 3) Site (GA4): sessões e a parte vinda de anúncios pagos.
    if (m.ga4_sessions && m.ga4_sessions.total > 0) {
        const paidShare = Math.round((m.ga4_sessions.by_group.paid.total / m.ga4_sessions.total) * 100);
        out.push(
            `O site teve ${int(m.ga4_sessions.total)} sessões${vsPct(m.ga4_sessions.comparison, data.month)}, ` +
            `${paidShare}% das quais vindas de anúncios pagos.`
        );
    }

    // 4) Peso do marketing na faturação (ou, sem Meta, a quota de reservas).
    if (out.length < 4 && m.marketing_weight && m.marketing_weight.value !== null) {
        const cmp = m.marketing_weight.comparison;
        const delta = cmp.tier !== null && cmp.delta_pp !== undefined ? ` (${signedPp(cmp.delta_pp)} face a ${referenceLong(cmp, data.month)})` : "";
        out.push(`O investimento na Meta representou ${m.marketing_weight.value.toLocaleString("pt-PT", { maximumFractionDigits: 1 })}% da faturação${delta}.`);
    } else if (out.length < 4 && m.reservations_mix && m.reservations_mix.reserved_share_pct !== null) {
        out.push(`${m.reservations_mix.reserved_share_pct.toLocaleString("pt-PT", { maximumFractionDigits: 1 })}% das entradas registadas foram reservas feitas com antecedência; as restantes foram sem reserva.`);
    }

    return out.slice(0, 4);
}

/** Há alguma comparação em uso com o mês anterior (aviso de sazonalidade)? */
export function usesSeasonalityWarning(data: RestaurantMarketing): boolean {
    const m = data.metrics;
    const cmps = [m.revenue?.comparison, m.covers?.comparison, m.meta_spend?.comparison, m.ga4_sessions?.comparison, m.marketing_weight?.comparison];
    return cmps.some((c) => c && c.tier !== null && c.seasonality_warning);
}

const capitalize = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);
