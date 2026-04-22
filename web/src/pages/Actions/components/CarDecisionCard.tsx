import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { Button, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { executeCarActionApi } from "../../../helpers/laravel_helper";
import { getRecommendationActionLabel } from "./ActionRecommendationCard";
import DecisionBadge, { getDecisionAccent } from "./DecisionBadge";
import { ActionCenterCarItem, ActionExecutionResponse, DecisionType, SmartAdsRecommendation } from "../types";

interface CarDecisionCardProps {
    item: ActionCenterCarItem;
}

type ActionableRecommendation = Partial<SmartAdsRecommendation> & {
    type: string;
    reason: string;
    next_step: string;
    confidence: number;
    label?: string;
};
type PrimaryAction = NonNullable<NonNullable<ActionCenterCarItem["recommendations"]>["primary_action"]>;

const actionKeyMap: Record<string, string> = {
    pause_campaign: "pause_campaign",
    generate_new_copy: "generate_new_copy",
    duplicate_campaign: "duplicate_winning_campaign",
};
const actionButtonStyle = { minWidth: 132 };

export default function CarDecisionCard({ item }: CarDecisionCardProps) {
    const navigate = useNavigate();
    const accent = getDecisionAccent(item.decision as DecisionType);
    const intelligence = item.intelligence ?? null;
    const [isExecuting, setIsExecuting] = useState(false);
    const [lastExecution, setLastExecution] = useState<ActionExecutionResponse | null>(null);
    const recommendations = item.recommendations;
    const contactProbability = item.contact_probability ?? null;
    const primaryRecommendedAction = item.primary_recommended_action ?? null;
    const orderedRecommendations: Array<{ bucket: "cut" | "scale" | "fix" | "test"; recommendation: SmartAdsRecommendation; }> = [
        ...(recommendations?.cut ?? []).map((recommendation) => ({ bucket: "cut" as const, recommendation })),
        ...(recommendations?.scale ?? []).map((recommendation) => ({ bucket: "scale" as const, recommendation })),
        ...(recommendations?.fix ?? []).map((recommendation) => ({ bucket: "fix" as const, recommendation })),
        ...(recommendations?.test ?? []).map((recommendation) => ({ bucket: "test" as const, recommendation })),
    ];
    const primaryAction = recommendations?.primary_action;
    const primaryRecommendation = orderedRecommendations[0] ?? null;
    const actionableRecommendation = resolveActionableRecommendation(primaryRecommendedAction ?? undefined, primaryAction, primaryRecommendation?.recommendation);
    const normalizedActionKey = actionableRecommendation?.action_key ? actionKeyMap[actionableRecommendation.action_key] ?? actionableRecommendation.action_key : null;
    const canExecute = Boolean(actionableRecommendation && normalizedActionKey && actionableRecommendation.type !== "no_action_needed");
    const hasMappedCampaign = item.decision !== "NO_ACTIVE_CAMPAIGN" && Boolean(actionableRecommendation?.target_id || primaryRecommendation?.recommendation?.target_id);
    const shouldMapCampaign = item.decision === "NO_ACTIVE_CAMPAIGN" || Boolean(actionableRecommendation?.type.startsWith("pause_") && !hasMappedCampaign);
    const actionLabel = actionableRecommendation ? getRecommendationActionLabel({ type: actionableRecommendation.type }) : "Executar ação";
    const score = Number(contactProbability?.score ?? intelligence?.intent_score ?? 0);
    const leads = Number(actionableRecommendation?.data?.leads ?? contactProbability?.inputs?.leads ?? intelligence?.leads ?? 0);
    const interest = Number(actionableRecommendation?.data?.intent_score ?? contactProbability?.inputs?.intent_score ?? intelligence?.intent_score ?? 0);
    const mainAction = resolveMainAction(actionableRecommendation, shouldMapCampaign, score);

    const handleExecute = async () => {
        const companyId = readCompanyId();

        if (!companyId || !actionableRecommendation || !normalizedActionKey || isExecuting) {
            return;
        }

        setIsExecuting(true);

        try {
            const response = await executeCarActionApi(companyId, item.id, {
                action: normalizedActionKey,
                context: {
                    decision: item.decision,
                    confidence: actionableRecommendation.confidence,
                    reason: actionableRecommendation.reason,
                    target_level: actionableRecommendation.target_level,
                    target_id: actionableRecommendation.target_id,
                    campaign_id: actionableRecommendation.target_level === "campaign" ? actionableRecommendation.target_id : undefined,
                    adset_id: actionableRecommendation.target_level === "adset" ? actionableRecommendation.target_id : undefined,
                    ad_id: actionableRecommendation.target_level === "ad" ? actionableRecommendation.target_id : undefined,
                    recommendation_type: actionableRecommendation.type,
                    next_step: actionableRecommendation.next_step,
                },
            });

            setLastExecution((response?.data ?? response) as ActionExecutionResponse);
            toast.success("Ação executada com sucesso.");
        } catch (error) {
            toast.error("Não foi possível executar esta ação.");
        } finally {
            setIsExecuting(false);
        }
    };

    return (
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
                    <h5 className="mb-0 fw-semibold">{item.car_name}</h5>
                </div>
                <DecisionBadge decision={item.decision as DecisionType} confidence={item.confidence} />
            </div>

            <div className="text-muted fs-13 mb-3">
                {score}% prob. venda <span className="mx-1">·</span> {leads} leads <span className="mx-1">·</span> {interest} interesse
            </div>

            {actionableRecommendation ? (
                <div
                    className="rounded-4 px-3 py-3 mb-4"
                    style={{
                        background: "#f8fafc",
                        border: "1px solid #e2e8f0",
                    }}
                >
                    <div className="d-flex align-items-start gap-2">
                        <span className="fs-18" aria-hidden="true">{resolveDecisionIcon(actionableRecommendation, score)}</span>
                        <div>
                            <div className="fw-semibold fs-16 mb-1">
                                {buildDecisionTitle(actionableRecommendation, contactProbability)}
                            </div>
                            <div className="text-body fs-14" style={{ maxWidth: 720 }}>
                                {contactProbability?.summary ?? actionableRecommendation.next_step}
                            </div>
                        </div>
                    </div>
                </div>
            ) : (
                <div className="rounded-4 px-4 py-4 mb-3 border" style={{ background: "#f8fafc" }}>
                    <div className="fw-semibold fs-15 mb-1">{item.reason}</div>
                    <div className="text-muted fs-13">Sem recomendação prioritária pronta para executar neste momento.</div>
                </div>
            )}

            <div className="text-center mb-3">
                <div className="mb-3">
                    {(actionableRecommendation || shouldMapCampaign) && (
                        shouldMapCampaign ? (
                            <Button color="primary" className="px-4" style={actionButtonStyle} onClick={() => navigate(`/cars/${item.id}/ads`)}>
                                Mapear campanha
                            </Button>
                        ) : (
                            <Button
                                color={mainAction.color}
                                outline={mainAction.outline}
                                className="px-4"
                                style={actionButtonStyle}
                                onClick={handleExecute}
                                disabled={!canExecute || isExecuting}
                            >
                                {isExecuting ? <><Spinner size="sm" className="me-2" />{mainAction.label ?? actionLabel}</> : (mainAction.label ?? actionLabel)}
                            </Button>
                        )
                    )}
                </div>
                <div className="d-flex align-items-center justify-content-center gap-3 text-muted fs-13">
                    <span style={{ minWidth: 80, borderTop: "1px solid #e5e7eb" }} />
                    <span>ou</span>
                    <span style={{ minWidth: 80, borderTop: "1px solid #e5e7eb" }} />
                </div>
            </div>

            <div className="d-flex align-items-center justify-content-center gap-4 flex-wrap">
                <button type="button" className="btn btn-link text-muted text-decoration-none p-0" onClick={() => navigate(`/cars/${item.id}/intelligence`)}>
                    Ver análise →
                </button>
                <button type="button" className="btn btn-link text-muted text-decoration-none p-0" onClick={() => navigate(item.detailPath)}>
                    Ver detalhes →
                </button>
            </div>

            {lastExecution && (
                <div className="border rounded-3 px-3 py-2 mb-3" style={{ background: "#f8fafc" }}>
                    <div className="fw-semibold fs-13 mb-1">Última execução</div>
                    <div className="text-muted fs-13">
                        {lastExecution.message}
                    </div>
                </div>
            )}
        </article>
    );
}

function resolveActionableRecommendation(
    primaryRecommendedAction: ActionCenterCarItem["primary_recommended_action"] | undefined,
    primaryAction: PrimaryAction | undefined,
    recommendation?: SmartAdsRecommendation
): ActionableRecommendation | null {
    if (primaryRecommendedAction) {
        return {
            ...recommendation,
            type: primaryRecommendedAction.type,
            reason: primaryRecommendedAction.reason,
            next_step: primaryRecommendedAction.next_step,
            confidence: primaryRecommendedAction.confidence,
            action_key: primaryRecommendedAction.action_key,
            label: primaryRecommendedAction.label,
            data: recommendation?.data,
            impact: recommendation?.impact,
            target_level: recommendation?.target_level,
            target_id: recommendation?.target_id,
        };
    }

    if (!primaryAction) {
        return recommendation ?? null;
    }

    if (!recommendation) {
        return {
            type: primaryAction.type,
            reason: primaryAction.reason,
            next_step: primaryAction.next_step,
            confidence: primaryAction.confidence,
            action_key: primaryAction.action_key,
            impact: primaryAction.impact as SmartAdsRecommendation["impact"] | undefined,
        };
    }

    return {
        ...recommendation,
        ...primaryAction,
        data: primaryAction.data ?? recommendation.data,
        impact: {
            ...recommendation.impact,
            ...(primaryAction.impact ?? {}),
        },
    };
}

function buildDecisionTitle(recommendation: ActionableRecommendation, contactProbability?: ActionCenterCarItem["contact_probability"] | null): string {
    if (contactProbability?.summary) {
        return contactProbability.state_label ?? recommendation.reason;
    }

    if (recommendation.type === "no_action_needed") {
        return recommendation.reason;
    }

    const normalizedReason = normalizeText(recommendation.reason);

    if (normalizedReason.includes("alcance baixo") || normalizedReason.includes("baixo alcance")) {
        return "Estás a perder alcance qualificado";
    }

    if (normalizedReason.includes("ctr") || normalizedReason.includes("criativo")) {
        return "O teu criativo não está a gerar atenção";
    }

    if (normalizedReason.includes("baixa conversao") || normalizedReason.includes("conversao")) {
        return "Estás a perder potenciais clientes";
    }

    if (recommendation.type.startsWith("pause_")) {
        return "Este anúncio está a gastar sem resultado";
    }

    if (recommendation.type === "scale_adset" || recommendation.type === "duplicate_campaign") {
        return "Este anúncio está pronto para escalar";
    }

    if (recommendation.type === "improve_landing" || recommendation.type === "improve_cta" || recommendation.type === "fix_contact_capture") {
        return "Estás a perder potenciais clientes";
    }

    if (recommendation.type === "test_creative") {
        return "O teu criativo não está a gerar atenção";
    }

    if (recommendation.type === "test_audience") {
        return "Estás a perder alcance qualificado";
    }

    if (recommendation.type === "test_offer") {
        return "A oferta precisa de novo ângulo";
    }

    return recommendation.reason;
}

function resolveDecisionIcon(recommendation: ActionableRecommendation, score: number): string {
    if (recommendation.type.startsWith("pause_") || score < 30) return "❌";
    if (recommendation.type.startsWith("test_") || score < 55) return "⚠️";
    return "✅";
}

function resolveMainAction(
    recommendation: ActionableRecommendation | null,
    shouldMapCampaign: boolean,
    score: number
): { label: string; color: "primary" | "danger" | "info" | "secondary"; outline: boolean } {
    if (shouldMapCampaign) {
        return { label: "Mapear campanha", color: "primary", outline: false };
    }

    if (!recommendation) {
        return { label: "Ver detalhes", color: "secondary", outline: true };
    }

    if (recommendation.type.startsWith("pause_") || score < 30) {
        return { label: getRecommendationActionLabel({ type: recommendation.type }), color: "danger", outline: true };
    }

    if (recommendation.type.startsWith("test_") || score < 55) {
        return { label: recommendation.type === "test_audience" ? "Testar novo público" : "Gerar criativo", color: "info", outline: true };
    }

    return { label: recommendation.label ?? getRecommendationActionLabel({ type: recommendation.type }), color: "primary", outline: false };
}

function normalizeText(value: string): string {
    return value
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .toLowerCase();
}

function readCompanyId() {
    const authUser = sessionStorage.getItem("authUser");
    if (!authUser) return 0;

    try {
        return Number(JSON.parse(authUser).company_id || 0);
    } catch {
        return 0;
    }
}
