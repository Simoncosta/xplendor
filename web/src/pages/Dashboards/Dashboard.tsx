// React
import React, { useEffect } from 'react';
import { createSelector } from 'reselect';
// Redux
import { useDispatch, useSelector } from 'react-redux';
// Components
import { Container, Row } from 'reactstrap';
import SummaryDashboard from './components/SummaryDashboard';
import { getAnalyticsDashboard } from 'slices/dashboards/thunk';
import ActionRequiredCarsDashboard from './components/ActionRequiredCarsDashboard';
import DashboardInsightsCard from './components/DashboardInsightsCard';
import SubscriptionTrialBanner from './components/SubscriptionTrialBanner';
import SilentBuyerExecutiveCard from './components/SilentBuyerExecutiveCard';
import StockIntelligenceDashboardCard from './components/StockIntelligenceDashboardCard';
import MarketingWorkspaceTabs from './components/MarketingWorkspaceTabs';
import { IAdsPriorityRankedCar, IMarketingRoi } from './components/marketingRoi.types';

const selectDashboardState = (state: any) => state.Dashboard;
const selectDashboardViewModel = createSelector(
    [selectDashboardState],
    (dashboardState) => ({
        analytics: dashboardState.data.analytics,
        loading: dashboardState.loading.list,
    })
);

const Dashboard = () => {
    const dispatch: any = useDispatch();
    document.title = "Dashboard | Xplendor";

    const { analytics, loading } = useSelector(selectDashboardViewModel);
    const safeAnalytics = analytics ?? emptyAnalytics;

    // Effects
    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return;

        const obj = JSON.parse(authUser);
        if (!obj?.company_id) return;

        dispatch(getAnalyticsDashboard({ companyId: obj.company_id }));
    }, [dispatch]);
    const marketingRoi = safeAnalytics.marketing_roi || emptyMarketingRoi;

    if (loading) return null;
    if (!analytics) return null;

    return (
        <React.Fragment>
            <div className="page-content">
                <Container fluid>
                    <Row className="g-3 mb-3">
                        <SummaryDashboard summary={analytics.summary} />
                    </Row>
                    <Row className="g-3 mb-3">
                        <SubscriptionTrialBanner />
                    </Row>
                    <Row className="g-3 mb-3">
                        <ActionRequiredCarsDashboard cars={analytics.immediate_actions || []} />
                    </Row>
                    <Row className="g-3 mb-3">
                        <SilentBuyerExecutiveCard summary={analytics.silent_buyers} />
                        <DashboardInsightsCard insights={analytics.insights || []} />
                    </Row>
                    <Row className="g-3 mb-3">
                        <StockIntelligenceDashboardCard
                            opportunities={analytics.stock_intelligence?.opportunities || []}
                            saturatedSegments={analytics.stock_intelligence?.saturated_segments || []}
                        />
                    </Row>
                    <Row className="g-3 mb-3">
                        <MarketingWorkspaceTabs
                            marketingPerformance={analytics.marketing_performance}
                            marketingRoi={marketingRoi}
                            rankingCars={analytics.ads_priority_ranking?.cars_ranked_for_ads || []}
                        />
                    </Row>
                </Container>
            </div>
        </React.Fragment>
    );
};

const emptyMarketingRoi: IMarketingRoi = {
    summary: {
        total_spend: 0,
        total_leads: 0,
        overall_conversion_rate: 0,
        avg_cost_per_lead: 0,
        best_channel: "",
        best_campaign: "",
    },
    by_channel: [],
    top_campaigns: [],
    top_cars_to_promote: [],
    insights: [],
};

type TAnalytics = {
    summary?: any;
    immediate_actions?: any[];
    high_demand_opportunity_cars?: any[];
    urgent_action_cars?: any[];
    high_interest_low_conversion_cars?: any[];
    highest_stuck_capital_cars?: any[];
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
};

const emptyAnalytics: TAnalytics = {
    summary: undefined,
    immediate_actions: [],
    high_demand_opportunity_cars: [],
    urgent_action_cars: [],
    high_interest_low_conversion_cars: [],
    highest_stuck_capital_cars: [],
    marketing_performance: undefined,
    insights: [],
    marketing_roi: emptyMarketingRoi,
    ads_priority_ranking: null,
    silent_buyers: null,
    stock_intelligence: null,
};

export default Dashboard;
