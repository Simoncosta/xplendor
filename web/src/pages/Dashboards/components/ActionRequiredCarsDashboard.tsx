import { useNavigate } from "react-router-dom";
import { Col } from "reactstrap";

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
        return {
            label: "Alta",
            tone: { bg: "#fde8e4", color: "#c2410c" },
            border: "#f97316",
        };
    }

    if (score >= 60) {
        return {
            label: "Media",
            tone: { bg: "#fff4d6", color: "#a16207" },
            border: "#f7b84b",
        };
    }

    return {
        label: "Baixa",
        tone: { bg: "#eef2f7", color: "#475569" },
        border: "#cbd5e1",
    };
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
            <section
                style={{
                    border: "1px solid #e9ebec",
                    borderRadius: 16,
                    background: "#fff",
                    overflow: "hidden",
                }}
            >
                <div
                    className="d-flex align-items-start justify-content-between gap-3 flex-wrap"
                    style={{ padding: "16px 18px", borderBottom: "1px solid #e9ebec" }}
                >
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Acao imediata
                        </p>
                        <h5 className="mb-1 fw-semibold">Decisões prioritárias no stock</h5>
                        <p className="text-muted fs-13 mb-0">
                            O sistema mostra primeiro o carro, o problema e o próximo passo mais útil.
                        </p>
                    </div>
                    <span className="badge bg-light text-dark fs-12 px-3 py-2">
                        {cars.length} prioridade{cars.length === 1 ? "" : "s"}
                    </span>
                </div>

                {cars.length > 0 ? (
                    <div style={{ padding: 16 }}>
                        <div className="d-flex flex-column gap-3">
                            {cars.map((car) => {
                                const priority = getPriorityMeta(car.priority_score);

                                return (
                                    <button
                                        key={car.id}
                                        type="button"
                                        onClick={() => navigate(`/cars/${car.id}/analytics`)}
                                        className="text-start"
                                        style={{
                                            width: "100%",
                                            border: "1px solid #e9ebec",
                                            borderLeft: `4px solid ${priority.border}`,
                                            borderRadius: 14,
                                            background: "#fff",
                                            padding: 16,
                                            transition: "all 0.2s ease",
                                        }}
                                    >
                                        <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                                            <div>
                                                <div className="d-flex align-items-center gap-2 flex-wrap mb-1">
                                                    <span
                                                        className="badge rounded-pill"
                                                        style={{
                                                            background: priority.tone.bg,
                                                            color: priority.tone.color,
                                                            fontWeight: 600,
                                                        }}
                                                    >
                                                        Prioridade {priority.label}
                                                    </span>
                                                    <span className="text-muted fs-12">{car.problem}</span>
                                                </div>
                                                <h6 className="mb-1 fw-semibold text-body">{car.title}</h6>
                                                <p className="text-muted fs-13 mb-0">{formatPrice(car.price)}</p>
                                            </div>

                                            <div
                                                className="d-inline-flex align-items-center gap-2"
                                                style={{
                                                    border: "1px solid #e9ebec",
                                                    borderRadius: 12,
                                                    padding: "8px 12px",
                                                    background: "#f8fafc",
                                                }}
                                            >
                                                <span className="text-body fw-semibold">{car.action.label}</span>
                                            </div>
                                        </div>

                                        <div className="row g-3 align-items-stretch">
                                            <div className="col-lg-7">
                                                <p className="text-uppercase text-muted fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                                    Diagnostico
                                                </p>
                                                <p className="mb-2 text-body fs-14">{car.diagnosis}</p>
                                                <p className="mb-0 text-muted fs-13">{car.action.suggestion}</p>
                                            </div>

                                            <div className="col-lg-5">
                                                <div className="d-flex flex-wrap gap-2 justify-content-lg-end">
                                                    {signalItems(car.signals).map((signal) => (
                                                        <div
                                                            key={signal.label}
                                                            style={{
                                                                minWidth: 78,
                                                                border: "1px solid #e9ebec",
                                                                borderRadius: 12,
                                                                padding: "8px 10px",
                                                                background: "#fff",
                                                            }}
                                                        >
                                                            <div className="text-muted fs-11 text-uppercase fw-semibold mb-1" style={{ letterSpacing: "0.06em" }}>
                                                                {signal.label}
                                                            </div>
                                                            <div className="fw-semibold text-body fs-14">{signal.value}</div>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                ) : (
                    <div style={{ padding: "16px 18px" }} className="text-muted">
                        Nenhuma viatura pede acao imediata neste momento.
                    </div>
                )}
            </section>
        </Col>
    );
}
