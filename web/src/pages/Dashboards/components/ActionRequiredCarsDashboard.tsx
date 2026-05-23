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
        return { border: "#ef4444", cardBg: "#fff6f5" };
    }
    if (score >= 60) {
        return { border: "#f7b84b", cardBg: "#fffdf5" };
    }
    return { border: "#cbd5e1", cardBg: "#f8fafc" };
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
                            Viaturas a acompanhar
                        </p>
                        <h5 className="mb-1 fw-semibold">Stock que merece atenção esta semana</h5>
                        <p className="text-muted fs-13 mb-0">
                            Lista das viaturas com tempo em stock prolongado ou sinais relevantes.
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
                                            background: priority.cardBg,
                                            padding: 16,
                                            transition: "all 0.2s ease",
                                        }}
                                    >
                                        <div className="mb-3">
                                            <h6 className="mb-1 fw-semibold text-body">{car.title}</h6>
                                            <p className="text-muted fs-13 mb-0">{formatPrice(car.price)}</p>
                                        </div>

                                        <div className="row g-3 align-items-stretch">
                                            <div className="col-lg-7">
                                                <p className="text-uppercase text-muted fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                                    Diagnóstico
                                                </p>
                                                <p className="mb-0 text-body fs-14">{buildFactualDescription(car.signals)}</p>
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
                        Nenhuma viatura a acompanhar neste momento.
                    </div>
                )}
            </section>
        </Col>
    );
}
