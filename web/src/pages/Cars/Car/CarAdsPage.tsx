import { useEffect, useMemo, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { useParams } from "react-router-dom";
import { Button, Col, Collapse, Container, Row } from "reactstrap";
import { createSelector } from "reselect";
import { ToastContainer } from "react-toastify";
import CarAdCampaignMapper from "pages/Companies/CompanyProfile/components/CarAdCampaignMapper";

import { analyticsCar } from "slices/cars/thunk";
import { getCarAudienceAnalysisApi } from "helpers/laravel_helper";

import CarAnalyticsHeader from "./components/CarAnalyticsHeader";
import CarPageNav from "./components/CarPageNav";
import SmartAdsRecommendationCard from "./components/SmartAdsRecommendationCard";
import AudienceSuggestionCard from "./components/AudienceSuggestionCard";

import {
    fmt,
    fmtDate,
    channelLabels, channelColors,
    ipsClassBadge,
} from "./helpers/CarAnalyticsData";

const sectionStyle = {
    padding: "16px 18px",
    border: "1px solid #e9ebec",
    borderRadius: "16px",
    background: "#fff",
};

const selectCarState = (state: any) => state.Car;
const selectViewModel = createSelector(
    [selectCarState],
    (carState) => ({
        carAnalytics: carState.data.carAnalytics,
        loading: carState.loading.analytics,
    })
);

export default function CarAdsPage() {
    document.title = "Ads | Xplendor";

    const dispatch: any = useDispatch();
    const { id } = useParams();

    const { carAnalytics, loading } = useSelector(selectViewModel);
    const [audienceAnalysis, setAudienceAnalysis] = useState<any | null>(null);
    const [loadingAudience, setLoadingAudience] = useState(false);
    const [showAudienceAnalysis, setShowAudienceAnalysis] = useState(false);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return;
        const { company_id } = JSON.parse(authUser);
        dispatch(analyticsCar({ companyId: company_id, id: Number(id) }));
    }, [dispatch, id]);

    const car = carAnalytics?.car;
    const ai = car?.analyses?.analysis;
    const aiMeta = car?.analyses;
    const ips = carAnalytics?.potential_score;
    const perf = carAnalytics?.performance;
    const recommendation = carAnalytics?.smart_ads_recommendation ?? null;
    const recommendedCreative = carAnalytics?.recommended_creative ?? null;
    const recommendedPlatform = carAnalytics?.ai_analysis?.recommended_channel ?? null;
    const m = carAnalytics?.metrics;

    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return 0;
        return Number(JSON.parse(authUser).company_id);
    }, []);

    useEffect(() => {
        if (!companyId || !id) {
            return;
        }

        setLoadingAudience(true);

        getCarAudienceAnalysisApi(companyId, Number(id))
            .then((response: any) => {
                setAudienceAnalysis(response?.data ?? null);
            })
            .catch(() => {
                setAudienceAnalysis(null);
            })
            .finally(() => {
                setLoadingAudience(false);
            });
    }, [companyId, id]);

    const perfChannels = useMemo(
        () => (perf?.by_channel || []).map((ch: any) => ({
            ...ch,
            label: channelLabels[ch.channel] || ch.channel,
            color: channelColors[ch.channel] || "#adb5bd",
        })),
        [perf?.by_channel]
    );

    if (loading || !carAnalytics) return null;

    return (
        <div className="page-content mb-3">
            <ToastContainer />
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
                        <CarPageNav active="ads" />
                    </Col>
                </Row>

                <Row>
                    <Col>
                        <div className="d-grid gap-3">

                            <section style={{ ...sectionStyle, background: resolveRecommendationZoneBackground(recommendation?.action) }}>
                                <SmartAdsRecommendationCard
                                    recommendation={recommendation}
                                    recommendedCreative={recommendedCreative}
                                    recommendedPlatform={recommendedPlatform}
                                    marketingUrl={`/cars/${id}/marketing`}
                                    metrics={m}
                                />
                            </section>

                            <section style={sectionStyle}>
                                <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap pb-3 mb-3 border-bottom">
                                    <div>
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                            Realidade atual
                                        </p>
                                        <h6 className="mb-0 fw-semibold">Campanhas e contexto de investimento</h6>
                                    </div>
                                    <Button color="light" className="border btn-sm" onClick={() => setShowAudienceAnalysis((value) => !value)}>
                                        {showAudienceAnalysis ? "Esconder análise do público" : "Ver análise do público"}
                                    </Button>
                                </div>

                                <div className="mb-3">
                                    <div className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                                        Campanhas activas
                                    </div>
                                    <CarAdCampaignMapper companyId={companyId} carId={Number(id)} />
                                </div>

                                {perfChannels.length > 0 && (
                                    <div className="table-responsive">
                                        <table className="table table-sm align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th className="ps-0">Canal</th>
                                                    <th className="text-end">Investimento</th>
                                                    <th className="text-end">Leads</th>
                                                    <th className="text-end">Sessões</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {perfChannels.map((ch: any, idx: number) => (
                                                    <tr key={idx}>
                                                        <td className="ps-0">
                                                            <div className="d-flex align-items-center gap-2">
                                                                <span style={{ width: 8, height: 8, borderRadius: "50%", background: ch.color, display: "inline-block" }} />
                                                                <span className="fw-medium">{ch.label}</span>
                                                            </div>
                                                        </td>
                                                        <td className="text-end">{ch.total_spend > 0 ? `€${fmt(ch.total_spend)}` : "—"}</td>
                                                        <td className="text-end">{ch.total_leads}</td>
                                                        <td className="text-end">{ch.total_sessions}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}

                                <Collapse isOpen={showAudienceAnalysis}>
                                    <div className="mt-3 pt-3 border-top">
                                        <AudienceSuggestionCard
                                            analysis={audienceAnalysis}
                                            loading={loadingAudience}
                                        />
                                    </div>
                                </Collapse>
                            </section>

                        </div>
                    </Col>
                </Row>

            </Container>
        </div>
    );
}

function resolveRecommendationZoneBackground(action?: string | null): string {
    if (action === "scale_ads") return "#f4fbf7";
    if (action === "review_campaign" || action === "test_campaign") return "#fffaf0";
    if (action === "do_not_invest") return "#f8fafc";
    return "#f8fbff";
}
