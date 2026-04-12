import { Col, Row } from "reactstrap";

type LeadRealityGap = {
    primary_gap_state?: string;
    secondary_gap_states?: string[];
    severity?: string;
    confidence?: number;
    confidence_reason?: string;
    message?: string;
    likely_failure_point?: string;
    estimated_real_contact_probability?: number;
    capture_gap_rate?: number | null;
    response_gap_rate?: number | null;
};

type Props = {
    gap?: LeadRealityGap | null;
};

export default function LeadRealityGapCard({ gap }: Props) {
    const primaryState = gap?.primary_gap_state ?? "healthy_flow";

    return (
        <div
            style={{
                border: `1px solid ${resolveGapBorder(primaryState)}`,
                borderRadius: 16,
                padding: "16px 18px",
                background: resolveGapBackground(primaryState),
            }}
        >
            <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                <div>
                    <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                        Lead Reality Gap
                    </p>
                    <h5 className="mb-1 fw-semibold">{resolveGapStateLabel(primaryState)}</h5>
                    <p className="text-muted fs-13 mb-0">
                        {gap?.message ?? "Sem leitura consolidada do gap entre interesse, captura e operação."}
                    </p>
                </div>
                <div className="d-flex gap-2 flex-wrap justify-content-end">
                    <span className={`badge fs-12 px-3 py-2 ${resolveGapBadge(primaryState)}`}>
                        {resolveSeverityLabel(gap?.severity)}
                    </span>
                    <span className="badge bg-light text-dark fs-12 px-3 py-2">
                        Confiança {gap?.confidence ?? 0}%
                    </span>
                </div>
            </div>

            <Row className="g-2 mb-3">
                <Col md={4} sm={6}>
                    <MetricCard label="Contacto estimado" value={`${gap?.estimated_real_contact_probability ?? 0}%`} helper="probabilidade inferida" />
                </Col>
                <Col md={4} sm={6}>
                    <MetricCard
                        label="Capture gap"
                        value={gap?.capture_gap_rate !== null && gap?.capture_gap_rate !== undefined ? `${gap.capture_gap_rate}%` : "—"}
                        helper="intenção forte vs lead"
                    />
                </Col>
                <Col md={4} sm={6}>
                    <MetricCard
                        label="Response gap"
                        value={gap?.response_gap_rate !== null && gap?.response_gap_rate !== undefined ? `${gap.response_gap_rate}%` : "—"}
                        helper="lead vs contacto"
                    />
                </Col>
            </Row>

            <div className="rounded-3 px-3 py-3 mb-3" style={{ background: "#ffffff" }}>
                <div className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                    Ponto provável de falha
                </div>
                <div className="fw-semibold fs-14 mb-1">{resolveFailurePointLabel(gap?.likely_failure_point ?? "healthy_flow")}</div>
                <div className="text-muted fs-12">{gap?.confidence_reason ?? "Sem justificação de confiança disponível."}</div>
            </div>

            {(gap?.secondary_gap_states?.length ?? 0) > 0 && (
                <div className="d-flex gap-2 flex-wrap">
                    {gap?.secondary_gap_states?.map((state) => (
                        <span key={state} className="badge bg-light text-dark fs-12 px-3 py-2">
                            {resolveGapStateLabel(state)}
                        </span>
                    ))}
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

function resolveGapStateLabel(state: string): string {
    return {
        no_real_interest: "Sem interesse real",
        decision_friction: "Interesse forte sem contacto",
        contact_capture_failure: "Provável falha de captura",
        no_response: "Lead capturada sem resposta",
        low_lead_quality: "Lead fraca ou pouco útil",
        tracking_gap: "Falha provável de tracking",
        healthy_flow: "Fluxo saudável",
    }[state] ?? state;
}

function resolveGapBadge(state: string): string {
    return {
        decision_friction: "bg-danger-subtle text-danger",
        contact_capture_failure: "bg-danger-subtle text-danger",
        no_response: "bg-danger-subtle text-danger",
        tracking_gap: "bg-warning-subtle text-warning",
        low_lead_quality: "bg-warning-subtle text-warning",
        healthy_flow: "bg-success-subtle text-success",
        no_real_interest: "bg-secondary-subtle text-secondary",
    }[state] ?? "bg-light text-dark";
}

function resolveGapBackground(state: string): string {
    return {
        decision_friction: "#fff7f7",
        contact_capture_failure: "#fff7f7",
        no_response: "#fff7f7",
        tracking_gap: "#fffaf3",
        low_lead_quality: "#fffaf3",
        healthy_flow: "#f8fffb",
    }[state] ?? "#ffffff";
}

function resolveGapBorder(state: string): string {
    return {
        decision_friction: "#fecaca",
        contact_capture_failure: "#fecaca",
        no_response: "#fecaca",
        tracking_gap: "#fed7aa",
        low_lead_quality: "#fed7aa",
        healthy_flow: "#bbf7d0",
    }[state] ?? "#e9ebec";
}

function resolveFailurePointLabel(point: string): string {
    return {
        traffic_quality: "Tráfego",
        decision: "Decisão",
        contact_capture: "Captura de contacto",
        operations: "Operação",
        lead_quality: "Qualidade do lead",
        tracking: "Tracking",
        healthy_flow: "Fluxo saudável",
    }[point] ?? point;
}

function resolveSeverityLabel(severity?: string): string {
    return {
        urgent: "Urgente",
        high: "Risco alto",
        medium: "Atenção",
        low: "Estável",
    }[severity ?? "low"] ?? "Estável";
}
