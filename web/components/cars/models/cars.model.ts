import { ICarBrand } from "@/components/car-brands/model/car-brands.model";
import { ICarModel } from "@/components/car-models/model/car-models.model";

export type ICarStatus = "draft" | "active" | "inactive" | "sold" | "available_soon";
export type ICarOrigin = "national" | "imported";
export type ICarCondition = "new" | "used" | "like_new" | "good" | "service" | "trade_in" | "classic";

export interface ICarImageMeta {
    is_primary?: boolean | null;
    order?: number | null;
}

export interface ICarViews {
    id: number | undefined;
    company_id: number;
    car_id: number;
    user_id: number | null;
    ip_address: string;
    user_agent: string | null;
}

export interface ICarLead {
    id: number | undefined;
    name: string;
    email: string;
    phone: string | null;
    message: string | null;
    car_id: number;
    company_id: number;
}

export interface ICarAiAnalysis {
    id: number | undefined;
    input_data: string;
    analysis: string;
    status: string;
    feedback: string | null;
    car_id: number;
    company_id: number;
}

export interface ICarExterior360Meta {
    order?: number | null;
}

export interface ICar {
    id: number | undefined;
    // Status
    status: ICarStatus;

    // Origin & identification
    origin: ICarOrigin;
    license_plate?: string | null;
    vin?: string | null;

    // Registration
    registration_month?: number | null; // 1-12
    registration_year: number; // 1900..currentYear

    // Car data
    brand?: ICarBrand;
    model?: ICarModel;
    views?: ICarViews[];
    leads?: ICarLead[];
    analyses?: ICarAiAnalysis;
    // Core vehicle data
    car_brand_id: number;
    car_model_id: number;
    version: string;
    public_version_name?: string | null;
    fuel_type: string;
    power_hp: number;
    engine_capacity_cc: number;
    doors: number; // 1..6
    transmission: string;

    // Details
    segment: string;
    seats: number; // 1..10
    exterior_color: string;
    is_metallic?: boolean; // default false no backend
    interior_color?: string | null;
    condition: ICarCondition;
    mileage_km?: number | null;

    // Additional data
    co2_emissions?: number | null;
    toll_class?: string | null;
    cylinders?: number | null;
    warranty_available?: string | null;
    warranty_due_date?: string | null; // ISO date string (YYYY-MM-DD)
    warranty_km?: number | null;
    service_records: string;
    has_spare_key?: boolean;
    has_manuals?: boolean;

    // Pricing
    price_gross?: number | string | null; // pode vir como string em form-data
    price_net?: number | string | null;
    hide_price_online?: boolean;
    monthly_payment?: number | string | null;

    // Extras
    extras?: any[] | null;
    lifestyle?: any[] | null;

    // Advertiser content
    description_website_pt?: string | null;
    description_website_en?: string | null;
    internal_notes?: string | null;
    youtube_url?: string | null;

    // Images (upload via multipart/form-data)
    images?: (File | string)[] | null;
    images_meta?: ICarImageMeta[] | null;

    // 360 exterior images
    exterior_360_images?: (File | string)[] | null;
    exterior_360_meta?: ICarExterior360Meta[] | null;

    // Relationship
    company_id: number;
}