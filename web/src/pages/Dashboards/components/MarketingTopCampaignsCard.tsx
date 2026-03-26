import { Col } from "reactstrap";
import { IMarketingRoiCampaign } from "./marketingRoi.types";
import { formatCurrencyWithFallback, formatPercent } from "./marketingRoi.utils";

type MarketingTopCampaignsCardProps = {
    campaigns: IMarketingRoiCampaign[];
};

const normalizePlatform = (platform: string) => {
    if (platform === "meta") return "Meta Ads";
    return platform;
};

export default function MarketingTopCampaignsCard({ campaigns }: MarketingTopCampaignsCardProps) {
    return (
        <Col xl={6}>
            <section style={{ border: "1px solid #e9ebec", borderRadius: 16, padding: "16px 18px", background: "#fff" }}>
                <h6 className="mb-3 fw-semibold">Top campaigns</h6>
                {campaigns.length > 0 ? (
                    <div className="table-responsive">
                        <table className="table table-sm align-middle mb-0">
                            <thead style={{ background: "#f8fafc" }}>
                                <tr>
                                    <th className="ps-0">Campanha</th>
                                    <th>Plataforma</th>
                                    <th>Investimento</th>
                                    <th>Leads</th>
                                    <th>Conversao</th>
                                    <th>CPL</th>
                                </tr>
                            </thead>
                            <tbody>
                                {campaigns.map((campaign) => (
                                    <tr key={`${campaign.platform}-${campaign.campaign_id}`}>
                                        <td className="ps-0 fw-semibold">{campaign.campaign_name}</td>
                                        <td>{normalizePlatform(campaign.platform)}</td>
                                        <td>{formatCurrencyWithFallback(campaign.spend)}</td>
                                        <td>{campaign.leads}</td>
                                        <td>{formatPercent(campaign.conversion_rate)}</td>
                                        <td>{formatCurrencyWithFallback(campaign.cost_per_lead)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <p className="text-muted mb-0">Ainda nao existem campanhas com dados suficientes para ranking.</p>
                )}
            </section>
        </Col>
    );
}
