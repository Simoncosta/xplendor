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
import SubscriptionTrialBanner from './components/SubscriptionTrialBanner';
import SilentBuyerExecutiveCard from './components/SilentBuyerExecutiveCard';

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

    // Effects
    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return;

        const obj = JSON.parse(authUser);
        if (!obj?.company_id) return;

        dispatch(getAnalyticsDashboard({ companyId: obj.company_id }));
    }, [dispatch]);

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
                    {(analytics.silent_buyers?.total_detected ?? 0) > 0 && (
                        <Row className="g-3 mb-3">
                            <SilentBuyerExecutiveCard summary={analytics.silent_buyers} />
                        </Row>
                    )}
                </Container>
            </div>
        </React.Fragment>
    );
};

export default Dashboard;
