import { Badge } from "reactstrap";
import { DecisionType } from "../types";

const decisionMeta: Record<DecisionType, { label: string; className: string; accent: string }> = {
    ESCALAR: {
        label: "ESCALAR",
        className: "bg-success-subtle text-success",
        accent: "#0ab39c",
    },
    MANTER: {
        label: "MANTER",
        className: "bg-info-subtle text-info",
        accent: "#299cdb",
    },
    CORRIGIR: {
        label: "CORRIGIR",
        className: "bg-warning-subtle text-warning",
        accent: "#f7b84b",
    },
    PARAR: {
        label: "PARAR",
        className: "bg-danger-subtle text-danger",
        accent: "#f06548",
    },
    NO_ACTIVE_CAMPAIGN: {
        label: "Sem campanhas ativas",
        className: "bg-secondary-subtle text-secondary",
        accent: "#6c757d",
    },
};

interface DecisionBadgeProps {
    decision: DecisionType;
    confidence: number;
}

export default function DecisionBadge({ decision, confidence }: DecisionBadgeProps) {
    const meta = decisionMeta[decision];

    return (
        <div className="d-flex align-items-center gap-2 flex-wrap">
            <Badge className={`${meta.className} border-0 px-3 py-2 fs-12 fw-semibold`}>
                {meta.label}
            </Badge>
            <span className="fw-semibold fs-14" style={{ color: meta.accent }}>
                {confidence}%
            </span>
        </div>
    );
}

export const getDecisionAccent = (decision: DecisionType) => decisionMeta[decision].accent;
export const getDecisionLabel = (decision: DecisionType) => decisionMeta[decision].label;
