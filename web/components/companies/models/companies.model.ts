export type LeadDistributionType = 'manual' | 'automatic_latest' | 'automatic_less';

export interface ICompany {
    id: number;

    name_user: string | undefined;
    email_user: string | undefined;

    nipc: string;
    fiscal_name: string;
    trade_name?: string | null;
    responsible_name?: string | null;

    address?: string | null;
    postal_code?: string | null;
    district_id?: number | null;
    municipality_id?: number | null;
    parish_id?: number | null;

    phone?: string | null;
    mobile?: string | null;
    email?: string | null;
    invoice_email?: string | null;

    capital_social?: string | null;
    certification_number?: string | null;
    registration_fees: number;
    export_promotion_price: boolean;

    credit_intermediation_link?: string | null;

    vat_value?: number | null;
    facebook_page_id?: string | null;
    facebook_pixel_id?: string | null;
    facebook_access_token?: string | null;

    lead_hours_pending?: string | null;
    lead_distribution: LeadDistributionType;

    ad_text?: string | null;

    pdf_path?: string | null;
    logo_path?: string | null;
    carmine_logo_path?: string | null;

    plan_id: number;

    created_at: string;
    updated_at: string;
    deleted_at?: string | null;
}