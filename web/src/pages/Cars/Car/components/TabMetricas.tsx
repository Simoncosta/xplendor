import { Col, Row, Progress } from "reactstrap";
import ReactApexChart from "react-apexcharts";

interface Props {
    trafficSources: any[];
    totalTraffic: number;
    donutOptions: any;
    interactions: any[];
    totalInteractions: number;
    insight: any;
    m: any;
    timeline: any[];
    perfTotals: any;
    perfChannels: any[];
    fmtDate: (d: string) => string;
    fmtTime: (d: string) => string;
    fmt: (n: number) => string;
    timelineDesc: (item: any) => string;
}

export default function TabMetricas({
    trafficSources, totalTraffic, donutOptions,
    interactions, totalInteractions,
    insight, m,
    timeline,
    perfTotals, perfChannels,
    fmtDate, fmtTime, fmt, timelineDesc,
}: Props) {
    return (
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
                        <ReactApexChart options={donutOptions} series={trafficSources.map((i) => i.total)} type="donut" height={260} />
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

            {/* Interactions */}
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
                            const pct = totalInteractions > 0 ? (item.total / totalInteractions) * 100 : 0;
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
                        <div key={String(l)} className="d-flex align-items-center gap-2" style={{ border: "1px dashed #e9ebec", borderRadius: "0.4rem", padding: "0.6rem 0.75rem", background: "#fff" }}>
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
                {!timeline?.length ? (
                    <div className="text-center py-4 text-muted">
                        <i className="ri-time-line fs-1 d-block mb-2" />
                        <p className="fs-13 mb-0">Ainda sem histórico registado</p>
                    </div>
                ) : (
                    <div style={{ maxHeight: 400, overflowY: "auto" }}>
                        <div className="timeline-2">
                            <div className="timeline-continue">
                                {timeline.map((item: any, idx: number) => (
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
                                                        <div className="avatar-sm flex-shrink-0">
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

            {/* Performance por Canal */}
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
                                        <td className="text-end"><span className={Number(ch.total_leads) > 0 ? "fw-semibold text-success" : "text-muted"}>{ch.total_leads}</span></td>
                                        <td className="text-end"><span className={Number(ch.total_whatsapp_clicks) > 0 ? "fw-semibold text-success" : "text-muted"}>{ch.total_whatsapp_clicks}</span></td>
                                        <td className="text-end"><span className={Number(ch.total_interactions) > 0 ? "fw-semibold text-info" : "text-muted"}>{ch.total_interactions}</span></td>
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
                                    <td className="text-end fw-semibold">{perfTotals.avg_conversion_rate > 0 ? `${perfTotals.avg_conversion_rate}%` : "—"}</td>
                                    <td className="text-end fw-semibold text-primary">{perfTotals.weighted_engagement_rate !== null ? `${perfTotals.weighted_engagement_rate}%` : "—"}</td>
                                    <td className="text-end fw-semibold">{Number(perfTotals.total_spend) > 0 ? `€${fmt(Number(perfTotals.total_spend))}` : "—"}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </Col>
            )}
        </Row>
    );
}