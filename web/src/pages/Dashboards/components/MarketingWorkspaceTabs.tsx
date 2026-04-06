import { useState } from "react";
import classNames from "classnames";
import { Col, Nav, NavItem, Row } from "reactstrap";
import MarketingTrafficDonutChart from "./MarketingTrafficDonutChart";
import MarketingRoiSummaryCards from "./MarketingRoiSummaryCards";
import MarketingRoiChannelTable from "./MarketingRoiChannelTable";
import MarketingRoiInsightsCard from "./MarketingRoiInsightsCard";
import MarketingTopCampaignsCard from "./MarketingTopCampaignsCard";
import TopCarsToPromoteCard from "./TopCarsToPromoteCard";
import AdsPriorityRankingCard from "./AdsPriorityRankingCard";
import PersonaGroupCard, { PersonaGroup } from "./PersonaGroupCard";
import { IAdsPriorityRankedCar, IMarketingRoi } from "./marketingRoi.types";

type Props = {
    marketingPerformance: any;
    marketingRoi: IMarketingRoi;
    rankingCars: IAdsPriorityRankedCar[];
    personas: PersonaGroup[];
};

type TabKey = "overview" | "ranking" | "personas";

const tabs: { key: TabKey; label: string }[] = [
    { key: "overview", label: "O que fazer hoje" },
    { key: "ranking", label: "Onde investir agora" },
    { key: "personas", label: "Por persona" },
];

export default function MarketingWorkspaceTabs({ marketingPerformance, marketingRoi, rankingCars, personas }: Props) {
    const [activeTab, setActiveTab] = useState<TabKey>("overview");

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
                            Marketing
                        </p>
                        <h5 className="mb-1 fw-semibold">Execucao e prioridade comercial</h5>
                        <p className="text-muted fs-13 mb-0">
                            Alterna entre a leitura global de marketing e a fila de carros com melhor potencial para investimento.
                        </p>
                    </div>
                </div>

                <div style={{ padding: "14px 16px 0 16px" }}>
                    <Nav pills className="gap-2">
                        {tabs.map((tab) => (
                            <NavItem key={tab.key}>
                                <button
                                    type="button"
                                    onClick={() => setActiveTab(tab.key)}
                                    className={classNames("btn btn-sm", {
                                        "btn-primary": activeTab === tab.key,
                                        "btn-light text-body": activeTab !== tab.key,
                                    })}
                                >
                                    {tab.label}
                                </button>
                            </NavItem>
                        ))}
                    </Nav>
                </div>

                <div style={{ padding: 16 }}>
                    {activeTab === "overview" ? (
                        <Row className="g-3">
                            <MarketingTrafficDonutChart
                                marketingPerformance={marketingPerformance}
                                dataColors='["--vz-primary", "--vz-success", "--vz-warning"]'
                            />
                            <MarketingRoiSummaryCards marketingRoi={marketingRoi} />
                            <MarketingRoiChannelTable channels={marketingRoi.by_channel} />
                            <MarketingRoiInsightsCard insights={marketingRoi.insights} />
                            <MarketingTopCampaignsCard campaigns={marketingRoi.top_campaigns} />
                            <TopCarsToPromoteCard cars={marketingRoi.top_cars_to_promote} />
                        </Row>
                    ) : activeTab === "ranking" ? (
                        <Row className="g-3">
                            <AdsPriorityRankingCard cars={rankingCars} />
                        </Row>
                    ) : (
                        <div className="d-grid gap-3">
                            {personas.length === 0 ? (
                                <div className="text-center py-5 text-muted">
                                    <i className="ri-group-line fs-1 d-block mb-2" />
                                    <p className="fs-13 mb-0">Sem dados de persona disponíveis</p>
                                </div>
                            ) : (
                                personas.map((group) => (
                                    <PersonaGroupCard key={group.persona} group={group} />
                                ))
                            )}
                        </div>
                    )}
                </div>
            </section>
        </Col>
    );
}
