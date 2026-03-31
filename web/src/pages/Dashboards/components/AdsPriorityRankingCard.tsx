import { useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Col, Modal, ModalBody, ModalFooter, ModalHeader } from "reactstrap";
import { IAdsPriorityRankedCar } from "./marketingRoi.types";

type Props = {
    cars: IAdsPriorityRankedCar[];
};

const limitOptions = [
    { label: "5", value: 5 },
    { label: "10", value: 10 },
    { label: "20", value: 20 },
    { label: "Todos", value: "all" as const },
];

const MIN_PRIORITY_FOR_READY = 55;
const MIN_CONFIDENCE_FOR_READY = 50;

const formatCurrency = (value: number | null) => {
    if (value === null || value === undefined) return "—";

    return value.toLocaleString("pt-PT", {
        style: "currency",
        currency: "EUR",
        maximumFractionDigits: 0,
    });
};

const formatConfidence = (value: number) => `${value}%`;

const priorityMeta = (label: IAdsPriorityRankedCar["investment_label"]) => {
    switch (label) {
        case "high_priority":
            return { label: "Alta prioridade", bg: "#e7f8ee", color: "#0f8a4b" };
        case "medium_priority":
            return { label: "Bom candidato", bg: "#fff4d6", color: "#a16207" };
        case "avoid_investment":
            return { label: "Evitar investimento", bg: "#fde8e4", color: "#c2410c" };
        default:
            return { label: "Em observação", bg: "#eef2f7", color: "#475569" };
    }
};

const decisionLabel = (
    decision: IAdsPriorityRankedCar["smartads_decision"],
    investmentLabel: IAdsPriorityRankedCar["investment_label"],
) => {
    if (investmentLabel === "low_priority") {
        return "Sem decisão forte";
    }

    switch (decision) {
        case "scale_ads":
            return "Escalar ads";
        case "test_campaign":
            return "Testar campanha";
        case "review_campaign":
            return "Rever campanha";
        case "do_not_invest":
            return "Não investir";
        default:
            return "Sem decisão";
    }
};

const getObjective = (car: IAdsPriorityRankedCar) => {
    switch (car.smartads_decision) {
        case "scale_ads":
            return "Conversão direta";
        case "test_campaign":
            return "Validação de procura";
        case "review_campaign":
            return "Recuperar atenção";
        default:
            return "Observação";
    }
};

const getAudienceSuggestion = (car: IAdsPriorityRankedCar) => {
    if (car.investment_label === "high_priority") {
        return "Audiência quente e lookalikes de intenção elevada.";
    }

    if (car.investment_label === "medium_priority") {
        return "Audiência de teste com foco em interesse e intenção de contacto.";
    }

    return "Audiência ainda em validação.";
};

const getCreativeSuggestion = (car: IAdsPriorityRankedCar) => {
    switch (car.smartads_decision) {
        case "scale_ads":
            return "Criativo comercial direto com CTA forte e foco em conversão.";
        case "test_campaign":
            return "Criativo de descoberta com gancho claro e promessa objetiva.";
        case "review_campaign":
            return "Criativo de revisão para reduzir fricção e clarificar valor.";
        default:
            return "Ainda não existe criativo forte recomendado.";
    }
};

const getSuggestedDailyBudget = (car: IAdsPriorityRankedCar) => {
    if (car.investment_label === "high_priority") return "25€/dia";
    if (car.investment_label === "medium_priority") return "12€/dia";
    return "Sem budget sugerido";
};

const getEmptyState = () => ({
    title: "Ainda não existem oportunidades fortes de investimento",
    description: "O sistema ainda não identificou carros com sinais consistentes de procura e conversão.",
    subtext: "Continua a acumular dados de comportamento, mercado e leads para melhorar a precisão das recomendações.",
});

const metricBoxStyle = {
    minWidth: 92,
    textAlign: "center" as const,
    border: "1px solid #e9ebec",
    borderRadius: 12,
    padding: "8px 10px",
    background: "#fff",
};

export default function AdsPriorityRankingCard({ cars }: Props) {
    const [limit, setLimit] = useState<number | "all">(5);
    const [selectedCar, setSelectedCar] = useState<IAdsPriorityRankedCar | null>(null);

    const visibleState = useMemo(() => {
        const readyCars = cars.filter((car) =>
            car.investment_label !== "avoid_investment"
            && car.investment_label !== "low_priority"
            && car.smartads_decision !== "do_not_invest"
            && car.priority_score >= MIN_PRIORITY_FOR_READY
            && car.confidence_score >= MIN_CONFIDENCE_FOR_READY
        );

        const goodCandidates = readyCars.filter((car) => car.investment_label === "medium_priority");
        const strongReadyCars = readyCars.filter((car) => car.investment_label === "high_priority");

        const watchlistCars = cars.filter((car) =>
            !readyCars.some((readyCar) => readyCar.car_id === car.car_id)
            && car.investment_label !== "avoid_investment"
        );

        const avoidCars = cars.filter((car) =>
            car.investment_label === "avoid_investment"
            || car.smartads_decision === "do_not_invest"
        );

        const applyLimit = (items: IAdsPriorityRankedCar[]) => limit === "all" ? items : items.slice(0, limit);

        return {
            readyCars: applyLimit(strongReadyCars),
            goodCandidates: applyLimit(goodCandidates),
            watchlistCars: applyLimit(watchlistCars),
            avoidCars: applyLimit(avoidCars),
            hasStrongOpportunities: strongReadyCars.length > 0 || goodCandidates.length > 0,
        };
    }, [cars, limit]);

    const renderActionButtons = (car: IAdsPriorityRankedCar, mode: "campaign" | "analytics_only") => (
        <div className="d-flex align-items-center gap-2 flex-wrap">
            {mode === "campaign" && (
                <button
                    type="button"
                    className="btn btn-primary btn-sm"
                    onClick={() => setSelectedCar(car)}
                >
                    Gerar campanha
                </button>
            )}
            <Link to={`/cars/${car.car_id}/analytics`} className="btn btn-soft-primary btn-sm">
                Ver analytics
            </Link>
        </div>
    );

    const renderCarItem = (car: IAdsPriorityRankedCar, mode: "campaign" | "analytics_only") => {
        const badge = priorityMeta(car.investment_label);

        return (
            <div
                key={car.car_id}
                style={{
                    border: "1px solid #e9ebec",
                    borderRadius: 14,
                    padding: 16,
                    background: "#fff",
                }}
            >
                <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                    <div>
                        <div className="d-flex align-items-center gap-2 flex-wrap mb-2">
                            <span className="badge bg-light text-dark fs-12 px-2 py-2">#{car.position}</span>
                            <span className="badge rounded-pill fs-12 px-3 py-2" style={{ background: badge.bg, color: badge.color }}>
                                {badge.label}
                            </span>
                            <span className="badge bg-light text-muted fs-12 px-3 py-2">
                                {decisionLabel(car.smartads_decision, car.investment_label)}
                            </span>
                        </div>
                        <h6 className="mb-1 fw-semibold text-body">
                            {car.car_name} · {formatCurrency(car.price_gross)}
                        </h6>
                    </div>

                    <div className="d-flex align-items-center gap-2 flex-wrap">
                        <div style={metricBoxStyle}>
                            <div className="text-muted fs-11 text-uppercase fw-semibold mb-1">Score</div>
                            <div className="fw-semibold">{car.priority_score}</div>
                        </div>
                        <div style={metricBoxStyle}>
                            <div className="text-muted fs-11 text-uppercase fw-semibold mb-1">Confiança</div>
                            <div className="fw-semibold">{formatConfidence(car.confidence_score)}</div>
                        </div>
                    </div>
                </div>

                <div className="d-flex flex-column gap-2 mb-3">
                    <div>
                        <p className="text-uppercase text-muted fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Motivo
                        </p>
                        <p className="mb-0 fs-14 text-body">{car.reason}</p>
                    </div>

                    <div>
                        <p className="text-uppercase text-muted fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Porque agora
                        </p>
                        <p className="mb-0 fs-13 text-muted">{car.why_now}</p>
                    </div>

                    {car.risk_note && (
                        <div>
                            <p className="text-uppercase text-muted fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                Risco
                            </p>
                            <p className="mb-0 fs-13" style={{ color: "#c2410c" }}>
                                {car.risk_note}
                            </p>
                        </div>
                    )}
                </div>

                {renderActionButtons(car, mode)}
            </div>
        );
    };

    const renderSection = (
        title: string,
        description: string,
        items: IAdsPriorityRankedCar[],
        mode: "campaign" | "analytics_only",
        emptyMessage?: string,
    ) => (
        <div className="d-flex flex-column gap-3">
            <div>
                <h6 className="mb-1 fw-semibold">{title}</h6>
                <p className="text-muted fs-13 mb-0">{description}</p>
            </div>

            {items.length > 0 ? (
                items.map((item) => renderCarItem(item, mode))
            ) : emptyMessage ? (
                <div
                    style={{
                        border: "1px dashed #dfe3e6",
                        borderRadius: 14,
                        padding: 16,
                        background: "#fafbfc",
                    }}
                >
                    <h6 className="mb-2 fw-semibold">{getEmptyState().title}</h6>
                    <p className="text-muted fs-14 mb-2">{getEmptyState().description}</p>
                    <p className="text-muted fs-13 mb-0">{getEmptyState().subtext}</p>
                </div>
            ) : null}
        </div>
    );

    return (
        <Col xs={12}>
            <section
                style={{
                    border: "1px solid #e9ebec",
                    borderRadius: 16,
                    background: "#fff",
                    overflow: "hidden",
                }}
            >
                <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap" style={{ padding: "16px 18px", borderBottom: "1px solid #e9ebec" }}>
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Top oportunidades de investimento
                        </p>
                        <h5 className="mb-1 fw-semibold">Top oportunidades de investimento</h5>
                        <p className="text-muted fs-13 mb-0">
                            Identificamos automaticamente os carros com maior probabilidade de gerar vendas com ads neste momento.
                        </p>
                    </div>

                    <div className="d-flex flex-column align-items-start align-items-md-end gap-2">
                        <div className="d-flex align-items-center gap-2 flex-wrap">
                            <span className="text-muted fs-12 fw-semibold">Selecionar quantidade:</span>
                            {limitOptions.map((option) => (
                                <button
                                    key={option.label}
                                    type="button"
                                    onClick={() => setLimit(option.value)}
                                    className="btn btn-sm"
                                    style={{
                                        border: limit === option.value ? "1px solid #405189" : "1px solid #dfe3e6",
                                        background: limit === option.value ? "#405189" : "#fff",
                                        color: limit === option.value ? "#fff" : "#405189",
                                        minWidth: 52,
                                    }}
                                >
                                    {option.label}
                                </button>
                            ))}
                        </div>
                        <span className="badge bg-light text-muted fs-12 px-3 py-2">
                            Por impacto potencial
                        </span>
                    </div>
                </div>

                {cars.length > 0 ? (
                    <div style={{ padding: 16 }} className="d-flex flex-column gap-4">
                        {renderSection(
                            "Prontos para anunciar",
                            "Carros com sinais fortes de procura, boa posição de mercado e potencial de conversão.",
                            visibleState.readyCars,
                            "campaign",
                            !visibleState.hasStrongOpportunities
                                ? "Ainda não existem oportunidades fortes de investimento"
                                : undefined
                        )}

                        {visibleState.goodCandidates.length > 0 && renderSection(
                            "Bons candidatos",
                            "Carros com sinais positivos mas ainda sem confirmação total. Ideal para testar campanhas.",
                            visibleState.goodCandidates,
                            "campaign",
                        )}

                        {visibleState.watchlistCars.length > 0 && renderSection(
                            "Em observação",
                            "Ainda sem dados suficientes para justificar investimento. Acompanhar evolução.",
                            visibleState.watchlistCars,
                            "analytics_only",
                        )}

                        {visibleState.avoidCars.length > 0 && renderSection(
                            "Evitar investimento agora",
                            "Carros com baixa procura, preço desalinhado ou baixa probabilidade de conversão.",
                            visibleState.avoidCars,
                            "analytics_only",
                        )}
                    </div>
                ) : (
                    <div style={{ padding: "16px 18px" }} className="text-muted">
                        Ainda não existem carros ativos suficientes para construir um ranking.
                    </div>
                )}
            </section>

            <Modal isOpen={!!selectedCar} toggle={() => setSelectedCar(null)} centered>
                <ModalHeader toggle={() => setSelectedCar(null)}>
                    Campanha sugerida pela Xplendor
                </ModalHeader>
                <ModalBody>
                    {selectedCar && (
                        <div className="d-flex flex-column gap-3">
                            <p className="text-muted fs-13 mb-0">
                                Revê e aprova antes de executar no Meta Ads.
                            </p>

                            <div>
                                <p className="text-uppercase text-muted fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                    Nome da campanha
                                </p>
                                <div className="fw-semibold">{selectedCar.car_name} · {decisionLabel(selectedCar.smartads_decision, selectedCar.investment_label)}</div>
                            </div>

                            <div>
                                <p className="text-uppercase text-muted fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                    Objetivo
                                </p>
                                <div>{getObjective(selectedCar)}</div>
                            </div>

                            <div>
                                <p className="text-uppercase text-muted fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                    Público sugerido
                                </p>
                                <div>{getAudienceSuggestion(selectedCar)}</div>
                            </div>

                            <div>
                                <p className="text-uppercase text-muted fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                    Criativo recomendado
                                </p>
                                <div>{getCreativeSuggestion(selectedCar)}</div>
                            </div>

                            <div>
                                <p className="text-uppercase text-muted fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                    Orçamento diário sugerido
                                </p>
                                <div>{getSuggestedDailyBudget(selectedCar)}</div>
                            </div>
                        </div>
                    )}
                </ModalBody>
                <ModalFooter>
                    <button type="button" className="btn btn-primary">
                        Aprovar campanha
                    </button>
                    <button type="button" className="btn btn-soft-secondary">
                        Editar campanha
                    </button>
                    <button type="button" className="btn btn-soft-primary">
                        Copiar conteúdo
                    </button>
                    <button type="button" className="btn btn-light" onClick={() => setSelectedCar(null)}>
                        Fechar
                    </button>
                </ModalFooter>
            </Modal>
        </Col>
    );
}
