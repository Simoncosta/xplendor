import { useNavigate } from "react-router-dom";
import { Col } from "reactstrap";

interface IActionRequiredCar {
    id: number;
    car_name: string;
    views_count: number;
    leads_count: number;
    interactions_count: number;
    days_in_stock: number | null;
    price_gross: number;
    promo_price_gross?: number | null;
    promo_discount_pct?: number | null;
    has_promo_price?: boolean;
    reason: string;
    suggestion: string;
    source: "urgent" | "low_conversion" | "stuck_capital";
    priority: number;
    ips_score?: number | null;
    ips_classification?: string | null;
}

type ActionRequiredCarsDashboardProps = {
    cars: IActionRequiredCar[];
};

const formatPrice = (value: number) =>
    Number(value || 0).toLocaleString("pt-PT", {
        style: "currency",
        currency: "EUR",
        maximumFractionDigits: 0,
    });

const getPriorityLabel = (priority: number) => {
    if (priority >= 3) return { label: "Alta", bg: "#fde8e4", color: "#d63939" };
    if (priority === 2) return { label: "Media", bg: "#fff3cd", color: "#8a6d1d" };
    return { label: "Baixa", bg: "#eef2f7", color: "#64748b" };
};

const getRowTint = (priority: number) => {
    if (priority >= 3) return "#fff5f3";
    if (priority === 2) return "#fffaf0";
    return "#ffffff";
};

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
                <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap" style={{ padding: "16px 18px", borderBottom: "1px solid #e9ebec" }}>
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Acao imediata
                        </p>
                        <h5 className="mb-1 fw-semibold">Problemas no stock</h5>
                        <p className="text-muted fs-13 mb-0">Os carros que pedem decisao agora aparecem primeiro. Clica numa linha para abrir a analise.</p>
                    </div>
                    <span className="badge bg-light text-dark fs-12 px-3 py-2">
                        {cars.length} viatura{cars.length === 1 ? "" : "s"}
                    </span>
                </div>

                {cars.length > 0 ? (
                    <div className="table-responsive">
                        <table className="table align-middle mb-0">
                            <thead style={{ background: "#f8fafc" }}>
                                <tr>
                                    <th className="ps-3">Carro</th>
                                    <th>Prioridade</th>
                                    <th>Views</th>
                                    <th>Leads</th>
                                    <th>Interacoes</th>
                                    <th>Dias</th>
                                    <th>Preco</th>
                                    <th>O que fazer</th>
                                </tr>
                            </thead>
                            <tbody>
                                {cars.map((car) => {
                                    const priority = getPriorityLabel(car.priority);
                                    return (
                                        <tr
                                            key={car.id}
                                            onClick={() => navigate(`/cars/${car.id}/analytics`)}
                                            style={{
                                                cursor: "pointer",
                                                background: getRowTint(car.priority),
                                                boxShadow: car.priority >= 3 ? "inset 4px 0 0 #d63939" : car.priority === 2 ? "inset 4px 0 0 #f7b84b" : "inset 4px 0 0 #cbd5e1",
                                            }}
                                        >
                                            <td className="ps-3 py-3">
                                                <div>
                                                    <div className="fw-semibold text-body">{car.car_name}</div>
                                                    <div className="text-muted fs-12">{car.reason}</div>
                                                </div>
                                            </td>
                                            <td>
                                                <span className="badge rounded-pill" style={{ background: priority.bg, color: priority.color }}>
                                                    {priority.label}
                                                </span>
                                            </td>
                                            <td>{Number(car.views_count || 0)}</td>
                                            <td>{Number(car.leads_count || 0)}</td>
                                            <td>{Number(car.interactions_count || 0)}</td>
                                            <td>{car.days_in_stock ?? "—"}</td>
                                            <td className="fw-semibold">
                                                {formatPrice(car.promo_price_gross && car.promo_price_gross > 0 ? car.promo_price_gross : car.price_gross)}
                                            </td>
                                            <td className="text-muted">{car.suggestion}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div style={{ padding: "16px 18px" }} className="text-muted">
                        Nenhuma viatura exige atencao imediata neste momento.
                    </div>
                )}
            </section>
        </Col>
    );
}
