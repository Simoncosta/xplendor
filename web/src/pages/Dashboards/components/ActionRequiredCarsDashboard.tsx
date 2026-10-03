import { useNavigate } from "react-router-dom";
import { Card, Col } from "reactstrap";

interface IActionRequiredCar {
    id: number;
    title: string;
    price: number | null;
    issue_type: "price_above_market" | "low_demand" | "dead_stock" | "poor_listing";
    problem: string;
    diagnosis: string;
    priority_score: number;
    signals?: {
        days_in_stock?: number | null;
        views?: number | null;
        leads?: number | null;
        interactions?: number | null;
    };
    action: {
        label: string;
        suggestion: string;
    };
}

type ActionRequiredCarsDashboardProps = {
    cars: IActionRequiredCar[];
};

const formatPrice = (value?: number | null) => {
    if (value === null || value === undefined) return "—";

    return Number(value).toLocaleString("pt-PT", {
        style: "currency",
        currency: "EUR",
        maximumFractionDigits: 0,
    });
};

const getPriorityMeta = (score: number) => {
    if (score >= 80) {
        return { border: "var(--vz-danger)", cardBg: "var(--vz-danger-bg-subtle)" };
    }
    if (score >= 60) {
        return { border: "var(--vz-warning)", cardBg: "var(--vz-warning-bg-subtle)" };
    }
    return { border: "var(--vz-border-color)", cardBg: "var(--vz-tertiary-bg)" };
};

const buildFactualDescription = (signals?: IActionRequiredCar["signals"]): string => {
    const days = signals?.days_in_stock;
    const views = signals?.views ?? 0;
    const leads = signals?.leads ?? 0;

    if (days === null || days === undefined) return "Dados insuficientes para análise.";

    const base = `Em stock há ${days} dias.`;
    if (days > 90) return `${base} Stock antigo.`;
    if (days > 60 && leads === 0) return `${base} Mais de 60 dias em stock sem leads.`;
    if (views > 1000 && leads === 0) return `${base} Atrai visitantes mas não gera leads.`;
    return base;
};

const signalItems = (signals?: IActionRequiredCar["signals"]) => [
    { label: "Dias", value: signals?.days_in_stock ?? "—" },
    { label: "Views", value: signals?.views ?? 0 },
    { label: "Leads", value: signals?.leads ?? 0 },
    { label: "Interações", value: signals?.interactions ?? 0 },
];

export default function ActionRequiredCarsDashboard({ cars }: ActionRequiredCarsDashboardProps) {
    const navigate = useNavigate();

    return (
        <Col xs={12}>
            {/* <Card> Velzon (como no Analytics) — antes era uma <section> só com
                borda, que se misturava com o fundo. */}
            <Card className="mb-0 overflow-hidden">
                <div
                    className="d-flex align-items-start justify-content-between gap-3 flex-wrap"
                    style={{ padding: "16px 18px", borderBottom: "1px solid var(--vz-border-color)" }}
                >
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Viaturas a acompanhar
                        </p>
                        <h5 className="mb-1 fw-semibold">Stock que merece atenção esta semana</h5>
                        <p className="text-muted fs-13 mb-0">
                            Lista das viaturas com tempo em stock prolongado ou sinais relevantes.
                        </p>
                    </div>
                    <span className="badge bg-light text-body fs-12 px-3 py-2">
                        {cars.length} prioridade{cars.length === 1 ? "" : "s"}
                    </span>
                </div>

                {cars.length > 0 ? (
                    <div style={{ padding: "12px 16px" }}>
                        <div className="d-flex flex-column gap-2">
                            {cars.map((car) => {
                                const priority = getPriorityMeta(car.priority_score);

                                // Linha COMPACTA: o mesmo conteúdo (título, preço,
                                // diagnóstico, 4 sinais, cor de prioridade) numa só
                                // faixa em vez de um bloco alto com caixas.
                                return (
                                    <button
                                        key={car.id}
                                        type="button"
                                        onClick={() => navigate(`/cars/${car.id}/analytics`)}
                                        className="text-start"
                                        style={{
                                            width: "100%",
                                            border: "1px solid var(--vz-border-color)",
                                            borderLeft: `4px solid ${priority.border}`,
                                            borderRadius: 10,
                                            background: priority.cardBg,
                                            padding: "10px 14px",
                                            transition: "all 0.2s ease",
                                        }}
                                    >
                                        <div className="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                                            <div style={{ minWidth: 0, flex: "1 1 260px" }}>
                                                <div className="d-flex align-items-baseline gap-2" style={{ minWidth: 0 }}>
                                                    <span className="fw-semibold text-body fs-14 text-truncate">{car.title}</span>
                                                    <span className="text-muted fs-12 flex-shrink-0">{formatPrice(car.price)}</span>
                                                </div>
                                                <div className="text-muted fs-12 text-truncate">
                                                    {buildFactualDescription(car.signals)}
                                                </div>
                                            </div>

                                            <div className="d-flex gap-3 flex-shrink-0">
                                                {signalItems(car.signals).map((signal) => (
                                                    <div key={signal.label} className="text-center" style={{ minWidth: 44 }}>
                                                        <div className="fw-semibold text-body fs-13 lh-1">{signal.value}</div>
                                                        <div className="text-muted fs-10 text-uppercase mt-1" style={{ letterSpacing: "0.04em" }}>
                                                            {signal.label}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                ) : (
                    <div style={{ padding: "16px 18px" }} className="text-muted">
                        Nenhuma viatura a acompanhar neste momento.
                    </div>
                )}
            </Card>
        </Col>
    );
}
