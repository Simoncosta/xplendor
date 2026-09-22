import React, { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Card, CardBody, Col, Container, Row, Spinner } from "reactstrap";
import ReactApexChart from "react-apexcharts";
import BreadCrumb from "Components/Common/BreadCrumb";
import getChartColorsArray from "Components/Common/ChartsDynamicColor";
import { getGa4Traffic } from "helpers/laravel_helper";
import {
    Ga4Traffic, CHANNEL_LABELS, DEVICE_LABELS, GENDER_LABELS, fmtDuration, fmtPct,
} from "common/models/ga4.model";

/**
 * XPLENDOR — Tráfego do site do cliente (GA4). Mostra a propriedade GA4 do
 * CLIENTE (o site dele), scoped por company_id. NÃO confundir com o GA4 da
 * própria app XPLENDOR (G-KMK84KG99K). Dados vêm cacheados do backend.
 */

const RANGES = [
    { days: 7, label: "7 dias" },
    { days: 28, label: "28 dias" },
    { days: 90, label: "90 dias" },
];

// Barra horizontal simples (Bootstrap .progress) — sem dependências de gráficos.
const Bar: React.FC<{ label: string; value: number; max: number; suffix?: string }> = ({ label, value, max, suffix }) => (
    <div className="mb-2">
        <div className="d-flex justify-content-between fs-12 mb-1">
            <span className="text-truncate me-2">{label}</span>
            <span className="fw-medium flex-shrink-0">{value.toLocaleString("pt-PT")}{suffix ?? ""}</span>
        </div>
        <div className="progress" style={{ height: 6 }}>
            <div className="progress-bar" style={{ width: `${max > 0 ? Math.round((value / max) * 100) : 0}%` }} />
        </div>
    </div>
);

const WebsiteTraffic = () => {
    document.title = "Tráfego do site | Xplendor";

    const companyId = useMemo(() => {
        const a = sessionStorage.getItem("authUser");
        return a ? Number(JSON.parse(a).company_id || 0) : 0;
    }, []);

    const [days, setDays] = useState(28);
    const [loading, setLoading] = useState(true);
    const [connected, setConnected] = useState(true);
    const [saEmail, setSaEmail] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [errorDetail, setErrorDetail] = useState<string | null>(null);
    const [traffic, setTraffic] = useState<Ga4Traffic | null>(null);
    const [refreshing, setRefreshing] = useState(false);

    const fetchTraffic = React.useCallback((fresh = false) => {
        if (!companyId) { setLoading(false); return; }
        if (fresh) setRefreshing(true); else setLoading(true);
        setError(null); setErrorDetail(null);
        getGa4Traffic(companyId, days, fresh)
            .then((r: any) => {
                const d = r?.data ?? {};
                setConnected(!!d.connected);
                setSaEmail(d.sa_email ?? null);
                setError(d.error ?? null);
                setErrorDetail(d.error_detail ?? null);
                setTraffic(d.traffic ?? null);
            })
            .catch(() => setError("Não foi possível carregar os dados."))
            .finally(() => { setLoading(false); setRefreshing(false); });
    }, [companyId, days]);

    useEffect(() => { fetchTraffic(false); }, [fetchTraffic]);

    const kpis = useMemo(() => {
        if (!traffic) return [];
        const o = traffic.overview;
        return [
            { label: "Visitantes", value: o.active_users.toLocaleString("pt-PT"), icon: "ri-user-3-line", color: "primary" },
            { label: "Novos visitantes", value: o.new_users.toLocaleString("pt-PT"), icon: "ri-user-add-line", color: "info" },
            { label: "Sessões", value: o.sessions.toLocaleString("pt-PT"), icon: "ri-loader-2-line", color: "secondary" },
            { label: "Páginas vistas", value: o.page_views.toLocaleString("pt-PT"), icon: "ri-pages-line", color: "success" },
            { label: "Duração média", value: fmtDuration(o.avg_session_duration), icon: "ri-time-line", color: "warning" },
            { label: "Envolvimento", value: fmtPct(o.engagement_rate), icon: "ri-heart-pulse-line", color: "danger" },
        ];
    }, [traffic]);

    const rangeToggle = (
        <div className="btn-group" role="group" aria-label="Intervalo">
            {RANGES.map((r) => (
                <button key={r.days} type="button" className={"btn btn-sm " + (days === r.days ? "btn-primary" : "btn-outline-primary")} onClick={() => setDays(r.days)}>
                    {r.label}
                </button>
            ))}
        </div>
    );

    return (
        <div className="page-content">
            <Container fluid>
                <BreadCrumb title="Tráfego do site" pageTitle="Análise" />

                {loading ? (
                    <div className="d-flex justify-content-center py-5"><Spinner color="primary" /></div>
                ) : !connected ? (
                    <Card><CardBody className="text-center py-5">
                        <div className="avatar-md mx-auto mb-3"><span className="avatar-title bg-light rounded fs-24" style={{ color: "#E37400" }}><i className="ri-bar-chart-box-line" /></span></div>
                        <h5 className="mb-2">Liga o Google Analytics</h5>
                        <p className="text-muted mb-1">Ainda não ligaste a propriedade GA4 do teu site.</p>
                        {saEmail && <p className="text-muted fs-13 mb-3">Adiciona <span className="fw-medium">{saEmail}</span> como Visualizador no teu GA4 e cola o ID da propriedade nas Integrações.</p>}
                        <Link to={`/companies/${companyId}`} className="btn btn-primary"><i className="ri-links-line me-1" />Ir às Integrações</Link>
                    </CardBody></Card>
                ) : error || !traffic ? (
                    <Card><CardBody className="text-center py-5">
                        <div className="avatar-md mx-auto mb-3"><span className="avatar-title bg-danger-subtle text-danger rounded fs-24"><i className="ri-error-warning-line" /></span></div>
                        <h5 className="mb-2">Não foi possível ler o GA4</h5>
                        <p className="text-muted mb-2">{error ?? "Tenta novamente mais tarde."}</p>
                        {errorDetail && (
                            <pre className="text-start d-inline-block bg-light text-danger p-2 rounded mb-3" style={{ maxWidth: 640, whiteSpace: "pre-wrap", fontSize: 12 }}>{errorDetail}</pre>
                        )}
                        <div>
                            <button className="btn btn-primary" onClick={() => fetchTraffic(true)} disabled={refreshing}>
                                {refreshing ? <><Spinner size="sm" className="me-1" /> A atualizar…</> : <><i className="ri-refresh-line me-1" />Forçar atualização (ignora cache)</>}
                            </button>
                        </div>
                    </CardBody></Card>
                ) : (
                    <>
                        <Row className="mb-3 align-items-center">
                            <Col><p className="text-muted mb-0 fs-13">{traffic.range.start} a {traffic.range.end}</p></Col>
                            <Col xs="auto" className="d-flex gap-2">
                                {rangeToggle}
                                <button className="btn btn-sm btn-outline-secondary" onClick={() => fetchTraffic(true)} disabled={refreshing} title="Ignorar cache e ler dados frescos">
                                    {refreshing ? <Spinner size="sm" /> : <i className="ri-refresh-line" />}
                                </button>
                            </Col>
                        </Row>

                        {/* KPIs */}
                        <Row className="g-4 mb-4">
                            {kpis.map((c) => (
                                <Col key={c.label} xs={6} lg={2}>
                                    <Card className="mb-0"><CardBody className="d-flex align-items-center gap-3">
                                        <span className="avatar-sm flex-shrink-0"><span className={`avatar-title bg-${c.color}-subtle text-${c.color} rounded fs-20`}><i className={c.icon} /></span></span>
                                        <div className="min-w-0">
                                            <div className="fs-18 fw-semibold text-truncate">{c.value}</div>
                                            <small className="text-muted">{c.label}</small>
                                        </div>
                                    </CardBody></Card>
                                </Col>
                            ))}
                        </Row>

                        <Row className="g-4 pb-5 mb-5">
                            {/* Tendência */}
                            <Col xl={8}>
                                <Card className="h-100 mb-0"><CardBody>
                                    <h6 className="mb-3 text-uppercase">Tendência</h6>
                                    <TrendChart trend={traffic.trend} />
                                </CardBody></Card>
                            </Col>

                            {/* Origens de tráfego */}
                            <Col xl={4}>
                                <Card className="h-100 mb-0"><CardBody>
                                    <h6 className="mb-3 text-uppercase">Origens de tráfego</h6>
                                    {traffic.traffic_sources.length === 0 ? <p className="text-muted fs-13 mb-0">Sem dados.</p> : (() => {
                                        const max = Math.max(...traffic.traffic_sources.map((s) => s.sessions), 1);
                                        return traffic.traffic_sources.map((s) => (
                                            <Bar key={s.channel} label={CHANNEL_LABELS[s.channel] ?? s.channel} value={s.sessions} max={max} />
                                        ));
                                    })()}
                                </CardBody></Card>
                            </Col>

                            {/* Páginas mais vistas */}
                            <Col xl={6}>
                                <Card className="h-100 mb-0"><CardBody>
                                    <h6 className="mb-3 text-uppercase">Páginas mais vistas</h6>
                                    {traffic.top_pages.length === 0 ? <p className="text-muted fs-13 mb-0">Sem dados.</p> : (
                                        <div className="table-responsive">
                                            <table className="table table-sm align-middle mb-0">
                                                <tbody>
                                                    {traffic.top_pages.map((p, i) => (
                                                        <tr key={i}>
                                                            <td className="text-truncate" style={{ maxWidth: 260 }}>
                                                                <div className="fw-medium text-truncate">{p.title || p.path}</div>
                                                                <small className="text-muted text-truncate d-block">{p.path}</small>
                                                            </td>
                                                            <td className="text-end fw-semibold">{p.views.toLocaleString("pt-PT")}</td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </CardBody></Card>
                            </Col>

                            {/* Dispositivos */}
                            <Col xl={3} md={6}>
                                <Card className="h-100 mb-0"><CardBody>
                                    <h6 className="mb-3 text-uppercase">Dispositivos</h6>
                                    {traffic.devices.length === 0 ? <p className="text-muted fs-13 mb-0">Sem dados.</p> : (() => {
                                        const max = Math.max(...traffic.devices.map((d) => d.sessions), 1);
                                        return traffic.devices.map((d) => (
                                            <Bar key={d.device} label={DEVICE_LABELS[d.device] ?? d.device} value={d.sessions} max={max} />
                                        ));
                                    })()}
                                </CardBody></Card>
                            </Col>

                            {/* Geografia */}
                            <Col xl={3} md={6}>
                                <Card className="h-100 mb-0"><CardBody>
                                    <h6 className="mb-3 text-uppercase">Localização</h6>
                                    {traffic.geo.length === 0 ? <p className="text-muted fs-13 mb-0">Sem dados.</p> : (
                                        <ul className="list-unstyled vstack gap-2 mb-0">
                                            {traffic.geo.map((g, i) => (
                                                <li key={i} className="d-flex justify-content-between fs-13">
                                                    <span className="text-truncate me-2">{g.city !== "(not set)" ? `${g.city}, ` : ""}{g.country}</span>
                                                    <span className="fw-medium flex-shrink-0">{g.sessions.toLocaleString("pt-PT")}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </CardBody></Card>
                            </Col>

                            {/* Demografia — BEST-EFFORT */}
                            <Col xl={12}>
                                <Card className="mb-0"><CardBody>
                                    <h6 className="mb-3 text-uppercase">Demografia</h6>
                                    {/* demografia — grelha interna arejada */}
                                    {!traffic.demographics.available ? (
                                        <div className="text-center text-muted py-3">
                                            <i className="ri-group-line fs-24 d-block mb-2" />
                                            <p className="mb-0 fs-13">
                                                {traffic.demographics.reason === "thresholded"
                                                    ? "Sem dados suficientes — o Google esconde a demografia quando o tráfego é reduzido (proteção de privacidade)."
                                                    : "Sem dados de demografia. Requer o Google Signals ativo e volume de tráfego suficiente."}
                                            </p>
                                        </div>
                                    ) : (
                                        <Row className="g-4">
                                            <Col md={6}>
                                                <p className="text-muted fs-12 text-uppercase mb-2">Faixa etária</p>
                                                {(() => {
                                                    const max = Math.max(...traffic.demographics.age.map((a) => a.users), 1);
                                                    return traffic.demographics.age.map((a) => <Bar key={a.bracket} label={a.bracket} value={a.users} max={max} />);
                                                })()}
                                                {traffic.demographics.age.length === 0 && <p className="text-muted fs-13 mb-0">Sem dados de idade.</p>}
                                            </Col>
                                            <Col md={6}>
                                                <p className="text-muted fs-12 text-uppercase mb-2">Género</p>
                                                {(() => {
                                                    const max = Math.max(...traffic.demographics.gender.map((g) => g.users), 1);
                                                    return traffic.demographics.gender.map((g) => <Bar key={g.gender} label={GENDER_LABELS[g.gender] ?? g.gender} value={g.users} max={max} />);
                                                })()}
                                                {traffic.demographics.gender.length === 0 && <p className="text-muted fs-13 mb-0">Sem dados de género.</p>}
                                            </Col>
                                        </Row>
                                    )}
                                </CardBody></Card>
                            </Col>
                        </Row>
                    </>
                )}
            </Container>
        </div>
    );
};

// Tendência — gráfico de linha/área ApexCharts (padrão Velzon): eixo X com os
// dias por baixo + tooltip no hover (visitantes e sessões desse dia), como o GA4.
// Datas em PT (dd/MM no eixo, dd/MM/aaaa no tooltip). Dados reais do GA4.
const TrendChart: React.FC<{ trend: { date: string; active_users: number; sessions: number }[] }> = ({ trend }) => {
    if (!trend.length) return <p className="text-muted fs-13 mb-0">Sem dados no período.</p>;

    const colors = getChartColorsArray('["--vz-primary","--vz-info"]');

    const series = [
        { name: "Visitantes", data: trend.map((t) => ({ x: new Date(t.date).getTime(), y: t.active_users })) },
        { name: "Sessões", data: trend.map((t) => ({ x: new Date(t.date).getTime(), y: t.sessions })) },
    ];

    const options: ApexCharts.ApexOptions = {
        chart: { type: "area", height: 320, toolbar: { show: false }, zoom: { enabled: false }, parentHeightOffset: 0 },
        colors,
        dataLabels: { enabled: false },
        stroke: { curve: "smooth", width: 2, dashArray: [0, 4] },
        fill: { type: "gradient", gradient: { shadeIntensity: 1, opacityFrom: 0.25, opacityTo: 0.03, stops: [0, 90] } },
        markers: { size: 0, hover: { size: 5 } },
        xaxis: {
            type: "datetime",
            labels: { format: "dd/MM", style: { fontSize: "11px", colors: "#878a99" } },
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: {
            labels: {
                style: { fontSize: "11px", colors: "#878a99" },
                formatter: (val) => Math.round(Number(val)).toLocaleString("pt-PT"),
            },
        },
        grid: { borderColor: "var(--vz-border-color)", strokeDashArray: 3, padding: { top: 0, right: 8 } },
        tooltip: {
            shared: true,
            x: { format: "dd/MM/yyyy" },
            y: { formatter: (val) => (val === null ? "—" : Number(val).toLocaleString("pt-PT")) },
        },
        legend: { show: true, position: "top", horizontalAlign: "right", fontSize: "12px" },
    };

    return <ReactApexChart options={options} series={series} type="area" height={320} />;
};

export default WebsiteTraffic;
