import { Badge, Card, CardBody, CardHeader, Col, Table } from "reactstrap";
import { IMarketingRoiChannel } from "./marketingRoi.types";
import { formatCurrencyWithFallback, formatNumber, formatPercent } from "./marketingRoi.utils";

type MarketingRoiChannelTableProps = {
    channels: IMarketingRoiChannel[];
};

const getStatusBadge = (status: string) => {
    if (status === "A escalar") return <Badge color="success">{status}</Badge>;
    if (status === "Estável") return <Badge color="warning">{status}</Badge>;
    return <Badge color="secondary">{status}</Badge>;
};

export default function MarketingRoiChannelTable({
    channels,
}: MarketingRoiChannelTableProps) {
    return (
        <Col xl={8}>
            <Card className="card-height-100">
                <CardHeader className="align-items-center d-flex">
                    <h4 className="card-title mb-0 flex-grow-1">Canais com retorno</h4>
                </CardHeader>

                <CardBody>
                    {channels.length > 0 ? (
                        <div className="table-responsive table-card">
                            <Table className="table table-hover align-middle table-nowrap mb-0">
                                <thead className="table-light">
                                    <tr>
                                        <th>Canal</th>
                                        <th>Views</th>
                                        <th>Leads</th>
                                        <th>Conversão</th>
                                        <th>Investimento</th>
                                        <th>CPL</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {channels.map((channel) => (
                                        <tr key={channel.channel}>
                                            <td className="fw-semibold">{channel.channel}</td>
                                            <td>{formatNumber(channel.sessions)}</td>
                                            <td>{formatNumber(channel.leads)}</td>
                                            <td>{formatPercent(channel.conversion_rate)}</td>
                                            <td>{formatCurrencyWithFallback(channel.total_spend)}</td>
                                            <td>{formatCurrencyWithFallback(channel.cost_per_lead)}</td>
                                            <td>{getStatusBadge(channel.status)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                    ) : (
                        <p className="text-muted mb-0">
                            Ainda não existem dados suficientes para avaliar os canais dos últimos 7 dias.
                        </p>
                    )}
                </CardBody>
            </Card>
        </Col>
    );
}
