import type { AutoComparison, AutomotiveMarketing } from "common/models/automotiveMarketing.model";
import type { Recommendation } from "common/models/recommendation.model";
import { eur0, int, monthLong, monthShort, signedPct } from "./restaurantMarketingText";

/**
 * XPLENDOR — Textos do bloco "Marketing e resultados" do automóvel.
 *
 * Regras de redação: português de Portugal, registo formal, SEM travessões;
 * frases DESCRITIVAS, lado a lado ("no mesmo período…"), NUNCA causais; cada
 * número diz contra o que está a ser comparado. Base 0: em texto ("sem
 * investimento no mesmo mês do ano passado"), nunca uma percentagem infinita.
 * Funções puras (sem React) para poderem ser testadas. A formatação de meses e
 * valores reaproveita a da restauração.
 */

/** O que dizer quando a base é 0, por métrica ("sem investimento …"). */
export type ZeroNoun = "investimento" | "cliques" | "leads" | "leads pagas" | "contactos" | "visitas";

const parseYm = (ym: string) => {
    const [y, m] = ym.split("-").map(Number);
    return { y, m };
};

/** "17,75 €" (custo por lead: com cêntimos). */
export const eur2 = (v: number) =>
    new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR", minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v);

/** "02/10" a partir de "2026-10-02". */
export const dayMonth = (date: string) => `${date.slice(8, 10)}/${date.slice(5, 7)}`;

/** "no mesmo mês do ano passado" / "no mês anterior". */
export const tierPhrase = (cmp: AutoComparison): string =>
    cmp.tier === "same_month_last_year" ? "no mesmo mês do ano passado" : "no mês anterior";

/** "outubro de 2025" / "setembro" (com o ano só se mudar de ano). */
const referenceLong = (cmp: AutoComparison, month: string): string | null => {
    if (cmp.tier === null) return null;
    return monthLong(cmp.reference_month, parseYm(cmp.reference_month).y !== parseYm(month).y);
};

export type ComparisonView =
    | { kind: "delta"; deltaPct: number; tag: string; seasonal: boolean }
    | { kind: "zero"; text: string; seasonal: boolean }
    | { kind: "none"; text: string };

/**
 * Como mostrar uma comparação num cartão:
 *   · delta → "+12%" e a etiqueta "vs set. 2026";
 *   · zero  → base 0: "sem investimento no mesmo mês do ano passado";
 *   · none  → "sem histórico" ou "sem comparação: o registo de visitas começou a 02/10".
 */
export function comparisonView(cmp: AutoComparison | null | undefined, zeroNoun: ZeroNoun): ComparisonView {
    if (!cmp) return { kind: "none", text: "sem histórico" };
    if (cmp.tier === null) {
        return cmp.reason === "tracking_started"
            ? { kind: "none", text: `sem comparação: o registo de visitas começou a ${dayMonth(cmp.tracking_since)}` }
            : { kind: "none", text: "sem histórico" };
    }
    if (cmp.base_value === 0 || cmp.delta_pct === null) {
        return { kind: "zero", text: `sem ${zeroNoun} ${tierPhrase(cmp)}`, seasonal: cmp.seasonality_warning };
    }
    return { kind: "delta", deltaPct: cmp.delta_pct, tag: `vs ${monthShort(cmp.reference_month)}`, seasonal: cmp.seasonality_warning };
}

/** " (+40% face a setembro)" / " (sem investimento no mesmo mês do ano passado)" / "". */
const vs = (cmp: AutoComparison | null | undefined, month: string, zeroNoun: ZeroNoun) => {
    const v = comparisonView(cmp, zeroNoun);
    if (v.kind === "delta" && cmp && cmp.tier !== null) return ` (${signedPct(v.deltaPct)} face a ${referenceLong(cmp, month)})`;
    if (v.kind === "zero") return ` (${v.text})`;
    return "";
};

const periodPhrase = (data: AutomotiveMarketing) => {
    const name = monthLong(data.month, false);
    return data.is_current_month ? `Entre 1 e ${data.period.days} de ${name}` : `Em ${name}`;
};

const plural = (n: number, one: string, many: string) => `${int(n)} ${n === 1 ? one : many}`;

/** 2 a 4 frases descritivas a partir dos números. Nunca causais. */
export function buildAutoInsights(data: AutomotiveMarketing): string[] {
    const m = data.metrics;
    const out: string[] = [];
    if (data.period.days === 0) return out;

    const when = periodPhrase(data);

    // 1) Investimento na Meta.
    if (m.meta_spend) {
        out.push(
            m.meta_spend.total > 0
                ? `${when}, investiu ${eur0(m.meta_spend.total)} na Meta${vs(m.meta_spend.comparison, data.month, "investimento")}.`
                : `${when}, não houve investimento registado na Meta.`
        );
    }

    // 2) Leads, lado a lado.
    if (m.leads) {
        const lead = out.length > 0 ? "No mesmo período," : `${when},`;
        if (m.leads.total === 0) {
            // Zero com base zero: "não registou leads, tal como no mês anterior".
            const v = comparisonView(m.leads.comparison, "leads");
            const tail = v.kind === "zero" && m.leads.comparison.tier !== null
                ? `, tal como ${tierPhrase(m.leads.comparison)}`
                : vs(m.leads.comparison, data.month, "leads");
            out.push(`${lead} não registou leads${tail}.`);
        } else {
            const paid = `, ${m.leads.paid === 1 ? "1 das quais" : `${int(m.leads.paid)} das quais`} com origem paga`;
            out.push(`${lead} registou ${plural(m.leads.total, "lead", "leads")}${paid}${vs(m.leads.comparison, data.month, "leads")}.`);
        }
    }

    // 3) Custo por lead pago (descritivo; sem divisão por zero).
    if (m.paid_cpl) {
        if (m.paid_cpl.state === "ok" && m.paid_cpl.value !== null) {
            out.push(`O custo por lead pago foi de ${eur2(m.paid_cpl.value)}.`);
        } else if (m.paid_cpl.state === "spend_without_lead") {
            out.push(`Houve ${eur0(m.paid_cpl.spend)} de investimento na Meta e nenhuma lead com origem paga.`);
        }
    }

    // 4) Site e contactos diretos.
    if (m.ga4_sessions && m.ga4_sessions.total > 0) {
        const paidShare = Math.round((m.ga4_sessions.by_group.paid.total / m.ga4_sessions.total) * 100);
        out.push(`O site teve ${plural(m.ga4_sessions.total, "visita", "visitas")}${vs(m.ga4_sessions.comparison, data.month, "visitas")}, ${paidShare}% das quais vindas de anúncios pagos.`);
    }
    if (m.contacts && m.contacts.total > 0) {
        out.push(`Registou ${plural(m.contacts.total, "contacto direto", "contactos diretos")} por WhatsApp, chamada ou telefone${vs(m.contacts.comparison, data.month, "contactos")}.`);
    }

    return out.slice(0, 4);
}

/** Alguma comparação usa o mês anterior (aviso de sazonalidade)? */
export function usesSeasonality(data: AutomotiveMarketing): boolean {
    const m = data.metrics;
    return [m.leads?.comparison, m.contacts?.comparison, m.meta_spend?.comparison, m.ga4_sessions?.comparison, m.paid_cpl?.comparison]
        .some((c) => !!c && c.tier !== null && c.seasonality_warning);
}

/**
 * Recomendações de prioridade alta para o contador do separador "Stock": uma
 * por viatura (como no hub, que mostra só a mais prioritária de cada viatura).
 */
export function countHighPriority(recs: Recommendation[]): number {
    const byCar = new Map<string, Recommendation>();
    let other = 0;
    for (const r of recs) {
        const carId = r.evidence?.car_id;
        if (carId === undefined || carId === null) {
            if (r.level === "high") other++;
            continue;
        }
        const key = String(carId);
        const cur = byCar.get(key);
        if (!cur || r.priority > cur.priority) byCar.set(key, r);
    }
    return other + Array.from(byCar.values()).filter((r) => r.level === "high").length;
}
