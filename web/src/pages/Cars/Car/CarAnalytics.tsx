import { useEffect, useMemo } from "react";
import { useDispatch, useSelector } from "react-redux";
import { useParams } from "react-router-dom";
import { Col, Container, Row } from "reactstrap";
import { createSelector } from "reselect";
import { ToastContainer } from "react-toastify";
import ReactApexChart from "react-apexcharts";

import { analyticsCar } from "slices/cars/thunk";

import CarAnalyticsHeader from "./components/CarAnalyticsHeader";
import CarPageNav from "./components/CarPageNav";
import CarAnalyticsKpiStrip from "./components/CarAnalyticsKpiStrip";

import {
    fmt, fmtDate, fmtTime,
    buildKpiItems,
    buildTrafficSources, buildDonutOptions,
    buildInteractions,
    channelLabels, channelColors,
    ipsClassBadge, timelineDesc,
} from "./helpers/CarAnalyticsData";

const sectionStyle = {
    padding: "16px 18px",
    border: "1px solid #e9ebec",
    borderRadius: "16px",
    background: "#fff",
};

const selectCarState = (state: any) => state.Car;
const selectCarAnalyticsViewModel = createSelector(
    [selectCarState],
    (carState) => ({
        carAnalytics: carState.data.carAnalytics,
        loading: carState.loading.analytics,
    })
);

export default function CarAnalytics() {
    document.title = "Analytics | Xplendor";

    const dispatch: any = useDispatch();
    const { id } = useParams();
    const { carAnalytics, loading } = useSelector(selectCarAnalyticsViewModel);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return;
        const { company_id } = JSON.parse(authUser);
        dispatch(analyticsCar({ companyId: company_id, id: Number(id) }));
    }, [dispatch, id]);

    const car = carAnalytics?.car;
    const m = carAnalytics?.metrics;
    const ai = car?.analyses?.analysis;
    const aiMeta = car?.analyses;
    const ips = carAnalytics?.potential_score;
    const perf = carAnalytics?.performance;
    const timeline = carAnalytics?.timeline || [];

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
    const perfTotals = perf?.totals;
    const perfChannels = useMemo(
        () => (perf?.by_channel || []).map((ch: any) => ({
            ...ch,
            label: channelLabels[ch.channel] || ch.channel,
            color: channelColors[ch.channel] || "#adb5bd",
        })),
        [perf?.by_channel]
    );

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
                        <CarPageNav active="analytics" />
                    </Col>
                </Row>

                <Row>
                    <Col>
                        <div className="d-grid gap-3">

                            <CarAnalyticsKpiStrip items={buildKpiItems(m)} />

                            <section style={sectionStyle}>
                                <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                                    <div>
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                            Gráfico principal
                                        </p>
                                        <h6 className="mb-0 fw-semibold">Distribuição de tráfego</h6>
                                    </div>
                                    <span className="text-muted fs-12">{totalTraffic} sessões monitorizadas</span>
                                </div>
                                {trafficSources.length === 0 ? (
                                    <div className="text-center py-4 text-muted">
                                        <i className="ri-pie-chart-line fs-1 d-block mb-2" />
                                        <p className="mb-0 fs-13">Ainda sem dados de tráfego</p>
                                    </div>
                                ) : (
                                    <Row className="align-items-center g-3">
                                        <Col lg={6}>
                                            <ReactApexChart
                                                options={donutOptions}
                                                series={trafficSources.map((i) => Number(i.total || 0))}
                                                type="donut"
                                                height={260}
                                            />
                                        </Col>
                                        <Col lg={6}>
                                            <div className="vstack gap-2">
                                                {trafficSources.map((item, idx) => {
                                                    const pct = totalTraffic > 0 ? ((item.total / totalTraffic) * 100).toFixed(1) : "0.0";
                                                    return (
                                                        <div key={idx} className="d-flex align-items-center justify-content-between fs-13 py-2 border-bottom">
                                                            <div className="d-flex align-items-center gap-2">
                                                                <span style={{ width: 8, height: 8, borderRadius: "50%", background: item.color, display: "inline-block", flexShrink: 0 }} />
                                                                <span>{item.label}</span>
                                                            </div>
                                                            <span className="fw-semibold">{item.total} <span className="text-muted fw-normal">({pct}%)</span></span>
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        </Col>
                                    </Row>
                                )}
                            </section>

                            <section style={sectionStyle}>
                                <p className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                                    Métricas resumidas
                                </p>
                                <div className="row g-2">
                                    <div className="col-lg-3 col-sm-6"><Metric label="Sessões" value={perfTotals?.total_sessions ?? 0} /></div>
                                    <div className="col-lg-3 col-sm-6"><Metric label="Leads" value={perfTotals?.total_leads ?? 0} /></div>
                                    <div className="col-lg-3 col-sm-6"><Metric label="WhatsApp" value={perfTotals?.total_whatsapp_clicks ?? 0} /></div>
                                    <div className="col-lg-3 col-sm-6"><Metric label="Conversão" value={perfTotals?.avg_conversion_rate ? `${perfTotals.avg_conversion_rate}%` : "—"} /></div>
                                </div>
                            </section>

                            <section style={sectionStyle}>
                                <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                                    <div>
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                            Tabela por canal
                                        </p>
                                        <h6 className="mb-0 fw-semibold">Performance consolidada</h6>
                                    </div>
                                    <span className="text-muted fs-12">{totalInteractions} interações totais</span>
                                </div>
                                <div className="table-responsive">
                                    <table className="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th className="ps-0">Canal</th>
                                                <th className="text-end">Sessões</th>
                                                <th className="text-end">Leads</th>
                                                <th className="text-end">WhatsApp</th>
                                                <th className="text-end">Interações</th>
                                                <th className="text-end">Investimento</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {perfChannels.map((ch: any, idx: number) => (
                                                <tr key={idx}>
                                                    <td className="ps-0">
                                                        <div className="d-flex align-items-center gap-2">
                                                            <span style={{ width: 8, height: 8, borderRadius: "50%", background: ch.color, display: "inline-block" }} />
                                                            <span className="fw-medium">{ch.label}</span>
                                                        </div>
                                                    </td>
                                                    <td className="text-end">{ch.total_sessions}</td>
                                                    <td className="text-end">{ch.total_leads}</td>
                                                    <td className="text-end">{ch.total_whatsapp_clicks}</td>
                                                    <td className="text-end">{ch.total_interactions}</td>
                                                    <td className="text-end">{ch.total_spend > 0 ? `€${fmt(ch.total_spend)}` : "—"}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </section>

                            <section style={sectionStyle}>
                                <h6 className="fs-13 fw-semibold mb-3">
                                    <i className="ri-time-line me-2 text-primary" />
                                    Timeline de actividade
                                </h6>
                                {!timeline.length ? (
                                    <div className="text-center py-4 text-muted bg-light-subtle rounded">
                                        <i className="ri-time-line fs-1 d-block mb-2" />
                                        <p className="fs-13 mb-0">Ainda sem histórico registado</p>
                                    </div>
                                ) : (
                                    <div className="vstack gap-2">
                                        {timeline.slice(0, 12).map((item: any, idx: number) => (
                                            <div key={idx} className="d-flex align-items-start gap-3" style={{ border: "1px dashed #e9ebec", borderRadius: "0.5rem", padding: "0.75rem", background: "#fff" }}>
                                                <div
                                                    className={`avatar-title rounded-circle bg-${item.color}-subtle text-${item.color}`}
                                                    style={{ width: 34, height: 34, display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0 }}
                                                >
                                                    <i className={`${item.icon} fs-16`} />
                                                </div>
                                                <div style={{ minWidth: 0 }}>
                                                    <div className="d-flex align-items-center gap-2 flex-wrap mb-1">
                                                        <span className="fw-semibold fs-13">{item.label}</span>
                                                        <span className="text-muted fs-12">{fmtDate(item.created_at)} às {fmtTime(item.created_at)}</span>
                                                    </div>
                                                    <p className="text-muted fs-12 mb-0">{timelineDesc(item)}</p>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </section>

                        </div>
                    </Col>
                </Row>

            </Container>
        </div>
    );
}

function Metric({ label, value }: { label: string; value: string | number }) {
    return (
        <div className="d-flex align-items-center justify-content-between px-3 py-3 rounded-3 bg-light-subtle">
            <span className="text-muted fs-13">{label}</span>
            <span className="fw-semibold">{value}</span>
        </div>
    );
}
