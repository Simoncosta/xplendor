// DMS sub-fase 1c.2b — Despesa.
export interface IExpense {
    id: number;
    company_id: number;
    description: string;
    amount: number;
    date: string; // YYYY-MM-DD

    expense_category_id: number | null;
    supplier_id: number | null;
    car_id: number | null;
    category_name?: string | null;
    category_color?: string | null;
    supplier_name?: string | null;
    car_name?: string | null;

    is_paid: boolean;
    paid_at: string | null;
    archived: boolean;
    can_delete: boolean; // false quando tem vínculo → só arquivar
    notes: string | null;

    created_at?: string;
    updated_at?: string;
}

// Payload de criação/edição (company_id deriva do auth no backend).
export interface IExpensePayload {
    description: string;
    amount: number | null;
    date: string | null;
    expense_category_id: number | null;
    supplier_id: number | null;
    car_id: number | null;
    is_paid: boolean;
    paid_at: string | null;
    archived?: boolean;
    notes: string | null;
}

export interface IExpenseSummary {
    total_amount: number;
    paid_amount: number;
    open_amount: number;
    count: number;
}

export interface IExpenseFilters {
    expense_category_id?: number | null;
    supplier_id?: number | null;
    car_id?: number | null;
    is_paid?: "" | "0" | "1";
    date_from?: string | null;
    date_to?: string | null;
    include_archived?: number;
}
