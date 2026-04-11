import { useNavigate } from "react-router-dom";
import { Button } from "reactstrap";
import { toast } from "react-toastify";
import ActionList from "./ActionList";
import DecisionBadge, { getDecisionAccent } from "./DecisionBadge";
import { ActionCenterCarItem, DecisionType } from "../types";

interface CarDecisionCardProps {
    item: ActionCenterCarItem;
}

export default function CarDecisionCard({ item }: CarDecisionCardProps) {
    const navigate = useNavigate();
    const accent = getDecisionAccent(item.decision as DecisionType);

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
            <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                <div>
                    <div className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                        Carro
                    </div>
                    <h5 className="mb-0 fw-semibold">{item.car_name}</h5>
                </div>
                <DecisionBadge decision={item.decision as DecisionType} confidence={item.confidence} />
            </div>

            <div className="mb-3">
                <div className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                    Motivo
                </div>
                <p className="mb-0 fs-14 text-body">{item.reason}</p>
            </div>

            <div className="mb-4">
                <div className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                    Próximas ações
                </div>
                <ActionList actions={item.actions} />
            </div>

            <div className="d-flex gap-2 flex-wrap">
                <Button color="light" className="border" onClick={() => navigate(item.detailPath)}>
                    Ver detalhes
                </Button>
                <Button
                    color="primary"
                    onClick={() => toast.info(`Execução automática para ${item.car_name} disponível em breve.`)}
                >
                    Executar
                </Button>
            </div>
        </article>
    );
}
