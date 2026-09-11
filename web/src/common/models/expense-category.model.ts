// DMS sub-fase 1c.2a — Categoria de Despesa.
export interface IExpenseCategory {
    id: number;
    company_id: number;
    name: string;
    color: string | null;
    archived: boolean;
    // Nº de despesas que usam a categoria (0 até a 1c.2b existir) + se pode ser eliminada.
    expenses_count: number;
    can_delete: boolean;
    created_at?: string;
    updated_at?: string;
}

// Payload de criação/edição (company_id deriva do auth no backend).
export type IExpenseCategoryPayload = {
    name: string;
    color: string | null;
    archived?: boolean;
};
