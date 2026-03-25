import { useEffect, useMemo, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { useParams } from "react-router-dom";
import { Card, CardBody, CardHeader, Col, Container, Row } from "reactstrap";
import { createSelector } from "reselect";
import { toast, ToastContainer } from "react-toastify";

import { analyticsCar } from "slices/cars/thunk";
import { carAiAnalyses, carRecalculate } from "slices/car-ai-analises/thunk";

// ── Sub-componentes ────────────────────────────────────────────────────────────
import CarAnalyticsHeader from "./components/CarAnalyticsHeader";
import CarAnalyticsKpiStrip from "./components/CarAnalyticsKpiStrip";
import SmartAdsRecommendationCard from "./components/SmartAdsRecommendationCard";
import TabMetricas from "./components/TabMetricas";
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
    ipsClassBadge, ipsFactorLabels,
    buildIpsRadialOptions, buildIpsHistoryOptions,
    forecastOptions,
} from "./helpers/CarAnalyticsData";

type TabKey = "metricas" | "analise" | "viatura";

const tabs: { key: TabKey; label: string; icon: string }[] = [
    { key: "metricas", label: "Métricas", icon: "ri-bar-chart-line" },
    { key: "analise", label: "Inteligência", icon: "ri-cpu-line" },
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
    })
);

export default function CarAnalytics() {
    document.title = "Análises do Carro | Xplendor";

    const dispatch: any = useDispatch();
    const { id } = useParams();
    const [activeTab, setActiveTab] = useState<TabKey>("metricas");
    const [companyId, setCompanyId] = useState<number>(0);

    const { carAnalytics, loading, generatingAi } = useSelector(selectCarAnalyticsViewModel);

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
    const recommendedPlatform = carAnalytics?.ai_analysis?.recommended_channel ?? null;

    if (loading || !carAnalytics) return null;

    // ── Handlers ──────────────────────────────────────────────────────────────
    const handleGenerateAi = async () => {
        if (!companyId || !id) return;

        try {
            await dispatch(carAiAnalyses({ companyId, carId: Number(id) })).unwrap();
            await dispatch(analyticsCar({ companyId, id: Number(id) })).unwrap();
            toast.success("Análise gerada com sucesso.");
        } catch (error: any) {
            toast.error(error?.message ?? error ?? "Erro ao gerar análise.");
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
        <div className="page-content">
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

                <Row className="mb-3">
                    <Col>
                        <SmartAdsRecommendationCard
                            recommendation={recommendation}
                            recommendedPlatform={recommendedPlatform}
                        />
                    </Col>
                </Row>

                <Row className="mb-3">
                    <Col>
                        <div>
                            <div className="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                                <div>
                                    <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                        Estado atual
                                    </p>
                                    <h6 className="mb-0 fw-semibold">Indicadores principais da viatura</h6>
                                </div>
                                <span className="badge bg-light text-muted fs-12 px-3 py-2">
                                    Atualizado com base na atividade do anúncio
                                </span>
                            </div>
                            <CarAnalyticsKpiStrip items={buildKpiItems(m)} />
                        </div>
                    </Col>
                </Row>

                <Row>
                    <Col>
                        <Card
                            className="border-0 overflow-hidden"
                            style={{
                                boxShadow: "0 16px 40px rgba(15, 23, 42, 0.08)",
                                background: "linear-gradient(180deg, #ffffff 0%, #fcfcfd 100%)",
                            }}
                        >
                            <CardHeader
                                className="border-bottom-0"
                                style={{
                                    padding: "1rem 1rem 0 1rem",
                                    background: "linear-gradient(180deg, rgba(64,81,137,0.05) 0%, rgba(64,81,137,0.015) 100%)",
                                }}
                            >
                                <div className="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3 px-2">
                                    <div>
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                            Análise detalhada
                                        </p>
                                        <h5 className="mb-1 fw-semibold">Explorar desempenho, inteligência e contexto da viatura</h5>
                                        <p className="text-muted fs-13 mb-0">
                                            Aprofunda a análise depois da recomendação e dos indicadores principais.
                                        </p>
                                    </div>
                                </div>
                                <ul
                                    className="nav nav-tabs nav-tabs-custom nav-justified rounded-3 p-2 mb-0"
                                    style={{
                                        borderBottom: "none",
                                        background: "#f8f9fa",
                                        boxShadow: "inset 0 0 0 1px rgba(233,235,236,0.95)",
                                        gap: "0.35rem",
                                    }}
                                >
                                    {tabs.map((t) => (
                                        <li className="nav-item" key={t.key}>
                                            <button
                                                className={`nav-link w-100 ${activeTab === t.key ? "" : ""}`}
                                                onClick={() => setActiveTab(t.key)}
                                                style={{
                                                    border: activeTab === t.key ? "1px solid rgba(64,81,137,0.12)" : "1px solid transparent",
                                                    borderBottom: "none",
                                                    borderRadius: "0.75rem",
                                                    background: activeTab === t.key ? "#ffffff" : "transparent",
                                                    color: activeTab === t.key ? "#405189" : "#878a99",
                                                    fontWeight: activeTab === t.key ? 600 : 400,
                                                    padding: "14px 16px",
                                                    fontSize: "13px",
                                                    boxShadow: activeTab === t.key ? "0 6px 18px rgba(15, 23, 42, 0.06)" : "none",
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
                            </CardHeader>

                            <CardBody style={{ padding: "1.5rem" }}>
                                {activeTab === "metricas" && (
                                    <TabMetricas
                                        companyId={companyId}
                                        carId={Number(id)}
                                        trafficSources={trafficSources}
                                        totalTraffic={totalTraffic}
                                        donutOptions={donutOptions}
                                        interactions={interactions}
                                        totalInteractions={totalInteractions}
                                        insight={insight}
                                        m={m}
                                        timeline={carAnalytics.timeline}
                                        perfTotals={perfTotals}
                                        perfChannels={perfChannels}
                                        fmtDate={fmtDate}
                                        fmtTime={fmtTime}
                                        fmt={fmt}
                                        timelineDesc={timelineDesc}
                                    />
                                )}

                                {activeTab === "analise" && (
                                    <TabAnaliseIA
                                        ips={ips}
                                        ai={ai}
                                        ipsRadialOptions={ipsRadialOptions}
                                        ipsHistoryOptions={ipsHistoryOptions}
                                        ipsClassBadge={ipsClassBadge}
                                        ipsFactorLabels={ipsFactorLabels}
                                        forecastOptions={forecastOptions}
                                        fmtDate={fmtDate}
                                        carId={id}
                                        companyId={companyId}
                                        onRecalculate={handleRecalculate}
                                        onGenerateAi={handleGenerateAi}
                                        generatingAi={generatingAi}
                                    />
                                )}

                                {activeTab === "viatura" && (
                                    <TabViatura car={car} />
                                )}
                            </CardBody>
                        </Card>
                    </Col>
                </Row>

            </Container>
        </div>
    );
}
