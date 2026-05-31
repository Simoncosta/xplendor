import type { BedType } from "pages/Cars/Car/data/vehicleAttributes";
export type { BedType };

export type CarStatus = "draft" | "active" | "inactive" | "sold";
export type CarOrigin = "national" | "imported";
export type VehicleType = "car" | "motorcycle" | "motorhome" | "caravan";
export type MotorhomeSubsegment = "autocaravana" | "caravana" | "residencial";

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

export type FridgeType = "trivalent" | "compressor" | "absorption" | "none";
export type ShowerType = "separate" | "independent" | "combined";
export type InverterType = "pure_sine" | "modified_sine";
export type WaterHeaterSource = "electric" | "gas" | "diesel" | "none";
export type AmbientHeatingSource = "electric" | "gas" | "diesel" | "none";
export type ChassisType = "standard" | "alko" | "other";
export type UpholsteryState = "good" | "fair" | "worn" | "replaced";

export interface VehicleAttributeDimensions {
    length_m?: number;
    width_m?: number;
    height_m?: number;
}

export interface VehicleAttributeWeights {
    gross_weight_kg?: number;
}

export interface VehicleAttributeKitchen {
    has_stove?: boolean;
    has_oven?: boolean;
    has_extending_counter?: boolean;
    has_microwave?: boolean;
    has_extractor?: boolean;
    has_fridge?: boolean;
    fridge_type?: FridgeType;
    fridge_litres?: number;
    fridge_shelves?: number;
}

export interface VehicleAttributeBathroom {
    has_toilet?: boolean;
    has_shower?: boolean;
    shower_type?: ShowerType;
    clean_water_litres?: number;
    waste_water_litres?: number;
}

export interface VehicleAttributeHabitationBasics {
    has_bathroom?: boolean;
    has_kitchen?: boolean;
    kitchen?: VehicleAttributeKitchen;
    bathroom?: VehicleAttributeBathroom;
}

export interface VehicleAttributeEnergyClimate {
    water_heater_source?: WaterHeaterSource;
    water_heater_brand?: string;
    ambient_heating_source?: AmbientHeatingSource;
    ambient_heating_brand?: string;
    has_solar_panel?: boolean;
    solar_panel_count?: number;
    solar_panel_watts?: number;
    has_inverter?: boolean;
    inverter_type?: InverterType;
    inverter_watts?: number;
    has_gpl?: boolean;
    gpl_bottles_count?: number;
    has_generator?: boolean;
    has_external_power_socket?: boolean;
    battery_count?: number;
    cabin_battery_count?: number;
    cell_battery_count?: number;
}

export interface VehicleAttributeGarage {
    has_garage?: boolean;
    has_double_opening?: boolean;
    is_spacious?: boolean;
    has_height_adjuster?: boolean;
}

export interface VehicleAttributeExterior {
    has_awning?: boolean;
    awning_brand?: string;
    has_national_antenna?: boolean;
    has_parabolic_antenna?: boolean;
    has_bike_rack?: boolean;
    has_motorbike_rack?: boolean;
    has_electric_step?: boolean;
    has_manual_step?: boolean;
    has_stabilizers?: boolean;
    has_spare_wheel?: boolean;
    has_fix_n_go_kit?: boolean;
    has_bull_eye?: boolean;
    has_external_wc?: boolean;
    has_hubcaps?: boolean;
    has_external_ladder?: boolean;
    garage?: VehicleAttributeGarage;
}

export interface VehicleAttributeSecurity {
    has_alarm?: boolean;
    has_hatch_lock?: boolean;
    has_cabin_lock?: boolean;
    has_safe_door?: boolean;
    has_gas_lock?: boolean;
    has_entry_door_lock?: boolean;
    other_locks_notes?: string;
}

export interface VehicleAttributeChassisStructure {
    chassis_type?: ChassisType;
    chassis_notes?: string;
    has_turbovent_skylight?: boolean;
    has_panoramic_skylight?: boolean;
    has_40x40_skylight?: boolean;
    other_skylights_notes?: string;
    has_remifront?: boolean;
    has_window_blackouts?: boolean;
    has_mosquito_nets?: boolean;
    has_door_mosquito_net?: boolean;
    has_cabin_blackouts?: boolean;
    cabin_blackout_type?: string;
}

export interface VehicleAttributeInteriorFurniture {
    has_foldable_table?: boolean;
    has_rotating_seats?: boolean;
    upholstery_state?: UpholsteryState;
    has_curtains?: boolean;
    has_led_lighting?: boolean;
    has_halo_lighting?: boolean;
    has_tv_support?: boolean;
    has_tv?: boolean;
    has_command_panel?: boolean;
    has_water_infiltrations?: boolean;
    infiltrations_notes?: string;
}

export type LivingRoomLayout = "face_to_face" | "l_shape" | "panoramic";

export interface VehicleAttributeLivingRoom {
    layout?: LivingRoomLayout;
    has_extending_table?: boolean;
}

export interface VehicleAttributes {
    dimensions?: VehicleAttributeDimensions;
    weights?: VehicleAttributeWeights;
    habitation_basics?: VehicleAttributeHabitationBasics;
    beds?: Array<{ type: BedType }> | null;
    autonomy_km?: number;
    energy_climate?: VehicleAttributeEnergyClimate;
    exterior?: VehicleAttributeExterior;
    security?: VehicleAttributeSecurity;
    chassis_structure?: VehicleAttributeChassisStructure;
    interior_furniture?: VehicleAttributeInteriorFurniture;
    living_room?: VehicleAttributeLivingRoom;
    [key: string]: unknown;
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

    fuel_type: string | null;
    power_hp: number | null;
    engine_capacity_cc: number | null;

    doors: number;
    transmission: string | null;
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
