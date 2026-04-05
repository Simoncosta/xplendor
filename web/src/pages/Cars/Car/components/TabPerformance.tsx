import { Col, Row } from "reactstrap";
import ReactApexChart from "react-apexcharts";
import CarAdCampaignMapper from "pages/Companies/CompanyProfile/components/CarAdCampaignMapper";

interface Props {
    companyId: number;
    carId: number;
    trafficSources: any[];
    totalTraffic: number;
    donutOptions: any;
    interactions: any[];
    totalInteractions: number;
    perfTotals: any;
    perfChannels: any[];
    fmt: (n: number) => string;
}

export default function TabPerformance({
    companyId,
    carId,
    trafficSources,
    totalTraffic,
    donutOptions,
    interactions,
    totalInteractions,
    perfTotals,
    perfChannels,
    fmt,
}: Props) {
    const safeTrafficSources = (trafficSources || []).map((item) => ({
        ...item,
        label: String(item?.label || "Desconhecido"),
        color: item?.color || "#adb5bd",
        total: Number(item?.total || 0),
    }));
    const safePerfChannels = (perfChannels || []).map((ch: any) => ({
        ...ch,
        label: String(ch?.label || "Desconhecido"),
        color: ch?.color || "#adb5bd",
        total_sessions: Number(ch?.total_sessions || 0),
        total_leads: Number(ch?.total_leads || 0),
        total_whatsapp_clicks: Number(ch?.total_whatsapp_clicks || 0),
        total_interactions: Number(ch?.total_interactions || 0),
        total_spend: Number(ch?.total_spend || 0),
    }));
    const safeTotalTraffic = Number(totalTraffic || 0);
    const safeTotalInteractions = Number(totalInteractions || 0);

    return (
        <div className="d-grid gap-3">
            <section style={sectionStyle}>
                <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Gráfico principal
                        </p>
                        <h6 className="mb-0 fw-semibold">Distribuição do tráfego</h6>
                    </div>
                    <span className="text-muted fs-12">{safeTotalTraffic} sessões monitorizadas</span>
                </div>
                {safeTrafficSources.length === 0 ? (
                    <div className="text-center py-4 text-muted">
                        <i className="ri-pie-chart-line fs-1 d-block mb-2" />
                        <p className="mb-0 fs-13">Este carro ainda não foi visto. Partilha o link ou cria um anúncio.</p>
                    </div>
                ) : (
                    <Row className="align-items-center g-3">
                        <Col lg={6}>
                            <ReactApexChart options={donutOptions} series={safeTrafficSources.map((i) => Number(i.total || 0))} type="donut" height={260} />
                        </Col>
                        <Col lg={6}>
                            <div className="vstack gap-2">
                                {safeTrafficSources.map((item, idx) => {
                                    const pct = safeTotalTraffic > 0 ? ((item.total / safeTotalTraffic) * 100).toFixed(1) : "0.0";
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
                    Leitura rápida
                </p>
                <div className="row g-2">
                    <div className="col-lg-3 col-sm-6"><Metric label="Sessões" value={perfTotals?.total_sessions ?? 0} /></div>
                    <div className="col-lg-3 col-sm-6"><Metric label="Leads" value={perfTotals?.total_leads ?? 0} /></div>
                    <div className="col-lg-3 col-sm-6"><Metric label="WhatsApp" value={perfTotals?.total_whatsapp_clicks ?? 0} /></div>
                    <div className="col-lg-3 col-sm-6"><Metric label="Envolvimento real" value={perfTotals?.avg_conversion_rate ? `${perfTotals.avg_conversion_rate}%` : "—"} /></div>
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
                    <span className="text-muted fs-12">{safeTotalInteractions} interações totais</span>
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
                            {safePerfChannels.map((ch: any, idx: number) => (
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
                <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Investimento activo
                        </p>
                        <h6 className="mb-0 fw-semibold">Campanhas ligadas a esta viatura</h6>
                    </div>
                </div>
                <CarAdCampaignMapper companyId={companyId} carId={carId} />
            </section>
        </div>
    );
}

const sectionStyle = {
    padding: "16px 18px",
    border: "1px solid #e9ebec",
    borderRadius: "16px",
    background: "#fff",
};

function Metric({ label, value }: { label: string; value: string | number }) {
    return (
        <div className="d-flex align-items-center justify-content-between px-3 py-3 rounded-3 bg-light-subtle">
            <span className="text-muted fs-13">{label}</span>
            <span className="fw-semibold">{value}</span>
        </div>
    );
}
