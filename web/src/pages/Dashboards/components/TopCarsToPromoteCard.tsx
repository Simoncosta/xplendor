import { Card, CardBody, CardHeader, Col, Table } from "reactstrap";
import { IMarketingRoiCar } from "./marketingRoi.types";
import { formatCurrencyWithFallback } from "./marketingRoi.utils";

type TopCarsToPromoteCardProps = {
    cars: IMarketingRoiCar[];
};

export default function TopCarsToPromoteCard({
    cars,
}: TopCarsToPromoteCardProps) {
    return (
        <Col xl={6}>
            <Card className="card-height-100">
                <CardHeader className="align-items-center d-flex">
                    <h4 className="card-title mb-0 flex-grow-1">Carros para promover</h4>
                </CardHeader>

                <CardBody>
                    {cars.length > 0 ? (
                        <div className="table-responsive table-card">
                            <Table className="table table-hover align-middle mb-0">
                                <thead className="table-light">
                                    <tr>
                                        <th>Carro</th>
                                        <th>IPS</th>
                                        <th>Views</th>
                                        <th>Leads</th>
                                        <th>Spend</th>
                                        <th>CPL</th>
                                        <th>Recomendação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {cars.map((car) => (
                                        <tr key={car.car_id}>
                                            <td className="fw-semibold">{car.car_name}</td>
                                            <td>{car.ips_score}</td>
                                            <td>{car.views}</td>
                                            <td>{car.leads}</td>
                                            <td>{formatCurrencyWithFallback(car.spend)}</td>
                                            <td>{formatCurrencyWithFallback(car.cost_per_lead)}</td>
                                            <td className="text-muted">{car.recommendation}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                    ) : (
                        <p className="text-muted mb-0">
                            Sem dados suficientes para sugerir carros a escalar neste momento.
                        </p>
                    )}
                </CardBody>
            </Card>
        </Col>
    );
}
