// DMS Caminho B — modelo de documento .docx.
export interface IDocumentTemplate {
    id: number;
    company_id: number;
    name: string;
    archived: boolean;
    created_at?: string;
    updated_at?: string;
}

// Variável disponível para os modelos (catálogo do "saco").
export interface IDocumentVariable {
    key: string;
    label: string;
    group: string;
}
