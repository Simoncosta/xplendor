import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { Badge, Button } from "reactstrap";
import ActionList from "./ActionList";
import ActionExecutionModal from "./ActionExecutionModal";
import DecisionBadge, { getDecisionAccent } from "./DecisionBadge";
import { ActionCenterCarItem, ActionExecutionResponse, DecisionType, GuardrailAlert } from "../types";

interface CarDecisionCardProps {
    item: ActionCenterCarItem;
}

export default function CarDecisionCard({ item }: CarDecisionCardProps) {
    const navigate = useNavigate();
    const accent = getDecisionAccent(item.decision as DecisionType);
    const guardrails = item.guardrails ?? [];
    const intelligence = item.intelligence ?? null;
    const leadRealityGap = item.lead_reality_gap ?? null;
    const hasGuardrails = guardrails.length > 0;
    const isHotNoConversion = (intelligence?.intent_score ?? 0) >= 75 && (intelligence?.leads ?? 0) === 0;
    const isDecisionFriction = leadRealityGap?.primary_gap_state === "decision_friction";
    const isContactLoss = leadRealityGap?.primary_gap_state === "contact_capture_failure";
    const [isExecutionOpen, setIsExecutionOpen] = useState(false);
    const [isExecuting, setIsExecuting] = useState(false);
    const [lastExecution, setLastExecution] = useState<ActionExecutionResponse | null>(null);

    const handleExecuted = async (result: ActionExecutionResponse) => {
        setLastExecution(result);

        // Para WhatsApp, mantemos o modal aberto para permitir copiar/abrir o link.
        if (result.action === "notify_client_whatsapp") {
            return;
        }

        // 🔥 força refresh da página (MVP rápido e eficaz)
        window.location.reload();
    };

    return (
        <>
            <article
                style={{
                    border: isHotNoConversion
                        ? "1px solid #fdba74"
                        : (isDecisionFriction || isContactLoss)
                            ? "1px solid #fca5a5"
                            : (hasGuardrails ? "1px solid #fed7aa" : "1px solid #e9ebec"),
                    borderLeft: `4px solid ${accent}`,
                    borderRadius: 18,
                    background: isHotNoConversion
                        ? "linear-gradient(180deg, #fffaf0 0%, #ffffff 100%)"
                        : (isDecisionFriction || isContactLoss)
                            ? "linear-gradient(180deg, #fff7f7 0%, #ffffff 100%)"
                            : hasGuardrails
                                ? "linear-gradient(180deg, #ffffff 0%, #fffaf3 100%)"
                                : "#fff",
                    padding: 20,
                    boxShadow: isHotNoConversion
                        ? "0 10px 30px rgba(249, 115, 22, 0.12)"
                        : (isDecisionFriction || isContactLoss)
                            ? "0 10px 30px rgba(239, 68, 68, 0.10)"
                            : hasGuardrails
                                ? "0 10px 30px rgba(245, 158, 11, 0.10)"
                                : "0 10px 30px rgba(15, 23, 42, 0.04)",
                }}
            >
                <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                    <div>
                        <div className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Carro
                        </div>
                        <h5 className="mb-0 fw-semibold">{item.car_name}</h5>
                        {isHotNoConversion && (
                            <div className="d-flex gap-2 flex-wrap mt-2">
                                <Badge className="bg-danger-subtle text-danger border-0 px-2 py-1 fs-11">
                                    <i className="ri-fire-line me-1" />
                                    Hot No Conversion
                                </Badge>
                                {intelligence?.diagnostic?.primary_issue && (
                                    <Badge className="bg-warning-subtle text-warning border-0 px-2 py-1 fs-11">
                                        <i className="ri-alert-line me-1" />
                                        {resolveDiagnosticLabel(intelligence.diagnostic.primary_issue)}
                                    </Badge>
                                )}
                            </div>
                        )}
                        {(isDecisionFriction || isContactLoss) && (
                            <div className="d-flex gap-2 flex-wrap mt-2">
                                <Badge className="bg-danger-subtle text-danger border-0 px-2 py-1 fs-11">
                                    <i className="ri-alarm-warning-line me-1" />
                                    {isDecisionFriction ? "Decision Friction" : "Contact Capture Failure"}
                                </Badge>
                            </div>
                        )}
                        {hasGuardrails && (
                            <div className="d-flex gap-2 flex-wrap mt-2">
                                {guardrails.map((guardrail, index) => (
                                    <Badge
                                        key={`${guardrail.type}-${index}`}
                                        className={`${resolveGuardrailBadgeClass(guardrail)} border-0 px-2 py-1 fs-11`}
                                    >
                                        <i className="ri-alert-line me-1" />
                                        {guardrail.title}
                                    </Badge>
                                ))}
                            </div>
                        )}
                    </div>
                    <DecisionBadge decision={item.decision as DecisionType} confidence={item.confidence} />
                </div>

                <div className="mb-3">
                    <div className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                        Motivo
                    </div>
                    <p className="mb-0 fs-14 text-body">{item.reason}</p>
                </div>

                {intelligence && (
                    <div className="border rounded-3 px-3 py-3 mb-4" style={{ background: "#f8fafc" }}>
                        <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                            <div className="fw-semibold fs-13">Contact Performance</div>
                            <div className="d-flex gap-2 flex-wrap">
                                <span className="badge bg-light text-dark fs-11 px-2 py-1">
                                    Intent {intelligence.intent_score}/100
                                </span>
                                <span className="badge bg-light text-dark fs-11 px-2 py-1">
                                    Confiança {intelligence.diagnostic?.confidence ?? 0}%
                                </span>
                                {intelligence.tags?.includes("hot_no_conversion") && (
                                    <span className="badge bg-danger-subtle text-danger fs-11 px-2 py-1">
                                        Hot No Conversion
                                    </span>
                                )}
                            </div>
                        </div>

                        <div className="d-flex gap-2 flex-wrap mb-3">
                            <CompactMetric label="Tentativas" value={intelligence.whatsapp_clicks} />
                            <CompactMetric label="Utilizadores fortes" value={intelligence.strong_intent_users} />
                            <CompactMetric label="Leads" value={intelligence.leads} />
                            <CompactMetric
                                label="Efficiency"
                                value={intelligence.contact_efficiency !== null ? `${Number(intelligence.contact_efficiency).toFixed(0)}%` : "—"}
                            />
                        </div>

                        <div className="text-muted fs-13 mb-2">
                            {intelligence.diagnostic?.message ?? "Sem diagnóstico de intenção disponível."}
                        </div>
                        {intelligence.diagnostic?.confidence_reason && (
                            <div className="text-muted fs-12 mb-2">
                                {intelligence.diagnostic.confidence_reason}
                            </div>
                        )}
                        {intelligence.intent_distribution && (
                            <div className="d-flex gap-2 flex-wrap mb-2">
                                <span className="badge bg-danger-subtle text-danger fs-11 px-2 py-1">
                                    VH {intelligence.intent_distribution.very_high}
                                </span>
                                <span className="badge bg-warning-subtle text-warning fs-11 px-2 py-1">
                                    H {intelligence.intent_distribution.high}
                                </span>
                                <span className="badge bg-info-subtle text-info fs-11 px-2 py-1">
                                    M {intelligence.intent_distribution.medium}
                                </span>
                                <span className="badge bg-light text-dark fs-11 px-2 py-1">
                                    L {intelligence.intent_distribution.low}
                                </span>
                            </div>
                        )}
                        <div className="d-flex gap-2 flex-wrap">
                            {intelligence.relative_performance?.has_sufficient_sample ? (
                                <>
                                    <span className="badge bg-light text-dark fs-11 px-2 py-1">
                                        Intent vs média {intelligence.relative_performance?.intent_vs_avg ?? "—"}
                                    </span>
                                    <span className="badge bg-light text-dark fs-11 px-2 py-1">
                                        Conversão vs média {intelligence.relative_performance?.conversion_vs_avg ?? "—"}
                                    </span>
                                </>
                            ) : (
                                <span className="badge bg-light text-dark fs-11 px-2 py-1">
                                    Benchmark insuficiente
                                </span>
                            )}
                        </div>
                    </div>
                )}

                {leadRealityGap && (
                    <div
                        className="border rounded-3 px-3 py-3 mb-4"
                        style={{
                            background: resolveGapBackground(leadRealityGap.primary_gap_state),
                            borderColor: resolveGapBorder(leadRealityGap.primary_gap_state),
                        }}
                    >
                        <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                            <div className="fw-semibold fs-13">Lead Reality Gap</div>
                            <div className="d-flex gap-2 flex-wrap">
                                <span className={`badge fs-11 px-2 py-1 ${resolveGapBadgeClass(leadRealityGap.primary_gap_state)}`}>
                                    {resolveGapStateLabel(leadRealityGap.primary_gap_state)}
                                </span>
                                <span className="badge bg-light text-dark fs-11 px-2 py-1">
                                    Confiança {leadRealityGap.confidence}%
                                </span>
                            </div>
                        </div>

                        <div className="text-body fs-13 mb-2">
                            {leadRealityGap.message}
                        </div>
                        <div className="text-muted fs-12 mb-3">
                            {leadRealityGap.confidence_reason}
                        </div>

                        <div className="d-flex gap-2 flex-wrap mb-2">
                            <CompactMetric
                                label="Contacto estimado"
                                value={`${leadRealityGap.estimated_real_contact_probability}%`}
                            />
                            <CompactMetric
                                label="Capture gap"
                                value={leadRealityGap.capture_gap_rate !== null ? `${leadRealityGap.capture_gap_rate}%` : "—"}
                            />
                            <CompactMetric
                                label="Response gap"
                                value={leadRealityGap.response_gap_rate !== null ? `${leadRealityGap.response_gap_rate}%` : "—"}
                            />
                        </div>

                        <div className="d-flex gap-2 flex-wrap">
                            <span className="badge bg-light text-dark fs-11 px-2 py-1">
                                Falha provável: {resolveFailurePointLabel(leadRealityGap.likely_failure_point)}
                            </span>
                            {leadRealityGap.secondary_gap_states.map((state) => (
                                <span key={state} className="badge bg-light text-dark fs-11 px-2 py-1">
                                    {resolveGapStateLabel(state)}
                                </span>
                            ))}
                        </div>
                    </div>
                )}

                {hasGuardrails && (
                    <div className="border rounded-3 px-3 py-3 mb-4" style={{ background: "#fff7ed", borderColor: "#fed7aa" }}>
                        <div className="d-flex align-items-center gap-2 fw-semibold fs-13 mb-2" style={{ color: "#b45309" }}>
                            <i className="ri-alarm-warning-line" />
                            Guardrails manuais
                        </div>
                        <div className="d-grid gap-2">
                            {guardrails.map((guardrail, index) => (
                                <div key={`${guardrail.type}-${index}`} className="rounded-3 px-3 py-2" style={{ background: "#fff" }}>
                                    <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-1">
                                        <span className="fw-semibold fs-13">{guardrail.title}</span>
                                        <span className="text-uppercase fw-semibold fs-11" style={{ color: resolveGuardrailAccent(guardrail) }}>
                                            {resolveGuardrailSeverityLabel(guardrail.severity)}
                                        </span>
                                    </div>
                                    <div className="text-muted fs-13 mb-1">{guardrail.message}</div>
                                    <div className="fs-13">
                                        <span className="fw-semibold">Sugestão:</span> {guardrail.recommended_action}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                <div className="mb-4">
                    <div className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                        Próximas ações
                    </div>
                    <ActionList actions={item.actions} />
                </div>

                {lastExecution && (
                    <div className="border rounded-3 px-3 py-2 mb-3" style={{ background: "#f8fafc" }}>
                        <div className="fw-semibold fs-13 mb-1">Última execução</div>
                        <div className="text-muted fs-13">
                            {lastExecution.message}
                        </div>
                    </div>
                )}

                <div className="d-flex gap-2 flex-wrap">
                    <Button color="light" className="border" onClick={() => navigate(item.detailPath)}>
                        Ver detalhes
                    </Button>
                    <Button
                        color="primary"
                        onClick={() => setIsExecutionOpen(true)}
                        disabled={isExecuting}
                    >
                        {isExecuting ? "A executar..." : "Executar"}
                    </Button>
                </div>
            </article>

            <ActionExecutionModal
                item={item}
                isOpen={isExecutionOpen}
                toggle={() => !isExecuting && setIsExecutionOpen(false)}
                onExecuted={handleExecuted}
                onExecutionStateChange={setIsExecuting}
            />
        </>
    );
}

function resolveGuardrailBadgeClass(guardrail: GuardrailAlert): string {
    return {
        high: "bg-danger-subtle text-danger",
        medium: "bg-warning-subtle text-warning",
        low: "bg-secondary-subtle text-secondary",
    }[guardrail.severity];
}

function resolveGuardrailAccent(guardrail: GuardrailAlert): string {
    return {
        high: "#dc3545",
        medium: "#b45309",
        low: "#64748b",
    }[guardrail.severity];
}

function resolveGuardrailSeverityLabel(severity: GuardrailAlert["severity"]): string {
    return {
        high: "Alto risco",
        medium: "Atenção",
        low: "Monitorizar",
    }[severity];
}

function resolveDiagnosticLabel(primaryIssue: string): string {
    return {
        decision_friction: "Decision Friction",
        contact_loss: "Contact Loss",
        contact_capture_failure: "Contact Capture Failure",
        no_response: "No Response",
        healthy_interest: "Fluxo saudável",
        low_intent: "Baixa intenção",
    }[primaryIssue] ?? primaryIssue;
}

function resolveGapStateLabel(state: string): string {
    return {
        no_real_interest: "Sem interesse real",
        decision_friction: "Interesse forte sem contacto",
        contact_capture_failure: "Provável falha de captura",
        no_response: "Lead capturada sem resposta",
        low_lead_quality: "Lead fraca",
        tracking_gap: "Falha de tracking",
        healthy_flow: "Fluxo saudável",
    }[state] ?? state;
}

function resolveGapBadgeClass(state: string): string {
    return {
        decision_friction: "bg-danger-subtle text-danger",
        contact_capture_failure: "bg-danger-subtle text-danger",
        no_response: "bg-danger-subtle text-danger",
        tracking_gap: "bg-warning-subtle text-warning",
        low_lead_quality: "bg-warning-subtle text-warning",
        no_real_interest: "bg-secondary-subtle text-secondary",
        healthy_flow: "bg-success-subtle text-success",
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
    }[state] ?? "#f8fafc";
}

function resolveGapBorder(state: string): string {
    return {
        decision_friction: "#fecaca",
        contact_capture_failure: "#fecaca",
        no_response: "#fecaca",
        tracking_gap: "#fed7aa",
        low_lead_quality: "#fed7aa",
        healthy_flow: "#bbf7d0",
    }[state] ?? "#e2e8f0";
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

function CompactMetric({ label, value }: { label: string; value: number | string }) {
    return (
        <div className="px-2 py-2 rounded-3 bg-white border" style={{ minWidth: 110 }}>
            <div className="text-muted text-uppercase fw-semibold fs-11 mb-1">{label}</div>
            <div className="fw-semibold fs-14">{value}</div>
        </div>
    );
}
