import React, { useEffect } from 'react';
import { createSelector } from 'reselect';
import { useDispatch, useSelector } from 'react-redux';
import { Container, Row } from 'reactstrap';
import { getAnalyticsDashboard } from 'slices/dashboards/thunk';
import DashboardInsightsCard from 'pages/Dashboards/components/DashboardInsightsCard';
import StockIntelligenceDashboardCard from 'pages/Dashboards/components/StockIntelligenceDashboardCard';
import MarketingWorkspaceTabs from 'pages/Dashboards/components/MarketingWorkspaceTabs';
import { IMarketingRoi, IAdsPriorityRankedCar } from 'pages/Dashboards/components/marketingRoi.types';
import { PersonaGroup } from 'pages/Dashboards/components/PersonaGroupCard';
import {
    hasMeaningfulInsights,
    hasStockIntelligence,
    visibleMarketingTabs,
    type AnalyticsData,
} from './visibilityHelpers';

const selectDashboardState = (state: any) => state.Dashboard;
const selectInsightsViewModel = createSelector(
    [selectDashboardState],
    (dashboardState) => ({
        analytics: dashboardState.data.analytics,
        loading: dashboardState.loading.list,
    })
);

const emptyMarketingRoi: IMarketingRoi = {
    summary: {
        total_spend: 0,
        total_leads: 0,
        overall_conversion_rate: 0,
        avg_cost_per_lead: 0,
        best_channel: '',
        best_campaign: '',
    },
    by_channel: [],
    top_campaigns: [],
    top_cars_to_promote: [],
    insights: [],
};

function EmptyInsightsState() {
    return (
        <div className="text-center py-5">
            <i className="ri-bar-chart-line fs-1 text-muted opacity-50 d-block mb-3" />
            <h4 className="mt-2">Sem dados de análise ainda</h4>
            <p className="text-muted">
                Os insights vão aparecer aqui à medida que o stock cresce e as
                campanhas começam a gerar resultados.
            </p>
        </div>
    );
}

const scrollTo = (id: string) => (e: React.MouseEvent) => {
    e.preventDefault();
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });
};

type FullAnalytics = {
    insights?: any[];
    stock_intelligence?: { opportunities?: any[]; saturated_segments?: any[] } | null;
    marketing_performance?: any;
    marketing_roi?: IMarketingRoi | null;
    ads_priority_ranking?: { cars_ranked_for_ads?: IAdsPriorityRankedCar[] } | null;
    personas?: PersonaGroup[];
};

const InsightsPage = () => {
    const dispatch: any = useDispatch();
    document.title = "Insights | Xplendor";

    const { analytics, loading } = useSelector(selectInsightsViewModel);

    useEffect(() => {
        if (analytics) return;
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return;
        const obj = JSON.parse(authUser);
        if (!obj?.company_id) return;
        dispatch(getAnalyticsDashboard({ companyId: obj.company_id }));
    }, [dispatch, analytics]);

    if (loading) return null;
    if (!analytics) return null;

    const a = analytics as AnalyticsData;
    const showInsights = hasMeaningfulInsights(a);
    const showStock = hasStockIntelligence(a);
    const mktTabs = visibleMarketingTabs(a);
    const showMarketing = mktTabs.length > 0;

    if (!showInsights && !showStock && !showMarketing) {
        return (
            <div className="page-content">
                <Container fluid>
                    <EmptyInsightsState />
                </Container>
            </div>
        );
    }

    const anchors = [
        showInsights ? { id: 'insights', label: 'Insights Automáticos' } : null,
        showStock ? { id: 'stock', label: 'Stock Intelligence' } : null,
        showMarketing ? { id: 'marketing', label: 'Marketing' } : null,
    ].filter((x): x is { id: string; label: string } => x !== null);

    const full = analytics as FullAnalytics;
    const marketingRoi = full.marketing_roi ?? emptyMarketingRoi;

    return (
        <React.Fragment>
            <div className="page-content">
                <Container fluid>
                    <div className="mb-4">
                        <h4 className="mb-1">Insights</h4>
                        <p className="text-muted mb-3">Análise completa de stock e marketing</p>
                        <div className="d-flex gap-2 flex-wrap">
                            {anchors.map(({ id, label }) => (
                                <a
                                    key={id}
                                    href={`#${id}`}
                                    onClick={scrollTo(id)}
                                    className="btn btn-sm btn-light"
                                >
                                    {label}
                                </a>
                            ))}
                        </div>
                    </div>

                    {showInsights && (
                        <div id="insights">
                            <Row className="g-3 mb-3">
                                <DashboardInsightsCard insights={full.insights || []} />
                            </Row>
                        </div>
                    )}

                    {showStock && (
                        <div id="stock">
                            <Row className="g-3 mb-3">
                                <StockIntelligenceDashboardCard
                                    opportunities={full.stock_intelligence?.opportunities || []}
                                    saturatedSegments={full.stock_intelligence?.saturated_segments || []}
                                />
                            </Row>
                        </div>
                    )}

                    {showMarketing && (
                        <div id="marketing">
                            <Row className="g-3 mb-3">
                                <MarketingWorkspaceTabs
                                    visibleTabs={mktTabs}
                                    marketingPerformance={full.marketing_performance}
                                    marketingRoi={marketingRoi}
                                    rankingCars={full.ads_priority_ranking?.cars_ranked_for_ads || []}
                                    personas={full.personas || []}
                                />
                            </Row>
                        </div>
                    )}
                </Container>
            </div>
        </React.Fragment>
    );
};

export default InsightsPage;
