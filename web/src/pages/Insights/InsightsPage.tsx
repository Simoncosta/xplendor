import React, { useEffect } from 'react';
import { createSelector } from 'reselect';
import { useDispatch, useSelector } from 'react-redux';
import { Container, Row } from 'reactstrap';
import { getAnalyticsDashboard } from 'slices/dashboards/thunk';
import DashboardInsightsCard from 'pages/Dashboards/components/DashboardInsightsCard';
import StockIntelligenceDashboardCard from 'pages/Dashboards/components/StockIntelligenceDashboardCard';
import MarketingWorkspaceTabs from 'pages/Dashboards/components/MarketingWorkspaceTabs';
import { IMarketingRoi } from 'pages/Dashboards/components/marketingRoi.types';
import { PersonaGroup } from 'pages/Dashboards/components/PersonaGroupCard';
import { IAdsPriorityRankedCar } from 'pages/Dashboards/components/marketingRoi.types';

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

type TAnalytics = {
    summary?: any;
    immediate_actions?: any[];
    marketing_performance?: any;
    insights?: any[];
    marketing_roi?: IMarketingRoi | null;
    ads_priority_ranking?: {
        cars_ranked_for_ads?: IAdsPriorityRankedCar[];
    } | null;
    silent_buyers?: any;
    stock_intelligence?: {
        opportunities?: any[];
        saturated_segments?: any[];
    } | null;
    personas?: PersonaGroup[];
};

const scrollTo = (id: string) => (e: React.MouseEvent) => {
    e.preventDefault();
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });
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

    const typedAnalytics = analytics as TAnalytics | null;
    const marketingRoi = typedAnalytics?.marketing_roi ?? emptyMarketingRoi;

    if (loading) return null;
    if (!analytics) return null;

    return (
        <React.Fragment>
            <div className="page-content">
                <Container fluid>
                    <div className="mb-4">
                        <h4 className="mb-1">Insights</h4>
                        <p className="text-muted mb-3">Análise completa de stock e marketing</p>
                        <div className="d-flex gap-2 flex-wrap">
                            <a href="#insights" onClick={scrollTo('insights')} className="btn btn-sm btn-light">
                                Insights Automáticos
                            </a>
                            <a href="#stock" onClick={scrollTo('stock')} className="btn btn-sm btn-light">
                                Stock Intelligence
                            </a>
                            <a href="#marketing" onClick={scrollTo('marketing')} className="btn btn-sm btn-light">
                                Marketing
                            </a>
                        </div>
                    </div>

                    <div id="insights">
                        <Row className="g-3 mb-3">
                            <DashboardInsightsCard insights={typedAnalytics?.insights || []} />
                        </Row>
                    </div>

                    <div id="stock">
                        <Row className="g-3 mb-3">
                            <StockIntelligenceDashboardCard
                                opportunities={typedAnalytics?.stock_intelligence?.opportunities || []}
                                saturatedSegments={typedAnalytics?.stock_intelligence?.saturated_segments || []}
                            />
                        </Row>
                    </div>

                    <div id="marketing">
                        <Row className="g-3 mb-3">
                            <MarketingWorkspaceTabs
                                marketingPerformance={typedAnalytics?.marketing_performance}
                                marketingRoi={marketingRoi}
                                rankingCars={typedAnalytics?.ads_priority_ranking?.cars_ranked_for_ads || []}
                                personas={typedAnalytics?.personas || []}
                            />
                        </Row>
                    </div>
                </Container>
            </div>
        </React.Fragment>
    );
};

export default InsightsPage;
