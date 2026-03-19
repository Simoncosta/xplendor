// React
import React, { useEffect, useMemo } from 'react';
import { createSelector } from 'reselect';
// Redux
import { useDispatch, useSelector } from 'react-redux';
// Components
import { Container, Row } from 'reactstrap';
import SummaryDashboard from './components/SummaryDashboard';
import { getAnalyticsDashboard } from 'slices/dashboards/thunk';
import HighDemandOpportunityChart from './components/HighDemandOpportunityChart';
import ActionRequiredCarsDashboard from './components/ActionRequiredCarsDashboard';
import MarketingTrafficDonutChart from './components/MarketingTrafficDonutChart';
import DashboardInsightsCard from './components/DashboardInsightsCard';
import MarketingRoiSummaryCards from './components/MarketingRoiSummaryCards';
import MarketingRoiChannelTable from './components/MarketingRoiChannelTable';
import MarketingRoiInsightsCard from './components/MarketingRoiInsightsCard';
import MarketingTopCampaignsCard from './components/MarketingTopCampaignsCard';
import TopCarsToPromoteCard from './components/TopCarsToPromoteCard';
import { IMarketingRoi } from './components/marketingRoi.types';

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
    const highDemandCars = useMemo(
        () => (safeAnalytics.high_demand_opportunity_cars || []).filter((car: any) => car.views_count > 0),
        [safeAnalytics]
    );
    const actionRequiredCars = useMemo(
        () => buildActionRequiredCars({
            urgent_action_cars: safeAnalytics.urgent_action_cars,
            high_interest_low_conversion_cars: safeAnalytics.high_interest_low_conversion_cars,
            highest_stuck_capital_cars: safeAnalytics.highest_stuck_capital_cars,
        }),
        [safeAnalytics]
    );
    const marketingRoi = safeAnalytics.marketing_roi || emptyMarketingRoi;

    if (loading) return null;
    if (!analytics) return null;

    return (
        <React.Fragment>
            <div className="page-content">
                <Container fluid>
                    <Row className="row">
                        <SummaryDashboard summary={analytics.summary} />
                        <HighDemandOpportunityChart
                            data={highDemandCars}
                            dataColors='["--vz-primary", "--vz-success", "--vz-warning"]'
                        />
                    </Row>
                    <Row className='tow'>
                        <ActionRequiredCarsDashboard cars={actionRequiredCars} />
                        <MarketingTrafficDonutChart
                            marketingPerformance={analytics.marketing_performance}
                            dataColors='["--vz-primary", "--vz-success", "--vz-warning"]'
                        />
                        <DashboardInsightsCard insights={analytics.insights || []} />
                    </Row>
                    <Row>
                        <MarketingRoiSummaryCards marketingRoi={marketingRoi} />
                    </Row>
                    <Row>
                        <MarketingRoiChannelTable channels={marketingRoi.by_channel} />
                        <MarketingRoiInsightsCard insights={marketingRoi.insights} />
                    </Row>
                    <Row>
                        <MarketingTopCampaignsCard campaigns={marketingRoi.top_campaigns} />
                        <TopCarsToPromoteCard cars={marketingRoi.top_cars_to_promote} />
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
    high_demand_opportunity_cars?: any[];
    urgent_action_cars?: any[];
    high_interest_low_conversion_cars?: any[];
    highest_stuck_capital_cars?: any[];
    marketing_performance?: any;
    insights?: any[];
    marketing_roi?: IMarketingRoi | null;
};

const emptyAnalytics: TAnalytics = {
    summary: undefined,
    high_demand_opportunity_cars: [],
    urgent_action_cars: [],
    high_interest_low_conversion_cars: [],
    highest_stuck_capital_cars: [],
    marketing_performance: undefined,
    insights: [],
    marketing_roi: emptyMarketingRoi,
};

const buildActionRequiredCars = (analytics: TAnalytics) => {
    const urgentCars = (analytics.urgent_action_cars || []).map((car) => ({
        id: car.id,
        car_name: `${car.brand_name} ${car.model_name} ${car.version || ""}`.trim(),
        views_count: car.views_count || 0,
        leads_count: car.leads_count || 0,
        interactions_count: car.interactions_count || 0,
        days_in_stock: car.days_in_stock ?? null,
        price_gross: Number(car.price_gross || 0),
        reason: car.reason || "Ação urgente",
        suggestion: car.priority >= 2 ? "Rever preço e destacar anúncio" : "Melhorar fotos e rever copy",
        source: "urgent" as const,
        priority: car.priority || 1,
        ips_score: car.ips_score ?? null,           // ← NOVO
        ips_classification: car.ips_classification ?? null, // ← NOVO
    }));

    const lowConversionCars = (analytics.high_interest_low_conversion_cars || []).map((car) => ({
        id: car.id,
        car_name: `${car.brand_name} ${car.model_name} ${car.version || ""}`.trim(),
        views_count: car.views_count || 0,
        leads_count: car.leads_count || 0,
        interactions_count: car.interactions_count || 0,
        days_in_stock: car.days_in_stock ?? null,
        price_gross: Number(car.price_gross || 0),
        reason: "Interesse alto / conversão baixa",
        suggestion: Array.isArray(car.suggestions)
            ? car.suggestions.join(", ")
            : "Melhorar fotos e rever copy",
        source: "low_conversion" as const,
        priority: 1,
        ips_score: car.ips_score ?? null,
        ips_classification: car.ips_classification ?? null,
    }));

    const stuckCapitalCars = (analytics.highest_stuck_capital_cars || []).map((car) => ({
        id: car.id,
        car_name: `${car.brand_name} ${car.model_name} ${car.version || ""}`.trim(),
        views_count: 0,
        leads_count: 0,
        interactions_count: 0,
        days_in_stock: car.days_in_stock ?? null,
        price_gross: Number(car.price_gross || 0),
        reason: `${car.days_in_stock} dias em stock / capital parado`,
        suggestion: "Rever preço e destacar anúncio",
        source: "stuck_capital" as const,
        priority: 2,
        ips_score: car.ips_score ?? null,
        ips_classification: car.ips_classification ?? null,
    }));

    const merged = [...urgentCars, ...lowConversionCars, ...stuckCapitalCars];

    const uniqueByCarId = merged.reduce((acc, current) => {
        const existing = acc.find((item) => item.id === current.id);

        if (!existing) {
            acc.push(current);
            return acc;
        }

        if (current.priority > existing.priority) {
            const index = acc.findIndex((item) => item.id === current.id);
            acc[index] = current;
        }

        return acc;
    }, [] as typeof merged);

    return uniqueByCarId.sort((a, b) => {
        if (b.priority !== a.priority) {
            return b.priority - a.priority;
        }

        return b.views_count - a.views_count;
    });
};

export default Dashboard;
