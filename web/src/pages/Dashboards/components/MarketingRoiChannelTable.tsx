import { Badge, Col } from "reactstrap";
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

export default function MarketingRoiChannelTable({ channels }: MarketingRoiChannelTableProps) {
    return (
        <Col xl={8}>
            <section style={{ border: "1px solid #e9ebec", borderRadius: 16, padding: "16px 18px", background: "#fff" }}>
                <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Marketing
                        </p>
                        <h6 className="mb-0 fw-semibold">Canais com retorno</h6>
                    </div>
                </div>

                {channels.length > 0 ? (
                    <div className="table-responsive">
                        <table className="table table-sm align-middle mb-0">
                            <thead style={{ background: "#f8fafc" }}>
                                <tr>
                                    <th className="ps-0">Canal</th>
                                    <th>Views</th>
                                    <th>Leads</th>
                                    <th>Conversao</th>
                                    <th>Investimento</th>
                                    <th>CPL</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                {channels.map((channel) => (
                                    <tr key={channel.channel}>
                                        <td className="ps-0 fw-semibold">{channel.channel}</td>
                                        <td>{formatNumber(channel.sessions)}</td>
                                        <td>{formatNumber(channel.leads)}</td>
                                        <td>{formatPercent(channel.conversion_rate)}</td>
                                        <td>{formatCurrencyWithFallback(channel.total_spend)}</td>
                                        <td>{formatCurrencyWithFallback(channel.cost_per_lead)}</td>
                                        <td>{getStatusBadge(channel.status)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <p className="text-muted mb-0">Ainda nao existem dados suficientes para avaliar os canais dos ultimos 7 dias.</p>
                )}
            </section>
        </Col>
    );
}
