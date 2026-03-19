import { Card, CardBody, CardHeader, Col, Table } from "reactstrap";
import { IMarketingRoiCampaign } from "./marketingRoi.types";
import { formatCurrencyWithFallback, formatPercent } from "./marketingRoi.utils";

type MarketingTopCampaignsCardProps = {
    campaigns: IMarketingRoiCampaign[];
};

const normalizePlatform = (platform: string) => {
    if (platform === "meta") return "Meta Ads";
    return platform;
};

export default function MarketingTopCampaignsCard({
    campaigns,
}: MarketingTopCampaignsCardProps) {
    return (
        <Col xl={6}>
            <Card className="card-height-100">
                <CardHeader className="align-items-center d-flex">
                    <h4 className="card-title mb-0 flex-grow-1">Top campaigns</h4>
                </CardHeader>

                <CardBody>
                    {campaigns.length > 0 ? (
                        <div className="table-responsive table-card">
                            <Table className="table table-hover align-middle table-nowrap mb-0">
                                <thead className="table-light">
                                    <tr>
                                        <th>Campanha</th>
                                        <th>Plataforma</th>
                                        <th>Investimento</th>
                                        <th>Leads</th>
                                        <th>Conversão</th>
                                        <th>CPL</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {campaigns.map((campaign) => (
                                        <tr key={`${campaign.platform}-${campaign.campaign_id}`}>
                                            <td className="fw-semibold">{campaign.campaign_name}</td>
                                            <td>{normalizePlatform(campaign.platform)}</td>
                                            <td>{formatCurrencyWithFallback(campaign.spend)}</td>
                                            <td>{campaign.leads}</td>
                                            <td>{formatPercent(campaign.conversion_rate)}</td>
                                            <td>{formatCurrencyWithFallback(campaign.cost_per_lead)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                    ) : (
                        <p className="text-muted mb-0">
                            Ainda não existem campanhas com dados suficientes para ranking.
                        </p>
                    )}
                </CardBody>
            </Card>
        </Col>
    );
}
