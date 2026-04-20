import { useMemo, useState } from "react";
import { Badge, Button, Spinner } from "reactstrap";
import { toast } from "react-toastify";

import { executeCarActionApi } from "../../../helpers/laravel_helper";
import { ActionCenterCarItem, SmartAdsRecommendation } from "../types";

interface ActionRecommendationCardProps {
    item: ActionCenterCarItem;
    recommendation: SmartAdsRecommendation;
    bucket: "cut" | "scale" | "fix" | "test";
    onExecuted?: () => void;
}

const badgeConfig = {
    cut: {
        label: "CUT",
        badgeClass: "bg-danger-subtle text-danger",
        accent: "#dc2626",
        impactBg: "#fff1f2",
    },
    scale: {
        label: "SCALE",
        badgeClass: "bg-success-subtle text-success",
        accent: "#16a34a",
        impactBg: "#f0fdf4",
    },
    fix: {
        label: "FIX",
        badgeClass: "bg-warning-subtle text-warning",
        accent: "#d97706",
        impactBg: "#fff7ed",
    },
    test: {
        label: "TEST",
        badgeClass: "bg-info-subtle text-info",
        accent: "#2563eb",
        impactBg: "#eff6ff",
    },
} as const;

const actionKeyMap: Record<string, string> = {
    pause_campaign: "pause_campaign",
    generate_new_copy: "generate_new_copy",
    duplicate_campaign: "duplicate_winning_campaign",
};

const readCompanyId = () => {
    const authUser = sessionStorage.getItem("authUser");
    if (!authUser) return 0;

    return Number(JSON.parse(authUser).company_id || 0);
};

export default function ActionRecommendationCard({
    item,
    recommendation,
    bucket,
    onExecuted,
}: ActionRecommendationCardProps) {
    const [isExecuting, setIsExecuting] = useState(false);
    const config = badgeConfig[bucket];
    const normalizedActionKey = recommendation.action_key ? actionKeyMap[recommendation.action_key] ?? recommendation.action_key : null;
    const canExecute = Boolean(normalizedActionKey);
    const actionLabel = getRecommendationActionLabel(recommendation);
    const actionHelper = getRecommendationActionHelper(recommendation);
    const impactLabel = useMemo(() => {
        if (bucket === "cut" && recommendation.impact.estimated_loss !== undefined) {
            return `Estás a perder ${formatMoney(recommendation.impact.estimated_loss)}`;
        }

        if (bucket === "scale" && recommendation.impact.estimated_gain !== undefined) {
            return `Pode gerar +${formatGain(recommendation.impact.estimated_gain)}`;
        }

        return null;
    }, [bucket, recommendation.impact.estimated_gain, recommendation.impact.estimated_loss]);

    const handleExecute = async () => {
        const companyId = readCompanyId();

        if (!companyId || !normalizedActionKey || isExecuting) {
            return;
        }

        setIsExecuting(true);

        try {
            await executeCarActionApi(companyId, item.id, {
                action: normalizedActionKey,
                context: {
                    decision: item.decision,
                    confidence: recommendation.confidence,
                    reason: recommendation.reason,
                    target_level: recommendation.target_level,
                    target_id: recommendation.target_id,
                    campaign_id: recommendation.target_level === "campaign" ? recommendation.target_id : undefined,
                    adset_id: recommendation.target_level === "adset" ? recommendation.target_id : undefined,
                    ad_id: recommendation.target_level === "ad" ? recommendation.target_id : undefined,
                    recommendation_type: recommendation.type,
                    next_step: recommendation.next_step,
                },
            });

            toast.success("Ação executada com sucesso.");
            onExecuted?.();
        } catch (error) {
            toast.error("Não foi possível executar esta ação.");
        } finally {
            setIsExecuting(false);
        }
    };

    return (
        <div
            className="border rounded-4 p-3"
            style={{
                background: "#fff",
                borderLeft: `4px solid ${config.accent}`,
                boxShadow: "0 10px 20px rgba(15, 23, 42, 0.04)",
            }}
        >
            <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                <div className="flex-grow-1">
                    <Badge className={`${config.badgeClass} border-0 px-2 py-1 fs-11 mb-2`}>
                        {config.label}
                    </Badge>
                    <div className="fw-semibold fs-15 mb-1">{recommendation.reason}</div>
                    {impactLabel && (
                        <div
                            className="fw-semibold fs-15"
                            style={{ color: config.accent }}
                        >
                            {impactLabel}
                        </div>
                    )}
                </div>
                {canExecute && (
                    <div className="d-flex flex-column align-items-start align-items-md-end gap-1">
                        <Button color="primary" onClick={handleExecute} disabled={isExecuting}>
                            {isExecuting ? <><Spinner size="sm" className="me-2" />{actionLabel}</> : actionLabel}
                        </Button>
                        {actionHelper && (
                            <div className="text-muted fs-12 text-md-end" style={{ maxWidth: 220 }}>
                                {actionHelper}
                            </div>
                        )}
                    </div>
                )}
            </div>

            <div className="text-muted fs-13 mb-2">
                {buildCompactDataLine(recommendation)}
            </div>

            <div
                className="rounded-3 px-3 py-2 mb-2"
                style={{
                    background: config.impactBg,
                    border: "1px solid rgba(148, 163, 184, 0.16)",
                }}
            >
                <div className="fw-semibold fs-14">{recommendation.next_step}</div>
            </div>

            <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <div className="text-muted fs-13">
                    {recommendation.why}
                </div>
                <div className="text-muted fs-12">
                    Confiança: {recommendation.confidence}% · {resolveUrgencyLabel(recommendation.impact.urgency)}
                </div>
            </div>
        </div>
    );
}

function buildCompactDataLine(recommendation: SmartAdsRecommendation): string {
    const parts = [
        recommendation.data.spend !== undefined ? `Spend: ${formatMoney(recommendation.data.spend)}` : null,
        recommendation.data.leads !== undefined ? `Leads: ${recommendation.data.leads}` : null,
        recommendation.data.intent_score !== undefined ? `Intent: ${recommendation.data.intent_score}` : null,
    ].filter(Boolean);

    return parts.join(" • ");
}

function formatMoney(value: number): string {
    return new Intl.NumberFormat("pt-PT", {
        style: "currency",
        currency: "EUR",
        maximumFractionDigits: 0,
    }).format(value);
}

function formatGain(value: number): string {
    if (Number.isInteger(value)) {
        return `${value}`;
    }

    return value.toFixed(1);
}

function resolveUrgencyLabel(urgency: "low" | "medium" | "high"): string {
    return {
        low: "Baixa",
        medium: "Média",
        high: "Alta",
    }[urgency];
}

function getRecommendationActionLabel(recommendation: SmartAdsRecommendation): string {
    const byType: Record<string, string> = {
        pause_ad: "Pausar anúncio",
        pause_adset: "Pausar conjunto",
        pause_campaign: "Pausar campanha",
        scale_adset: "Preparar escala",
        duplicate_campaign: "Duplicar campanha",
        improve_landing: "Ver melhoria",
        improve_cta: "Ajustar CTA",
        fix_contact_capture: "Rever contacto",
        test_creative: "Gerar novo criativo",
        test_audience: "Preparar teste de público",
        test_offer: "Testar nova oferta",
    };

    return byType[recommendation.type] ?? "Executar ação";
}

function getRecommendationActionHelper(recommendation: SmartAdsRecommendation): string | null {
    const byType: Record<string, string> = {
        pause_ad: "Vai pausar este anúncio na Meta Ads.",
        pause_adset: "Vai pausar este conjunto na Meta Ads.",
        pause_campaign: "Vai pausar esta campanha na Meta Ads.",
        scale_adset: "Abre o fluxo para preparar a escala deste conjunto.",
        duplicate_campaign: "Prepara a duplicação da campanha com base no que está a funcionar.",
        improve_landing: "Abre o fluxo assistido para rever a página e a conversão.",
        improve_cta: "Abre o fluxo assistido para ajustar o CTA principal.",
        fix_contact_capture: "Abre o fluxo para rever captação e contacto.",
        test_creative: "Abre o fluxo assistido para criar nova variação.",
        test_audience: "Abre o fluxo para preparar um novo teste de público.",
        test_offer: "Abre o fluxo para testar uma nova oferta.",
    };

    return byType[recommendation.type] ?? null;
}
