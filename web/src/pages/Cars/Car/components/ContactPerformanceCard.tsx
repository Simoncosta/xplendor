import { Col, Row } from "reactstrap";

type IntentDiagnostic = {
    primary_issue: string;
    message: string;
    confidence: number;
    confidence_reason?: string;
};

type RelativePerformance = {
    intent_vs_avg: string | null;
    conversion_vs_avg: string | null;
    status: string;
    sample_size?: number;
    has_sufficient_sample?: boolean;
};

type IntentAnalysis = {
    intent_score?: number;
    intent_level?: string;
    unique_visitors?: number;
    strong_intent_users?: number;
    strong_intent_users_logic?: string;
    whatsapp_clicks?: number;
    leads?: number;
    contact_efficiency?: number | null;
    contact_efficiency_confidence?: string;
    confidence_score?: number;
    diagnostic?: IntentDiagnostic | null;
    tags?: string[];
    relative_performance?: RelativePerformance | null;
    intent_distribution?: {
        very_high: number;
        high: number;
        medium: number;
        low: number;
    };
    avg_time_on_page?: number;
    avg_scroll?: number;
    sessions?: number;
};

type Props = {
    analysis?: IntentAnalysis | null;
};

export default function ContactPerformanceCard({ analysis }: Props) {
    const intentScore = Number(analysis?.intent_score ?? 0);
    const leads = Number(analysis?.leads ?? 0);
    const hotNoConversion = intentScore >= 75 && leads === 0;
    const tags = analysis?.tags ?? [];
    const diagnostic = analysis?.diagnostic;
    const relative = analysis?.relative_performance;
    const distribution = analysis?.intent_distribution;
    const hasBenchmark = Boolean(relative?.has_sufficient_sample);

    return (
        <div
            style={{
                border: hotNoConversion ? "1px solid #fdba74" : "1px solid #e9ebec",
                borderRadius: 16,
                padding: "16px 18px",
                background: hotNoConversion
                    ? "linear-gradient(180deg, #fffaf0 0%, #ffffff 100%)"
                    : "#fff",
                boxShadow: hotNoConversion
                    ? "0 10px 30px rgba(249, 115, 22, 0.10)"
                    : "none",
            }}
        >
            <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                <div>
                    <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                        Contact Performance
                    </p>
                    <h5 className="mb-1 fw-semibold">Intenção e eficiência de contacto</h5>
                    <p className="text-muted fs-13 mb-0">
                        Leitura de intenção real, tentativa de contacto e falha operacional sem depender do vendedor.
                    </p>
                </div>
                <div className="d-flex gap-2 flex-wrap justify-content-end">
                    <span className={`badge fs-12 px-3 py-2 ${resolveIntentLevelBadge(intentScore)}`}>
                        Intent score {intentScore}/100
                    </span>
                    {tags.map((tag) => (
                        <span key={tag} className={`badge fs-12 px-3 py-2 ${resolveTagBadge(tag)}`}>
                            {resolveTagLabel(tag)}
                        </span>
                    ))}
                </div>
            </div>

            <Row className="g-2 mb-3">
                <Col md={3} sm={6}>
                    <MetricCard label="Tentativas" value={analysis?.whatsapp_clicks ?? 0} helper="cliques em WhatsApp" />
                </Col>
                <Col md={3} sm={6}>
                    <MetricCard label="Utilizadores fortes" value={analysis?.strong_intent_users ?? 0} helper="intenção de contacto" />
                </Col>
                <Col md={3} sm={6}>
                    <MetricCard label="Visitantes únicos" value={analysis?.unique_visitors ?? 0} helper="base observada" />
                </Col>
                <Col md={3} sm={6}>
                    <MetricCard label="Leads" value={analysis?.leads ?? 0} helper="contacto real" />
                </Col>
                <Col md={3} sm={6}>
                    <MetricCard
                        label="Efficiency"
                        value={analysis?.contact_efficiency !== null && analysis?.contact_efficiency !== undefined
                            ? `${Number(analysis.contact_efficiency).toFixed(0)}%`
                            : "—"}
                        helper={`confiança ${resolveConfidenceLabel(analysis?.contact_efficiency_confidence)}`}
                    />
                </Col>
            </Row>

            <Row className="g-2 mb-3">
                <Col md={4} sm={6}>
                    <MetricCard label="Tempo médio" value={`${Number(analysis?.avg_time_on_page ?? 0).toFixed(0)}s`} helper="por visualização" />
                </Col>
                <Col md={4} sm={6}>
                    <MetricCard label="Scroll médio" value={`${Number(analysis?.avg_scroll ?? 0).toFixed(0)}%`} helper="profundidade" />
                </Col>
                <Col md={4} sm={6}>
                    <MetricCard label="Sessões" value={analysis?.sessions ?? 0} helper={`nível ${analysis?.intent_level ?? "low"}`} />
                </Col>
            </Row>

            <div className="rounded-3 px-3 py-3 mb-3" style={{ background: "#f8fafc" }}>
                <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
                    <span className="text-muted text-uppercase fw-semibold fs-11" style={{ letterSpacing: "0.08em" }}>
                        Diagnóstico
                    </span>
                    <div className="d-flex gap-2 flex-wrap">
                        <span className="fw-semibold fs-12" style={{ color: "#0f172a" }}>
                            Confiança {diagnostic?.confidence ?? 0}%
                        </span>
                        <span className="badge bg-light text-dark fs-11 px-2 py-1">
                            Score {analysis?.confidence_score ?? 0}/100
                        </span>
                    </div>
                </div>
                <p className="mb-0 fs-14 text-body">{diagnostic?.message ?? "Sem leitura disponível."}</p>
                {diagnostic?.confidence_reason && (
                    <p className="mb-0 mt-2 fs-12 text-muted">{diagnostic.confidence_reason}</p>
                )}
            </div>

            {distribution && (
                <div className="mb-3">
                    <p className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                        Intent Distribution
                    </p>
                    <div className="d-flex gap-2 flex-wrap">
                        <span className="badge bg-danger-subtle text-danger fs-12 px-3 py-2">Very high: {distribution.very_high}</span>
                        <span className="badge bg-warning-subtle text-warning fs-12 px-3 py-2">High: {distribution.high}</span>
                        <span className="badge bg-info-subtle text-info fs-12 px-3 py-2">Medium: {distribution.medium}</span>
                        <span className="badge bg-light text-dark fs-12 px-3 py-2">Low: {distribution.low}</span>
                    </div>
                </div>
            )}

            {hasBenchmark ? (
                <div className="d-flex gap-2 flex-wrap">
                    <span className="badge bg-light text-dark fs-12 px-3 py-2">
                        Intent vs média: {relative?.intent_vs_avg ?? "—"}
                    </span>
                    <span className="badge bg-light text-dark fs-12 px-3 py-2">
                        Conversão vs média: {relative?.conversion_vs_avg ?? "—"}
                    </span>
                    <span className={`badge fs-12 px-3 py-2 ${resolveRelativeStatusBadge(relative?.status)}`}>
                        {resolveRelativeStatusLabel(relative?.status)}
                    </span>
                    <span className="badge bg-light text-dark fs-12 px-3 py-2">
                        Amostra: {relative?.sample_size ?? 0} carros
                    </span>
                </div>
            ) : (
                <div className="d-flex gap-2 flex-wrap">
                    <span className="badge bg-light text-dark fs-12 px-3 py-2">
                        Benchmark indisponível
                    </span>
                    <span className="text-muted fs-12 align-self-center">
                        Ainda não há amostra suficiente para comparação fiável.
                    </span>
                </div>
            )}
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

function resolveIntentLevelBadge(intentScore: number): string {
    if (intentScore >= 75) return "bg-danger-subtle text-danger";
    if (intentScore >= 45) return "bg-warning-subtle text-warning";
    return "bg-secondary-subtle text-secondary";
}

function resolveTagBadge(tag: string): string {
    return {
        hot_no_conversion: "bg-danger-subtle text-danger",
    }[tag] ?? "bg-light text-dark";
}

function resolveTagLabel(tag: string): string {
    return {
        hot_no_conversion: "Hot No Conversion",
    }[tag] ?? tag;
}

function resolveRelativeStatusBadge(status?: string | null): string {
    return {
        underperforming_conversion: "bg-warning-subtle text-warning",
        outperforming: "bg-success-subtle text-success",
        low_intent: "bg-secondary-subtle text-secondary",
        aligned: "bg-light text-dark",
        insufficient_sample: "bg-light text-dark",
    }[status ?? "aligned"] ?? "bg-light text-dark";
}

function resolveRelativeStatusLabel(status?: string | null): string {
    return {
        underperforming_conversion: "Conversão abaixo da média",
        outperforming: "Acima da média",
        low_intent: "Intenção abaixo da média",
        aligned: "Alinhado com a média",
        insufficient_sample: "Amostra insuficiente",
    }[status ?? "aligned"] ?? "Alinhado com a média";
}

function resolveConfidenceLabel(level?: string | null): string {
    return {
        high: "alta",
        medium: "média",
        low: "baixa",
    }[level ?? "low"] ?? "baixa";
}
