import { useRef } from "react";
import { UncontrolledTooltip } from "reactstrap";
import { labelOf, PRICE_SIGNAL_LABELS } from "../../../helpers/labels";
import type { PromotionCandidateMarket } from "../../../types/api";

/**
 * Chip de posição vs mercado — modular por CONFIDENCE.
 *
 * Razão de ser: um "+36% acima do mercado" calculado em apenas 2 comparáveis
 * (confidence=low) NÃO pode parecer mais forte que um "-4% justo" em 8
 * comparáveis (confidence=high). Mostrar o signal sem contexto cria
 * "autoridade falsa" — a mesma armadilha que nos fez recusar o score
 * automático no Relatório A.
 *
 * Regras visuais:
 *   - market: null              → chip neutro "Sem dados de mercado"
 *   - confidence "high"         → chip cheio com cor do signal + %
 *   - confidence "low"/"medium" → chip APAGADO (cinza) + ⓘ tooltip com
 *                                 nº comparáveis e nota de confiança
 *   - signal: null              → cai no estado "Sem dados" (não há median)
 */
interface MarketChipProps {
    market: PromotionCandidateMarket | null;
    /** id único por linha para o tooltip não colidir entre cards. */
    rowId: string | number;
}

const MarketChip = ({ market, rowId }: MarketChipProps) => {
    const tooltipRef = useRef<HTMLSpanElement>(null);
    const tooltipId = `mkt-tip-${rowId}`;

    // CASO 1 — Sem aggregate (motorhomes novos, modelos sem comparáveis).
    if (!market || market.price_signal === null || market.price_difference_percent === null) {
        return (
            <span className="badge bg-light text-muted fw-normal">
                Sem dados de mercado
            </span>
        );
    }

    const signal = market.price_signal;
    const diff = market.price_difference_percent;
    const count = market.comparables_count;
    const isHighConfidence = market.confidence === "high";
    const signalLabel = labelOf(signal, PRICE_SIGNAL_LABELS);

    // Sinal visual baseado no signal — verde para below/fair, vermelho para above.
    const isAboveMarket = signal === "overpriced" || signal === "slightly_high";
    const baseClass = isAboveMarket
        ? (isHighConfidence ? "bg-danger-subtle text-danger" : "bg-light text-muted")
        : (isHighConfidence ? "bg-success-subtle text-success" : "bg-light text-muted");

    // Formato da diferença: "+36%" / "-4%" (sinal explícito ajuda leitura rápida).
    const diffStr = `${diff > 0 ? "+" : ""}${diff.toFixed(diff % 1 === 0 ? 0 : 1)}%`;

    return (
        <>
            <span
                ref={tooltipRef}
                id={tooltipId}
                className={`badge fw-normal ${baseClass}`}
                style={{ cursor: "help" }}
            >
                {signalLabel} · {diffStr}
                {!isHighConfidence && (
                    <i className="ri-information-line ms-1" aria-hidden="true" />
                )}
            </span>
            <UncontrolledTooltip
                target={tooltipId}
                placement="top"
                trigger="hover focus click"
            >
                {isHighConfidence
                    ? `Confiança alta · baseado em ${count} ${count === 1 ? "anúncio" : "anúncios"} comparáveis.`
                    : `Confiança ${market.confidence === "medium" ? "média" : "baixa"} · só ${count} ${count === 1 ? "anúncio" : "anúncios"} comparáveis. Interpretar com cautela.`}
            </UncontrolledTooltip>
        </>
    );
};

export default MarketChip;
