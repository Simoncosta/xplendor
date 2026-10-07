import { useMemo, useState } from "react";
import { Card, CardBody, Input, Label, Progress } from "reactstrap";
import type { ISupportTicketTask } from "common/models/supportTicket.model";

/**
 * Lista de tarefas de um ticket (ticket de arranque de um orçamento aceite), agrupada
 * por serviço. Com onToggle (equipa XPLENDOR) as tarefas marcam-se; sem ele (cliente)
 * só se acompanha o progresso.
 */
type Props = {
    tasks: ISupportTicketTask[];
    onToggle?: (task: ISupportTicketTask, done: boolean) => Promise<void>;
    /** Tarefas de acesso (com chave) por fazer: "Gerar link de configuração" (equipa XPLENDOR). */
    onSetupLink?: (task: ISupportTicketTask) => void;
};

export default function TicketTasksCard({ tasks, onToggle, onSetupLink }: Props) {
    const [busyId, setBusyId] = useState<number | null>(null);
    const groups = useMemo(() => {
        const map = new Map<string, ISupportTicketTask[]>();
        tasks.forEach((t) => {
            const key = t.group_label || "Tarefas";
            map.set(key, [...(map.get(key) ?? []), t]);
        });
        return Array.from(map.entries());
    }, [tasks]);
    if (tasks.length === 0) return null;

    const done = tasks.filter((t) => t.done).length;
    const toggle = async (t: ISupportTicketTask, value: boolean) => {
        if (!onToggle) return;
        setBusyId(t.id);
        try { await onToggle(t, value); } finally { setBusyId(null); }
    };

    return (
        <Card>
            <CardBody>
                <div className="d-flex align-items-center justify-content-between gap-2 mb-2">
                    <h6 className="mb-0 text-uppercase">Lista de arranque</h6>
                    <small className="text-muted">{done} de {tasks.length} {tasks.length === 1 ? "concluída" : "concluídas"}</small>
                </div>
                <Progress value={(done / tasks.length) * 100} color="success" className="progress-sm mb-3" />
                <div className="vstack gap-3">
                    {groups.map(([group, items]) => (
                        <div key={group}>
                            <div className="fw-semibold fs-13 mb-1">{group}</div>
                            <ul className="list-unstyled vstack gap-1 mb-0">
                                {items.map((t) => (
                                    <li key={t.id} className="d-flex align-items-start gap-2">
                                        {onToggle ? (
                                            <Input type="checkbox" className="form-check-input mt-1" id={`task-${t.id}`} checked={t.done}
                                                disabled={busyId === t.id} onChange={(e) => void toggle(t, e.target.checked)} />
                                        ) : (
                                            <i className={`${t.done ? "ri-checkbox-circle-fill text-success" : "ri-checkbox-blank-circle-line text-muted"} fs-16`} aria-hidden />
                                        )}
                                        <Label className={`mb-0 fs-13${t.done ? " text-muted text-decoration-line-through" : ""}`} for={onToggle ? `task-${t.id}` : undefined}>
                                            {t.title}
                                            {t.done && t.done_by_name && <small className="text-muted ms-1 text-decoration-none d-inline-block">({t.done_by_name})</small>}
                                            {t.done && !t.done_by_name && t.task_key && <small className="text-muted ms-1 text-decoration-none d-inline-block">(pelo link de configuração)</small>}
                                        </Label>
                                        {onSetupLink && t.task_key && !t.done && (
                                            <button type="button" className="btn btn-link btn-sm p-0 ms-auto fs-12 text-nowrap" onClick={() => onSetupLink(t)}>
                                                <i className="ri-send-plane-line me-1" />Gerar link de configuração
                                            </button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            </CardBody>
        </Card>
    );
}
