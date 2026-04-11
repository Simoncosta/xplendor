import { Badge } from "reactstrap";
import { DecisionType } from "../types";

const decisionMeta: Record<DecisionType, { className: string; accent: string }> = {
    ESCALAR: {
        className: "bg-success-subtle text-success",
        accent: "#0ab39c",
    },
    MANTER: {
        className: "bg-info-subtle text-info",
        accent: "#299cdb",
    },
    CORRIGIR: {
        className: "bg-warning-subtle text-warning",
        accent: "#f7b84b",
    },
    PARAR: {
        className: "bg-danger-subtle text-danger",
        accent: "#f06548",
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
                {decision}
            </Badge>
            <span className="fw-semibold fs-14" style={{ color: meta.accent }}>
                {confidence}%
            </span>
        </div>
    );
}

export const getDecisionAccent = (decision: DecisionType) => decisionMeta[decision].accent;
