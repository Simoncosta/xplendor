import { Spinner } from "reactstrap";
import type { AiPollable } from "hooks/useAiRequestPoll";

/**
 * Estado de um pedido à IA, sem bloquear o ecrã: "Em fila" ou "A gerar" (com a indicação
 * de que pode sair da página), "parado" ao fim de 3 minutos e o motivo do erro em
 * linguagem simples. Não mostra nada quando o pedido está pronto.
 */
export default function AiRequestState({ data, what = "o resultado" }: { data: AiPollable | null; what?: string }) {
    if (!data) return null;

    if (data.status === "error") {
        return (
            <div className="alert alert-danger fs-13 py-2" role="alert">
                <i className="ri-error-warning-line me-1" />
                <strong>Não foi possível gerar {what}.</strong> {data.error_message}
            </div>
        );
    }
    if (data.status !== "queued" && data.status !== "processing") return null;

    return (
        <div className={`alert ${data.stalled ? "alert-warning" : "alert-light border"} fs-13 py-2`} role="status">
            <div className="d-flex align-items-center gap-2">
                {!data.stalled && <Spinner size="sm" />}
                {data.stalled && <i className="ri-time-line" />}
                <strong>{data.status === "queued" ? "Em fila" : "A gerar"}</strong>
            </div>
            <div className="mt-1">
                {data.stalled
                    ? data.stalled_message ?? "Está a demorar mais do que o normal. O processamento pode estar parado."
                    : "Pode fechar esta janela ou sair da página: o pedido continua e avisamos no sino quando estiver pronto."}
            </div>
        </div>
    );
}
