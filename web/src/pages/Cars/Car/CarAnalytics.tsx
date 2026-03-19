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

const selectCarAnalyticsViewModel = createSelector(
    [selectCarState],
    (carState) => ({
        carAnalytics: carState.data.carAnalytics,
        loading: carState.loading.analytics,
    })
);

export default function CarAnalytics() {
    document.title = "Análises do Carro | Xplendor";

    const dispatch: any = useDispatch();
    const { id } = useParams();
    const [activeTab, setActiveTab] = useState<TabKey>("metricas");
    const [companyId, setCompanyId] = useState<number>(0);

    const { carAnalytics, loading } = useSelector(selectCarAnalyticsViewModel);

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

    if (loading || !carAnalytics) return null;

    // ── Handlers ──────────────────────────────────────────────────────────────
    const handleGenerateAi = () => {
        dispatch(carAiAnalyses({ companyId, carId: Number(id) }));
        toast("Gerando análises de viatura... Aguarde um pouco e recarregue a página.");
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
                            fmt={fmt}
                            fmtDate={fmtDate}
                            ipsClassBadge={ipsClassBadge}
                        />
                    </Col>
                </Row>

                <Row className="mb-3">
                    <Col>
                        <CarAnalyticsKpiStrip items={buildKpiItems(m)} />
                    </Col>
                </Row>

                <Row>
                    <Col>
                        <Card>
                            <CardHeader className="p-0 border-bottom-0">
                                <ul className="nav nav-tabs nav-tabs-custom nav-justified" style={{ borderBottom: "1px solid #e9ebec" }}>
                                    {tabs.map((t) => (
                                        <li className="nav-item" key={t.key}>
                                            <button
                                                className={`nav-link w-100 ${activeTab === t.key ? "active" : ""}`}
                                                onClick={() => setActiveTab(t.key)}
                                                style={{
                                                    border: "none",
                                                    borderBottom: activeTab === t.key ? "2px solid #405189" : "2px solid transparent",
                                                    borderRadius: 0,
                                                    background: "transparent",
                                                    color: activeTab === t.key ? "#405189" : "#878a99",
                                                    fontWeight: activeTab === t.key ? 600 : 400,
                                                    padding: "12px 16px",
                                                    fontSize: "13px",
                                                    transition: "all .2s",
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

                            <CardBody>
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
                                    />
                                )}

                                {activeTab === "viatura" && (
                                    <TabViatura car={car} fmt={fmt} />
                                )}
                            </CardBody>
                        </Card>
                    </Col>
                </Row>

            </Container>
        </div>
    );
}
