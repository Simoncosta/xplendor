import { Col, Row } from "reactstrap";

type SilentBuyerVisitor = {
    visitor_id: string;
    views_count: number;
    sessions_count: number;
    interactions_count: number;
    total_view_duration_seconds: number;
    intent_score: number;
    recommended_action: string;
    interaction_types: string[];
};

type SilentBuyerSummary = {
    visitors_count?: number;
    average_intent_score?: number;
    top_intent_score?: number;
    total_view_duration_seconds?: number;
    total_interactions?: number;
    recent_window_days?: number;
    recommended_action?: string;
    summary_text?: string;
    visitors?: SilentBuyerVisitor[];
};

type Props = {
    summary?: SilentBuyerSummary | null;
};

const formatDuration = (seconds?: number) => {
    const total = Number(seconds ?? 0);
    const minutes = Math.floor(total / 60);
    const remaining = total % 60;

    if (minutes <= 0) {
        return `${remaining}s`;
    }

    return `${minutes}m ${remaining}s`;
};

const maskVisitorId = (value: string) => {
    if (!value) return "-";
    return `${value.slice(0, 8)}...${value.slice(-4)}`;
};

export default function SilentBuyerIntentCard({ summary }: Props) {
    const visitors = summary?.visitors ?? [];
    const visitorsCount = summary?.visitors_count ?? 0;
    const averageIntentScore = summary?.average_intent_score ?? 0;
    const topIntentScore = summary?.top_intent_score ?? 0;
    const totalDuration = summary?.total_view_duration_seconds ?? 0;
    const totalInteractions = summary?.total_interactions ?? 0;
    const recommendedAction = summary?.recommended_action ?? "Monitorizar comportamento silencioso";
    const summaryText = summary?.summary_text ?? "Sem sinais suficientes de compradores silenciosos nesta viatura.";
    const recentWindowDays = summary?.recent_window_days ?? 30;

    return (
        <div style={{ border: "1px solid #e9ebec", borderRadius: 16, padding: "16px 18px", background: "#fff" }}>
            <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                <div>
                    <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                        Silent Buyer Detection
                    </p>
                    <h5 className="mb-1 fw-semibold">Intenção silenciosa nesta viatura</h5>
                    <p className="text-muted fs-13 mb-0">
                        Comportamento recorrente com interações relevantes e sem conversão formal nos últimos {recentWindowDays} dias.
                    </p>
                </div>
                <span className="badge bg-dark-subtle text-dark fs-12 px-3 py-2">{recommendedAction}</span>
            </div>

            <Row className="g-2 mb-3">
                <Col md={3} sm={6}>
                    <MetricCard label="Visitantes" value={visitorsCount} helper="sinais detetados" />
                </Col>
                <Col md={3} sm={6}>
                    <MetricCard label="Score médio" value={`${Number(averageIntentScore).toFixed(0)}/100`} helper="intenção silenciosa" />
                </Col>
                <Col md={3} sm={6}>
                    <MetricCard label="Top score" value={`${topIntentScore}/100`} helper="visitante mais quente" />
                </Col>
                <Col md={3} sm={6}>
                    <MetricCard label="Tempo acumulado" value={formatDuration(totalDuration)} helper={`${totalInteractions} interações`} />
                </Col>
            </Row>

            <div className="pb-3 mb-3 border-bottom">
                <p className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                    Leitura automática
                </p>
                <p className="mb-0 fs-14 text-body" style={{ lineHeight: 1.6 }}>
                    {summaryText}
                </p>
            </div>

            <div>
                <p className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                    Visitantes com maior intenção
                </p>
                {visitors.length > 0 ? (
                    <div className="vstack gap-2">
                        {visitors.slice(0, 4).map((visitor) => (
                            <div key={visitor.visitor_id} className="d-flex align-items-center justify-content-between flex-wrap gap-3 py-2 border-bottom">
                                <div>
                                    <h6 className="mb-1 fw-semibold">{maskVisitorId(visitor.visitor_id)}</h6>
                                    <p className="text-muted fs-12 mb-0">
                                        {visitor.views_count} views · {visitor.sessions_count} sessões · {visitor.interactions_count} interações · {formatDuration(visitor.total_view_duration_seconds)}
                                    </p>
                                </div>
                                <div className="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                                    <span className="badge bg-primary-subtle text-primary fs-12 px-3 py-2">
                                        {visitor.intent_score}/100
                                    </span>
                                    <span className="badge bg-light text-dark fs-12 px-3 py-2">
                                        {visitor.recommended_action}
                                    </span>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="py-2 text-muted">
                        Ainda não há visitantes suficientes com comportamento silencioso para destacar nesta viatura.
                    </div>
                )}
            </div>
        </div>
    );
}

function MetricCard({ label, value, helper }: { label: string; value: number | string; helper: string }) {
    return (
        <div className="px-3 py-3 h-100 rounded-3 bg-light-subtle">
            <p className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                {label}
            </p>
            <h4 className="mb-1 fw-semibold">{value}</h4>
            <p className="text-muted fs-12 mb-0">{helper}</p>
        </div>
    );
}
