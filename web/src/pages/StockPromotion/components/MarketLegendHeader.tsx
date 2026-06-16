import { useId } from "react";
import { UncontrolledTooltip } from "reactstrap";

/**
 * Header da coluna "Mercado" com ⓘ que abre legenda das cores.
 *
 * Reusa a mesma mecânica do `FieldLabelWithHint` (trigger "hover focus click"
 * → toca/clica funcionam em touch + desktop). Não é o próprio componente
 * porque aquele renderiza `<Label>` para formulários — aqui precisamos só
 * de ícone + tooltip.
 *
 * Razão de ser: a tabela usa verde/vermelho/cinza, mas o utilizador que não
 * construiu a ferramenta não sabe o mapeamento. Esta legenda dá gestão à
 * vista para a Matilde ler sem explicação prévia.
 */
const MarketLegendHeader = () => {
    const reactId = useId();
    const tipId = `mkt-legend-${reactId.replace(/:/g, "")}`;

    return (
        <span className="d-inline-flex align-items-center">
            Mercado
            <span
                id={tipId}
                tabIndex={0}
                role="button"
                aria-label="Legenda das cores da coluna Mercado"
                className="ms-1 d-inline-flex align-items-center"
                style={{ cursor: "help", verticalAlign: "middle" }}
            >
                <i className="ri-information-line text-muted" aria-hidden="true" />
            </span>
            <UncontrolledTooltip
                target={tipId}
                trigger="hover focus click"
                // placement="bottom" — antes era "top" e sobrepunha-se aos
                // cabeçalhos vizinhos (Preço, IPS), tapando dados úteis.
                // "bottom" desce para o espaço da linha de dados, mais limpo.
                placement="bottom"
                autohide={false}
            >
                <div className="text-start" style={{ minWidth: 220 }}>
                    <div className="mb-1">
                        <span className="badge bg-success-subtle text-success me-2">verde</span>
                        no mercado ou abaixo
                    </div>
                    <div className="mb-1">
                        <span className="badge bg-danger-subtle text-danger me-2">vermelho</span>
                        acima do mercado
                    </div>
                    <div>
                        <span className="badge bg-light text-muted me-2">cinza</span>
                        confiança baixa ou sem dados suficientes
                    </div>
                </div>
            </UncontrolledTooltip>
        </span>
    );
};

export default MarketLegendHeader;
