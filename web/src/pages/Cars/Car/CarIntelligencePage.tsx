import { useEffect, useMemo, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { useParams } from "react-router-dom";
import { Col, Container, Row } from "reactstrap";
import { createSelector } from "reselect";
import { toast, ToastContainer } from "react-toastify";

import { analyticsCar } from "slices/cars/thunk";
import { carRecalculate, refreshCarMetaAds, regenerateCarAnalysis } from "slices/car-ai-analises/thunk";

import CarAnalyticsHeader from "./components/CarAnalyticsHeader";
import CarPageNav from "./components/CarPageNav";
import SilentBuyerIntentCard from "./components/SilentBuyerIntentCard";
import TabAnaliseIA from "./components/TabAnaliseIA";

import {
    fmtDate,
    buildInsight,
    ipsClassBadge, ipsFactorLabels, marketPositionMeta,
    buildIpsRadialOptions, buildIpsHistoryOptions,
    forecastOptions,
} from "./helpers/CarAnalyticsData";

const sectionStyle = {
    padding: "16px 18px",
    border: "1px solid #e9ebec",
    borderRadius: "16px",
    background: "#fff",
};

const selectCarState = (state: any) => state.Car;
const selectCarAiAnalysesState = (state: any) => state.CarAiAnalyses;

const selectViewModel = createSelector(
    [selectCarState, selectCarAiAnalysesState],
    (carState, carAiAnalysesState) => ({
        carAnalytics: carState.data.carAnalytics,
        loading: carState.loading.analytics,
        generatingAi: carAiAnalysesState.loading.create,
        refreshingMetaAds: carAiAnalysesState.loading.refreshMeta,
        regeneratingAnalysis: carAiAnalysesState.loading.regenerate,
    })
);

export default function CarIntelligencePage() {
    document.title = "Inteligência | Xplendor";

    const dispatch: any = useDispatch();
    const { id } = useParams();
    const [companyId, setCompanyId] = useState<number>(0);
    const [refreshingAndReanalyzing, setRefreshingAndReanalyzing] = useState(false);

    const { carAnalytics, loading, generatingAi, refreshingMetaAds, regeneratingAnalysis } = useSelector(selectViewModel);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return;
        const obj = JSON.parse(authUser);
        setCompanyId(Number(obj.company_id));
        dispatch(analyticsCar({ companyId: obj.company_id, id: Number(id) }));
    }, [dispatch, id]);

    const car = carAnalytics?.car;
    const m = carAnalytics?.metrics;
    const ai = car?.analyses?.analysis;
    const aiMeta = car?.analyses;
    const ips = carAnalytics?.potential_score;
    const marketIntelligence = carAnalytics?.market_intelligence ?? null;
    const metaAdsTargetingStatus = carAnalytics?.meta_ads_targeting_status ?? null;
    const silentBuyers = carAnalytics?.silent_buyers ?? null;

    const insight = useMemo(() => buildInsight(m), [m]);
    const ipsRadialOptions = useMemo(() => buildIpsRadialOptions(ips), [ips]);
    const ipsHistoryOptions = useMemo(() => buildIpsHistoryOptions(ips), [ips]);

    const handleRefreshMetaAds = async () => {
        if (!companyId || !id) return;
        try {
            await dispatch(refreshCarMetaAds({ companyId, carId: Number(id) })).unwrap();
            await dispatch(analyticsCar({ companyId, id: Number(id) })).unwrap();
            toast.success("Dados Meta Ads atualizados com sucesso.");
        } catch (error: any) {
            toast.error(error?.message ?? "Nao foi possivel atualizar os dados Meta Ads.");
        }
    };

    const handleRegenerateAnalysis = async () => {
        if (!companyId || !id) return;
        try {
            await dispatch(regenerateCarAnalysis({ companyId, carId: Number(id) })).unwrap();
            await dispatch(analyticsCar({ companyId, id: Number(id) })).unwrap();
            toast.success("Analise regenerada com sucesso.");
        } catch (error: any) {
            toast.error(error?.message ?? "Nao foi possivel regenerar a analise.");
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
            toast.error(error?.message ?? "Nao foi possivel atualizar e reanalisar.");
        } finally {
            setRefreshingAndReanalyzing(false);
        }
    };

    const handleRecalculate = () => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return;
        const { company_id } = JSON.parse(authUser);
        dispatch(carRecalculate({ companyId: company_id, carId: Number(id) }));
        toast("A atualizar análise da viatura...");
    };

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
                        <CarPageNav active="intelligence" />
                    </Col>
                </Row>

                <Row className="g-3">
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

                    {insight && (
                        <Col xs={12}>
                            <section style={sectionStyle}>
                                <Row className="g-3 align-items-start">
                                    <Col lg={8}>
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                            Insight automático
                                        </p>
                                        <h6 className="mb-2 fw-semibold">{insight.title ?? "Sem insight disponível"}</h6>
                                        <p className="text-muted fs-14 mb-0" style={{ lineHeight: 1.6 }}>
                                            {insight.text ?? "Assim que houver mais sinais comportamentais, surge aqui uma leitura automática."}
                                        </p>
                                    </Col>
                                    <Col lg={4}>
                                        <div className="d-flex align-items-center justify-content-between gap-2">
                                            <span className="text-muted fs-12">Próximo passo</span>
                                            <i className={`${insight.icon ?? "ri-lightbulb-flash-line"} text-${insight.color ?? "warning"} fs-20`} />
                                        </div>
                                        <p className="mb-0 fs-13 fw-medium mt-2">{insight.rec ?? "Continuar a monitorizar a performance do anúncio."}</p>
                                    </Col>
                                </Row>
                            </section>
                        </Col>
                    )}

                    <Col xs={12}>
                        <SilentBuyerIntentCard summary={silentBuyers} />
                    </Col>
                </Row>

            </Container>
        </div>
    );
}
