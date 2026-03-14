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
}

type ActionRequiredCarsDashboardProps = {
    cars: IActionRequiredCar[];
};

const getPriorityBadge = (priority: number) => {
    if (priority >= 2) {
        return <Badge color="danger">Alta</Badge>;
    }

    return <Badge color="warning">Média</Badge>;
};

export default function ActionRequiredCarsDashboard({
    cars,
}: ActionRequiredCarsDashboardProps) {
    return (
        <Col xl={12}>
            <Card className="card-height-100">
                <CardHeader className="align-items-center d-flex">
                    <h4 className="card-title mb-0 flex-grow-1">
                        ⚠️ Carros que exigem atenção
                    </h4>
                </CardHeader>

                {
                    cars.length > 0 ? (
                        <CardBody>
                            <div className="table-responsive table-card">
                                <Table className="table table-centered table-hover align-middle table-nowrap mb-0">
                                    <thead className="table-light">
                                        <tr>
                                            <th>Carro</th>
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
                                            <tr key={car.id}>
                                                <td>
                                                    <h6 className="mb-0">{car.car_name}</h6>
                                                </td>
                                                <td>{car.views_count}</td>
                                                <td>{car.leads_count}</td>
                                                <td>{car.days_in_stock ?? "-"}</td>
                                                <td>
                                                    {Number(car.price_gross).toLocaleString("pt-PT", {
                                                        style: "currency",
                                                        currency: "EUR",
                                                        maximumFractionDigits: 0,
                                                    })}
                                                </td>
                                                <td>{car.reason}</td>
                                                <td>{car.suggestion}</td>
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
                                À medida que o stock envelhecer ou surgirem padrões de baixa conversão, a Xplendor vai destacar os veículos que precisam de ação.
                            </p>
                        </div>
                    )
                }
            </Card>
        </Col>
    );
}