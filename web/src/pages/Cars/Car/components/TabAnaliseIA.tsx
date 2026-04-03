import { Col, Row, Progress } from "reactstrap";
import ReactApexChart from "react-apexcharts";
import XButton from "Components/Common/XButton";

interface Props {
    ips: any;
    marketIntelligence?: any;
    metaAdsTargetingStatus?: any;
    ai: any;
    ipsRadialOptions: any;
    ipsHistoryOptions: any;
    ipsClassBadge: (cls: string) => string;
    ipsFactorLabels: Record<string, { label: string; max: number; icon: string; color: string }>;
    marketPositionMeta: Record<string, { label: string; className: string; impact: string; description: string }>;
    forecastOptions: any;
    fmtDate: (d: string) => string;
    carId: string | undefined;
    companyId: number;
    onRecalculate: () => void;
    onRefreshMetaAds: () => void;
    onRegenerateAnalysis: () => void;
    onRefreshAndReanalyze: () => void;
    generatingAi?: boolean;
    refreshingMetaAds?: boolean;
    regeneratingAnalysis?: boolean;
    refreshingAndReanalyzing?: boolean;
}

const sectionStyle = {
    border: "1px solid #e9ebec",
    borderRadius: "16px",
    background: "#fff",
    padding: "16px 18px",
} as const;

export default function TabAnaliseIA({
    ips,
    marketIntelligence,
    metaAdsTargetingStatus,
    ai,
    ipsRadialOptions,
    ipsHistoryOptions,
    ipsClassBadge,
    ipsFactorLabels,
    marketPositionMeta,
    forecastOptions,
    fmtDate,
    carId,
    companyId,
    onRecalculate,
    onRefreshMetaAds,
    onRegenerateAnalysis,
    onRefreshAndReanalyze,
    generatingAi = false,
    refreshingMetaAds = false,
    regeneratingAnalysis = false,
    refreshingAndReanalyzing = false,
}: Props) {
    const marketMeta = marketPositionMeta[marketIntelligence?.market_position ?? "insufficient_data"]
        ?? marketPositionMeta.insufficient_data;
    const targetingMeta = getTargetingMeta(metaAdsTargetingStatus?.status);
    const metricsMeta = getDataStepMeta(Boolean(metaAdsTargetingStatus?.has_metrics), "Métricas disponíveis", "Métricas indisponíveis");
    const breakdownMeta = getDataStepMeta(Boolean(metaAdsTargetingStatus?.has_breakdown), "Breakdown disponível", "Breakdown indisponível");

    return (
        <Row className="g-3">
            <Col xs={12}>
                <section style={{ ...sectionStyle, padding: "14px 16px" }}>
                    <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                        <div>
                            <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                Pipeline operacional
                            </p>
                            <h6 className="mb-1 fw-semibold">Sincronizar campanha e gerar leitura IA</h6>
                            <p className="text-muted fs-13 mb-0">
                                Primeiro sincronizas a campanha, depois confirmas o estado dos dados e só então geras a leitura IA com contexto atualizado.
                            </p>
                        </div>

                        <XButton
                            variant="primary"
                            soft
                            onClick={onRefreshAndReanalyze}
                            loading={refreshingAndReanalyzing}
                            disabled={refreshingAndReanalyzing || refreshingMetaAds || regeneratingAnalysis}
                        >
                            {refreshingAndReanalyzing ? "A sincronizar e gerar leitura..." : "Sincronizar e gerar leitura"}
                        </XButton>
                    </div>

                    <div className="row g-3">
                        <div className="col-lg-4">
                            <div className="h-100 rounded-3" style={{ border: "1px solid #eef0f2", background: "#fcfcfd", padding: "14px 16px" }}>
                                <div className="d-flex align-items-start justify-content-between gap-2 mb-3">
                                    <div>
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                            Passo 1
                                        </p>
                                        <h6 className="mb-1 fw-semibold fs-14">Sincronizar campanha</h6>
                                        <p className="text-muted fs-12 mb-0">
                                            Vai buscar métricas atuais, tenta resolver o ad set e atualiza o targeting guardado.
                                        </p>
                                    </div>
                                    <i className="ri-refresh-line text-info fs-20" />
                                </div>

                                <div className="d-flex align-items-center gap-2 flex-wrap mb-3">
                                    <span className={`badge rounded-pill px-3 py-2 fs-12 ${targetingMeta.className}`}>
                                        {targetingMeta.label}
                                    </span>
                                    {metaAdsTargetingStatus?.resolved_adset_id ? (
                                        <span className="text-muted fs-12">Ad set: {metaAdsTargetingStatus.resolved_adset_id}</span>
                                    ) : (
                                        <span className="text-muted fs-12">Sem ad set resolvido</span>
                                    )}
                                </div>

                                <XButton
                                    variant="info"
                                    soft
                                    onClick={onRefreshMetaAds}
                                    loading={refreshingMetaAds}
                                    disabled={refreshingAndReanalyzing || refreshingMetaAds}
                                >
                                    {refreshingMetaAds ? "A sincronizar campanha..." : "Sincronizar campanha"}
                                </XButton>
                            </div>
                        </div>

                        <div className="col-lg-4">
                            <div className="h-100 rounded-3" style={{ border: "1px solid #eef0f2", background: "#fcfcfd", padding: "14px 16px" }}>
                                <div className="d-flex align-items-start justify-content-between gap-2 mb-3">
                                    <div>
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                            Passo 2
                                        </p>
                                        <h6 className="mb-1 fw-semibold fs-14">Estado dos dados</h6>
                                        <p className="text-muted fs-12 mb-0">
                                            Confirma rapidamente se já existem métricas, targeting e breakdown suficientes para uma leitura mais forte.
                                        </p>
                                    </div>
                                    <i className="ri-database-2-line text-primary fs-20" />
                                </div>

                                <div className="vstack gap-2">
                                    {[
                                        { label: "Métricas", meta: metricsMeta },
                                        { label: "Targeting", meta: targetingMeta },
                                        { label: "Breakdown", meta: breakdownMeta },
                                    ].map((item) => (
                                        <div key={item.label} className="d-flex align-items-center justify-content-between gap-2 bg-white rounded-3 px-3 py-2" style={{ border: "1px solid #eef0f2" }}>
                                            <span className="fs-13 text-body">{item.label}</span>
                                            <span className={`badge rounded-pill px-3 py-2 fs-11 ${item.meta.className}`}>
                                                {item.meta.label}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>

                        <div className="col-lg-4">
                            <div className="h-100 rounded-3" style={{ border: "1px solid #eef0f2", background: "#fcfcfd", padding: "14px 16px" }}>
                                <div className="d-flex align-items-start justify-content-between gap-2 mb-3">
                                    <div>
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                            Passo 3
                                        </p>
                                        <h6 className="mb-1 fw-semibold fs-14">Gerar leitura IA</h6>
                                        <p className="text-muted fs-12 mb-0">
                                            Reprocessa a análise com os dados mais recentes de performance, targeting e contexto comercial.
                                        </p>
                                    </div>
                                    <i className="ri-cpu-line text-secondary fs-20" />
                                </div>

                                <div className="text-muted fs-12 mb-3">
                                    {ai ? "Já existe uma leitura guardada. Podes regenerar para refletir os dados mais recentes." : "Ainda não existe leitura IA para esta viatura."}
                                </div>

                                <XButton
                                    variant="secondary"
                                    soft
                                    onClick={onRegenerateAnalysis}
                                    loading={regeneratingAnalysis || generatingAi}
                                    disabled={refreshingAndReanalyzing || regeneratingAnalysis || generatingAi}
                                >
                                    {regeneratingAnalysis || generatingAi ? "A gerar leitura IA..." : "Gerar leitura IA"}
                                </XButton>
                            </div>
                        </div>
                    </div>
                </section>
            </Col>

            <Col lg={4}>
                <section style={sectionStyle}>
                    <div className="d-flex align-items-center justify-content-between gap-2 mb-3">
                        <div>
                            <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                Score
                            </p>
                            <h6 className="fs-15 fw-semibold mb-0">Indice de Potencial de Venda</h6>
                        </div>
                        <i className="ri-award-line text-primary fs-20" />
                    </div>

                    {ips ? (
                        <>
                            <div className="text-center mb-2">
                                <ReactApexChart options={ipsRadialOptions} series={[ips.score]} type="radialBar" height={200} />
                                <div className="d-flex align-items-center justify-content-center gap-2 mt-1 flex-wrap">
                                    <span className={`badge rounded-pill fs-12 px-3 py-2 text-dark ${ipsClassBadge(ips.classification)}`}>
                                        {ips.classification === "hot" ? "Hot" : ips.classification === "warm" ? "Warm" : "Cold"}
                                    </span>
                                    {ips.price_vs_market !== null && (
                                        <span className={`badge rounded-pill fs-11 text-dark ${Number(ips.price_vs_market) < 0 ? "badge-soft-success" : "badge-soft-danger"}`}>
                                            {Number(ips.price_vs_market) < 0 ? "▼" : "▲"} {Math.abs(Number(ips.price_vs_market))}% vs mercado
                                        </span>
                                    )}
                                </div>
                            </div>

                            <div className="rounded-3 mt-3 mb-3" style={{ border: "1px solid #eef0f2", background: "#fcfcfd", padding: "14px 16px" }}>
                                <div className="d-flex align-items-start justify-content-between gap-2 flex-wrap mb-2">
                                    <div>
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                            Posição no Mercado
                                        </p>
                                        <h6 className="mb-0 fw-semibold fs-14">{marketMeta.label}</h6>
                                    </div>
                                    <span className={`badge rounded-pill px-3 py-2 fs-11 ${marketMeta.className}`}>
                                        Impacto {marketMeta.impact}
                                    </span>
                                </div>

                                <div className="d-flex align-items-center gap-2 flex-wrap mb-2">
                                    <span className={`badge rounded-pill px-3 py-2 fs-12 ${marketMeta.className}`}>
                                        {formatPercent(marketIntelligence?.car_price_vs_median_pct)} vs mediana
                                    </span>
                                </div>

                                <p className="text-muted fs-12 mb-3">{marketMeta.description}</p>

                                <div className="row g-2">
                                    <div className="col-6">
                                        <div className="bg-white rounded-3 px-3 py-2 h-100" style={{ border: "1px solid #eef0f2" }}>
                                            <span className="text-muted fs-11 d-block mb-1">Preço médio mercado</span>
                                            <span className="fw-semibold fs-13">{formatCurrency(marketIntelligence?.market_median_price)}</span>
                                        </div>
                                    </div>
                                    <div className="col-6">
                                        <div className="bg-white rounded-3 px-3 py-2 h-100" style={{ border: "1px solid #eef0f2" }}>
                                            <span className="text-muted fs-11 d-block mb-1">Preço sugerido</span>
                                            <span className="fw-semibold fs-13">{formatCurrency(marketIntelligence?.recommended_price)}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="vstack gap-2 mt-3">
                                {Object.entries(ips.breakdown || {}).map(([key, pts]: any) => {
                                    const factor = ipsFactorLabels[key];
                                    if (!factor) return null;
                                    const pct = Math.round((pts / factor.max) * 100);

                                    return (
                                        <div key={key} className="bg-light-subtle rounded-3 px-3 py-2">
                                            <div className="d-flex align-items-center justify-content-between mb-1">
                                                <div className="d-flex align-items-center gap-2">
                                                    <i className={`${factor.icon} text-${factor.color} fs-14`} />
                                                    <span className="fs-12 fw-medium">{factor.label}</span>
                                                </div>
                                                <span className="fs-12 fw-semibold">
                                                    {pts}<span className="text-muted fw-normal">/{factor.max}</span>
                                                </span>
                                            </div>
                                            <Progress color={pct >= 70 ? "success" : pct >= 40 ? "warning" : "danger"} value={pct} style={{ height: "4px" }} />
                                            {key === "price_vs_market" && (
                                                <div className="d-flex align-items-center justify-content-between gap-2 mt-2 flex-wrap">
                                                    <span className="text-muted fs-11">
                                                        {marketMeta.description}
                                                    </span>
                                                    <span className={`badge rounded-pill fs-10 px-2 py-1 ${marketMeta.className}`}>
                                                        {marketMeta.label}
                                                    </span>
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>

                            <div className="mt-3">
                                <div className="d-flex align-items-center justify-content-between mb-2">
                                    <span className="fs-12 fw-semibold text-muted text-uppercase">Historico 90 dias</span>
                                    <span className="fs-11 text-muted">
                                        {ips.history?.length || 0} calculo{(ips.history?.length || 0) !== 1 ? "s" : ""}
                                    </span>
                                </div>
                                {ips.history && ips.history.length > 1 ? (
                                    <ReactApexChart
                                        options={ipsHistoryOptions}
                                        series={[{ name: "Score", data: ips.history.map((h: any) => h.score) }]}
                                        type="line"
                                        height={80}
                                    />
                                ) : (
                                    <div className="bg-light-subtle rounded-3 px-3 py-3">
                                        <p className="fs-12 text-muted mb-0 text-center">Historico a construir com novos recalculos</p>
                                    </div>
                                )}
                            </div>

                            <div className="mt-3 d-flex align-items-center justify-content-between gap-2 flex-wrap">
                                <span className="fs-11 text-muted">Calculado {fmtDate(ips.calculated_at)}</span>
                                <button className="btn btn-soft-primary btn-sm" onClick={onRecalculate}>
                                    <i className="ri-refresh-line me-1" /> Recalcular
                                </button>
                            </div>
                        </>
                    ) : (
                        <div className="text-center py-4 text-muted">
                            <i className="ri-award-line fs-1 d-block mb-2" />
                            <p className="fs-13 mb-2">Score ainda nao calculado</p>
                            <button className="btn btn-soft-primary btn-sm" onClick={onRecalculate}>
                                <i className="ri-play-line me-1" /> Calcular agora
                            </button>
                        </div>
                    )}
                </section>
            </Col>

            {ai ? (
                <>
                    <Col lg={4}>
                        <section style={sectionStyle}>
                            <div className="d-flex align-items-center justify-content-between gap-2 mb-3">
                                <div>
                                    <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                        Recomendacoes
                                    </p>
                                    <h6 className="fs-15 fw-semibold mb-0">Publico e argumentos</h6>
                                </div>
                                <i className="ri-user-heart-line text-info fs-20" />
                            </div>

                            {ai.publico_alvo && (
                                <div className="vstack gap-2 mb-3">
                                    {[
                                        { label: "Faixa etaria", val: ai.publico_alvo.faixa_etaria },
                                        { label: "Genero", val: ai.publico_alvo.genero_predominante },
                                        { label: "Perfil profissional", val: ai.publico_alvo.perfil_profissional },
                                        { label: "Estilo de vida", val: ai.publico_alvo.estilo_de_vida },
                                        { label: "Comportamento", val: ai.publico_alvo.comportamento_de_compra },
                                    ].map((row, idx) => (
                                        <div key={idx} className="d-flex align-items-start justify-content-between gap-3 bg-light-subtle rounded-3 px-3 py-2">
                                            <span className="fs-12 text-muted">{row.label}</span>
                                            <span className="fs-13 fw-medium text-end">{row.val}</span>
                                        </div>
                                    ))}
                                </div>
                            )}

                            <div>
                                <p className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                                    Argumentos de venda
                                </p>
                                <div className="vstack gap-2">
                                    {(ai.argumentos_de_venda || []).map((arg: string, idx: number) => (
                                        <div key={idx} className="d-flex align-items-start gap-2 bg-light-subtle rounded-3 px-3 py-2 fs-13">
                                            <i className="ri-check-line text-success fs-16 flex-shrink-0" />
                                            <span>{arg}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </section>
                    </Col>

                    <Col lg={4}>
                        <section style={sectionStyle}>
                            <div className="d-flex align-items-center justify-content-between gap-2 mb-3">
                                <div>
                                    <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                        IA
                                    </p>
                                    <h6 className="fs-15 fw-semibold mb-0">Plano recomendado</h6>
                                </div>
                                <i className="ri-cpu-line text-primary fs-20" />
                            </div>

                            <Row className="g-2 mb-3">
                                {[
                                    { label: "7 dias", val: ai.previsao?.probabilidade_venda_7d, color: "danger" },
                                    { label: "14 dias", val: ai.previsao?.probabilidade_venda_14d, color: "warning" },
                                    { label: "30 dias", val: ai.previsao?.probabilidade_venda_30d, color: "success" },
                                ].map((p, idx) => (
                                    <Col xs={4} key={idx}>
                                        <div className={`bg-${p.color}-subtle rounded-3 text-center px-2 py-3`}>
                                            <div className={`fs-18 fw-bold text-${p.color}`}>{p.val}%</div>
                                            <div className="fs-11 text-muted">{p.label}</div>
                                        </div>
                                    </Col>
                                ))}
                            </Row>

                            <div className="bg-light-subtle rounded-3 px-3 py-3 mb-3">
                                <p className="fw-semibold text-muted text-uppercase mb-2" style={{ fontSize: 11, letterSpacing: "0.08em" }}>
                                    Leitura rapida
                                </p>
                                <p className="text-muted fs-12 mb-0">
                                    Probabilidade estimada de venda com base em preco vs mercado, engagement, tempo em stock e historico do modelo.
                                </p>
                                {ai.previsao?.condicao && (
                                    <p className="text-muted fs-12 mb-0 mt-2">
                                        <i className="ri-lightbulb-line me-1 text-warning" />
                                        {ai.previsao.condicao}
                                    </p>
                                )}
                            </div>

                            <ReactApexChart
                                options={forecastOptions}
                                series={[{ name: "Probabilidade", data: [0, ai.previsao?.probabilidade_venda_7d, ai.previsao?.probabilidade_venda_14d, ai.previsao?.probabilidade_venda_30d] }]}
                                type="area"
                                height={140}
                            />

                            {ai?.campaign_diagnosis && (
                                <div
                                    className="rounded-3 px-3 py-3 mt-3"
                                    style={{ border: "1px solid #fde8e4", background: "#fff" }}
                                >
                                    <p className="text-muted text-uppercase fw-semibold fs-11 mb-2">
                                        Diagnóstico da campanha
                                    </p>

                                    <h6 className="fw-semibold mb-2 text-danger fs-14">
                                        {ai.campaign_diagnosis.main_problem === "targeting" && "Público mal definido"}
                                        {ai.campaign_diagnosis.main_problem === "copy" && "Mensagem fraca"}
                                        {ai.campaign_diagnosis.main_problem === "offer" && "Oferta desalinhada"}
                                        {ai.campaign_diagnosis.main_problem === "mixed" && "Problema misto"}
                                    </h6>

                                    <p className="mb-2 fs-13">
                                        {ai.campaign_diagnosis.message}
                                    </p>

                                    <div
                                        className="rounded-2 px-2 py-2 fs-13 fw-semibold"
                                        style={{ background: "#e7f8ee", color: "#0f8a4b" }}
                                    >
                                        👉 {ai.campaign_diagnosis.action}
                                    </div>
                                </div>
                            )}

                            <div className="vstack gap-2 mt-3">
                                {[
                                    { data: ai.canal_principal, badge: "Principal", badgeClass: "bg-primary-subtle text-primary" },
                                    { data: ai.canal_secundario, badge: "Secundario", badgeClass: "bg-info-subtle text-info" },
                                ].map((c, idx) => c.data && (
                                    <div key={idx} className="bg-light-subtle rounded-3 px-3 py-3">
                                        <div className="d-flex align-items-center justify-content-between mb-1 gap-2">
                                            <span className="fw-semibold fs-13">{c.data.canal}</span>
                                            <span className={`badge ${c.badgeClass} fs-11`}>{c.badge}</span>
                                        </div>
                                        <p className="text-muted fs-12 mb-0">{c.data.justificacao}</p>
                                    </div>
                                ))}
                            </div>

                            {ai.sugestao_conteudo && (
                                <div className="vstack gap-2 mt-3">
                                    {[
                                        { label: "Titulo do anuncio", val: ai.sugestao_conteudo.titulo_anuncio },
                                        { label: "Hook de video", val: ai.sugestao_conteudo.hook_video },
                                        { label: "Copy curto", val: ai.sugestao_conteudo.copy_curto },
                                    ].map((item, idx) => (
                                        <div key={idx} className="bg-light-subtle rounded-3 px-3 py-3">
                                            <p className="fs-11 text-primary fw-semibold text-uppercase mb-1">{item.label}</p>
                                            <p className="fs-13 mb-0">{item.val}</p>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>
                    </Col>
                </>
            ) : (
                <Col lg={8}>
                    <section style={sectionStyle}>
                        <div className="text-center py-5 text-muted">
                            <i className="ri-cpu-line fs-1 d-block mb-3" />
                            <h5>Inteligencia ainda nao disponivel</h5>
                            <p className="mb-3 fs-13">A analise sera gerada automaticamente assim que existirem dados suficientes.</p>
                            <XButton onClick={onRegenerateAnalysis} loading={generatingAi || regeneratingAnalysis} disabled={generatingAi || regeneratingAnalysis}>
                                {generatingAi || regeneratingAnalysis ? "A gerar leitura IA..." : "Gerar leitura IA"}
                            </XButton>
                            {!!carId && !!companyId && (
                                <p className="text-muted fs-11 mt-3 mb-0">Viatura #{carId} ligada a empresa {companyId}</p>
                            )}
                        </div>
                    </section>
                </Col>
            )}
        </Row>
    );
}

function formatCurrency(value?: number | null) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return "—";
    }

    return new Intl.NumberFormat("pt-PT", {
        style: "currency",
        currency: "EUR",
        maximumFractionDigits: 0,
    }).format(Number(value));
}

function formatPercent(value?: number | null) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return "—";
    }

    const amount = Number(value);
    const sign = amount > 0 ? "+" : "";

    return `${sign}${amount.toFixed(1)}%`;
}

function getTargetingMeta(status?: string) {
    switch (status) {
        case "available":
            return {
                label: "Targeting disponível",
                className: "bg-success-subtle text-success",
            };
        case "campaign_without_adset":
            return {
                label: "Campanha sem ad set resolvido",
                className: "bg-warning-subtle text-warning",
            };
        default:
            return {
                label: "Targeting indisponível",
                className: "bg-secondary-subtle text-secondary",
            };
    }
}

function getDataStepMeta(isAvailable: boolean, availableLabel: string, unavailableLabel: string) {
    return isAvailable
        ? {
            label: availableLabel,
            className: "bg-success-subtle text-success",
        }
        : {
            label: unavailableLabel,
            className: "bg-secondary-subtle text-secondary",
        };
}
