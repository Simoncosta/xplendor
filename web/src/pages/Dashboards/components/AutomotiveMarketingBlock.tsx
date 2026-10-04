import { useEffect, useMemo, useState, type ReactNode } from "react";
import { Link } from "react-router-dom";
import ReactApexChart from "react-apexcharts";
import Select from "react-select";
import { Alert, Card, CardBody, Col, Row, Spinner } from "reactstrap";
import getChartColorsArray from "Components/Common/ChartsDynamicColor";
import { getAutomotiveMarketing } from "helpers/laravel_helper";
import { reactSelectTheme } from "helpers/reactSelectStyles";
import type { AutoComparison, AutomotiveMarketing } from "common/models/automotiveMarketing.model";
import DashboardSectionHeader from "./DashboardSectionHeader";
import { buildAutoInsights, comparisonView, dayMonth, eur2, usesSeasonality, type ZeroNoun } from "./automotiveMarketingText";
import { eur0, int, monthLong, signedPct } from "./restaurantMarketingText";

/**
 * XPLENDOR — Dashboard do automóvel: separador "Marketing e resultados".
 *
 * Mostra lado a lado o que se investiu e atraiu (Meta, site) e o que aconteceu no
 * stand (leads, contactos diretos, vendas). Cada número diz contra o que está a
 * ser comparado; base 0 aparece em texto; sem comparação, diz-se porquê. As
 * frases são descritivas, nunca causais. Cartões sobre o fundo da página (sem
 * cartão dentro de cartão). Os números vêm do backend; aqui só se apresentam.
 */

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

// ── Etiqueta de comparação ───────────────────────────────────────────────────

const ComparisonLine = ({ cmp, zero }: { cmp: AutoComparison | null | undefined; zero: ZeroNoun }) => {
    const v = comparisonView(cmp, zero);
    if (v.kind === "none") {
        return <span className="badge bg-light text-muted fw-normal text-wrap text-start">{v.text}</span>;
    }
    const seasonal = v.seasonal && (
        <i className="ri-sun-cloudy-line text-muted" title="Comparação com o mês anterior: pode refletir a época do ano (sazonalidade)." aria-label="Pode refletir sazonalidade" />
    );
    if (v.kind === "zero") {
        return <span className="d-inline-flex align-items-center gap-1 flex-wrap fs-12 text-muted">{v.text}{seasonal}</span>;
    }
    const up = v.deltaPct >= 0;
    return (
        <span className="d-inline-flex align-items-center gap-1 flex-wrap fs-12">
            <span className={`fw-semibold ${up ? "text-success" : "text-danger"}`}>
                <i className={up ? "ri-arrow-up-line" : "ri-arrow-down-line"} />{signedPct(v.deltaPct)}
            </span>
            <span className="text-muted">{v.tag}</span>
            {seasonal}
        </span>
    );
};

// ── Cartão de métrica com "?" ────────────────────────────────────────────────

export const MetricCard = ({ label, icon, color, value, children, help, unavailable }: {
    label: string; icon: string; color: string; value?: string; children?: ReactNode; help: string; unavailable?: ReactNode;
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
                    <button type="button" className="btn btn-link btn-sm p-0 text-muted lh-1" onClick={() => setShowHelp((s) => !s)}
                        aria-expanded={showHelp} aria-label={`O que quer dizer: ${label}`} title="O que isto quer dizer?">
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

const SubLine = ({ children }: { children: ReactNode }) => <div className="mt-2 fs-13 d-flex flex-wrap align-items-center gap-1">{children}</div>;

// ── Bloco ────────────────────────────────────────────────────────────────────

export default function AutomotiveMarketingBlock({ companyId }: { companyId: number }) {
    const months = useMemo(lastMonths, []);
    const [month, setMonth] = useState(months[0]);
    const [data, setData] = useState<AutomotiveMarketing | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);

    useEffect(() => {
        if (!companyId) return;
        let alive = true;
        setLoading(true);
        setError(false);
        getAutomotiveMarketing(companyId, month === months[0] ? undefined : month)
            .then((r: any) => { if (alive) setData(r?.data ?? null); })
            .catch(() => { if (alive) setError(true); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [companyId, month, months]);

    const insights = useMemo(() => (data ? buildAutoInsights(data) : []), [data]);
    const seasonal = data ? usesSeasonality(data) : false;
    const monthOptions = useMemo(
        () => months.map((m, i) => ({ value: m, label: `${capitalize(monthLong(m))}${i === 0 ? " (mês em curso)" : ""}` })),
        [months],
    );

    const header = (
        <DashboardSectionHeader subtitle="O que se investiu e atraiu, e o que aconteceu no stand, no mês escolhido.">
            <div style={{ minWidth: 240 }}>
                <Select styles={reactSelectTheme} menuPortalTarget={document.body} options={monthOptions}
                    value={monthOptions.find((o) => o.value === month) ?? monthOptions[0]}
                    onChange={(o: any) => o && setMonth(o.value)} isSearchable={false} isDisabled={loading} aria-label="Mês" />
            </div>
        </DashboardSectionHeader>
    );

    if (loading && !data) {
        return <section className="mb-4">{header}<div className="text-center py-4"><Spinner color="primary" size="sm" /></div></section>;
    }
    if (error || !data) {
        return (
            <section className="mb-4">
                {header}
                <Alert color="light" className="fs-13">Não foi possível carregar o resumo de marketing. Tente novamente dentro de momentos.</Alert>
            </section>
        );
    }

    const m = data.metrics;
    const metaState = data.sources.meta.state;
    const ga4State = data.sources.ga4.state;
    const metaBanner = metaState !== "ok" && metaState !== "not_connected" ? META_STATE_COPY[metaState] : undefined;
    const utm = data.quality_signals.find((q) => q.code === "possible_missing_utm");

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

    const breakdown = m.meta_spend?.breakdown;

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
                    {/* Registo de visitas parado: os zeros podem não ser reais. */}
                    {data.sources.tracking.state === "stale" && (
                        <Alert color="warning" className="fs-13">
                            <i className="ri-error-warning-line me-1" />
                            <strong>Sem registos de visitas há {int(data.sources.tracking.days_without ?? 0)} dias</strong>
                            {data.sources.tracking.last_seen && <> (o último foi a {dayMonth(data.sources.tracking.last_seen)})</>}.
                            {" "}Os números de leads e contactos deste período podem estar incompletos. Confirme que o código de registo de visitas continua instalado no site.
                        </Alert>
                    )}
                    {data.sources.tracking.state === "no_data" && (
                        <Alert color="info" className="fs-13">
                            <i className="ri-information-line me-1" />
                            Ainda não há visitas registadas no site da empresa. Os números de leads e contactos aparecem quando o registo de visitas estiver ativo.
                        </Alert>
                    )}
                    {metaBanner && (
                        <Alert color={metaBanner.color} className="d-flex flex-wrap align-items-center justify-content-between gap-2 fs-13 py-2">
                            <span><i className="ri-facebook-circle-line me-1" />{metaBanner.text}</span>
                            {metaBanner.cta && <Link to={integrationsUrl(companyId)} className={`btn btn-sm btn-${metaBanner.color === "info" ? "info" : "warning"}`}>{metaBanner.cta}</Link>}
                        </Alert>
                    )}

                    {/* Aviso de qualidade: investimento sem leads pagas e com leads de social orgânico. */}
                    {utm && (
                        <Alert color="warning" className="d-flex flex-wrap align-items-start justify-content-between gap-2 fs-13">
                            <span>
                                <i className="ri-error-warning-line me-1" />
                                <strong>Possíveis anúncios sem parâmetros UTM.</strong>{" "}
                                No período houve {eur0(utm.meta_spend)} de investimento na Meta, nenhuma lead com origem paga e{" "}
                                {int(utm.organic_social_leads)} {utm.organic_social_leads === 1 ? "lead registada" : "leads registadas"} como redes sociais orgânicas.
                                Se os anúncios não levam os parâmetros de URL, as leads que trazem ficam registadas como orgânicas.
                                Sugerimos rever os parâmetros de URL dos anúncios (utm_source=meta, utm_medium=paid, ad_id).
                            </span>
                            <Link to="/meta-ads" className="btn btn-sm btn-warning flex-shrink-0">Abrir Meta / Anúncios</Link>
                        </Alert>
                    )}

                    {/* ── Os números do mês ── */}
                    <Row className="g-3 mb-3">
                        <Col xs={12} sm={6} xl={4}>
                            <MetricCard label="Leads" icon="ri-user-add-line" color="primary"
                                value={m.leads ? int(m.leads.total) : "Sem dados"}
                                help="Pedidos de contacto registados no site (formulários e pedidos de informação). As pagas são as que chegaram de anúncios com os parâmetros de URL corretos.">
                                <ComparisonLine cmp={m.leads?.comparison} zero="leads" />
                                {data.sources.tracking.state === "stale" && <div className="fs-12 text-warning mt-1">Registo de visitas parado: pode estar incompleto.</div>}
                                {m.leads && (
                                    <SubLine>
                                        <span className="text-body fw-medium">{int(m.leads.paid)} {m.leads.paid === 1 ? "paga" : "pagas"}</span>
                                        <ComparisonLine cmp={m.leads.paid_comparison} zero="leads pagas" />
                                    </SubLine>
                                )}
                            </MetricCard>
                        </Col>
                        <Col xs={12} sm={6} xl={4}>
                            <MetricCard label="Investimento Meta" icon="ri-facebook-circle-line" color="info"
                                value={m.meta_spend ? eur0(m.meta_spend.total) : undefined} unavailable={metaUnavailable}
                                help="O dinheiro gasto em anúncios no Facebook e no Instagram no período (valor reportado pela Meta, sem IVA). Por baixo, os cliques nos anúncios e para onde foi o investimento: anúncios com a etiqueta de uma viatura, anúncios de stock geral e anúncios com etiqueta inválida.">
                                <ComparisonLine cmp={m.meta_spend?.comparison} zero="investimento" />
                                {m.meta_spend && (
                                    <SubLine>
                                        <span className="text-body fw-medium">{int(m.meta_spend.clicks)} cliques</span>
                                        <ComparisonLine cmp={m.meta_spend.clicks_comparison} zero="cliques" />
                                    </SubLine>
                                )}
                                {breakdown && (
                                    <div className="mt-2 fs-12 text-muted">
                                        Por viatura {eur0(breakdown.by_car)}
                                        {breakdown.general_stock !== null && <> · Stock geral {eur0(breakdown.general_stock)}</>}
                                        {breakdown.unattributed !== null && breakdown.unattributed > 0 && <> · <span className="text-warning">Por atribuir {eur0(breakdown.unattributed)}</span></>}
                                    </div>
                                )}
                            </MetricCard>
                        </Col>
                        <Col xs={12} sm={6} xl={4}>
                            <MetricCard label="Visitas ao site" icon="ri-global-line" color="success"
                                value={m.ga4_sessions ? `${int(m.ga4_sessions.total)} visitas` : undefined} unavailable={ga4Unavailable}
                                help="Quantas vezes o site foi visitado (sessões no Google Analytics) e de onde vieram as visitas: anúncios pagos, redes sociais, pesquisa no Google ou acesso direto.">
                                <ComparisonLine cmp={m.ga4_sessions?.comparison} zero="visitas" />
                                {m.ga4_sessions && m.ga4_sessions.total > 0 && (
                                    <div className="mt-2 d-flex flex-wrap gap-1">
                                        {([["paid", "Pago"], ["organic_social", "Redes sociais"], ["search", "Pesquisa"], ["direct", "Direto"]] as const).map(([k, l]) => (
                                            <span key={k} className="badge bg-light text-body fw-normal">
                                                {l} {Math.round((m.ga4_sessions!.by_group[k].total / m.ga4_sessions!.total) * 100)}%
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </MetricCard>
                        </Col>
                        <Col xs={12} sm={6} xl={4}>
                            <MetricCard label="Custo por lead pago" icon="ri-price-tag-3-line" color="warning"
                                value={m.paid_cpl?.state === "ok" && m.paid_cpl.value !== null ? eur2(m.paid_cpl.value) : undefined}
                                unavailable={!m.paid_cpl
                                    ? "Precisa da Meta ligada para calcular."
                                    : m.paid_cpl.state === "spend_without_lead"
                                        ? <><span className="text-body fw-medium">Gasto sem lead</span><div className="fs-12 mt-1">{eur0(m.paid_cpl.spend)} investidos e nenhuma lead com origem paga no período.</div></>
                                        : m.paid_cpl.state === "no_spend" ? "Sem investimento na Meta no período." : undefined}
                                help="O investimento na Meta a dividir pelas leads com origem paga. Sem leads pagas, não há divisão: mostra-se o gasto sem lead.">
                                <ComparisonLine cmp={m.paid_cpl?.comparison} zero="leads pagas" />
                                {m.paid_cpl && <div className="mt-2 fs-12 text-muted">{eur0(m.paid_cpl.spend)} ÷ {int(m.paid_cpl.paid_leads)} {m.paid_cpl.paid_leads === 1 ? "lead paga" : "leads pagas"}</div>}
                            </MetricCard>
                        </Col>
                        <Col xs={12} sm={6} xl={4}>
                            <MetricCard label="Contactos diretos" icon="ri-whatsapp-line" color="secondary"
                                value={m.contacts ? int(m.contacts.total) : "Sem dados"}
                                help="Contactos feitos diretamente a partir do site: cliques no WhatsApp, chamadas e vezes que o número de telefone foi mostrado ou copiado.">
                                <ComparisonLine cmp={m.contacts?.comparison} zero="contactos" />
                                {data.sources.tracking.state === "stale" && <div className="fs-12 text-warning mt-1">Registo de visitas parado: pode estar incompleto.</div>}
                                {m.contacts && (
                                    <div className="mt-2 fs-12 text-muted">
                                        WhatsApp {int(m.contacts.by_type.whatsapp)} · Chamada {int(m.contacts.by_type.call)} · Telefone {int(m.contacts.by_type.phone_reveal)}
                                    </div>
                                )}
                            </MetricCard>
                        </Col>
                        <Col xs={12} sm={6} xl={4}>
                            <MetricCard label="Vendas do mês" icon="ri-car-line" color="primary"
                                value={m.sales ? `${int(m.sales.count)} ${m.sales.count === 1 ? "venda" : "vendas"}` : "Sem dados"}
                                help="As viaturas vendidas no período, só como contexto. São poucas por mês para uma comparação com significado, por isso não se mostra variação.">
                                {m.sales && (
                                    <div className="fs-12 text-muted">
                                        {m.sales.revenue > 0 && <>{eur0(m.sales.revenue)} faturados · </>}
                                        só contexto, sem comparação
                                        {m.sales.without_value > 0 && <> · {int(m.sales.without_value)} sem valor registado</>}
                                    </div>
                                )}
                            </MetricCard>
                        </Col>
                    </Row>

                    {/* ── Frases + gráfico diário ── */}
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
                </>
            )}

        </section>
    );
}

// ── Série diária: leads, investimento e visitas lado a lado ──────────────────

function DailyChart({ data }: { data: AutomotiveMarketing }) {
    const m = data.metrics;
    const dates = (m.leads?.series ?? []).map((p) => p.date);
    if (dates.length === 0) return <p className="text-muted mb-0">Sem dados diários para este período.</p>;

    const palette = getChartColorsArray('["--vz-primary", "--vz-info", "--vz-success", "--vz-warning"]');
    const narrow = typeof window !== "undefined" && window.innerWidth < 576;
    const maxLabels = narrow ? 6 : 16;
    const labelStep = Math.max(1, Math.ceil(dates.length / maxLabels));
    const series: { name: string; type: string; data: number[] }[] = [];
    const yaxis: ApexYAxis[] = [];
    const colors: string[] = [];

    if (m.leads) {
        series.push({ name: "Leads", type: "column", data: m.leads.series.map((p) => p.total) });
        yaxis.push({ seriesName: "Leads", title: { text: "Leads e contactos" }, labels: { formatter: (v: number) => int(v) } });
        colors.push(palette[0]);
    }
    if (m.contacts) {
        // Contactos diretos (WhatsApp, chamada, telefone) no mesmo eixo das leads.
        series.push({ name: "Contactos diretos", type: "line", data: m.contacts.series.map((p) => p.value) });
        yaxis.push({ seriesName: "Leads", show: false, labels: { formatter: (v: number) => int(v) } });
        colors.push(palette[3]);
    }
    if (m.meta_spend) {
        series.push({ name: "Investimento Meta (€)", type: "line", data: m.meta_spend.series.map((p) => p.spend) });
        // Em ecrãs estreitos, os eixos da direita escondem-se (os valores ficam na dica).
        yaxis.push({ seriesName: "Investimento Meta (€)", opposite: true, show: !narrow, title: { text: "Meta (€)" }, labels: { formatter: (v: number) => eur0(v) } });
        colors.push(palette[1]);
    }
    if (m.ga4_sessions) {
        series.push({ name: "Visitas ao site", type: "line", data: m.ga4_sessions.series.map((p) => p.paid + p.organic_social + p.search + p.direct + p.other) });
        yaxis.push({ seriesName: "Visitas ao site", opposite: true, show: !narrow, title: { text: "Visitas" }, labels: { formatter: (v: number) => int(v) } });
        colors.push(palette[2]);
    }

    const options: ApexCharts.ApexOptions = {
        chart: { toolbar: { show: false }, zoom: { enabled: false }, parentHeightOffset: 0 },
        stroke: { width: series.map((s) => (s.type === "column" ? 0 : 2.5)), curve: "smooth" },
        plotOptions: { bar: { columnWidth: "55%", borderRadius: 2 } },
        colors,
        xaxis: {
            // Em ecrãs estreitos, só um dia em cada N no eixo (não se atropelam); a
            // dica ao passar o rato mostra sempre a data completa.
            categories: dates.map((d, i) => (i % labelStep === 0 ? Number(d.slice(8, 10)).toString() : "")),
            labels: { style: { fontSize: "11px" }, rotate: 0 },
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
