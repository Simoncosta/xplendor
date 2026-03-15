import { useEffect, useState } from "react";
import CountUp from "react-countup";
import { useDispatch, useSelector } from "react-redux";
import { Link, useParams } from "react-router-dom";
import {
    Card, CardBody, CardHeader,
    Col, Container, Row, Progress,
} from "reactstrap";
import ReactApexChart from "react-apexcharts";
import { createSelector } from "reselect";
import { analyticsCar } from "slices/cars/thunk";
import XButton from "Components/Common/XButton";
import { carAiAnalyses } from "slices/car-ai-analises/thunk";
import { toast, ToastContainer } from "react-toastify";

// ─── tipos internos ────────────────────────────────────────────────────────────
type TabKey = "metricas" | "analise" | "viatura";

export default function CarAnalytics() {
    document.title = "Análises do Carro | Xplendor";

    const dispatch: any = useDispatch();
    const { id } = useParams();
    const [activeTab, setActiveTab] = useState<TabKey>("metricas");
    const [companyId, setCompanyId] = useState<number>(0);

    const selectCarState = (state: any) => state.Car;
    const carSelector = createSelector(selectCarState, (state: any) => ({
        carAnalytics: state.carAnalytics,
        loading: state.loading,
    }));
    const { carAnalytics, loading } = useSelector(carSelector);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (authUser) {
            const obj = JSON.parse(authUser);
            setCompanyId(Number(obj.company_id));
            dispatch(analyticsCar({ companyId: obj.company_id, id: Number(id) }));
        }
    }, [dispatch, id]);

    if (loading || !carAnalytics) return null;

    const handleGenerateAiAnalyses = () => {
        dispatch(carAiAnalyses({ companyId: companyId, carId: Number(id) }));
        toast("Gerando analises de viatura... Aguarde um pouco e recarregue a página.");
    };

    // ─── helpers ───────────────────────────────────────────────────────────────
    const fmt = (n: number) =>
        new Intl.NumberFormat("pt-PT", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);

    const fmtDate = (d: string) =>
        new Date(d).toLocaleDateString("pt-PT", { day: "2-digit", month: "short", year: "numeric" });

    const fmtTime = (d: string) =>
        new Date(d).toLocaleTimeString("pt-PT", { hour: "2-digit", minute: "2-digit" });

    const interestRateColor = (r: number) =>
        r >= 10 ? "text-success" : r >= 3 ? "text-warning" : "text-muted";

    const interestRateBadge = (r: number) =>
        r >= 10 ? "ri-arrow-up-line text-success" : r >= 3 ? "ri-subtract-line text-warning" : "ri-arrow-down-line text-muted";

    // ─── KPI items ─────────────────────────────────────────────────────────────
    const m = carAnalytics.metrics;
    const kpiItems = [
        { id: 1, label: "Views", counter: m.views, icon: "ri-eye-line", iconClass: "text-primary", suffix: "", decimals: 0, valueClass: "text-body", badge: "" },
        { id: 2, label: "Views 24h", counter: m.views_24h, icon: "ri-time-line", iconClass: m.views_24h > 0 ? "text-info" : "text-muted", suffix: "", decimals: 0, valueClass: "text-body", badge: "" },
        { id: 3, label: "Views 7 dias", counter: m.views_7d, icon: "ri-calendar-line", iconClass: m.views_7d > 0 ? "text-secondary" : "text-muted", suffix: "", decimals: 0, valueClass: "text-body", badge: "" },
        { id: 4, label: "Interações", counter: m.interactions, icon: "ri-cursor-line", iconClass: m.interactions > 0 ? "text-success" : "text-muted", suffix: "", decimals: 0, valueClass: m.interactions > 0 ? "text-success" : "text-body", badge: "" },
        { id: 5, label: "Leads", counter: m.leads, icon: "ri-user-follow-line", iconClass: m.leads > 0 ? "text-success" : "text-muted", suffix: "", decimals: 0, valueClass: m.leads > 0 ? "text-success" : "text-body", badge: "" },
        { id: 6, label: "Taxa de Interesse", counter: m.interest_rate, icon: "ri-line-chart-line", iconClass: interestRateColor(m.interest_rate), suffix: "%", decimals: 1, valueClass: interestRateColor(m.interest_rate), badge: interestRateBadge(m.interest_rate) },
    ];

    // ─── traffic sources ───────────────────────────────────────────────────────
    const channelLabels: Record<string, string> = {
        paid: "Tráfego Pago", direct: "Direto", organic_social: "Social Orgânico",
        organic_search: "Pesquisa Orgânica", referral: "Referência", email: "Email", utm: "Campanha UTM",
    };
    const channelColors: Record<string, string> = {
        paid: "#405189", direct: "#6c757d", organic_social: "#0ab39c",
        organic_search: "#198754", referral: "#f7b84b", email: "#6610f2", utm: "#fd7e14",
    };

    const trafficSources = [...(carAnalytics.traffic_sources || [])]
        .map((item: any) => ({
            key: item.channel, label: channelLabels[item.channel] || item.channel,
            total: Number(item.total || 0), color: channelColors[item.channel] || "#adb5bd",
        }))
        .sort((a, b) => b.total - a.total);

    const totalTraffic = trafficSources.reduce((s: number, i: any) => s + i.total, 0);

    const donutOptions: any = {
        chart: { type: "donut", toolbar: { show: false } },
        labels: trafficSources.map((i) => i.label),
        colors: trafficSources.map((i) => i.color),
        legend: { show: false },
        dataLabels: { enabled: false },
        stroke: { width: 0 },
        plotOptions: {
            pie: {
                donut: {
                    size: "72%",
                    labels: {
                        show: true,
                        name: { show: true, offsetY: 18 },
                        value: { show: true, fontSize: "22px", fontWeight: 700, offsetY: -10, formatter: (v: any) => parseInt(v) },
                        total: { show: true, label: "Views", fontSize: "14px", fontWeight: 500, formatter: () => totalTraffic },
                    },
                },
            },
        },
        tooltip: { y: { formatter: (v: any) => `${v} views (${totalTraffic > 0 ? ((v / totalTraffic) * 100).toFixed(1) : 0}%)` } },
    };

    // ─── interactions breakdown ────────────────────────────────────────────────
    const interactionLabels: Record<string, string> = {
        whatsapp_click: "WhatsApp", call_click: "Chamada", show_phone: "Mostrar Telefone",
        copy_phone: "Copiar Telefone", favorite: "Favorito", share: "Partilha",
        form_open: "Abrir Formulário", form_start: "Iniciar Formulário", location_view: "Ver Localização",
    };
    const interactionIcons: Record<string, string> = {
        whatsapp_click: "ri-whatsapp-line", call_click: "ri-phone-line", show_phone: "ri-smartphone-line",
        copy_phone: "ri-file-copy-line", favorite: "ri-heart-line", share: "ri-share-line",
        form_open: "ri-file-list-line", form_start: "ri-edit-line", location_view: "ri-map-pin-line",
    };
    const interactionColors: Record<string, string> = {
        whatsapp_click: "success", call_click: "info", show_phone: "warning",
        copy_phone: "secondary", favorite: "danger", share: "primary",
        form_open: "dark", form_start: "muted", location_view: "primary",
    };

    const interactions = [...(carAnalytics.interactions_breakdown || [])]
        .map((item: any) => ({
            key: item.interaction_type, label: interactionLabels[item.interaction_type] || item.interaction_type,
            total: Number(item.total || 0), icon: interactionIcons[item.interaction_type] || "ri-cursor-line",
            color: interactionColors[item.interaction_type] || "muted",
        }))
        .sort((a, b) => b.total - a.total);

    const totalInteractions = interactions.reduce((s, i) => s + i.total, 0);

    // ─── insight automático ────────────────────────────────────────────────────
    const buildInsight = (met: any) => {
        if (!met || met.views === 0)
            return { title: "Sem dados suficientes", text: "Este carro ainda não gerou tráfego suficiente.", rec: "Reforce a divulgação do anúncio.", icon: "ri-search-eye-line", color: "muted", bg: "bg-light" };
        if (met.views > 0 && met.interactions === 0)
            return { title: "Tráfego sem intenção", text: "Recebe visualizações, mas sem interações de contacto ainda.", rec: "Reveja preço, fotos e descrição. Se continuar, reforce a distribuição.", icon: "ri-eye-line", color: "warning", bg: "bg-warning-subtle" };
        if (met.interactions > 0 && met.leads === 0)
            return { title: "Interesse do mercado", text: "Está a gerar ações de contacto, mas ainda sem leads.", rec: "Acompanhe os contactos via WhatsApp e chamada. Este carro demonstra intenção comercial.", icon: "ri-cursor-line", color: "info", bg: "bg-info-subtle" };
        if (met.leads > 0)
            return { title: "Conversão registada", text: "Este carro está a converter visitas em leads.", rec: "Mantenha a promoção ativa e acompanhe rapidamente os contactos.", icon: "ri-checkbox-circle-line", color: "success", bg: "bg-success-subtle" };
        return { title: "Análise em curso", text: "Os dados estão a ser analisados.", rec: "Continue a acompanhar a evolução do anúncio.", icon: "ri-line-chart-line", color: "muted", bg: "bg-light" };
    };
    const insight = buildInsight(m);

    // ─── AI analyses ─────────────────────────────────────────────────────────
    const ai = carAnalytics.car?.analyses?.analysis;
    const aiMeta = carAnalytics.car?.analyses;

    const forecastOptions: any = {
        chart: { type: "area", toolbar: { show: false }, sparkline: { enabled: false } },
        dataLabels: { enabled: false },
        stroke: { curve: "smooth", width: 2 },
        fill: { type: "gradient", gradient: { opacityFrom: 0.4, opacityTo: 0.05 } },
        xaxis: { categories: ["Hoje", "7 dias", "14 dias", "30 dias"], labels: { style: { fontSize: "11px" } } },
        yaxis: { min: 0, max: 100, labels: { formatter: (v: any) => `${v}%`, style: { fontSize: "11px" } } },
        colors: ["#405189"],
        tooltip: { y: { formatter: (v: any) => `${v}% probabilidade` } },
        grid: { borderColor: "#f3f3f9", strokeDashArray: 4 },
        markers: { size: 4 },
    };

    // ─── timeline ─────────────────────────────────────────────────────────────
    const timelineDesc = (item: any) => {
        if (item.type === "car_created") return "O anúncio foi publicado na plataforma.";
        if (item.type === "view_group") {
            const parts = [];
            if (item.count) parts.push(item.count === 1 ? "1 visualização" : `${item.count} visualizações`);
            if (item.unique_visitors) parts.push(`${item.unique_visitors} visitante${item.unique_visitors > 1 ? "s" : ""} único${item.unique_visitors > 1 ? "s" : ""}`);
            return parts.join(" • ") || "Visualizações registadas.";
        }
        if (item.type === "interaction") return "Ação de intenção registada neste anúncio.";
        if (item.type === "lead") return "Lead gerada associada a este carro.";
        return "Atividade registada.";
    };

    // ─── car specs (from carAnalytics.car or raw car data) ────────────────────
    const car = carAnalytics.car;

    // ─── Performance (XPLDR-26) ───────────────────────────────────────────────
    const perf = carAnalytics.performance;
    const perfTotals = perf?.totals;
    const perfChannels = (perf?.by_channel || []).map((ch: any) => ({
        ...ch,
        label: channelLabels[ch.channel] || ch.channel,
        color: channelColors[ch.channel] || "#adb5bd",
    }));

    // ─── IPS (XPLDR-27) ───────────────────────────────────────────────────────
    const ips = carAnalytics.potential_score;

    const ipsScoreColor = (score: number) =>
        score >= 70 ? "#0ab39c" : score >= 40 ? "#f7b84b" : "#f06548";

    const ipsClassBadge = (cls: string) =>
        cls === "hot" ? "badge-soft-success" : cls === "warm" ? "badge-soft-warning" : "badge-soft-danger";

    const ipsFactorLabels: Record<string, { label: string; max: number; icon: string; color: string }> = {
        price_vs_market: { label: "Preço vs Mercado", max: 25, icon: "ri-price-tag-3-line", color: "primary" },
        engagement_rate: { label: "Engajamento", max: 20, icon: "ri-cursor-line", color: "info" },
        days_in_stock: { label: "Dias em Stock", max: 20, icon: "ri-time-line", color: "warning" },
        segment_demand: { label: "Procura do Segmento", max: 15, icon: "ri-bar-chart-line", color: "success" },
        listing_quality: { label: "Qualidade do Anúncio", max: 10, icon: "ri-image-line", color: "purple" },
        model_history: { label: "Histórico do Modelo", max: 10, icon: "ri-history-line", color: "secondary" },
    };

    const ipsRadialOptions: any = {
        chart: { type: "radialBar", sparkline: { enabled: true } },
        plotOptions: {
            radialBar: {
                startAngle: -90, endAngle: 90,
                track: { background: "#e9ebec", strokeWidth: "97%", margin: 5 },
                dataLabels: {
                    name: { show: false },
                    value: { offsetY: -2, fontSize: "28px", fontWeight: 700, formatter: (v: any) => `${v}`, color: "#212529" },
                },
                hollow: { size: "60%" },
            },
        },
        fill: { colors: [ips ? ipsScoreColor(ips.score) : "#e9ebec"] },
        stroke: { lineCap: "round" },
        labels: ["IPS"],
    };

    const ipsHistoryOptions: any = {
        chart: { type: "line", toolbar: { show: false }, sparkline: { enabled: true } },
        stroke: { curve: "smooth", width: 2 },
        colors: [ips ? ipsScoreColor(ips.score) : "#405189"],
        tooltip: { y: { formatter: (v: any) => `Score: ${v}` } },
        markers: { size: 3 },
    };

    // ── tab config ────────────────────────────────────────────────────────────
    const tabs: { key: TabKey; label: string; icon: string }[] = [
        { key: "metricas", label: "Métricas", icon: "ri-bar-chart-line" },
        { key: "analise", label: "Análise IA", icon: "ri-cpu-line" },
        { key: "viatura", label: "Viatura", icon: "ri-car-line" },
    ];

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>

                {/* ── Header ─────────────────────────────────────────────── */}
                <Row className="mb-3">
                    <Col>
                        <Card className="mb-0">
                            <CardBody className="py-3">
                                <div className="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                    <div>
                                        <h5 className="mb-1 fw-semibold">
                                            {car?.brand?.name} {car?.model?.name}
                                            {car?.version && (
                                                <span className="badge bg-primary-subtle text-primary ms-2 fw-medium" style={{ fontSize: "12px", verticalAlign: "middle" }}>
                                                    {car.version}
                                                </span>
                                            )}
                                        </h5>
                                        <div className="hstack gap-3 flex-wrap">
                                            <span className="text-muted fs-13">
                                                Preço: <span className="text-dark fw-semibold">€{fmt(car?.price_gross ?? car?.price)}</span>
                                            </span>
                                            <span className="vr" />
                                            <span className="text-muted fs-13">
                                                Publicado: <span className="text-dark fw-semibold">{fmtDate(car?.created_at)}</span>
                                            </span>
                                            {car?.license_plate && (
                                                <>
                                                    <span className="vr" />
                                                    <span className="text-muted fs-13">
                                                        Matrícula: <span className="text-dark fw-semibold">{car.license_plate}</span>
                                                    </span>
                                                </>
                                            )}
                                            {ai && (
                                                <>
                                                    <span className="vr" />
                                                    <span className={`badge ${aiMeta?.urgency_level === "Alta" ? "badge-soft-danger" : "badge-soft-warning"} rounded-pill`}>
                                                        <i className="ri-alarm-warning-line me-1" />
                                                        Urgência {aiMeta?.urgency_level}
                                                    </span>
                                                    {aiMeta?.price_alert && (
                                                        <span className="badge badge-soft-warning rounded-pill">
                                                            <i className="ri-price-tag-3-line me-1" />
                                                            Alerta de preço
                                                        </span>
                                                    )}
                                                </>
                                            )}
                                            {ips && (
                                                <span className={`badge ${ipsClassBadge(ips.classification)} rounded-pill`}>
                                                    <i className="ri-award-line me-1" />
                                                    IPS {ips.score}/100
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                    <div className="d-flex gap-2">
                                        <Link to={`/cars/${car?.id}`} className="btn btn-soft-primary btn-sm">
                                            <i className="ri-pencil-fill me-1" /> Editar viatura
                                        </Link>
                                    </div>
                                </div>
                            </CardBody>
                        </Card>
                    </Col>
                </Row>

                {/* ── KPI strip ─────────────────────────────────────────── */}
                <Row className="mb-3">
                    <Col>
                        <Card className="mb-0">
                            <CardBody className="p-0">
                                <Row className="g-0 row-cols-2 row-cols-sm-3 row-cols-md-6">
                                    {kpiItems.map((item, idx) => (
                                        <Col key={item.id}>
                                            <div
                                                className="py-3 px-3 h-100"
                                                style={{
                                                    borderRight: idx < kpiItems.length - 1 ? "1px solid #e9ebec" : "none",
                                                    borderBottom: "1px solid #e9ebec",
                                                }}
                                            >
                                                <p className="text-muted text-uppercase fs-12 mb-2 d-flex align-items-center justify-content-between">
                                                    {item.label}
                                                    {item.badge && <i className={`fs-16 ${item.badge}`} />}
                                                </p>
                                                <div className="d-flex align-items-center gap-2">
                                                    <i className={`${item.icon} ${item.iconClass} fs-24`} />
                                                    <h4 className={`mb-0 fw-semibold ${item.valueClass}`}>
                                                        <CountUp start={0} end={item.counter} suffix={item.suffix}
                                                            separator="," decimals={item.decimals} duration={1} />
                                                    </h4>
                                                </div>
                                            </div>
                                        </Col>
                                    ))}
                                </Row>
                            </CardBody>
                        </Card>
                    </Col>
                </Row>

                {/* ── Tabs ──────────────────────────────────────────────── */}
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

                                {/* ═══════════════════════════════════════════
                                    TAB 1 — MÉTRICAS
                                ═══════════════════════════════════════════ */}
                                {activeTab === "metricas" && (
                                    <Row className="g-3">

                                        {/* Traffic donut */}
                                        <Col xl={4} xxl={3}>
                                            <h6 className="fs-13 fw-semibold mb-3">
                                                <i className="ri-pie-chart-line me-2 text-primary" />
                                                Distribuição do Tráfego
                                            </h6>
                                            {trafficSources.length === 0 ? (
                                                <div className="text-center py-5 text-muted">
                                                    <i className="ri-pie-chart-line fs-1 d-block mb-2" />
                                                    <p className="mb-0 fs-13">Ainda sem dados de tráfego</p>
                                                </div>
                                            ) : (
                                                <>
                                                    <ReactApexChart
                                                        options={donutOptions}
                                                        series={trafficSources.map((i) => i.total)}
                                                        type="donut" height={260}
                                                    />
                                                    <div className="mt-3">
                                                        {trafficSources.map((item, idx) => {
                                                            const pct = totalTraffic > 0 ? ((item.total / totalTraffic) * 100).toFixed(1) : "0.0";
                                                            return (
                                                                <div key={idx} className="d-flex align-items-center justify-content-between mb-2 fs-13">
                                                                    <div className="d-flex align-items-center gap-2">
                                                                        <span style={{ width: 8, height: 8, borderRadius: "50%", background: item.color, display: "inline-block", flexShrink: 0 }} />
                                                                        <span className="text-muted">{item.label}</span>
                                                                    </div>
                                                                    <div>
                                                                        <span className="fw-semibold">{item.total}</span>
                                                                        <span className="text-muted ms-1">({pct}%)</span>
                                                                    </div>
                                                                </div>
                                                            );
                                                        })}
                                                    </div>
                                                </>
                                            )}
                                        </Col>

                                        {/* Interactions breakdown */}
                                        <Col xl={4} xxl={3}>
                                            <h6 className="fs-13 fw-semibold mb-3">
                                                <i className="ri-cursor-line me-2 text-success" />
                                                Interações
                                            </h6>
                                            {interactions.length === 0 ? (
                                                <div className="text-center py-5 text-muted">
                                                    <i className="ri-cursor-line fs-1 d-block mb-2" />
                                                    <p className="mb-0 fs-13">Ainda sem interações registadas</p>
                                                </div>
                                            ) : (
                                                <div className="vstack gap-3">
                                                    {interactions.map((item, idx) => {
                                                        const pct = totalInteractions > 0 ? ((item.total / totalInteractions) * 100) : 0;
                                                        return (
                                                            <div key={idx}>
                                                                <div className="d-flex align-items-center justify-content-between mb-1 fs-13">
                                                                    <div className="d-flex align-items-center gap-2">
                                                                        <i className={`${item.icon} text-${item.color} fs-16`} />
                                                                        <span>{item.label}</span>
                                                                    </div>
                                                                    <div>
                                                                        <span className="fw-semibold">{item.total}</span>
                                                                        <span className="text-muted ms-1">({pct.toFixed(1)}%)</span>
                                                                    </div>
                                                                </div>
                                                                <Progress color="primary" value={pct} style={{ height: "4px" }} />
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                            )}
                                        </Col>

                                        {/* Insight automático */}
                                        <Col xl={4} xxl={3}>
                                            <h6 className="fs-13 fw-semibold mb-3">
                                                <i className="ri-lightbulb-flash-line me-2 text-warning" />
                                                Insight Automático
                                            </h6>
                                            <div className="text-center mb-3">
                                                <i className={`${insight.icon} text-${insight.color}`} style={{ fontSize: 48 }} />
                                                <h6 className="mt-2 mb-1 fw-semibold">{insight.title}</h6>
                                                <p className="text-muted fs-13 mb-0">{insight.text}</p>
                                            </div>
                                            <div className={`${insight.bg} rounded p-3 mb-3`}>
                                                <p className="fw-semibold fs-13 mb-1">Recomendação</p>
                                                <p className="text-muted fs-13 mb-0">{insight.rec}</p>
                                            </div>
                                            <div className="vstack gap-2">
                                                {[["Views", m.views], ["Interações", m.interactions], ["Leads", m.leads], ["Taxa de Interesse", `${m.interest_rate}%`]].map(([l, v]) => (
                                                    <div
                                                        key={String(l)}
                                                        className="d-flex align-items-center gap-2"
                                                        style={{
                                                            border: "1px dashed #e9ebec",
                                                            borderRadius: "0.4rem",
                                                            padding: "0.6rem 0.75rem",
                                                            background: "#fff",
                                                        }}
                                                    >
                                                        <span className="text-muted">{l}</span>
                                                        <span className="fw-semibold">{v}</span>
                                                    </div>
                                                ))}
                                            </div>
                                        </Col>

                                        {/* Timeline */}
                                        <Col xl={12} xxl={3}>
                                            <h6 className="fs-13 fw-semibold mb-3">
                                                <i className="ri-time-line me-2 text-info" />
                                                Timeline
                                            </h6>
                                            {!carAnalytics.timeline?.length ? (
                                                <div className="text-center py-4 text-muted">
                                                    <i className="ri-time-line fs-1 d-block mb-2" />
                                                    <p className="fs-13 mb-0">Ainda sem histórico registado</p>
                                                </div>
                                            ) : (
                                                <div style={{ maxHeight: 400, overflowY: "auto" }}>
                                                    <div className="timeline-2">
                                                        <div className="timeline-continue">
                                                            {carAnalytics.timeline.map((item: any, idx: number) => (
                                                                <Row className="timeline-right" key={idx}>
                                                                    <Col xs={12}>
                                                                        <p className="timeline-date text-muted fs-12">
                                                                            {fmtDate(item.created_at)} às {fmtTime(item.created_at)}
                                                                        </p>
                                                                    </Col>
                                                                    <Col xs={12}>
                                                                        <div className="timeline-box">
                                                                            <div className="timeline-text">
                                                                                <div className="d-flex align-items-start gap-3">
                                                                                    <div className={`avatar-sm flex-shrink-0`}>
                                                                                        <div className={`avatar-title rounded-circle bg-${item.color}-subtle text-${item.color}`}>
                                                                                            <i className={`${item.icon} fs-18`} />
                                                                                        </div>
                                                                                    </div>
                                                                                    <div>
                                                                                        <div className="d-flex align-items-center gap-2 mb-1">
                                                                                            <span className="fw-semibold fs-13">{item.label}</span>
                                                                                            {item.type === "view_group" && item.count && (
                                                                                                <span className="badge bg-primary-subtle text-primary">{item.count}</span>
                                                                                            )}
                                                                                        </div>
                                                                                        <p className="text-muted fs-12 mb-0">{timelineDesc(item)}</p>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </Col>
                                                                </Row>
                                                            ))}
                                                        </div>
                                                    </div>
                                                </div>
                                            )}
                                        </Col>

                                        {/* ── Performance por Canal (XPLDR-26) ── */}
                                        {perfTotals && (
                                            <Col xs={12}>
                                                <div className="d-flex align-items-center justify-content-between mb-3">
                                                    <h6 className="fs-13 fw-semibold mb-0">
                                                        <i className="ri-funds-line me-2 text-primary" />
                                                        Performance por Canal
                                                    </h6>
                                                    {perfTotals.weighted_engagement_rate !== null && (
                                                        <span className="badge badge-soft-primary fs-12">
                                                            Engajamento ponderado: {perfTotals.weighted_engagement_rate}%
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="table-responsive">
                                                    <table className="table table-borderless table-sm align-middle mb-0" style={{ fontSize: 13 }}>
                                                        <thead>
                                                            <tr style={{ borderBottom: "1px solid #e9ebec" }}>
                                                                <th className="text-muted fw-medium ps-0">Canal</th>
                                                                <th className="text-muted fw-medium text-end">Sessões</th>
                                                                <th className="text-muted fw-medium text-end">Leads</th>
                                                                <th className="text-muted fw-medium text-end">WhatsApp</th>
                                                                <th className="text-muted fw-medium text-end">Interações</th>
                                                                <th className="text-muted fw-medium text-end">Taxa Conv.</th>
                                                                <th className="text-muted fw-medium text-end">Eng. Ponderado</th>
                                                                <th className="text-muted fw-medium text-end">Investimento</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            {perfChannels.map((ch: any, idx: number) => (
                                                                <tr key={idx} style={{ borderBottom: "1px dashed #e9ebec" }}>
                                                                    <td className="ps-0">
                                                                        <div className="d-flex align-items-center gap-2">
                                                                            <span style={{ width: 8, height: 8, borderRadius: "50%", background: ch.color, display: "inline-block", flexShrink: 0 }} />
                                                                            <span className="fw-medium">{ch.label}</span>
                                                                            {ch.channel === "paid" && Number(ch.total_spend) === 0 && (
                                                                                <span className="badge badge-soft-warning" style={{ fontSize: 10 }}>Sem spend</span>
                                                                            )}
                                                                        </div>
                                                                    </td>
                                                                    <td className="text-end fw-semibold">{ch.total_sessions}</td>
                                                                    <td className="text-end">
                                                                        <span className={Number(ch.total_leads) > 0 ? "fw-semibold text-success" : "text-muted"}>
                                                                            {ch.total_leads}
                                                                        </span>
                                                                    </td>
                                                                    <td className="text-end">
                                                                        <span className={Number(ch.total_whatsapp_clicks) > 0 ? "fw-semibold text-success" : "text-muted"}>
                                                                            {ch.total_whatsapp_clicks}
                                                                        </span>
                                                                    </td>
                                                                    <td className="text-end">
                                                                        <span className={Number(ch.total_interactions) > 0 ? "fw-semibold text-info" : "text-muted"}>
                                                                            {ch.total_interactions}
                                                                        </span>
                                                                    </td>
                                                                    <td className="text-end">
                                                                        {Number(ch.avg_conversion_rate) > 0
                                                                            ? <span className="fw-semibold text-success">{Number(ch.avg_conversion_rate).toFixed(2)}%</span>
                                                                            : <span className="text-muted">—</span>}
                                                                    </td>
                                                                    <td className="text-end">
                                                                        {ch.weighted_engagement_rate > 0
                                                                            ? <span className="fw-semibold text-primary">{ch.weighted_engagement_rate}%</span>
                                                                            : <span className="text-muted">—</span>}
                                                                    </td>
                                                                    <td className="text-end">
                                                                        {Number(ch.total_spend) > 0
                                                                            ? <span className="fw-semibold">€{fmt(Number(ch.total_spend))}</span>
                                                                            : <span className="text-muted">—</span>}
                                                                    </td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                        <tfoot>
                                                            <tr style={{ borderTop: "2px solid #e9ebec" }}>
                                                                <td className="ps-0 fw-semibold">Total</td>
                                                                <td className="text-end fw-semibold">{perfTotals.total_sessions}</td>
                                                                <td className="text-end fw-semibold text-success">{perfTotals.total_leads}</td>
                                                                <td className="text-end fw-semibold text-success">{perfTotals.total_whatsapp_clicks}</td>
                                                                <td className="text-end fw-semibold text-info">{perfTotals.total_interactions}</td>
                                                                <td className="text-end fw-semibold">
                                                                    {perfTotals.avg_conversion_rate > 0 ? `${perfTotals.avg_conversion_rate}%` : "—"}
                                                                </td>
                                                                <td className="text-end fw-semibold text-primary">
                                                                    {perfTotals.weighted_engagement_rate !== null ? `${perfTotals.weighted_engagement_rate}%` : "—"}
                                                                </td>
                                                                <td className="text-end fw-semibold">
                                                                    {Number(perfTotals.total_spend) > 0 ? `€${fmt(Number(perfTotals.total_spend))}` : "—"}
                                                                </td>
                                                            </tr>
                                                        </tfoot>
                                                    </table>
                                                </div>
                                            </Col>
                                        )}
                                    </Row>
                                )}

                                {/* ═══════════════════════════════════════════
                                    TAB 2 — ANÁLISE IA
                                ═══════════════════════════════════════════ */}
                                {activeTab === "analise" && (
                                    <Row className="g-3">
                                        {/* IPS — Índice de Potencial de Venda (XPLDR-27) */}
                                        < Col md={4}>
                                            <h6 className="fs-13 fw-semibold mb-3">
                                                <i className="ri-award-line me-2 text-primary" />
                                                Índice de Potencial de Venda
                                            </h6>

                                            {ips ? (
                                                <>
                                                    {/* Score radial */}
                                                    <div className="text-center mb-2">
                                                        <ReactApexChart
                                                            options={ipsRadialOptions}
                                                            series={[ips.score]}
                                                            type="radialBar" height={200}
                                                        />
                                                        <div className="d-flex align-items-center justify-content-center gap-2 mt-1">
                                                            <span className={`badge rounded-pill fs-12 px-3 py-2 ${ipsClassBadge(ips.classification)}`}>
                                                                {ips.classification === "hot" ? "🔥 Hot" : ips.classification === "warm" ? "Warm" : "Cold"}
                                                            </span>
                                                            {ips.price_vs_market !== null && (
                                                                <span className={`badge rounded-pill fs-11 ${Number(ips.price_vs_market) < 0 ? "badge-soft-success" : "badge-soft-danger"}`}>
                                                                    {Number(ips.price_vs_market) < 0 ? "▼" : "▲"} {Math.abs(Number(ips.price_vs_market))}% vs mercado
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>

                                                    {/* Breakdown dos fatores */}
                                                    <div className="vstack gap-2 mt-3">
                                                        {Object.entries(ips.breakdown || {}).map(([key, pts]: any) => {
                                                            const factor = ipsFactorLabels[key];
                                                            if (!factor) return null;
                                                            const pct = Math.round((pts / factor.max) * 100);
                                                            return (
                                                                <div
                                                                    key={key}
                                                                    style={{ border: "1px dashed #e9ebec", borderRadius: "0.4rem", padding: "0.6rem 0.75rem", background: "#fff" }}
                                                                >
                                                                    <div className="d-flex align-items-center justify-content-between mb-1">
                                                                        <div className="d-flex align-items-center gap-2">
                                                                            <i className={`${factor.icon} text-${factor.color} fs-14`} />
                                                                            <span className="fs-12 fw-medium">{factor.label}</span>
                                                                        </div>
                                                                        <span className="fs-12 fw-semibold">
                                                                            {pts}<span className="text-muted fw-normal">/{factor.max}</span>
                                                                        </span>
                                                                    </div>
                                                                    <Progress
                                                                        color={pct >= 70 ? "success" : pct >= 40 ? "warning" : "danger"}
                                                                        value={pct}
                                                                        style={{ height: "4px" }}
                                                                    />
                                                                </div>
                                                            );
                                                        })}
                                                    </div>

                                                    {/* Histórico */}
                                                    <div className="mt-3">
                                                        <div className="d-flex align-items-center justify-content-between mb-2">
                                                            <span className="fs-12 fw-semibold text-muted text-uppercase">Histórico (90 dias)</span>
                                                            <span className="fs-11 text-muted">
                                                                {ips.history?.length || 0} cálculo{(ips.history?.length || 0) !== 1 ? "s" : ""}
                                                            </span>
                                                        </div>
                                                        {ips.history && ips.history.length > 1 ? (
                                                            <ReactApexChart
                                                                options={ipsHistoryOptions}
                                                                series={[{ name: "Score", data: ips.history.map((h: any) => h.score) }]}
                                                                type="line" height={80}
                                                            />
                                                        ) : (
                                                            <div style={{ border: "1px dashed #e9ebec", borderRadius: "0.4rem", padding: "0.6rem 0.75rem", background: "#fff" }}>
                                                                <p className="fs-12 text-muted mb-0 text-center">Histórico a construir — recalcula diariamente</p>
                                                            </div>
                                                        )}
                                                    </div>

                                                    {/* Recalcular */}
                                                    <div className="mt-3 text-end">
                                                        <span className="fs-11 text-muted me-2">
                                                            Calculado: {fmtDate(ips.calculated_at)}
                                                        </span>
                                                        <button
                                                            className="btn btn-soft-primary btn-sm"
                                                            onClick={() => {
                                                                const authUser = sessionStorage.getItem("authUser");
                                                                if (!authUser) return;
                                                                const { company_id } = JSON.parse(authUser);
                                                                fetch(`${process.env.REACT_APP_API_URL}/v1/companies/${company_id}/cars/${id}/potential-score/recalculate`, {
                                                                    method: "POST",
                                                                    headers: { Authorization: `Bearer ${JSON.parse(authUser).token}`, "Content-Type": "application/json" },
                                                                }).then(() => dispatch(analyticsCar({ companyId: company_id, id: Number(id) })));
                                                            }}
                                                        >
                                                            <i className="ri-refresh-line me-1" /> Recalcular
                                                        </button>
                                                    </div>
                                                </>
                                            ) : (
                                                <div className="text-center py-4 text-muted">
                                                    <i className="ri-award-line fs-1 d-block mb-2" />
                                                    <p className="fs-13 mb-2">Score ainda não calculado</p>
                                                    <button
                                                        className="btn btn-soft-primary btn-sm"
                                                        onClick={() => {
                                                            const authUser = sessionStorage.getItem("authUser");
                                                            if (!authUser) return;
                                                            const { company_id } = JSON.parse(authUser);
                                                            fetch(`${process.env.REACT_APP_API_URL}/companies/${company_id}/cars/${id}/potential-score/recalculate`, {
                                                                method: "POST",
                                                                headers: { Authorization: `Bearer ${JSON.parse(authUser).token}`, "Content-Type": "application/json" },
                                                            }).then(() => dispatch(analyticsCar({ companyId: company_id, id: Number(id) })));
                                                        }}
                                                    >
                                                        <i className="ri-play-line me-1" /> Calcular agora
                                                    </button>
                                                </div>
                                            )}
                                        </Col>

                                        {ai ? (
                                            <>
                                                {/* Público-alvo + argumentos */}
                                                <Col md={4}>
                                                    <h6 className="fs-13 fw-semibold mb-3">
                                                        <i className="ri-user-heart-line me-2 text-info" />
                                                        Público-Alvo
                                                    </h6>
                                                    {ai.publico_alvo && (
                                                        <div className="vstack gap-2">
                                                            {[
                                                                { icon: "ri-calendar-2-line", color: "primary", label: "Faixa etária", val: ai.publico_alvo.faixa_etaria },
                                                                { icon: "ri-men-line", color: "info", label: "Género", val: ai.publico_alvo.genero_predominante },
                                                                { icon: "ri-briefcase-4-line", color: "success", label: "Perfil profissional", val: ai.publico_alvo.perfil_profissional },
                                                                { icon: "ri-trophy-line", color: "warning", label: "Estilo de vida", val: ai.publico_alvo.estilo_de_vida },
                                                                { icon: "ri-search-eye-line", color: "danger", label: "Comportamento", val: ai.publico_alvo.comportamento_de_compra },
                                                            ].map((row, idx) => (
                                                                <div
                                                                    key={idx}
                                                                    className="d-flex align-items-start gap-2"
                                                                    style={{
                                                                        border: "1px dashed #e9ebec",
                                                                        borderRadius: "0.4rem",
                                                                        padding: "0.6rem 0.75rem",
                                                                        background: "#fff",
                                                                    }}
                                                                >
                                                                    <div
                                                                        className={`avatar-title rounded-circle bg-${row.color}-subtle text-${row.color} flex-shrink-0`}
                                                                        style={{ width: 30, height: 30, fontSize: 14, display: "flex", alignItems: "center", justifyContent: "center", marginTop: 2 }}
                                                                    >
                                                                        <i className={row.icon} />
                                                                    </div>
                                                                    <div style={{ minWidth: 0, flex: 1 }}>
                                                                        <p className="fs-11 text-muted mb-0">{row.label}</p>
                                                                        <p className="fs-13 fw-medium mb-0" style={{ whiteSpace: "normal", wordBreak: "break-word" }}>{row.val}</p>
                                                                    </div>
                                                                </div>
                                                            ))}
                                                        </div>
                                                    )}

                                                    <h6 className="fs-13 fw-semibold mt-4 mb-2">
                                                        <i className="ri-checkbox-circle-line me-2 text-success" />
                                                        Argumentos de Venda
                                                    </h6>
                                                    <div className="vstack gap-2">
                                                        {(ai.argumentos_de_venda || []).map((arg: string, idx: number) => (
                                                            <div
                                                                key={idx}
                                                                className="d-flex align-items-center gap-2 fs-13"
                                                                style={{
                                                                    border: "1px dashed #e9ebec",
                                                                    borderRadius: "0.4rem",
                                                                    padding: "0.6rem 0.75rem",
                                                                    background: "#fff",
                                                                }}
                                                            >
                                                                <i className="ri-check-line text-success fs-16 flex-shrink-0" />
                                                                {arg}
                                                            </div>
                                                        ))}
                                                    </div>
                                                </Col>

                                                {/* Previsão + canais + copy */}
                                                <Col md={4}>
                                                    <h6 className="fs-13 fw-semibold mb-3">
                                                        <i className="ri-line-chart-line me-2 text-success" />
                                                        Previsão de Venda
                                                    </h6>
                                                    <Row className="g-2 mb-3">
                                                        {[
                                                            { label: "7 dias", val: ai.previsao?.probabilidade_venda_7d, color: "danger" },
                                                            { label: "14 dias", val: ai.previsao?.probabilidade_venda_14d, color: "warning" },
                                                            { label: "30 dias", val: ai.previsao?.probabilidade_venda_30d, color: "success" },
                                                        ].map((p, idx) => (
                                                            <Col xs={4} key={idx}>
                                                                <div className={`bg-${p.color}-subtle rounded text-center p-2`}>
                                                                    <div className={`fs-18 fw-bold text-${p.color}`}>{p.val}%</div>
                                                                    <div className="fs-11 text-muted">{p.label}</div>
                                                                </div>
                                                            </Col>
                                                        ))}
                                                    </Row>
                                                    <div
                                                        className="mt-3 p-3 rounded"
                                                        style={{ background: "#f8f9fa", border: "1px dashed #e9ebec", fontSize: 12 }}
                                                    >
                                                        <p className="fw-semibold text-muted text-uppercase mb-2" style={{ fontSize: 11, letterSpacing: "0.5px" }}>
                                                            <i className="ri-information-line me-1" />
                                                            O que significa este score?
                                                        </p>
                                                        <p className="text-muted mb-2">
                                                            Probabilidade estimada de venda desta viatura com base no preço vs mercado, engajamento, dias em stock e histórico do modelo.
                                                        </p>
                                                        <div className="vstack gap-1">
                                                            <div className="d-flex align-items-center gap-2">
                                                                <span className="badge badge-soft-danger text-dark" style={{ fontSize: 10, minWidth: 48 }}>7 dias</span>
                                                                <span className="text-muted">Probabilidade imediata — sem ajustes</span>
                                                            </div>
                                                            <div className="d-flex align-items-center gap-2">
                                                                <span className="badge badge-soft-warning text-dark" style={{ fontSize: 10, minWidth: 48 }}>14 dias</span>
                                                                <span className="text-muted">Com pequenos ajustes de preço ou distribuição</span>
                                                            </div>
                                                            <div className="d-flex align-items-center gap-2">
                                                                <span className="badge badge-soft-success text-dark" style={{ fontSize: 10, minWidth: 48 }}>30 dias</span>
                                                                <span className="text-muted">Potencial máximo com otimização ativa</span>
                                                            </div>
                                                        </div>
                                                        {ai.previsao?.condicao && (
                                                            <div className="mt-2 pt-2" style={{ borderTop: "1px dashed #e9ebec" }}>
                                                                <p className="text-muted mb-0" style={{ fontSize: 11, fontStyle: "italic" }}>
                                                                    <i className="ri-lightbulb-line me-1 text-warning" />
                                                                    {ai.previsao.condicao}
                                                                </p>
                                                            </div>
                                                        )}
                                                    </div>
                                                    <ReactApexChart
                                                        options={forecastOptions}
                                                        series={[{ name: "Probabilidade", data: [0, ai.previsao?.probabilidade_venda_7d, ai.previsao?.probabilidade_venda_14d, ai.previsao?.probabilidade_venda_30d] }]}
                                                        type="area" height={140}
                                                    />

                                                    <h6 className="fs-13 fw-semibold mt-4 mb-3">
                                                        <i className="ri-megaphone-line me-2 text-primary" />
                                                        Canais Recomendados
                                                    </h6>
                                                    {[
                                                        { data: ai.canal_principal, badge: "Principal", badgeClass: "bg-primary-subtle text-primary", accentColor: "#405189" },
                                                        { data: ai.canal_secundario, badge: "Secundário", badgeClass: "bg-info-subtle text-info", accentColor: "#299cdb" },
                                                    ].map((c, idx) => c.data && (
                                                        <div
                                                            key={idx}
                                                            className="mb-2"
                                                            style={{
                                                                border: "1px dashed #e9ebec",
                                                                borderLeft: `3px solid ${c.accentColor}`,
                                                                borderRadius: "0.4rem",
                                                                padding: "0.65rem 0.75rem",
                                                                background: "#fff",
                                                            }}
                                                        >
                                                            <div className="d-flex align-items-center justify-content-between mb-1">
                                                                <span className="fw-semibold fs-13">{c.data.canal}</span>
                                                                <span className={`badge ${c.badgeClass} fs-11`}>{c.badge}</span>
                                                            </div>
                                                            <p className="text-muted fs-12 mb-0">{c.data.justificacao}</p>
                                                        </div>
                                                    ))}

                                                    {ai.sugestao_conteudo && (
                                                        <>
                                                            <h6 className="fs-13 fw-semibold mt-3 mb-2">
                                                                <i className="ri-quill-pen-line me-2 text-warning" />
                                                                Copy Sugerido
                                                            </h6>
                                                            <div className="vstack gap-2">
                                                                {[
                                                                    { label: "Título do anúncio", val: ai.sugestao_conteudo.titulo_anuncio, bold: true, italic: false },
                                                                    { label: "Hook de vídeo", val: ai.sugestao_conteudo.hook_video, bold: false, italic: true },
                                                                    { label: "Copy curto", val: ai.sugestao_conteudo.copy_curto, bold: false, italic: false },
                                                                ].map((item, idx) => (
                                                                    <div
                                                                        key={idx}
                                                                        style={{
                                                                            border: "1px dashed #e9ebec",
                                                                            borderRadius: "0.4rem",
                                                                            padding: "0.65rem 0.75rem",
                                                                            background: "#fff",
                                                                        }}
                                                                    >
                                                                        <p className="fs-11 text-primary fw-semibold text-uppercase mb-1">{item.label}</p>
                                                                        <p className={`fs-13 mb-0 ${item.bold ? "fw-semibold" : ""} ${item.italic ? "fst-italic text-muted" : ""}`}>
                                                                            {item.val}
                                                                        </p>
                                                                    </div>
                                                                ))}
                                                            </div>
                                                        </>
                                                    )}
                                                </Col>
                                            </>
                                        ) : (
                                            <Col md={8}>
                                                <div className="text-center py-5 text-muted">
                                                    <i className="ri-cpu-line fs-1 d-block mb-3" />
                                                    <h5>Análise IA ainda não disponível</h5>
                                                    <p className="mb-3 fs-13">A análise será gerada automaticamente assim que existirem dados suficientes.</p>
                                                    <XButton onClick={handleGenerateAiAnalyses}>
                                                        Gerar análise
                                                    </XButton>
                                                </div>
                                            </Col>
                                        )}
                                    </Row>
                                )}

                                {/* ═══════════════════════════════════════════
                                    TAB 3 — VIATURA
                                ═══════════════════════════════════════════ */}
                                {activeTab === "viatura" && (
                                    <Row className="g-3">

                                        {/* Specs técnicas */}
                                        <Col md={6} xl={4}>
                                            <h6 className="fs-13 fw-semibold mb-3">
                                                <i className="ri-settings-3-line me-2 text-primary" />
                                                Informação Técnica
                                            </h6>
                                            <div className="vstack gap-2">
                                                {[
                                                    { icon: "ri-oil-line", label: "Combustível", val: car?.fuel_type },
                                                    { icon: "ri-settings-2-line", label: "Caixa", val: car?.transmission },
                                                    { icon: "ri-flashlight-line", label: "Potência", val: car?.power_hp ? `${car.power_hp} cv` : null },
                                                    { icon: "ri-speed-up-line", label: "Cilindrada", val: car?.engine_capacity_cc ? `${car.engine_capacity_cc} cc` : null },
                                                    { icon: "ri-door-open-line", label: "Portas", val: car?.doors },
                                                    { icon: "ri-group-line", label: "Lugares", val: car?.seats },
                                                    { icon: "ri-palette-line", label: "Cor exterior", val: car?.exterior_color },
                                                    { icon: "ri-layout-grid-line", label: "Segmento", val: car?.segment },
                                                ].map((row, idx) => row.val && (
                                                    <div
                                                        key={idx}
                                                        className="d-flex align-items-center gap-2 fs-13"
                                                        style={{
                                                            border: "1px dashed #e9ebec",
                                                            borderRadius: "0.4rem",
                                                            padding: "0.55rem 0.75rem",
                                                            background: "#fff",
                                                        }}
                                                    >
                                                        <i className={`${row.icon} text-primary fs-16 flex-shrink-0`} style={{ width: 20 }} />
                                                        <span className="text-muted flex-grow-1">{row.label}</span>
                                                        <span className="fw-medium">{row.val}</span>
                                                    </div>
                                                ))}
                                            </div>
                                        </Col>

                                        {/* Estado & documentação */}
                                        <Col md={6} xl={4}>
                                            <h6 className="fs-13 fw-semibold mb-3">
                                                <i className="ri-file-shield-2-line me-2 text-success" />
                                                Estado & Documentação
                                            </h6>
                                            <div className="vstack gap-2">
                                                {[
                                                    { icon: "ri-calendar-2-line", label: "Registo", val: car?.registration_month && car?.registration_year ? `${car.registration_month}/${car.registration_year}` : null },
                                                    { icon: "ri-hashtag", label: "Matrícula", val: car?.license_plate },
                                                    { icon: "ri-shield-star-line", label: "Condição", val: car?.condition, badge: true },
                                                    { icon: "ri-key-2-line", label: "Chave extra", val: car?.has_spare_key ? "Sim" : "Não", badge: true, bColor: car?.has_spare_key ? "success" : "danger" },
                                                    { icon: "ri-book-open-line", label: "Livro revisões", val: car?.has_manuals ? "Sim" : "Não", badge: true, bColor: car?.has_manuals ? "success" : "danger" },
                                                    { icon: "ri-truck-line", label: "Origem", val: car?.origin },
                                                    { icon: "ri-global-line", label: "Quilometragem", val: car?.mileage_km ? `${car.mileage_km.toLocaleString("pt-PT")} km` : null },
                                                ].map((row, idx) => row.val != null && (
                                                    <div
                                                        key={idx}
                                                        className="d-flex align-items-center gap-2 fs-13"
                                                        style={{
                                                            border: "1px dashed #e9ebec",
                                                            borderRadius: "0.4rem",
                                                            padding: "0.55rem 0.75rem",
                                                            background: "#fff",
                                                        }}
                                                    >
                                                        <i className={`${row.icon} text-success fs-16 flex-shrink-0`} style={{ width: 20 }} />
                                                        <span className="text-muted flex-grow-1">{row.label}</span>
                                                        {(row as any).badge
                                                            ? <span className={`badge badge-soft-${(row as any).bColor || "secondary"}`}>{row.val}</span>
                                                            : <span className="fw-medium">{row.val}</span>}
                                                    </div>
                                                ))}
                                            </div>
                                        </Col>

                                        {/* Preço + descrição */}
                                        <Col xl={4}>
                                            <h6 className="fs-13 fw-semibold mb-3">
                                                <i className="ri-price-tag-3-line me-2 text-warning" />
                                                Preço & Notas
                                            </h6>
                                            <div className="bg-primary-subtle rounded p-3 mb-3 text-center">
                                                <p className="text-muted fs-12 mb-1">Preço de venda</p>
                                                <div className="fs-24 fw-bold text-primary">€{fmt(car?.price_gross)}</div>
                                            </div>

                                            {car?.description_website_pt && (
                                                <>
                                                    <h6 className="fs-13 fw-semibold mb-2">Descrição</h6>
                                                    <div
                                                        className="fs-13 text-muted bg-light rounded p-3"
                                                        style={{ maxHeight: 280, overflowY: "auto", lineHeight: 1.7 }}
                                                        dangerouslySetInnerHTML={{ __html: car.description_website_pt }}
                                                    />
                                                </>
                                            )}
                                        </Col>

                                        {/* Imagens */}
                                        {car?.images?.length > 0 && (
                                            <Col xs={12}>
                                                <h6 className="fs-13 fw-semibold mb-3">
                                                    <i className="ri-image-line me-2 text-info" />
                                                    Imagens ({car.images.length})
                                                </h6>
                                                <div className="d-flex flex-wrap gap-2">
                                                    {car.images.map((img: any) => (
                                                        <img
                                                            key={img.id}
                                                            src={`${process.env.REACT_APP_PUBLIC_URL ?? ""}${img.image}`}
                                                            alt=""
                                                            style={{ width: 100, height: 70, objectFit: "cover", borderRadius: 6, border: img.is_primary ? "2px solid #405189" : "1px solid #e9ebec", cursor: "pointer" }}
                                                        />
                                                    ))}
                                                </div>
                                            </Col>
                                        )}

                                    </Row>
                                )}

                            </CardBody>
                        </Card>
                    </Col>
                </Row>

            </Container>
        </div >
    );
}