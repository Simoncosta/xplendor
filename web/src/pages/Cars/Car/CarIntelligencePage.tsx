import { useEffect, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { useParams } from "react-router-dom";
import { Button, Col, Collapse, Container, Row, Spinner } from "reactstrap";
import { createSelector } from "reselect";
import { toast, ToastContainer } from "react-toastify";

import { analyticsCar } from "slices/cars/thunk";
import { refreshCarMetaAds, regenerateCarAnalysis } from "slices/car-ai-analises/thunk";

import CarAnalyticsHeader from "./components/CarAnalyticsHeader";
import CarPageNav from "./components/CarPageNav";

import {
    fmtDate,
    ipsClassBadge,
    ipsFactorLabels,
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
    const [showTechnicalAnalysis, setShowTechnicalAnalysis] = useState(false);

    const { carAnalytics, loading, refreshingMetaAds, regeneratingAnalysis } = useSelector(selectViewModel);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return;
        const obj = JSON.parse(authUser);
        setCompanyId(Number(obj.company_id));
        dispatch(analyticsCar({ companyId: obj.company_id, id: Number(id) }));
    }, [dispatch, id]);

    const car = carAnalytics?.car;
    const ai = car?.analyses?.analysis;
    const aiMeta = car?.analyses;
    const ips = carAnalytics?.potential_score;
    const intentAnalysis = carAnalytics?.intent_analysis ?? null;
    const leadRealityGap = carAnalytics?.lead_reality_gap ?? null;
    const metaAdsTargetingStatus = carAnalytics?.meta_ads_targeting_status ?? null;
    const silentBuyers = carAnalytics?.silent_buyers ?? null;
    const primaryAction = carAnalytics?.primary_recommended_action ?? null;

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
                        <div className="d-grid gap-3">
                            <CarDiagnosisBlock
                                ips={ips}
                                analysis={intentAnalysis}
                                gap={leadRealityGap}
                                primaryAction={primaryAction}
                                carId={id}
                            />

                            <section style={sectionStyle}>
                                <Button
                                    color="link"
                                    className="p-0 text-decoration-none fw-semibold"
                                    onClick={() => setShowTechnicalAnalysis((value) => !value)}
                                >
                                    Ver análise técnica completa {showTechnicalAnalysis ? "↑" : "↓"}
                                </Button>

                                <Collapse isOpen={showTechnicalAnalysis}>
                                    <div className="pt-3 mt-3 border-top d-grid gap-3">
                                        <TechnicalPipeline
                                            ai={ai}
                                            metaAdsTargetingStatus={metaAdsTargetingStatus}
                                            factorsCount={Object.keys(ips?.breakdown ?? {}).length}
                                            onRefreshAndReanalyze={handleRefreshAndReanalyze}
                                            refreshingMetaAds={refreshingMetaAds}
                                            regeneratingAnalysis={regeneratingAnalysis}
                                            refreshingAndReanalyzing={refreshingAndReanalyzing}
                                        />

                                        <IntentSignalsBlock analysis={intentAnalysis} gap={leadRealityGap} />

                                        {Number(silentBuyers?.visitors_count ?? 0) > 0 && (
                                            <SilentBuyerCompactBlock summary={silentBuyers} />
                                        )}
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

function CarDiagnosisBlock({
    ips,
    analysis,
    gap,
    primaryAction,
    carId,
}: {
    ips: any;
    analysis: any;
    gap: any;
    primaryAction: any;
    carId?: string;
}) {
    const score = Number(ips?.score ?? 0);
    const factors = buildSignalCards(ips, analysis);
    const actionLabel = primaryAction?.label ?? resolveBusinessActionLabel(score, analysis);
    const actionReason = primaryAction?.reason ?? buildActionReason(score, analysis, gap);

    return (
        <section style={{ ...sectionStyle, padding: 22 }}>
            <p className="text-muted text-uppercase fw-semibold fs-11 mb-3" style={{ letterSpacing: "0.08em" }}>
                Diagnóstico do carro
            </p>

            <div
                className="rounded-4 p-4 mb-4"
                style={{
                    background: "linear-gradient(135deg, #eef6ff 0%, #ffffff 70%)",
                    border: "1px solid #cfe4ff",
                }}
            >
                <div className="d-flex align-items-start gap-3 flex-wrap">
                    <div className="fs-2" aria-hidden="true">🎯</div>
                    <div>
                        <div className="fw-bold mb-1" style={{ fontSize: 34, lineHeight: 1.05, color: resolveScoreColor(score) }}>
                            {score}% de probabilidade de vender
                        </div>
                        <div className="text-muted fs-14 mb-3">com base nos dados atuais</div>
                        <div className="fs-16 fw-medium text-body" style={{ maxWidth: 760, lineHeight: 1.55 }}>
                            “{buildBusinessDiagnosis(score, analysis, gap)}”
                        </div>
                    </div>
                </div>
            </div>

            <div className="mb-4">
                <p className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                    Próxima ação recomendada
                </p>
                <div
                    className="rounded-4 p-3 d-flex align-items-center justify-content-between gap-3 flex-wrap"
                    style={{ background: "#fff", border: "1px solid #e2e8f0" }}
                >
                    <div className="d-flex align-items-start gap-3">
                        <div className="fs-4" aria-hidden="true">✏️</div>
                        <div>
                            <div className="fw-semibold fs-16 mb-1">{actionLabel}</div>
                            <div className="text-muted fs-14">{actionReason}</div>
                        </div>
                    </div>
                    <a href={`/cars/${carId}/marketing`} className="btn btn-primary">
                        Fazer →
                    </a>
                </div>
            </div>

            <div>
                <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
                    <p className="text-muted text-uppercase fw-semibold fs-11 mb-0" style={{ letterSpacing: "0.08em" }}>
                        Sinais do carro
                    </p>
                    <span className="text-muted fs-12">porquê esta nota?</span>
                </div>

                <div className="row g-3">
                    {factors.map((factor) => (
                        <div key={factor.key} className="col-xl-4 col-md-6">
                            <div
                                className="h-100 rounded-4 p-3"
                                style={{
                                    background: "#f8fafc",
                                    border: "1px solid #e2e8f0",
                                }}
                            >
                                <div className="d-flex align-items-start justify-content-between gap-2 mb-2">
                                    <div className="text-muted fs-13">{factor.label}</div>
                                    <span className="fs-18" aria-hidden="true">{factor.icon}</span>
                                </div>
                                <div className="fw-semibold fs-16 mb-1">{factor.headline}</div>
                                <div className="text-muted fs-13 mb-2">{factor.helper}</div>
                                <div className="text-muted fs-12">{factor.points}</div>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

function TechnicalPipeline({
    ai,
    metaAdsTargetingStatus,
    factorsCount,
    onRefreshAndReanalyze,
    refreshingMetaAds,
    regeneratingAnalysis,
    refreshingAndReanalyzing,
}: {
    ai: any;
    metaAdsTargetingStatus: any;
    factorsCount: number;
    onRefreshAndReanalyze: () => void;
    refreshingMetaAds?: boolean;
    regeneratingAnalysis?: boolean;
    refreshingAndReanalyzing?: boolean;
}) {
    return (
        <div className="d-flex align-items-center justify-content-between gap-3 flex-wrap rounded-3 px-3 py-2" style={{ background: "#f8fafc" }}>
            <div className="text-muted fs-13">
                Meta: {statusMark(Boolean(metaAdsTargetingStatus?.resolved_adset_id || metaAdsTargetingStatus?.has_targeting))}
                {"  "}Dados: {statusMark(Boolean(metaAdsTargetingStatus?.has_metrics || factorsCount > 0))}
                {"  "}IA: {statusMark(Boolean(ai))}
            </div>

            <Button color="primary" outline className="btn-sm" onClick={onRefreshAndReanalyze} disabled={refreshingAndReanalyzing || refreshingMetaAds || regeneratingAnalysis}>
                {refreshingAndReanalyzing ? <><Spinner size="sm" className="me-1" />A analisar</> : "Analisar"}
            </Button>
        </div>
    );
}

function buildSignalCards(ips: any, analysis: any) {
    const breakdown = ips?.breakdown ?? {};
    const visibility = Number(analysis?.unique_visitors ?? analysis?.sessions ?? 0);
    const contacts = Number(analysis?.whatsapp_clicks ?? 0) + Number(analysis?.leads ?? 0);

    return [
        buildScoreSignal("price_vs_market", "Preço", Number(breakdown.price_vs_market ?? 0), "OK", "A confirmar face ao mercado"),
        {
            key: "visibility",
            label: "Visibilidade",
            icon: visibility > 0 ? "✅" : "⚠️",
            headline: visibility > 0 ? `${visibility} visitas` : "Baixa",
            helper: visibility >= 100 ? "Média normal" : visibility > 0 ? "Ainda limitada" : "Quase sem leitura",
            points: "sinal de tráfego",
        },
        {
            key: "intent",
            label: "Intenção",
            icon: contacts > 0 ? "✅" : "❌",
            headline: contacts > 0 ? `${contacts} contactos` : "Ninguém tocou",
            helper: contacts > 0 ? "Já há tentativa comercial" : "Sem cliques de contacto",
            points: `${Number(analysis?.intent_score ?? 0)}/100`,
        },
        buildScoreSignal("days_in_stock", "Dias stock", Number(breakdown.days_in_stock ?? 0), `${Number(ips?.days_in_stock ?? 0) || "A rever"} dias`, "Pode estar a perder novidade"),
        buildScoreSignal("listing_quality", "Anúncio", Number(breakdown.listing_quality ?? 0), "Qualidade", "Fotos/copy podem estar a travar"),
        buildScoreSignal("model_history", "Histórico", Number(breakdown.model_history ?? 0), "Normal", "Comportamento dentro do esperado"),
    ];
}

function buildScoreSignal(key: string, label: string, points: number, fallbackHeadline: string, fallbackHelper: string) {
    const factor = ipsFactorLabels[key];
    const max = factor?.max ?? 10;
    const ratio = max > 0 ? points / max : 0;

    return {
        key,
        label,
        icon: ratio >= 0.65 ? "✅" : ratio >= 0.35 ? "⚠️" : "❌",
        headline: ratio >= 0.65 ? "OK" : fallbackHeadline,
        helper: ratio >= 0.65 ? "Não parece ser o bloqueio principal" : fallbackHelper,
        points: `${points}/${max}`,
    };
}

function buildBusinessDiagnosis(score: number, analysis: any, gap: any): string {
    const contacts = Number(analysis?.whatsapp_clicks ?? 0) + Number(analysis?.leads ?? 0);
    const visitors = Number(analysis?.unique_visitors ?? analysis?.sessions ?? 0);

    if (score >= 65 && contacts > 0) {
        return "Este carro mostra sinais fortes e já está a gerar contacto. O próximo passo é acelerar o que está a funcionar.";
    }

    if (score >= 40 && contacts === 0) {
        return "Este carro tem atenção, mas ainda não gera contacto suficiente. O problema provável está na mensagem ou no incentivo à ação.";
    }

    if (visitors > 0 && contacts === 0) {
        return "Este carro tem visibilidade mas ainda não gera intenção de compra suficiente. O problema parece estar no criativo ou na proposta, não apenas no preço.";
    }

    if (gap?.message) {
        return gap.message;
    }

    return "Ainda há pouco sinal para justificar mais investimento. Primeiro é preciso melhorar a forma como o carro é apresentado.";
}

function resolveBusinessActionLabel(score: number, analysis: any): string {
    const contacts = Number(analysis?.whatsapp_clicks ?? 0) + Number(analysis?.leads ?? 0);

    if (score >= 65 && contacts > 0) return "Preparar escala";
    if (contacts === 0) return "Gerar novo criativo";
    return "Melhorar chamada à ação";
}

function buildActionReason(score: number, analysis: any, gap: any): string {
    const contacts = Number(analysis?.whatsapp_clicks ?? 0) + Number(analysis?.leads ?? 0);

    if (score >= 65 && contacts > 0) {
        return "Os sinais atuais justificam testar mais alcance com controlo.";
    }

    if (contacts === 0) {
        return "O anúncio atual não está a converter visitantes em contactos.";
    }

    return gap?.message ?? "Há interesse, mas a página e o CTA precisam de remover fricção.";
}

function IntentSignalsBlock({ analysis, gap }: { analysis: any; gap: any }) {
    if (!analysis && !gap) {
        return null;
    }

    const rows = [
        { label: "Cliques WhatsApp", value: Number(analysis?.whatsapp_clicks ?? 0), benchmark: translateBenchmark(analysis?.relative_performance?.status) },
        { label: "Visitantes únicos", value: Number(analysis?.unique_visitors ?? 0), benchmark: translateBenchmark(analysis?.relative_performance?.status) },
        { label: "Sessões", value: Number(analysis?.sessions ?? 0), benchmark: translateIntentLevel(analysis?.intent_level) },
        { label: "Leads", value: Number(analysis?.leads ?? 0), benchmark: "—" },
        { label: "Tempo médio", value: `${Number(analysis?.avg_time_on_page ?? 0).toFixed(0)}s`, benchmark: "—" },
        { label: "Scroll médio", value: `${Number(analysis?.avg_scroll ?? 0).toFixed(0)}%`, benchmark: "—" },
    ];

    return (
        <section style={sectionStyle}>
            <div className="table-responsive">
                <table className="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th className="ps-0">Métrica</th>
                            <th>Valor</th>
                            <th className="text-end pe-0">Leitura</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.label}>
                                <td className="ps-0 text-muted">{row.label}</td>
                                <td className="fw-semibold">{row.value}</td>
                                <td className="text-end pe-0">{row.benchmark}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function SilentBuyerCompactBlock({ summary }: { summary: any }) {
    return (
        <section style={sectionStyle}>
            <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap">
                <div>
                    <div className="text-muted fs-12 mb-1">Visitantes silenciosos</div>
                    <h6 className="mb-1 fw-semibold">Interesse médio: {Number(summary?.average_intent_score ?? 0).toFixed(0)}/100</h6>
                    <p className="text-muted fs-13 mb-0">{summary?.summary_text ?? "Existem sinais silenciosos a acompanhar nesta viatura."}</p>
                </div>
                <span className="badge bg-dark-subtle text-dark px-3 py-2">
                    {summary?.visitors_count ?? 0} visitantes
                </span>
            </div>
        </section>
    );
}

function resolveScoreColor(score: number): string {
    if (score >= 70) return "#0ab39c";
    if (score >= 40) return "#f7b84b";
    return "#f06548";
}

function statusMark(isReady: boolean): string {
    return isReady ? "✓" : "pendente";
}

function translateBenchmark(status?: string | null): string {
    return {
        high: "alto",
        medium: "médio",
        low: "baixo",
        above_average: "acima",
        average: "médio",
        below_average: "baixo",
    }[status ?? ""] ?? "—";
}

function translateIntentLevel(level?: string | null): string {
    return {
        low: "baixo",
        medium: "médio",
        high: "alto",
        very_high: "muito alto",
    }[level ?? ""] ?? "—";
}
