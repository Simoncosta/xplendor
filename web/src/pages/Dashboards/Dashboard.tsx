// React
import React, { useEffect } from 'react';
import { createSelector } from 'reselect';
// Redux
import { useDispatch, useSelector } from 'react-redux';
// Components
import { Col, Container, Row } from 'reactstrap';
import { Link } from 'react-router-dom';
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
                    <Row className="g-3 mb-3">
                        <SilentBuyerExecutiveCard summary={analytics.silent_buyers} />
                    </Row>
                    <Row className="g-3 mb-3">
                        <Col xs={12}>
                            <Link
                                to="/insights"
                                className="d-block text-decoration-none"
                                style={{
                                    background: '#f8f9fa',
                                    border: '1px solid #e9ebec',
                                    borderRadius: 12,
                                    padding: '16px 20px',
                                }}
                            >
                                <div className="d-flex align-items-center gap-2">
                                    <i className="ri-bar-chart-line text-muted fs-5" />
                                    <div>
                                        <div className="fw-semibold text-dark">Análise completa →</div>
                                        <div className="text-muted fs-12">Vê insights de stock, marketing e ROI detalhados</div>
                                    </div>
                                </div>
                            </Link>
                        </Col>
                    </Row>
                </Container>
            </div>
        </React.Fragment>
    );
};

export default Dashboard;
