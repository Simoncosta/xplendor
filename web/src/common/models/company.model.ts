// src/common/models/company.model.ts

export type LeadDistribution =
    | "manual"
    | "automatic_latest"
    | "automatic_less";

export interface ICompany {
    id: number;

    nipc: string;
    fiscal_name: string;

    slug: string | null;
    trade_name: string | null;
    responsible_name: string | null;

    address: string | null;
    postal_code: string | null;

    district_id: number | null;
    municipality_id: number | null;
    parish_id: number | null;

    phone: string | null;
    mobile: string | null;

    email: string | null;
    invoice_email: string | null;

    registry_office: string | null;
    registry_office_number: string | null;
    capital_social: string | null;
    nib: string | null;

    registration_fees: number;
    export_promotion_price: number;

    credit_intermediation_link: string | null;
    vat_value: number | null;

    facebook_page_id: string | null;
    facebook_pixel_id: string | null;
    facebook_access_token: string | null;

    website: string | null;
    instagram: string | null;
    youtube: string | null;
    facebook: string | null;
    google: string | null;

    lead_hours_pending: string | null;
    lead_distribution: LeadDistribution;

    ad_text: string | null;

    pdf_path: string | null;
    logo_path: string | null;
    banner_path: string | null;
    carmine_logo_path: string | null;

    public_api_token: string | null;

    plan_id: number;

    created_at: string | null;
    updated_at: string | null;
    deleted_at: string | null;
}

// útil para updates (PATCH/PUT) sem obrigar tudo
export type ICompanyUpdatePayload = Omit<ICompany, "id" | "public_api_token" | "created_at" | "updated_at" | "deleted_at" & {
    logo_file?: File | null;
}>;