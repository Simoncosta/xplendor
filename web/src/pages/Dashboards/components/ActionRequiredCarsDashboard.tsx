import { useNavigate } from "react-router-dom";
import { Card, CardBody, CardHeader, Col, Table, Badge } from "reactstrap";

interface IActionRequiredCar {
    id: number;
    car_name: string;
    views_count: number;
    leads_count: number;
    days_in_stock: number | null;
    price_gross: number;
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

const getPriorityBadge = (priority: number) => {

    if (priority >= 3) return <Badge color="danger">Alta</Badge>

    if (priority === 2) return <Badge color="warning">Média</Badge>

    return <Badge color="secondary">Baixa</Badge>
};

const getIpsBadge = (score?: number | null, classification?: string | null) => {
    if (score === null || score === undefined) {
        return <span className="text-muted fs-12">—</span>;
    }

    const color =
        classification === "hot" ? "success" :
            classification === "warm" ? "warning" : "danger";

    const label =
        classification === "hot" ? "🔥" :
            classification === "warm" ? "●" : "⚠️";

    return (
        <span className={`fw-semibold text-${color} fs-13`}>
            {label} {score}
            <span className="text-muted fw-normal fs-11">/100</span>
        </span>
    );
};

const getReasonIcon = (reason: string) => {

    if (!reason) return "⚠️"

    const r = reason.toLowerCase()

    if (r.includes("conversão")) return "🔥"
    if (r.includes("interesse")) return "👀"
    if (r.includes("visibilidade")) return "📉"
    if (r.includes("inventário")) return "⏳"
    if (r.includes("capital")) return "💰"

    return "⚠️"
};

const formatSuggestion = (suggestion: string) => {

    if (!suggestion) return null

    return (
        <span className="text-muted fs-12">
            {suggestion}
        </span>
    )
}

const getRowClass = (priority: number) => {

    if (priority >= 3) return "table-danger"
    if (priority === 2) return "table-warning"

    return ""
}

export default function ActionRequiredCarsDashboard({
    cars,
}: ActionRequiredCarsDashboardProps) {
    const navigate = useNavigate();

    return (
        <Col xl={12}>
            <Card className="card-height-100">
                <CardHeader className="align-items-center d-flex">
                    <h4 className="card-title mb-0 flex-grow-1">
                        🚨 Problemas no stock
                    </h4>
                </CardHeader>

                {cars.length > 0 ? (
                    <CardBody>
                        <div className="table-responsive table-card">
                            <Table className="table table-centered table-hover align-middle table-nowrap mb-0">
                                <thead className="table-light">
                                    <tr>
                                        <th>Carro</th>
                                        <th>IPS</th>
                                        <th>Views</th>
                                        <th>Leads</th>
                                        <th>Dias em stock</th>
                                        <th>Preço</th>
                                        <th>Motivo</th>
                                        <th>Sugestão</th>
                                        <th>Prioridade</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {cars.map((car) => (
                                        <tr
                                            className={getRowClass(car.priority)}
                                            key={car.id}
                                            style={{ cursor: "pointer" }}
                                            onClick={() => navigate(`/cars/${car.id}/analytics`)}
                                        >
                                            <td>
                                                <h6 className="mb-0 text-primary fw-semibold">
                                                    {car.car_name}
                                                </h6>
                                            </td>
                                            <td>
                                                {getIpsBadge(car.ips_score, car.ips_classification)}
                                            </td>
                                            <td>{car.views_count}</td>
                                            <td>{car.leads_count}</td>
                                            <td>{car.days_in_stock ?? "—"}</td>
                                            <td>
                                                {Number(car.price_gross).toLocaleString("pt-PT", {
                                                    style: "currency",
                                                    currency: "EUR",
                                                    maximumFractionDigits: 0,
                                                })}
                                            </td>
                                            <td>
                                                <span className="fw-medium">
                                                    {getReasonIcon(car.reason)} {car.reason}
                                                </span>
                                            </td>
                                            <td>
                                                {formatSuggestion(car.suggestion)}
                                            </td>
                                            <td>{getPriorityBadge(car.priority)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                    </CardBody>
                ) : (
                    <div className="p-3">
                        <p className="fs-16 lh-base">
                            Nenhum carro exige atenção imediata neste momento.
                            À medida que o stock envelhecer ou surgirem padrões de baixa conversão,
                            a Xplendor vai destacar os veículos que precisam de ação.
                        </p>
                    </div>
                )}
            </Card>
        </Col>
    );
}