export type CarStatus = "draft" | "active" | "inactive" | "sold";
export type CarOrigin = "national" | "imported";

export type CarCondition =
    | "new"
    | "used"
    | "like_new"
    | "good"
    | "service"
    | "damaged";

/**
 * Extras no backend: array de grupos { group, items }
 * O accessor do Laravel garante sempre estes 4 grupos.
 */
export type CarExtraGroup =
    | "comfort_multimedia"
    | "exterior_equipment"
    | "interior_equipment"
    | "safety_performance";

export interface CarExtrasGroup {
    group: CarExtraGroup;
    items: string[];
}

export interface ICar {
    id: number;

    status: CarStatus;
    origin: CarOrigin;

    license_plate: string | null;
    vin: string | null;

    registration_month: number | null; // 1-12
    registration_year: number; // obrigatório no BD

    car_brand_id: number;
    car_model_id: number;

    version: string;
    public_version_name: string | null;

    fuel_type: string;
    power_hp: number;
    engine_capacity_cc: number;

    doors: number;
    transmission: string;
    segment: string;
    seats: number;

    exterior_color: string;
    is_metallic: boolean;

    interior_color: string | null;

    condition: CarCondition;

    mileage_km: number | null;
    co2_emissions: number | null;

    toll_class: string | null;
    cylinders: number | null;

    warranty_available: string | null;
    warranty_due_date: string | null; // YYYY-MM-DD
    warranty_km: number | null;

    service_records: string | null;

    has_spare_key: boolean;
    has_manuals: boolean;

    price_gross: number | null;
    price_net: number | null;

    hide_price_online: boolean;

    monthly_payment: number | null;

    extras: CarExtrasGroup[]; // cast array + accessor no Laravel
    extrasByGroup?: {
        comfort_multimedia: [],
        exterior_equipment: [],
        interior_equipment: [],
        safety_performance: [],
    },
    lifestyle: any[] | null; // no BD é longtext; tipa melhor quando definires estrutura

    description_website_pt: string | null;
    description_website_en: string | null;

    internal_notes: string | null;

    images?: any[];

    youtube_url: string | null;

    company_id: number;

    created_at: string | null;
    updated_at: string | null;
}

/**
 * Payload para CREATE/UPDATE.
 * - Para CREATE, normalmente NÃO envias id/created_at/updated_at
 * - Para UPDATE, envias só o que muda (Partial)
 */
export type ICarCreatePayload = Omit<ICar, "id" | "created_at" | "updated_at">;

export type ICarUpdatePayload = Partial<
    Omit<ICar, "id" | "created_at" | "updated_at">
>;