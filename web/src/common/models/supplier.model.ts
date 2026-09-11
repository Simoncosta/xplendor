// DMS sub-fase 1c.1 — Fornecedor.
export interface ISupplier {
    id: number;
    company_id: number;
    name: string;
    nif: string | null;
    phone: string | null;
    email: string | null;

    // Morada — mesmo padrão da empresa (campos inline + FKs lookup).
    address: string | null;
    postal_code: string | null;
    district_id: number | null;
    municipality_id: number | null;
    parish_id: number | null;
    // Nomes legíveis (emitidos pelo Resource quando eager-loaded).
    district_name?: string | null;
    municipality_name?: string | null;
    parish_name?: string | null;

    iban: string | null;
    notes: string | null;

    // 1c.2b — arquivo. can_delete/expenses_count são read-only (server-computed).
    archived: boolean;
    expenses_count?: number;
    can_delete?: boolean;

    created_at?: string;
    updated_at?: string;
}

// Payload de criação/edição (o company_id deriva do auth no backend).
export type ISupplierPayload = Omit<
    ISupplier,
    "id" | "company_id" | "created_at" | "updated_at" | "district_name" | "municipality_name" | "parish_name" | "archived" | "expenses_count" | "can_delete"
> & { archived?: boolean };
