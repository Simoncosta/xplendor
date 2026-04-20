import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { Button } from "reactstrap";
import ActionList from "./ActionList";
import ActionExecutionModal from "./ActionExecutionModal";
import ActionRecommendationCard from "./ActionRecommendationCard";
import DecisionBadge, { getDecisionAccent } from "./DecisionBadge";
import { ActionCenterCarItem, ActionExecutionResponse, DecisionType, SmartAdsRecommendation } from "../types";

interface CarDecisionCardProps {
    item: ActionCenterCarItem;
}

export default function CarDecisionCard({ item }: CarDecisionCardProps) {
    const navigate = useNavigate();
    const accent = getDecisionAccent(item.decision as DecisionType);
    const guardrails = item.guardrails ?? [];
    const intelligence = item.intelligence ?? null;
    const leadRealityGap = item.lead_reality_gap ?? null;
    const [isExecutionOpen, setIsExecutionOpen] = useState(false);
    const [isExecuting, setIsExecuting] = useState(false);
    const [showAnalysis, setShowAnalysis] = useState(false);
    const [lastExecution, setLastExecution] = useState<ActionExecutionResponse | null>(null);
    const recommendations = item.recommendations;
    const orderedRecommendations: Array<{ bucket: "cut" | "scale" | "fix" | "test"; recommendation: SmartAdsRecommendation; }> = [
        ...(recommendations?.cut ?? []).map((recommendation) => ({ bucket: "cut" as const, recommendation })),
        ...(recommendations?.scale ?? []).map((recommendation) => ({ bucket: "scale" as const, recommendation })),
        ...(recommendations?.fix ?? []).map((recommendation) => ({ bucket: "fix" as const, recommendation })),
        ...(recommendations?.test ?? []).map((recommendation) => ({ bucket: "test" as const, recommendation })),
    ];
    const primaryAction = recommendations?.primary_action;
    const primaryRecommendation = orderedRecommendations[0] ?? null;
    const secondaryRecommendations = orderedRecommendations.slice(1);

    const handleExecuted = async (result: ActionExecutionResponse) => {
        setLastExecution(result);
        window.location.reload();
    };

    return (
        <>
            <article
                style={{
                    border: "1px solid #e9ebec",
                    borderLeft: `4px solid ${accent}`,
                    borderRadius: 18,
                    background: "#fff",
                    padding: 20,
                    boxShadow: "0 10px 30px rgba(15, 23, 42, 0.04)",
                }}
            >
                <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-4">
                    <div>
                        <div className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Carro
                        </div>
                        <h5 className="mb-0 fw-semibold">{item.car_name}</h5>
                    </div>
                    <DecisionBadge decision={item.decision as DecisionType} confidence={item.confidence} />
                </div>

                {primaryAction && (
                    <div
                        className="rounded-4 px-3 py-3 mb-4"
                        style={{
                            background: "#fff7ed",
                            border: "1px solid #fed7aa",
                        }}
                    >
                        <div className="text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em", color: "#9a3412" }}>
                            Ação recomendada
                        </div>
                        <div className="fw-semibold fs-17 mb-1">
                            {buildPrimaryActionHeadline(primaryAction)}
                        </div>
                        <div className="text-body fs-14">
                            {primaryAction.next_step}
                        </div>
                    </div>
                )}

                {primaryRecommendation ? (
                    <div className="mb-4">
                        <ActionRecommendationCard
                            key={`${primaryRecommendation.bucket}-${primaryRecommendation.recommendation.type}-${primaryRecommendation.recommendation.target_id}`}
                            item={item}
                            recommendation={primaryRecommendation.recommendation}
                            bucket={primaryRecommendation.bucket}
                            onExecuted={() => window.location.reload()}
                        />
                    </div>
                ) : (
                    <div className="rounded-3 px-3 py-3 mb-4 border" style={{ background: "#f8fafc" }}>
                        <div className="fw-semibold fs-15 mb-1">{item.reason}</div>
                        <div className="text-muted fs-13">Sem recomendação prioritária pronta para executar neste momento.</div>
                    </div>
                )}

                <div className="d-flex gap-2 flex-wrap">
                    <Button color="light" className="border" onClick={() => setShowAnalysis((value) => !value)}>
                        {showAnalysis ? "Esconder análise" : "Ver análise"}
                    </Button>
                    <Button color="light" className="border" onClick={() => navigate(item.detailPath)}>
                        Ver detalhes
                    </Button>
                    <Button color="light" className="border" onClick={() => setIsExecutionOpen(true)} disabled={isExecuting}>
                        {isExecuting ? "A executar..." : "Ações manuais"}
                    </Button>
                </div>

                {showAnalysis && (
                    <div className="d-grid gap-3 mt-4 mb-4">
                        {intelligence && (
                            <div className="border rounded-3 px-3 py-3" style={{ background: "#f8fafc" }}>
                                <div className="fw-semibold fs-13 mb-2">Contact Performance</div>
                                <div className="d-flex gap-2 flex-wrap mb-3">
                                    <CompactMetric label="Intent" value={`${intelligence.intent_score}/100`} />
                                    <CompactMetric label="Tentativas" value={intelligence.whatsapp_clicks} />
                                    <CompactMetric label="Fortes" value={intelligence.strong_intent_users} />
                                    <CompactMetric label="Leads" value={intelligence.leads} />
                                </div>
                                <div className="text-muted fs-13">{intelligence.diagnostic?.message ?? "Sem diagnóstico de intenção disponível."}</div>
                            </div>
                        )}

                        {leadRealityGap && (
                            <div
                                className="border rounded-3 px-3 py-3"
                                style={{
                                    background: resolveGapBackground(leadRealityGap.primary_gap_state),
                                    borderColor: resolveGapBorder(leadRealityGap.primary_gap_state),
                                }}
                            >
                                <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
                                    <div className="fw-semibold fs-13">Lead Reality Gap</div>
                                    <span className={`badge fs-11 px-2 py-1 ${resolveGapBadgeClass(leadRealityGap.primary_gap_state)}`}>
                                        {resolveGapStateLabel(leadRealityGap.primary_gap_state)}
                                    </span>
                                </div>
                                <div className="text-body fs-13 mb-2">{leadRealityGap.message}</div>
                                <div className="text-muted fs-12">{leadRealityGap.confidence_reason}</div>
                            </div>
                        )}

                        {guardrails.length > 0 && (
                            <div className="border rounded-3 px-3 py-3" style={{ background: "#fff7ed", borderColor: "#fed7aa" }}>
                                <div className="fw-semibold fs-13 mb-2">Guardrails</div>
                                <div className="d-grid gap-2">
                                    {guardrails.map((guardrail, index) => (
                                        <div key={`${guardrail.type}-${index}`} className="rounded-3 px-3 py-2" style={{ background: "#fff" }}>
                                            <div className="fw-semibold fs-13 mb-1">{guardrail.title}</div>
                                            <div className="text-muted fs-13">{guardrail.message}</div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {secondaryRecommendations.length > 0 && (
                            <div className="d-grid gap-3">
                                {secondaryRecommendations.map(({ bucket, recommendation }, index) => (
                                    <ActionRecommendationCard
                                        key={`${bucket}-${recommendation.type}-${recommendation.target_id}-${index}`}
                                        item={item}
                                        recommendation={recommendation}
                                        bucket={bucket}
                                        onExecuted={() => window.location.reload()}
                                    />
                                ))}
                            </div>
                        )}

                        <div>
                            <div className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                                Próximas ações manuais
                            </div>
                            <ActionList actions={item.actions} />
                        </div>
                    </div>
                )}

                {lastExecution && (
                    <div className="border rounded-3 px-3 py-2 mb-3" style={{ background: "#f8fafc" }}>
                        <div className="fw-semibold fs-13 mb-1">Última execução</div>
                        <div className="text-muted fs-13">
                            {lastExecution.message}
                        </div>
                    </div>
                )}
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

function CompactMetric({ label, value }: { label: string; value: number | string }) {
    return (
        <div className="px-2 py-2 rounded-3 bg-white border" style={{ minWidth: 110 }}>
            <div className="text-muted text-uppercase fw-semibold fs-11 mb-1">{label}</div>
            <div className="fw-semibold fs-14">{value}</div>
        </div>
    );
}

function buildPrimaryActionHeadline(primaryAction: NonNullable<ActionCenterCarItem["recommendations"]>["primary_action"]) {
    if (!primaryAction) {
        return "Sem ação prioritária";
    }

    if (primaryAction.type === "no_action_needed") {
        return primaryAction.reason;
    }

    const impact = primaryAction.impact as { estimated_loss?: number; estimated_gain?: number } | undefined;

    if (impact?.estimated_loss !== undefined) {
        return `${primaryAction.reason} - estás a perder ${formatMoney(impact.estimated_loss)}`;
    }

    if (impact?.estimated_gain !== undefined) {
        return `${primaryAction.reason} - pode gerar +${impact.estimated_gain}`;
    }

    return primaryAction.reason;
}

function formatMoney(value: number): string {
    return new Intl.NumberFormat("pt-PT", {
        style: "currency",
        currency: "EUR",
        maximumFractionDigits: 0,
    }).format(value);
}
