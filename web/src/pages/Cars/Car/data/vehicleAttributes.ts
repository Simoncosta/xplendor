export type BedType =
    | "camas_gemeas"
    | "cama_central"
    | "cama_francesa"
    | "cama_basculante"
    | "cama_capucino"
    | "cama_garagem"
    | "beliche"
    | "cama_transversal"
    | "cama_elevatoria_eletrica"
    | "cama_suspensa"
    | "cama_convertivel"
    | "outra"
    | "cama_rebativel_cabine"; // legacy — não mostrar em viatura nova

export const BED_LABELS: Record<BedType, string> = {
    camas_gemeas:            "Camas gémeas",
    cama_central:            "Cama central",
    cama_francesa:           "Cama francesa",
    cama_basculante:         "Cama basculante",
    cama_capucino:           "Cama capucino",
    cama_garagem:            "Cama de garagem",
    beliche:                 "Beliche",
    cama_transversal:        "Cama transversal",
    cama_elevatoria_eletrica: "Cama elevatória eléctrica",
    cama_suspensa:           "Cama suspensa",
    cama_convertivel:        "Cama convertível",
    outra:                   "Outra",
    cama_rebativel_cabine:   "Rebatível na cabine",
};

export type FridgeType = "trivalent" | "compressor" | "absorption" | "none";
export type ShowerType = "separate" | "independent" | "combined";
export type InverterType = "pure_sine" | "modified_sine";
export type WaterHeaterSource = "electric" | "gas" | "diesel" | "none";
export type AmbientHeatingSource = "electric" | "gas" | "diesel" | "none";
export type ChassisType = "standard" | "alko" | "other";
export type UpholsteryState = "good" | "fair" | "worn" | "replaced";

// B1 — habitation basics sub-objects
export interface VehicleAttributeKitchen {
    has_stove?: boolean;
    has_oven?: boolean;
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

export interface VehicleAttributeDimensions {
    length_m?: number;
    width_m?: number;
    height_m?: number;
}

export interface VehicleAttributeWeights {
    gross_weight_kg?: number;
}

// B2 — five new top-level sections
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

export interface VehicleAttributesB1 {
    dimensions?: VehicleAttributeDimensions;
    weights?: VehicleAttributeWeights;
    habitation_basics?: VehicleAttributeHabitationBasics;
    beds?: Array<{ type: BedType }>;
    autonomy_km?: number;
    energy_climate?: VehicleAttributeEnergyClimate;
    exterior?: VehicleAttributeExterior;
    security?: VehicleAttributeSecurity;
    chassis_structure?: VehicleAttributeChassisStructure;
    interior_furniture?: VehicleAttributeInteriorFurniture;
}
