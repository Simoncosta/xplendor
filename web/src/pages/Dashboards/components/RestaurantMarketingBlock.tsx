import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import ReactApexChart from "react-apexcharts";
import Select from "react-select";
import { Alert, Card, CardBody, Col, Row, Spinner } from "reactstrap";
import getChartColorsArray from "Components/Common/ChartsDynamicColor";
import { getRestaurantMarketing } from "helpers/laravel_helper";
import { reactSelectTheme } from "helpers/reactSelectStyles";
import type { MarketingComparison, RestaurantMarketing } from "common/models/restaurantMarketing.model";
import DashboardSectionHeader from "./DashboardSectionHeader";
import RecommendationsCard, { type RecommendationsState } from "./RecommendationsCard";
import { buildInsights, comparisonTag, eur0, int, monthLong, signedPct, signedPp, usesSeasonalityWarning } from "./restaurantMarketingText";

/**
 * XPLENDOR — Dashboard de restauração: bloco "Marketing e resultados" (o HUB).
 *
 * Pirâmide Dados → Informação → Insight → Ação, para quem NÃO é técnico:
 *   · INFORMAÇÃO: os totais do mês das 5 métricas, cada um com a etiqueta da
 *     comparação usada (e o aviso de sazonalidade, discreto);
 *   · INSIGHT: 2 a 4 frases descritivas (nunca causais);
 *   · DADOS: série diária de faturação, gasto Meta e sessões lado a lado;
 *   · AÇÃO: as ações de cada aviso e de cada recomendação (sem atalhos genéricos).
 * Estados honestos (Meta/GA4 por ligar, sessão expirada, conta em falta, a
 * sincronizar, sem dados). Os números vêm do backend; aqui só se apresentam.
 */

/** Últimos 13 meses (o actual + 12), para o seletor. */
const lastMonths = (): string[] => {
    const out: string[] = [];
    const d = new Date();
    d.setDate(1);
    for (let i = 0; i < 13; i++) {
        out.push(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`);
        d.setMonth(d.getMonth() - 1);
    }
    return out;
};

const capitalize = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

const integrationsUrl = (companyId: number) => `/companies/${companyId}?tab=integrations`;

const META_STATE_COPY: Record<string, { text: string; color: string; cta: string } | undefined> = {
    not_connected: { text: "A Meta (Facebook e Instagram) ainda não está ligada.", color: "light", cta: "Ligar a Meta" },
    token_expired: { text: "A sessão da Meta expirou. Os valores da Meta podem estar desatualizados.", color: "warning", cta: "Reconectar a Meta" },
    needs_account: { text: "A Meta está ligada, mas falta escolher a conta de anúncios.", color: "warning", cta: "Escolher a conta" },
    syncing_first: { text: "A Meta está a sincronizar pela primeira vez. Os valores aparecem em breve.", color: "info", cta: "" },
    sync_failed: { text: "A última sincronização com a Meta falhou. Os valores podem estar desatualizados.", color: "warning", cta: "Ver integrações" },
};

// ── Etiqueta de comparação (delta + "vs set. 2025" + aviso de sazonalidade) ──

const ComparisonBadge = ({ cmp, points = false }: { cmp: MarketingComparison | null | undefined; points?: boolean }) => {
    if (!cmp || cmp.tier === null) {
        return <span className="badge bg-light text-muted fw-normal">sem histórico</span>;
    }
    const delta = points ? cmp.delta_pp : cmp.delta_pct;
    const up = (delta ?? 0) >= 0;
    return (
        <span className="d-inline-flex align-items-center gap-1 flex-wrap fs-12">
            {delta !== null && delta !== undefined && (
                <span className={`fw-semibold ${up ? "text-success" : "text-danger"}`}>
                    <i className={up ? "ri-arrow-up-line" : "ri-arrow-down-line"} />
                    {points ? signedPp(delta) : signedPct(delta)}
                </span>
            )}
            <span className="text-muted">{comparisonTag(cmp)}</span>
            {cmp.seasonality_warning && (
                <i
                    className="ri-sun-cloudy-line text-muted"
                    title="Comparação com o mês anterior: pode refletir a época do ano (sazonalidade)."
                    aria-label="Pode refletir sazonalidade"
                />
            )}
        </span>
    );
};

// ── Cartão de métrica (Informação) com "o que isto quer dizer" ───────────────

const MetricCard = ({
    label, icon, color, value, children, help, unavailable,
}: {
    label: string;
    icon: string;
    color: string;
    value?: string;
    children?: React.ReactNode;
    help: string;
    unavailable?: React.ReactNode;
}) => {
    const [showHelp, setShowHelp] = useState(false);
    return (
        <Card className="mb-0 h-100">
            <CardBody className="p-3">
                <div className="d-flex align-items-start justify-content-between gap-2 mb-2">
                    <div className="d-flex align-items-center gap-2">
                        <span className="avatar-xs flex-shrink-0">
                            <span className={`avatar-title bg-${color}-subtle text-${color} rounded fs-16`}><i className={icon} /></span>
                        </span>
                        <span className="text-muted fs-13 fw-medium">{label}</span>
                    </div>
                    <button
                        type="button"
                        className="btn btn-link btn-sm p-0 text-muted lh-1"
                        onClick={() => setShowHelp((v) => !v)}
                        aria-expanded={showHelp}
                        aria-label={`O que quer dizer: ${label}`}
                        title="O que isto quer dizer?"
                    >
                        <i className="ri-question-line fs-16" />
                    </button>
                </div>
                {unavailable ? (
                    <div className="text-muted fs-13">{unavailable}</div>
                ) : (
                    <>
                        <div className="fs-20 fw-semibold text-body mb-1">{value}</div>
                        {children}
                    </>
                )}
                {showHelp && <p className="text-muted fs-12 mb-0 mt-2 border-top pt-2">{help}</p>}
            </CardBody>
        </Card>
    );
};

// ── Bloco ────────────────────────────────────────────────────────────────────

export default function RestaurantMarketingBlock({ companyId, recommendations }: {
    companyId: number;
    /** Recomendações já carregadas pela página (para o contador do separador); evita pedi-las duas vezes. */
    recommendations?: RecommendationsState;
}) {
    const months = useMemo(lastMonths, []);
    const [month, setMonth] = useState(months[0]);
    const [data, setData] = useState<RestaurantMarketing | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);

    useEffect(() => {
        if (!companyId) return;
        let alive = true;
        setLoading(true);
        setError(false);
        getRestaurantMarketing(companyId, month === months[0] ? undefined : month)
            .then((r: any) => { if (alive) setData(r?.data ?? null); })
            .catch(() => { if (alive) setError(true); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [companyId, month, months]);

    const insights = useMemo(() => (data ? buildInsights(data) : []), [data]);
    const seasonal = data ? usesSeasonalityWarning(data) : false;
    const monthOptions = useMemo(
        () => months.map((m, i) => ({ value: m, label: `${capitalize(monthLong(m))}${i === 0 ? " (mês em curso)" : ""}` })),
        [months],
    );

    // Secção sem cartão à volta: título sobre o fundo da página e os cartões
    // diretamente por baixo (nunca branco sobre branco).
    const header = (
        // Sem título: está dentro do separador "Marketing e resultados", que já o diz.
        <DashboardSectionHeader subtitle="Como estão a empresa e o marketing no mês escolhido.">
            <div style={{ minWidth: 240 }}>
                <Select
                    styles={reactSelectTheme}
                    menuPortalTarget={document.body}
                    options={monthOptions}
                    value={monthOptions.find((o) => o.value === month) ?? monthOptions[0]}
                    onChange={(o: any) => o && setMonth(o.value)}
                    isSearchable={false}
                    isDisabled={loading}
                    aria-label="Mês"
                />
            </div>
        </DashboardSectionHeader>
    );

    if (loading && !data) {
        return (
            <section className="mb-4">
                {header}
                <Card className="mb-0"><CardBody className="text-center py-4"><Spinner color="primary" size="sm" /></CardBody></Card>
            </section>
        );
    }
    if (error || !data) {
        return (
            <section className="mb-4">
                {header}
                <Card className="mb-0"><CardBody>
                    <p className="text-muted mb-0">Não foi possível carregar o resumo de marketing. Tente novamente dentro de momentos.</p>
                </CardBody></Card>
            </section>
        );
    }

    const m = data.metrics;
    const metaState = data.sources.meta.state;
    const ga4State = data.sources.ga4.state;
    const noMarketing = metaState === "not_connected" && ga4State === "not_connected";
    const metaBanner = metaState !== "ok" && metaState !== "not_connected" ? META_STATE_COPY[metaState] : undefined;

    const metaUnavailable = !m.meta_spend ? (
        <>
            {META_STATE_COPY[metaState]?.text ?? "Sem dados da Meta neste mês."}
            {META_STATE_COPY[metaState]?.cta && <div className="mt-1"><Link to={integrationsUrl(companyId)} className="fs-12">{META_STATE_COPY[metaState]?.cta}</Link></div>}
        </>
    ) : undefined;

    const ga4Unavailable = !m.ga4_sessions ? (
        <>
            {ga4State === "error" ? "Não foi possível ler o Google Analytics." : "O site ainda não está ligado (Google Analytics)."}
            <div className="mt-1"><Link to={integrationsUrl(companyId)} className="fs-12">{ga4State === "error" ? "Ver integrações" : "Ligar o Google Analytics"}</Link></div>
        </>
    ) : undefined;

    return (
        <section className="mb-4">
            {header}

            {data.period.days === 0 ? (
                <Alert color="info" className="mb-3 fs-13">
                    <i className="ri-calendar-line me-1" />
                    O mês acabou de começar. Os primeiros números aparecem amanhã, com o dia de hoje completo.
                </Alert>
            ) : (
                <>
                    {data.sources.internal.state === "no_data" && (
                        <Alert color="info" className="fs-13">
                            <i className="ri-information-line me-1" />
                            Ainda não há vendas sincronizadas para este mês.
                        </Alert>
                    )}
                    {metaBanner && (
                        <Alert color={metaBanner.color} className="d-flex flex-wrap align-items-center justify-content-between gap-2 fs-13 py-2">
                            <span><i className="ri-facebook-circle-line me-1" />{metaBanner.text}</span>
                            {metaBanner.cta && <Link to={integrationsUrl(companyId)} className={`btn btn-sm btn-${metaBanner.color === "info" ? "info" : "warning"}`}>{metaBanner.cta}</Link>}
                        </Alert>
                    )}

                    {/* ── INFORMAÇÃO: os 5 totais do mês ── */}
                    <Row className="g-3 mb-3">
                        <Col xs={12} sm={6} xl>
                            <MetricCard
                                label="Faturação" icon="ri-money-euro-circle-line" color="primary"
                                value={m.revenue ? eur0(m.revenue.total) : "Sem dados"}
                                help="O total faturado nas lojas (com IVA) no período, segundo o sistema de vendas. Por baixo, as pessoas servidas segundo as reservas."
                            >
                                <ComparisonBadge cmp={m.revenue?.comparison} />
                                {m.covers?.has_data && (
                                    <div className="mt-2 fs-13">
                                        <span className="text-body fw-medium">{int(m.covers.total)} pessoas</span>{" "}
                                        <ComparisonBadge cmp={m.covers.comparison} />
                                    </div>
                                )}
                            </MetricCard>
                        </Col>
                        <Col xs={12} sm={6} xl>
                            <MetricCard
                                label="Investimento Meta" icon="ri-facebook-circle-line" color="info"
                                value={m.meta_spend ? eur0(m.meta_spend.total) : undefined}
                                unavailable={metaUnavailable}
                                help="O dinheiro gasto em anúncios no Facebook e no Instagram no período. Os cliques são as vezes que alguém carregou num anúncio."
                            >
                                <ComparisonBadge cmp={m.meta_spend?.comparison} />
                                {m.meta_clicks && (
                                    <div className="mt-2 fs-13">
                                        <span className="text-body fw-medium">{int(m.meta_clicks.total)} cliques</span>{" "}
                                        <ComparisonBadge cmp={m.meta_clicks.comparison} />
                                    </div>
                                )}
                            </MetricCard>
                        </Col>
                        <Col xs={12} sm={6} xl>
                            <MetricCard
                                label="Visitas ao site" icon="ri-global-line" color="success"
                                value={m.ga4_sessions ? `${int(m.ga4_sessions.total)} sessões` : undefined}
                                unavailable={ga4Unavailable}
                                help="Quantas vezes o site foi visitado (sessões), e de onde vieram as visitas: anúncios pagos, redes sociais, pesquisa no Google ou acesso direto."
                            >
                                <ComparisonBadge cmp={m.ga4_sessions?.comparison} />
                                {m.ga4_sessions && m.ga4_sessions.total > 0 && (
                                    <div className="mt-2 d-flex flex-wrap gap-1">
                                        {([
                                            ["paid", "Pago"], ["organic_social", "Redes sociais"], ["search", "Pesquisa"], ["direct", "Direto"],
                                        ] as const).map(([k, l]) => (
                                            <span key={k} className="badge bg-light text-body fw-normal">
                                                {l} {Math.round((m.ga4_sessions!.by_group[k].total / m.ga4_sessions!.total) * 100)}%
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </MetricCard>
                        </Col>
                        <Col xs={12} sm={6} xl>
                            <MetricCard
                                label="Peso do marketing" icon="ri-pie-chart-2-line" color="warning"
                                value={m.marketing_weight && m.marketing_weight.value !== null
                                    ? `${m.marketing_weight.value.toLocaleString("pt-PT", { maximumFractionDigits: 1 })}%`
                                    : undefined}
                                unavailable={!m.marketing_weight || m.marketing_weight.value === null
                                    ? (m.meta_spend ? "Sem faturação no período para calcular." : "Precisa da Meta ligada para calcular.")
                                    : undefined}
                                help="Quanto o investimento na Meta representa da faturação: por cada 100 € faturados, quantos euros foram para anúncios. Não mede o efeito dos anúncios."
                            >
                                <ComparisonBadge cmp={m.marketing_weight?.comparison} points />
                            </MetricCard>
                        </Col>
                        <Col xs={12} sm={6} xl>
                            <MetricCard
                                label="Reservas e sem reserva" icon="ri-calendar-check-line" color="secondary"
                                value={m.reservations_mix && m.reservations_mix.reserved_share_pct !== null
                                    ? `${m.reservations_mix.reserved_share_pct.toLocaleString("pt-PT", { maximumFractionDigits: 1 })}% com reserva`
                                    : undefined}
                                unavailable={!m.reservations_mix || m.reservations_mix.reserved_share_pct === null ? "Sem dados de reservas neste mês." : undefined}
                                help="Das entradas registadas, quantas foram reservas feitas com antecedência e quantas chegaram sem reserva (walk-in)."
                            >
                                <ComparisonBadge cmp={m.reservations_mix?.comparison} points />
                                {m.reservations_mix && (
                                    <div className="mt-2 fs-12 text-muted">
                                        {int(m.reservations_mix.reserved)} reservas · {int(m.reservations_mix.walk_ins)} sem reserva
                                    </div>
                                )}
                            </MetricCard>
                        </Col>
                    </Row>

                    {/* ── INSIGHT + DADOS ── */}
                    <Row className="g-3 mb-3">
                        <Col lg={5}>
                            <Card className="mb-0 h-100">
                                <CardBody>
                                    <h6 className="text-uppercase text-muted fs-12 mb-3">O que aconteceu neste período</h6>
                                    {insights.length > 0 ? (
                                        <ul className="list-unstyled vstack gap-2 mb-0">
                                            {insights.map((s, i) => (
                                                <li key={i} className="d-flex gap-2 fs-14">
                                                    <i className="ri-checkbox-blank-circle-fill text-primary mt-2 flex-shrink-0" style={{ fontSize: 7 }} />
                                                    <span>{s}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <p className="text-muted mb-0">Ainda não há dados suficientes para resumir este período.</p>
                                    )}
                                    <p className="text-muted fs-12 mb-0 mt-3">
                                        <i className="ri-information-line me-1" />
                                        Os números aparecem lado a lado para comparar. Não indicam que uns causaram os outros.
                                    </p>
                                    {seasonal && (
                                        <p className="text-muted fs-12 mb-0 mt-1">
                                            <i className="ri-sun-cloudy-line me-1" />
                                            Algumas comparações usam o mês anterior e podem refletir a época do ano.
                                        </p>
                                    )}
                                </CardBody>
                            </Card>
                        </Col>
                        <Col lg={7}>
                            <Card className="mb-0 h-100">
                                <CardBody>
                                    <h6 className="text-uppercase text-muted fs-12 mb-2">Evolução diária</h6>
                                    <DailyChart data={data} />
                                </CardBody>
                            </Card>
                        </Col>
                    </Row>

                    {/* Sem GA4 nem Meta: painel suave sobre o que podem acrescentar. */}
                    {noMarketing && (
                        <Card className="mb-3">
                            <CardBody>
                                <h6 className="mb-1"><i className="ri-lightbulb-line me-1 text-warning" />O que o marketing digital pode acrescentar a esta vista</h6>
                                <p className="text-muted fs-13 mb-2">
                                    Ao ligar a Meta (Facebook e Instagram) e o Google Analytics, passa a ver aqui, ao lado das vendas e das reservas,
                                    quanto investe em anúncios e quantas pessoas visitam o site em cada mês.
                                </p>
                                <Link to={integrationsUrl(companyId)} className="btn btn-sm btn-outline-primary"><i className="ri-links-line me-1" />Configurar integrações</Link>
                            </CardBody>
                        </Card>
                    )}
                </>
            )}

            {/* ── Recomendações (motor de regras explicáveis), entre o Insight e os atalhos ── */}
            <RecommendationsCard companyId={companyId} vertical="restaurant" state={recommendations} />

        </section>
    );
}

// ── DADOS: série diária lado a lado (faturação, gasto Meta, sessões) ─────────

function DailyChart({ data }: { data: RestaurantMarketing }) {
    const m = data.metrics;
    const dates = (m.revenue?.series ?? m.meta_spend?.series ?? []).map((p) => p.date);
    if (dates.length === 0) {
        return <p className="text-muted mb-0">Sem dados diários para este período.</p>;
    }

    const series: { name: string; type: string; data: number[] }[] = [];
    const yaxis: ApexYAxis[] = [];
    const colors: string[] = [];
    const palette = getChartColorsArray('["--vz-primary", "--vz-info", "--vz-success"]');

    if (m.revenue) {
        series.push({ name: "Faturação (€)", type: "area", data: m.revenue.series.map((p) => p.value) });
        yaxis.push({ seriesName: "Faturação (€)", title: { text: "Faturação (€)" }, labels: { formatter: (v: number) => eur0(v) } });
        colors.push(palette[0]);
    }
    if (m.meta_spend) {
        series.push({ name: "Investimento Meta (€)", type: "line", data: m.meta_spend.series.map((p) => p.value) });
        yaxis.push({ seriesName: "Investimento Meta (€)", opposite: true, title: { text: "Meta (€)" }, labels: { formatter: (v: number) => eur0(v) } });
        colors.push(palette[1]);
    }
    if (m.ga4_sessions) {
        series.push({
            name: "Sessões no site",
            type: "line",
            data: m.ga4_sessions.series.map((p) => p.paid + p.organic_social + p.search + p.direct + p.other),
        });
        yaxis.push({ seriesName: "Sessões no site", opposite: true, title: { text: "Sessões" }, labels: { formatter: (v: number) => int(v) } });
        colors.push(palette[2]);
    }

    const options: ApexCharts.ApexOptions = {
        chart: { toolbar: { show: false }, zoom: { enabled: false }, parentHeightOffset: 0 },
        stroke: { width: series.map((s) => (s.type === "area" ? 2 : 2.5)), curve: "smooth" },
        fill: { type: series.map((s) => (s.type === "area" ? "gradient" : "solid")), gradient: { opacityFrom: 0.25, opacityTo: 0.02 } },
        colors,
        xaxis: {
            categories: dates.map((d) => Number(d.slice(8, 10)).toString()),
            labels: { style: { fontSize: "11px" } },
            title: { text: capitalize(monthLong(data.month)) },
        },
        yaxis,
        legend: { position: "top", horizontalAlign: "left", fontSize: "12px" },
        dataLabels: { enabled: false },
        grid: { borderColor: "var(--vz-border-color)", strokeDashArray: 3 },
        tooltip: { shared: true, x: { formatter: (_v: number, o: any) => `${dates[o?.dataPointIndex ?? 0]}` } },
    };

    return <ReactApexChart options={options} series={series} type="line" height={280} />;
}
