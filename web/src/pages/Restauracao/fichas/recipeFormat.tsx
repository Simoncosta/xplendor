import React from "react";
import HiddenMoney from "Components/Common/HiddenMoney";
import { RecipeState } from "common/models/recipe.model";

/** Fatia 1 — formatos e peças partilhadas das fichas técnicas (lista, detalhe e resumo no artigo). */

const eurFmt = (decimals: number) =>
    new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR", minimumFractionDigits: decimals, maximumFractionDigits: decimals });

/** Euros: 2 casas por omissão; os custos unitários pequenos com 4. */
export const fmtEur = (v: number | null | undefined, decimals = 2) =>
    v === null || v === undefined ? "—" : eurFmt(decimals).format(v);

export const fmtPct = (v: number | null | undefined, decimals = 1) =>
    v === null || v === undefined ? "—" : `${v.toLocaleString("pt-PT", { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}%`;

export const fmtQty = (v: number | null | undefined, decimals = 3) =>
    v === null || v === undefined ? "—" : v.toLocaleString("pt-PT", { maximumFractionDigits: decimals });

export const fmtDate = (d?: string | null) =>
    d ? new Date(`${d.slice(0, 10)}T00:00:00`).toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric" }) : "—";

/** Um valor em euros: o número, ou desfocado para quem não vê as Finanças. */
export function Money({ value, hidden, decimals = 2, seed = 0 }: { value: number | null | undefined; hidden: boolean; decimals?: number; seed?: number }) {
    if (hidden) return <HiddenMoney seed={seed} />;
    return <>{fmtEur(value, decimals)}</>;
}

const STATE_STYLE: Record<RecipeState, { color: string; icon: string }> = {
    com_ficha: { color: "success", icon: "ri-checkbox-circle-line" },
    sem_custo: { color: "warning", icon: "ri-error-warning-line" },
    loja_sem_ficha: { color: "danger", icon: "ri-store-2-line" },
    sem_ficha: { color: "secondary", icon: "ri-file-unknow-line" },
};

export function StateBadge({ state, label, title }: { state: RecipeState; label: string; title?: string }) {
    const s = STATE_STYLE[state];
    return (
        <span className={`badge bg-${s.color}-subtle text-${s.color} text-wrap text-start`} title={title}>
            <i className={`${s.icon} me-1`} aria-hidden />{label}
        </span>
    );
}

/** Diferença do custo XPLENDOR para o do PingWin: âmbar acima de 20%. */
export function DiffBadge({ pct }: { pct: number | null | undefined }) {
    if (pct === null || pct === undefined) return <span className="text-muted">—</span>;
    const big = Math.abs(pct) > 20;
    const shown = Math.round(pct) === 0 ? 0 : pct; // sem "-0%"
    return (
        <span className={big ? "badge bg-warning-subtle text-warning" : "text-muted fs-12"} title={big ? "Diferença acima de 20% para o custo PingWin" : undefined}>
            {shown > 0 ? "+" : ""}{fmtPct(shown, 0)}
        </span>
    );
}

/** A explicação de onde vem cada custo (InfoTip do detalhe e da lista). */
export const COST_SOURCES_TEXT: React.ReactNode = (
    <>
        <strong>Custo XPLENDOR:</strong> das compras dos últimos 90 dias (faturas e faturas-recibo de fornecedor), por unidade base:
        último preço e preço médio ponderado (a coluna mostra os dois), já com os descontos da linha. A quantidade vem do fator
        confirmado, quando existe; senão, da compra. Compras com preço acima de 4 vezes ou abaixo de um quarto
        da mediana ficam de fora. Sem compras em 90 dias, usa o último preço conhecido (antigo). O custo da linha usa o preço médio:
        quantidade base × custo × (1 + desperdício). Os semi-produtos usam o custo do lote da sua ficha ÷ o rendimento confirmado.
        <br /><strong>Custo PingWin:</strong> o que o PingWin calcula para todos os armazéns.
    </>
);
