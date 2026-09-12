// DMS — Cliente (pré-requisito da Fase 3: documentos de venda).
export interface ICustomer {
    id: number;
    company_id: number;
    name: string;
    nif: string | null;
    phone: string | null;
    email: string | null;

    // Morada — mesmo padrão da empresa/fornecedor (inline + FKs lookup).
    address: string | null;
    postal_code: string | null;
    district_id: number | null;
    municipality_id: number | null;
    parish_id: number | null;
    district_name?: string | null;
    municipality_name?: string | null;
    parish_name?: string | null;

    // Dados legais (documentos).
    citizen_card_number: string | null;
    citizen_card_validity: string | null; // YYYY-MM-DD
    birth_date: string | null;            // YYYY-MM-DD
    nationality: string | null;
    profession: string | null;
    marital_status: string | null;

    contact_consent: boolean;
    notes: string | null;

    // Read-only (server-computed).
    archived: boolean;
    sales_count?: number;
    can_delete?: boolean;

    created_at?: string;
    updated_at?: string;
}

export type ICustomerPayload = Omit<
    ICustomer,
    "id" | "company_id" | "created_at" | "updated_at" | "district_name" | "municipality_name" | "parish_name" | "archived" | "sales_count" | "can_delete"
> & { archived?: boolean };
