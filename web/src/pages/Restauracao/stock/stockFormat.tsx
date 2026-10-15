import { useEffect, useRef, useState } from "react";
import HiddenMoney from "Components/Common/HiddenMoney";
import { getStockWrite } from "helpers/laravel_helper";
import { StockState, StockWrite } from "common/models/stock.model";
import { fmtEur, fmtNum, fmtUnitPrice } from "pages/Auditoria/auditFormat";

/** XPLENDOR — Fatia 7a: formatos e peças comuns do stock e dos armazéns. */

/** Uma quantidade: separador de milhares sempre e até 3 casas, sem zeros à direita (1 896; 308,333). */
export const fmtStockQty = (v: number | null | undefined, decimals = 3) => {
    if (v === null || v === undefined) return "—";
    const s = fmtNum(v, decimals);
    return s.includes(",") ? s.replace(/0+$/, "").replace(/,$/, "") : s;
};

/** Dias de cobertura: inteiros acima de 10; uma casa abaixo. */
export const fmtDays = (v: number | null | undefined) =>
    v === null || v === undefined ? "—" : v >= 10 ? fmtNum(Math.round(v), 0) : fmtNum(v, 1);

/** O plural da unidade de stock ("Unidade" → "unidades"). */
export const unitLabel = (unit: string | null | undefined, qty = 2) => {
    const u = (unit || "unidade").toLowerCase();
    if (qty === 1) return u;
    const plural: Record<string, string> = { unidade: "unidades", quilograma: "quilogramas", litro: "litros", metro: "metros", grama: "gramas", mililitro: "mililitros" };
    return plural[u] ?? u;
};

export function StockMoney({ value, hidden, unit = false, seed = 0 }: { value: number | null | undefined; hidden: boolean; unit?: boolean; seed?: number }) {
    if (hidden) return <HiddenMoney seed={seed} />;
    return <>{unit ? fmtUnitPrice(value) : fmtEur(value)}</>;
}

export const STATE_STYLE: Record<StockState, { label: string; color: string; icon: string }> = {
    negativo: { label: "Negativo", color: "danger", icon: "ri-arrow-down-circle-line" },
    abaixo_minimo: { label: "Abaixo do mínimo", color: "warning", icon: "ri-alarm-warning-line" },
    sem_consumo: { label: "Sem consumo", color: "info", icon: "ri-pause-circle-line" },
    cobertura_60: { label: "Mais de 60 dias", color: "secondary", icon: "ri-time-line" },
};

export function StockStates({ states }: { states: StockState[] }) {
    if (!states.length) return <span className="text-muted">—</span>;
    return (
        <div className="d-flex flex-wrap gap-1">
            {states.map((s) => (
                <span key={s} className={`badge bg-${STATE_STYLE[s].color}-subtle text-${STATE_STYLE[s].color}`} data-testid="stock-state">
                    <i className={`${STATE_STYLE[s].icon} me-1`} aria-hidden />{STATE_STYLE[s].label}
                </span>
            ))}
        </div>
    );
}

export const PCM_TIP = "Custo médio: o custo de cada unidade em stock, calculado pelo PingWin com a média das entradas (PCM).";
export const PCU_TIP = "Último custo: o preço da última entrada deste artigo neste armazém (PCU).";
export const CONSUMPTION_TIP =
    "Consumo teórico: as vendas de cada dia × a ficha técnica em vigor nesse dia e nessa loja (com os preparados da cozinha expandidos pelo rendimento confirmado), na unidade de stock. Um artigo vendido sem ficha conta como consumo dele próprio. Média dos últimos 28 dias com vendas lidas.";
export const COVERAGE_TIP = "Dias de cobertura: o stock a dividir pelo consumo teórico médio por dia. Só aparece quando há consumo.";

export const WRITE_STATUS: Record<StockWrite["status"], { label: string; color: string }> = {
    queued: { label: "Na fila", color: "secondary" },
    running: { label: "A gravar no PingWin", color: "info" },
    ok: { label: "Gravado e confirmado", color: "success" },
    mismatch: { label: "Gravado, mas não confirmado", color: "warning" },
    conflict: { label: "Não gravado: os dados mudaram", color: "warning" },
    failed: { label: "Não gravado", color: "danger" },
};

const FINAL: StockWrite["status"][] = ["ok", "mismatch", "conflict", "failed"];

/** Acompanha uma escrita até ao fim (pergunta o estado a cada 1,5 s). */
export function useStockWrite(companyId: number | null, onDone?: (w: StockWrite) => void) {
    const [write, setWrite] = useState<StockWrite | null>(null);
    const done = useRef(onDone);
    done.current = onDone;
    useEffect(() => {
        if (!companyId || !write || FINAL.includes(write.status)) return;
        const t = setTimeout(async () => {
            try {
                const res: any = await getStockWrite(companyId, write.id);
                const next: StockWrite | undefined = res?.data?.write;
                if (next) {
                    setWrite(next);
                    if (FINAL.includes(next.status)) done.current?.(next);
                }
            } catch { /* tenta outra vez */ }
        }, 1500);
        return () => clearTimeout(t);
    }, [companyId, write]);
    const busy = !!write && !FINAL.includes(write.status);
    return { write, setWrite, busy };
}

/** A frase de uma escrita terminada (para o toast e para o histórico). */
export function writeMessage(w: StockWrite): string {
    if (w.status === "ok") return "Gravado no PingWin e confirmado pela releitura.";
    return w.error || WRITE_STATUS[w.status].label;
}
