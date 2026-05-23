import { useEffect } from "react";
import { useDispatch, useSelector } from "react-redux";
import { useParams } from "react-router-dom";
import { Col, Container, Row } from "reactstrap";
import { createSelector } from "reselect";

import { analyticsCar } from "slices/cars/thunk";

import CarAnalyticsHeader from "./components/CarAnalyticsHeader";
import CarPageNav from "./components/CarPageNav";
import MarketPositionCard from "./components/intelligence/MarketPositionCard";

import { fmtDate, ipsClassBadge } from "./helpers/CarAnalyticsData";

const selectCarState = (state: any) => state.Car;
const selectViewModel = createSelector(
    [selectCarState],
    (carState) => ({
        carAnalytics: carState.data.carAnalytics,
        loading: carState.loading.analytics,
    })
);

export default function CarIntelligencePage() {
    document.title = "Inteligência | Xplendor";

    const dispatch: any = useDispatch();
    const { id } = useParams();
    const { carAnalytics, loading } = useSelector(selectViewModel);

    const authUser = sessionStorage.getItem("authUser");
    const companyId = authUser ? Number(JSON.parse(authUser).company_id ?? 0) : 0;
    const userRole = authUser ? (JSON.parse(authUser).role ?? "") : "";

    useEffect(() => {
        const existingId = carAnalytics?.car?.id;
        if (existingId && existingId === Number(id)) return;
        if (!authUser) return;
        dispatch(analyticsCar({ companyId, id: Number(id) }));
    }, [dispatch, id, carAnalytics?.car?.id]);

    const car = carAnalytics?.car;
    const ai = car?.analyses?.analysis;
    const aiMeta = car?.analyses;
    const ips = carAnalytics?.potential_score;

    if (loading || !carAnalytics) return null;

    return (
        <div className="page-content mb-3">
            <Container fluid>
                <Row className="mb-2">
                    <Col>
                        <CarAnalyticsHeader
                            car={car}
                            ips={ips}
                            ai={ai}
                            aiMeta={aiMeta}
                            fmtDate={fmtDate}
                            ipsClassBadge={ipsClassBadge}
                        />
                    </Col>
                </Row>

                <Row className="mb-3">
                    <Col>
                        <CarPageNav active="intelligence" />
                    </Col>
                </Row>

                <Row>
                    <Col>
                        {companyId > 0 && id && (
                            <MarketPositionCard
                                companyId={companyId}
                                carId={Number(id)}
                                userRole={userRole}
                            />
                        )}
                    </Col>
                </Row>
            </Container>
        </div>
    );
}
