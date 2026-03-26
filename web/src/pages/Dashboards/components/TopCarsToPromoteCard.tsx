import { Col } from "reactstrap";
import { IMarketingRoiCar } from "./marketingRoi.types";
import { formatCurrencyWithFallback } from "./marketingRoi.utils";

type TopCarsToPromoteCardProps = {
    cars: IMarketingRoiCar[];
};

export default function TopCarsToPromoteCard({ cars }: TopCarsToPromoteCardProps) {
    return (
        <Col xl={6}>
            <section style={{ border: "1px solid #e9ebec", borderRadius: 16, padding: "16px 18px", background: "#fff" }}>
                <h6 className="mb-3 fw-semibold">Carros para promover</h6>
                {cars.length > 0 ? (
                    <div className="table-responsive">
                        <table className="table table-sm align-middle mb-0">
                            <thead style={{ background: "#f8fafc" }}>
                                <tr>
                                    <th className="ps-0">Carro</th>
                                    <th>IPS</th>
                                    <th>Views</th>
                                    <th>Leads</th>
                                    <th>Spend</th>
                                    <th>CPL</th>
                                </tr>
                            </thead>
                            <tbody>
                                {cars.map((car) => (
                                    <tr key={car.car_id}>
                                        <td className="ps-0 fw-semibold">{car.car_name}</td>
                                        <td>{car.ips_score}</td>
                                        <td>{car.views}</td>
                                        <td>{car.leads}</td>
                                        <td>{formatCurrencyWithFallback(car.spend)}</td>
                                        <td>{formatCurrencyWithFallback(car.cost_per_lead)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <p className="text-muted mb-0">Sem dados suficientes para sugerir carros a escalar neste momento.</p>
                )}
            </section>
        </Col>
    );
}
