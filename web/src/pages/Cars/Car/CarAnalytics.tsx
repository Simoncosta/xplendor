import { useEffect, useMemo, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { useParams } from "react-router-dom";
import { Col, Container, Row } from "reactstrap";
import { createSelector } from "reselect";
import { toast, ToastContainer } from "react-toastify";

import { analyticsCar } from "slices/cars/thunk";
import { carRecalculate, refreshCarMetaAds, regenerateCarAnalysis } from "slices/car-ai-analises/thunk";

// ── Sub-componentes ────────────────────────────────────────────────────────────
import CarAnalyticsHeader from "./components/CarAnalyticsHeader";
import CarAnalyticsKpiStrip from "./components/CarAnalyticsKpiStrip";
import SilentBuyerIntentCard from "./components/SilentBuyerIntentCard";
import TabOverview from "./components/TabOverview";
import TabPerformance from "./components/TabPerformance";
import TabAnaliseIA from "./components/TabAnaliseIA";
import TabViatura from "./components/TabViatura";

// ── Helpers / data ─────────────────────────────────────────────────────────────
import {
    fmt, fmtDate, fmtTime,
    buildKpiItems,
    buildTrafficSources, buildDonutOptions,
    buildInteractions,
    buildInsight,
    timelineDesc,
    channelLabels, channelColors,
    ipsClassBadge, ipsFactorLabels, marketPositionMeta,
    buildIpsRadialOptions, buildIpsHistoryOptions,
    forecastOptions,
} from "./helpers/CarAnalyticsData";

type TabKey = "overview" | "performance" | "inteligencia" | "viatura";

const tabs: { key: TabKey; label: string; icon: string }[] = [
    { key: "overview", label: "Overview", icon: "ri-dashboard-line" },
    { key: "performance", label: "Performance", icon: "ri-bar-chart-grouped-line" },
    { key: "inteligencia", label: "Inteligência", icon: "ri-cpu-line" },
    { key: "viatura", label: "Viatura", icon: "ri-car-line" },
];

const selectCarState = (state: any) => state.Car;
const selectCarAiAnalysesState = (state: any) => state.CarAiAnalyses;

const selectCarAnalyticsViewModel = createSelector(
    [selectCarState, selectCarAiAnalysesState],
    (carState, carAiAnalysesState) => ({
        carAnalytics: carState.data.carAnalytics,
        loading: carState.loading.analytics,
        generatingAi: carAiAnalysesState.loading.create,
        refreshingMetaAds: carAiAnalysesState.loading.refreshMeta,
        regeneratingAnalysis: carAiAnalysesState.loading.regenerate,
    })
);

export default function CarAnalytics() {
    document.title = "Análises do Carro | Xplendor";

    const dispatch: any = useDispatch();
    const { id } = useParams();
    const [activeTab, setActiveTab] = useState<TabKey>("overview");
    const [companyId, setCompanyId] = useState<number>(0);

    const { carAnalytics, loading, generatingAi, refreshingMetaAds, regeneratingAnalysis } = useSelector(selectCarAnalyticsViewModel);
    const [refreshingAndReanalyzing, setRefreshingAndReanalyzing] = useState(false);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return;
        const obj = JSON.parse(authUser);
        setCompanyId(Number(obj.company_id));
        dispatch(analyticsCar({ companyId: obj.company_id, id: Number(id) }));
    }, [dispatch, id]);

    // ── Dados derivados ────────────────────────────────────────────────────────
    const car = carAnalytics?.car;
    const m = carAnalytics?.metrics;
    const ai = car?.analyses?.analysis;
    const aiMeta = car?.analyses;
    const ips = carAnalytics?.potential_score;
    const perf = carAnalytics?.performance;

    const trafficSources = useMemo(
        () => buildTrafficSources(carAnalytics?.traffic_sources),
        [carAnalytics?.traffic_sources]
    );
    const totalTraffic = useMemo(
        () => trafficSources.reduce((s: number, i: any) => s + i.total, 0),
        [trafficSources]
    );
    const donutOptions = useMemo(
        () => buildDonutOptions(trafficSources, totalTraffic),
        [trafficSources, totalTraffic]
    );

    const interactions = useMemo(
        () => buildInteractions(carAnalytics?.interactions_breakdown),
        [carAnalytics?.interactions_breakdown]
    );
    const totalInteractions = useMemo(
        () => interactions.reduce((s: number, i: any) => s + i.total, 0),
        [interactions]
    );

    const insight = useMemo(() => buildInsight(m), [m]);

    const perfTotals = perf?.totals;
    const perfChannels = useMemo(
        () => (perf?.by_channel || []).map((ch: any) => ({
            ...ch,
            label: channelLabels[ch.channel] || ch.channel,
            color: channelColors[ch.channel] || "#adb5bd",
        })),
        [perf?.by_channel]
    );

    const ipsRadialOptions = useMemo(() => buildIpsRadialOptions(ips), [ips]);
    const ipsHistoryOptions = useMemo(() => buildIpsHistoryOptions(ips), [ips]);
    const recommendation = carAnalytics?.smart_ads_recommendation ?? null;
    const recommendedCreative = carAnalytics?.recommended_creative ?? null;
    const recommendedPlatform = carAnalytics?.ai_analysis?.recommended_channel ?? null;
    const marketIntelligence = carAnalytics?.market_intelligence ?? null;
    const metaAdsTargetingStatus = carAnalytics?.meta_ads_targeting_status ?? null;
    const silentBuyers = carAnalytics?.silent_buyers ?? null;
    const overviewKpiStrip = <CarAnalyticsKpiStrip items={buildKpiItems(m)} />;

    if (loading || !carAnalytics) return null;

    // ── Handlers ──────────────────────────────────────────────────────────────
    const handleRefreshMetaAds = async () => {
        if (!companyId || !id) return;

        try {
            await dispatch(refreshCarMetaAds({ companyId, carId: Number(id) })).unwrap();
            await dispatch(analyticsCar({ companyId, id: Number(id) })).unwrap();
            toast.success("Dados Meta Ads atualizados com sucesso.");
        } catch (error: any) {
            toast.error(error?.message ?? error ?? "Nao foi possivel atualizar os dados Meta Ads.");
        }
    };

    const handleRegenerateAnalysis = async () => {
        if (!companyId || !id) return;

        try {
            await dispatch(regenerateCarAnalysis({ companyId, carId: Number(id) })).unwrap();
            await dispatch(analyticsCar({ companyId, id: Number(id) })).unwrap();
            toast.success("Analise regenerada com sucesso.");
        } catch (error: any) {
            toast.error(error?.message ?? error ?? "Nao foi possivel regenerar a analise.");
        }
    };

    const handleRefreshAndReanalyze = async () => {
        if (!companyId || !id) return;

        setRefreshingAndReanalyzing(true);

        try {
            await dispatch(refreshCarMetaAds({ companyId, carId: Number(id) })).unwrap();
            await dispatch(regenerateCarAnalysis({ companyId, carId: Number(id) })).unwrap();
            await dispatch(analyticsCar({ companyId, id: Number(id) })).unwrap();
            toast.success("Dados Meta Ads e analise atualizados com sucesso.");
        } catch (error: any) {
            toast.error(error?.message ?? error ?? "Nao foi possivel atualizar e reanalisar esta viatura.");
        } finally {
            setRefreshingAndReanalyzing(false);
        }
    };

    const handleRecalculate = () => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return;
        const { company_id } = JSON.parse(authUser);
        dispatch(carRecalculate({ companyId: company_id, carId: Number(id) }));
        toast("Recalculando análise de viatura... Aguarde um pouco e recarregue a página.");
    };

    // ── Render ────────────────────────────────────────────────────────────────
    return (
        <div className="page-content mb-3">
            <ToastContainer />
            <Container fluid>

                <Row className="mb-3">
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

                <Row>
                    <Col>
                        <div
                            style={{
                                border: "1px solid #e9ebec",
                                borderRadius: "18px",
                                background: "#fff",
                            }}
                        >
                            <div
                                style={{
                                    padding: "16px 16px 0 16px",
                                    borderBottom: "1px solid #e9ebec",
                                }}
                            >
                                <div className="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3 px-2">
                                    <div>
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                            Análise detalhada
                                        </p>
                                        <h5 className="mb-1 fw-semibold">Painel estratégico com leitura clara por contexto</h5>
                                        <p className="text-muted fs-13 mb-0">
                                            Cada tab organiza a decisão por foco, sem sobrecarga visual nem repetição desnecessária.
                                        </p>
                                    </div>
                                </div>
                                <ul
                                    className="nav nav-tabs nav-tabs-custom nav-justified rounded-3 p-2 mb-0"
                                    style={{
                                        borderBottom: "none",
                                        background: "#fff",
                                        gap: "0.35rem",
                                    }}
                                >
                                    {tabs.map((t) => (
                                        <li className="nav-item" key={t.key}>
                                            <button
                                                className={`nav-link w-100 ${activeTab === t.key ? "" : ""}`}
                                                onClick={() => setActiveTab(t.key)}
                                                style={{
                                                    border: activeTab === t.key ? "1px solid #e9ebec" : "1px solid transparent",
                                                    borderBottom: "none",
                                                    borderRadius: "0.75rem",
                                                    background: activeTab === t.key ? "#f8f9fa" : "transparent",
                                                    color: activeTab === t.key ? "#405189" : "#878a99",
                                                    fontWeight: activeTab === t.key ? 600 : 400,
                                                    padding: "12px 14px",
                                                    fontSize: "13px",
                                                    transition: "all 0.2s ease",
                                                    cursor: "pointer",
                                                }}
                                            >
                                                <i className={`${t.icon} me-2`} />
                                                {t.label}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </div>

                            <div style={{ padding: "16px" }}>
                                {activeTab === "overview" && (
                                    <TabOverview
                                        recommendation={recommendation}
                                        recommendedCreative={recommendedCreative}
                                        recommendedPlatform={recommendedPlatform}
                                        marketIntelligence={marketIntelligence}
                                        silentBuyers={silentBuyers}
                                        metrics={m}
                                        insight={insight}
                                        kpiStrip={overviewKpiStrip}
                                        onOpenIntelligence={() => setActiveTab("inteligencia")}
                                        marketingUrl={`/cars/${id}/marketing`}
                                    />
                                )}

                                {activeTab === "performance" && (
                                    <TabPerformance
                                        companyId={companyId}
                                        carId={Number(id)}
                                        trafficSources={trafficSources}
                                        totalTraffic={totalTraffic}
                                        donutOptions={donutOptions}
                                        interactions={interactions}
                                        totalInteractions={totalInteractions}
                                        perfTotals={perfTotals}
                                        perfChannels={perfChannels}
                                        fmt={fmt}
                                    />
                                )}

                                {activeTab === "inteligencia" && (
                                    <Row className="g-3">
                                        <Col xs={12}>
                                            <SilentBuyerIntentCard summary={silentBuyers} />
                                        </Col>
                                        <Col xs={12}>
                                            <TabAnaliseIA
                                                ips={ips}
                                                marketIntelligence={marketIntelligence}
                                                metaAdsTargetingStatus={metaAdsTargetingStatus}
                                                ai={ai}
                                                ipsRadialOptions={ipsRadialOptions}
                                                ipsHistoryOptions={ipsHistoryOptions}
                                                ipsClassBadge={ipsClassBadge}
                                                ipsFactorLabels={ipsFactorLabels}
                                                marketPositionMeta={marketPositionMeta}
                                                forecastOptions={forecastOptions}
                                                fmtDate={fmtDate}
                                                carId={id}
                                                companyId={companyId}
                                                onRecalculate={handleRecalculate}
                                                onRefreshMetaAds={handleRefreshMetaAds}
                                                onRegenerateAnalysis={handleRegenerateAnalysis}
                                                onRefreshAndReanalyze={handleRefreshAndReanalyze}
                                                generatingAi={generatingAi}
                                                refreshingMetaAds={refreshingMetaAds}
                                                regeneratingAnalysis={regeneratingAnalysis}
                                                refreshingAndReanalyzing={refreshingAndReanalyzing}
                                            />
                                        </Col>
                                    </Row>
                                )}

                                {activeTab === "viatura" && (
                                    <TabViatura
                                        car={car}
                                        ips={ips}
                                        timeline={carAnalytics.timeline}
                                        fmtDate={fmtDate}
                                        fmtTime={fmtTime}
                                        timelineDesc={timelineDesc}
                                    />
                                )}
                            </div>
                        </div>
                    </Col>
                </Row>

            </Container>
        </div>
    );
}
