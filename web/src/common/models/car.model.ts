export type CarStatus = "draft" | "active" | "inactive" | "sold";
export type CarOrigin = "national" | "imported";
export type VehicleType = "car" | "motorhome";
export type MotorhomeSubsegment = "autocaravana" | "caravana" | "residencial";
export type MotorhomeBedType = "central" | "rebatível na cabine" | "beliche" | "transversal" | "outra";

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

export interface VehicleAttributes {
    length?: number | string | null;
    width?: number | string | null;
    height?: number | string | null;
    gross_weight?: number | string | null;
    beds?: Array<{ type?: MotorhomeBedType | string | null }> | null;
    has_bathroom?: boolean | number | null;
    has_kitchen?: boolean | number | null;
    autonomy?: number | string | null;
    autonomy_km?: number | string | null;
    [key: string]: string | number | boolean | Array<{ type?: string | null }> | null | undefined;
}

export interface ICar {
    id: number;

    status: CarStatus;
    origin: CarOrigin;
    vehicle_type: VehicleType;
    subsegment?: MotorhomeSubsegment | null;

    license_plate: string | null;
    vin: string | null;

    registration_month: number | null; // 1-12
    registration_year: number; // obrigatório no BD

    car_brand_id: number;
    car_model_id: number;
    car_category_id?: number | null;

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
    promo_price_gross: number | null;
    price_net: number | null;
    promo_discount_value?: number | null;
    promo_discount_pct?: number | null;

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
    external_images?: Array<{
        id: number;
        source: string;
        external_url: string;
        external_index?: number | null;
        is_primary?: boolean;
        sort_order?: number | null;
    }>;

    youtube_url: string | null;
    seller_user_id?: number | null;
    seller_contact?: {
        id: number;
        name: string;
        phone: string | null;
        mobile: string | null;
        whatsapp: string | null;
    } | null;

    company_id: number;
    vehicle_attributes?: VehicleAttributes | null;

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
