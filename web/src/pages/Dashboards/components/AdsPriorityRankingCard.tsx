import { useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Col, Modal, ModalBody, ModalFooter, ModalHeader } from "reactstrap";
import { IAdsPriorityRankedCar } from "./marketingRoi.types";

type Props = {
    cars: IAdsPriorityRankedCar[];
};

type PromotionState = "ready" | "candidate" | "watch" | "avoid";

const limitOptions = [
    { label: "5", value: 5 },
    { label: "10", value: 10 },
    { label: "20", value: 20 },
    { label: "Todos", value: "all" as const },
];

const stateSections: Array<{
    state: PromotionState;
    title: string;
    description: string;
    empty: string;
    tone: { bg: string; border: string; color: string };
}> = [
    {
        state: "ready",
        title: "Prontos para anunciar",
        description: "Carros com sinais fortes e sem bloqueadores relevantes.",
        empty: "Nenhum carro está forte o suficiente para investimento imediato.",
        tone: { bg: "#ecfdf3", border: "#bbf7d0", color: "#15803d" },
    },
    {
        state: "candidate",
        title: "Bons candidatos",
        description: "Carros com sinais positivos, bons para testar ou validar orçamento.",
        empty: "Não existem candidatos intermédios neste momento.",
        tone: { bg: "#eff6ff", border: "#bfdbfe", color: "#1d4ed8" },
    },
    {
        state: "watch",
        title: "Em observação",
        description: "Carros ainda com pouco volume ou sinais insuficientes para investir com confiança.",
        empty: "Sem carros em observação agora.",
        tone: { bg: "#fffbeb", border: "#fde68a", color: "#a16207" },
    },
    {
        state: "avoid",
        title: "Evitar investimento",
        description: "Carros onde investir agora tende a gerar baixo retorno.",
        empty: "Nenhum carro foi sinalizado para evitar investimento.",
        tone: { bg: "#fff1f2", border: "#fecdd3", color: "#be123c" },
    },
];

const getPromotionState = (car: IAdsPriorityRankedCar): PromotionState => {
    if (car.promotion_state) return car.promotion_state;

    if (car.smartads_decision === "scale_ads") return "ready";
    if (car.smartads_decision === "test_campaign" || car.smartads_decision === "test_campaign_seed") return "candidate";
    if (car.smartads_decision === "do_not_invest") return "avoid";

    return "watch";
};

const getScore = (car: IAdsPriorityRankedCar) => car.promotion_score ?? car.priority_score ?? 0;
const getConfidence = (car: IAdsPriorityRankedCar) => car.confidence ?? car.confidence_score ?? 0;
const getReasons = (car: IAdsPriorityRankedCar) => {
    if (car.reasons?.length) return car.reasons.slice(0, 3);

    return [car.reason, car.why_now, car.risk_note].filter(Boolean).slice(0, 3) as string[];
};

const getActionLabel = (car: IAdsPriorityRankedCar) => {
    if (car.recommended_action?.label) return car.recommended_action.label;

    const state = getPromotionState(car);

    if (state === "ready" || state === "candidate") return "Promover este carro";
    if (state === "avoid") return "Evitar investimento";

    return "Observar evolução";
};

const formatCurrency = (value: number | null | undefined) => {
    if (value === null || value === undefined) return null;

    return value.toLocaleString("pt-PT", {
        style: "currency",
        currency: "EUR",
        maximumFractionDigits: 0,
    });
};

export default function AdsPriorityRankingCard({ cars }: Props) {
    const [limit, setLimit] = useState<number | "all">(5);
    const [selectedCar, setSelectedCar] = useState<IAdsPriorityRankedCar | null>(null);

    const limitedCars = useMemo(
        () => (limit === "all" ? cars : cars.slice(0, limit)),
        [cars, limit]
    );

    const carsByState = useMemo(() => {
        return stateSections.reduce<Record<PromotionState, IAdsPriorityRankedCar[]>>((acc, section) => {
            acc[section.state] = limitedCars.filter((car) => getPromotionState(car) === section.state);
            return acc;
        }, {
            ready: [],
            candidate: [],
            watch: [],
            avoid: [],
        });
    }, [limitedCars]);

    const renderCard = (car: IAdsPriorityRankedCar, tone: typeof stateSections[number]["tone"]) => {
        const score = getScore(car);
        const confidence = getConfidence(car);
        const reasons = getReasons(car);
        const state = getPromotionState(car);
        const actionLabel = getActionLabel(car);
        const price = formatCurrency(car.price_gross);

        return (
            <article
                key={car.car_id}
                style={{
                    border: "1px solid #e9ebec",
                    borderRadius: 16,
                    background: "#fff",
                    padding: 16,
                }}
            >
                <div className="d-flex align-items-start justify-content-between gap-3 mb-3">
                    <div>
                        <div className="d-flex align-items-center gap-2 flex-wrap mb-1">
                            <h6 className="mb-0 fw-semibold text-body">{car.car_name}</h6>
                            {price && <span className="text-muted fs-12">{price}</span>}
                        </div>
                        <span
                            className="badge rounded-pill fs-12 px-3 py-2"
                            style={{ background: tone.bg, color: tone.color, border: `1px solid ${tone.border}` }}
                        >
                            {car.promotion_label ?? stateSections.find((section) => section.state === state)?.title}
                        </span>
                    </div>

                    <div className="text-end">
                        <div className="fw-semibold fs-5" style={{ color: tone.color }}>{score}</div>
                        <div className="text-muted fs-11">score</div>
                    </div>
                </div>

                <div className="d-flex align-items-center gap-3 flex-wrap mb-3 text-muted fs-13">
                    <span>Confiança: {confidence}%</span>
                    {car.has_active_campaign && <span>Campanha ativa</span>}
                    {car.flags?.includes("test_campaign_seed") && <span>Seed recomendado</span>}
                </div>

                {reasons.length > 0 && (
                    <ul className="mb-3 ps-3 text-body fs-13">
                        {reasons.map((reason) => (
                            <li key={reason} className="mb-1">{reason}</li>
                        ))}
                    </ul>
                )}

                <div className="d-flex align-items-center gap-2 flex-wrap">
                    <button
                        type="button"
                        className={`btn btn-sm ${state === "avoid" ? "btn-outline-danger" : "btn-primary"}`}
                        onClick={() => setSelectedCar(car)}
                    >
                        {actionLabel}
                    </button>
                    <Link to={`/cars/${car.car_id}/analytics`} className="btn btn-outline-secondary btn-sm">
                        Ver análise
                    </Link>
                    <Link to={`/cars/${car.car_id}`} className="btn btn-link btn-sm text-muted text-decoration-none">
                        Ver detalhes →
                    </Link>
                </div>
            </article>
        );
    };

    const renderSection = (section: typeof stateSections[number]) => {
        const items = carsByState[section.state];

        return (
            <section
                key={section.state}
                style={{
                    border: "1px solid #eef0f2",
                    borderRadius: 18,
                    background: "#fbfcfd",
                    padding: 16,
                }}
            >
                <div className="d-flex align-items-start justify-content-between gap-3 mb-3">
                    <div>
                        <h6 className="mb-1 fw-semibold">{section.title}</h6>
                        <p className="text-muted fs-13 mb-0">{section.description}</p>
                    </div>
                    <span
                        className="badge rounded-pill fs-12 px-3 py-2"
                        style={{ background: section.tone.bg, color: section.tone.color, border: `1px solid ${section.tone.border}` }}
                    >
                        {items.length}
                    </span>
                </div>

                {items.length > 0 ? (
                    <div className="d-grid gap-3">
                        {items.map((car) => renderCard(car, section.tone))}
                    </div>
                ) : (
                    <div
                        className="text-muted fs-14"
                        style={{
                            border: "1px dashed #dfe3e6",
                            borderRadius: 14,
                            padding: 14,
                            background: "#fff",
                        }}
                    >
                        {section.empty}
                    </div>
                )}
            </section>
        );
    };

    return (
        <Col xs={12}>
            <section
                style={{
                    border: "1px solid #e9ebec",
                    borderRadius: 18,
                    background: "#fff",
                    overflow: "hidden",
                }}
            >
                <div
                    className="d-flex align-items-start justify-content-between gap-3 flex-wrap"
                    style={{ padding: "18px 20px", borderBottom: "1px solid #e9ebec" }}
                >
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Ranking para anunciar
                        </p>
                        <h5 className="mb-1 fw-semibold">Onde investir agora</h5>
                        <p className="text-muted fs-13 mb-0">
                            Priorização simples com base em sinal de contacto, procura recente, preço e investimento ativo.
                        </p>
                    </div>

                    <div className="d-flex align-items-center gap-2 flex-wrap">
                        <span className="text-muted fs-12 fw-semibold">Mostrar:</span>
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
                </div>

                <div style={{ padding: 16 }} className="d-grid gap-3">
                    {stateSections.map(renderSection)}
                </div>
            </section>

            <Modal isOpen={!!selectedCar} toggle={() => setSelectedCar(null)} centered>
                <ModalHeader toggle={() => setSelectedCar(null)}>
                    {selectedCar ? getActionLabel(selectedCar) : "Ação recomendada"}
                </ModalHeader>
                <ModalBody>
                    {selectedCar && (
                        <div className="d-flex flex-column gap-3">
                            <div>
                                <p className="text-uppercase text-muted fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                    Carro
                                </p>
                                <div className="fw-semibold">{selectedCar.car_name}</div>
                            </div>

                            <div>
                                <p className="text-uppercase text-muted fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                    Porque faz sentido
                                </p>
                                <ul className="mb-0 ps-3">
                                    {getReasons(selectedCar).map((reason) => (
                                        <li key={reason}>{reason}</li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    )}
                </ModalBody>
                <ModalFooter>
                    {selectedCar && (
                        <Link to={`/cars/${selectedCar.car_id}/marketing`} className="btn btn-primary">
                            Preparar ação
                        </Link>
                    )}
                    <button type="button" className="btn btn-light" onClick={() => setSelectedCar(null)}>
                        Fechar
                    </button>
                </ModalFooter>
            </Modal>
        </Col>
    );
}
