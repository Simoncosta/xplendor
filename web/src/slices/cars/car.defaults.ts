// slices/cars/car.defaults.ts
import type { ICarUpdatePayload, CarExtrasGroup, VehicleAttributes } from "common/models/car.model";

const DEFAULT_EXTRAS: CarExtrasGroup[] = [
    { group: "comfort_multimedia", items: [] },
    { group: "exterior_equipment", items: [] },
    { group: "interior_equipment", items: [] },
    { group: "safety_performance", items: [] },
];

export const DEFAULT_VEHICLE_ATTRIBUTES: VehicleAttributes = {
    length: "",
    width: "",
    height: "",
    beds: "",
    has_bathroom: false,
    has_kitchen: false,
    autonomy_km: "",
};

export const CAR_CREATE_DEFAULTS: ICarUpdatePayload = {
    status: "draft",
    origin: "national",

    license_plate: null,
    vin: null,

    registration_month: null,
    registration_year: new Date().getFullYear(),

    car_brand_id: 0, // troca por null se o backend aceitar; no teu BD é NOT NULL
    car_model_id: 0, // idem

    version: "",
    public_version_name: null,

    fuel_type: "",
    power_hp: 0,
    engine_capacity_cc: 0,

    doors: 5,
    transmission: "",
    segment: "",
    seats: 5,

    exterior_color: "",
    is_metallic: false,

    interior_color: null,

    condition: "used",

    mileage_km: null,
    co2_emissions: null,

    toll_class: null,
    cylinders: null,

    warranty_available: null,
    warranty_due_date: null,
    warranty_km: null,

    service_records: null,

    has_spare_key: false,
    has_manuals: false,

    price_gross: null,
    promo_price_gross: null,
    price_net: null,

    hide_price_online: false,

    monthly_payment: null,

    extrasByGroup: {
        comfort_multimedia: [],
        exterior_equipment: [],
        interior_equipment: [],
        safety_performance: [],
    },
    extras: DEFAULT_EXTRAS,
    lifestyle: null,

    description_website_pt: null,
    description_website_en: null,

    internal_notes: null,

    youtube_url: null,
    seller_user_id: null,
    vehicle_attributes: { ...DEFAULT_VEHICLE_ATTRIBUTES },

    company_id: 0,
};
