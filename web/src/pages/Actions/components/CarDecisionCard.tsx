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
    const hasGuardrails = guardrails.length > 0;
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
                    border: hasGuardrails ? "1px solid #fed7aa" : "1px solid #e9ebec",
                    borderLeft: `4px solid ${accent}`,
                    borderRadius: 18,
                    background: hasGuardrails
                        ? "linear-gradient(180deg, #ffffff 0%, #fffaf3 100%)"
                        : "#fff",
                    padding: 20,
                    boxShadow: hasGuardrails
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
