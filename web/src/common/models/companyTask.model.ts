// XPLENDOR — Tarefas internas do cliente (Kanban do stand). Entidade nova,
// separada dos tickets. Partilhada por toda a equipa da empresa (company_id).
// Colunas FIXAS: todo | doing | done ("A Fazer" | "Em Curso" | "Concluído").

export type CompanyTaskStatus = "todo" | "doing" | "done";

export interface ICompanyTask {
    id: number;
    company_id: number;
    title: string;
    description: string | null;
    status: CompanyTaskStatus;
    order: number;
    assignee_user_id: number | null;
    assignee_name?: string | null;
    created_by: number;
    creator_name?: string | null;
    created_at?: string;
    updated_at?: string;
}

// Ordem e rótulo das colunas do quadro. `color` alinha com os badges Velzon.
export const TASK_COLUMNS: { key: CompanyTaskStatus; label: string; color: string }[] = [
    { key: "todo", label: "A Fazer", color: "secondary" },
    { key: "doing", label: "Em Curso", color: "info" },
    { key: "done", label: "Concluído", color: "success" },
];

export const TASK_STATUS_META: Record<CompanyTaskStatus, { label: string; color: string }> = {
    todo: { label: "A Fazer", color: "secondary" },
    doing: { label: "Em Curso", color: "info" },
    done: { label: "Concluído", color: "success" },
};
