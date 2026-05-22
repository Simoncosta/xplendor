export type FridgeType = "trivalent" | "compressor" | "absorption" | "none";
export type ShowerType = "separate" | "combined" | "none";

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
    has_microwave?: boolean;
    has_extractor?: boolean;
    has_fridge?: boolean;
    fridge_type?: FridgeType;
    fridge_litres?: number;
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

export interface VehicleAttributesB1 {
    dimensions?: VehicleAttributeDimensions;
    weights?: VehicleAttributeWeights;
    habitation_basics?: VehicleAttributeHabitationBasics;
    beds?: Array<{ type: string }>;
    autonomy_km?: number;
}
