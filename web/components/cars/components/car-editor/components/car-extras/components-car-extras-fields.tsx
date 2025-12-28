import { useEffect, useMemo, useState } from "react";
import { getTranslation } from "@/i18n";
import AnimateHeight from "react-animate-height";
import { Controller } from "react-hook-form";
import { CarExtrasFieldsProps } from "./model/car-extras.model";
import IconCaretDown from "@/components/icon/icon-caret-down";

type ExtraItemGroup = {
    group: string;
    items: string[];
};

const groupedExtras: ExtraItemGroup[] = [
    {
        group: 'comfort_multimedia',
        items: [
            "rear_seat_ac",
            "android_auto",
            "apple_carplay",
            "audi_smartphone_interface",
            "bluetooth",
            "wireless_charging",
            "virtual_cockpit",
            "trip_computer",
            "voice_control",
            "easy_open_close",
            "central_console_screen",
            "headrest_screen",
            "roof_screen",
            "touch_screen",
            "aux_input",
            "usb_input",
            "gps",
            "phone_gps",
            "head_up_display",
            "onboard_internet",
            "handsfree_kit",
            "dvd_player",
            "mp3_player",
            "mirror_link",
            "mmi",
            "automatic_trunk",
            "keyless_entry",
            "sound_system",
            "tv",
            "rear_usb",
            "usb_c",
            "wifi",
        ],
    },
    {
        group: 'exterior_equipment',
        items: [
            "aluminium_trim",
            "roof_bars",
            "fog_lights",
            "directional_headlights",
            "daytime_lights",
            "led_daytime_lights",
            "adjustable_headlights",
            "coming_leaving_home",
            "tow_hook",
            "removable_hardtop",
            "alloy_wheels",
            "rear_led_lights",
            "auto_side_doors",
            "auto_dimming_mirror",
            "heated_mirrors",
            "auto_dimming_side_mirrors",
            "electric_mirrors",
            "foldable_mirrors",
            "visual_tuning",
            "tinted_windows",
        ],
    },
    {
        group: 'interior_equipment',
        items: [
            "wood_trim",
            "armrest",
            "sport_seats",
            "front_seat_heating",
            "front_seat_memory",
            "electric_front_seats",
            "lumbar_support",
            "alcantara_seats",
            "orthopedic_seats",
            "foldable_seats",
            "rear_seat_heating",
            "individual_rear_seats",
            "ventilated_seats",
            "wireless_smartphone_charger",
            "voice_control_interior",
            "rear_window_curtains",
            "rear_headrests",
            "leather_gear_knob",
            "foldable_table",
            "non_smoker",
            "paddle_shifters",
            "radio_controls_steering",
            "sport_steering_wheel",
            "multi_function_steering",
        ],
    },
    {
        group: 'safety_performance',
        items: [
            "abs",
            "passenger_airbag",
            "driver_airbag",
            "side_airbags",
            "parking_assist",
            "alarm",
            "collision_alert",
            "maintenance_alert",
            "lane_departure_alert",
            "seatbelt_alert",
            "night_driving_assist",
            "lane_assist",
            "blind_spot_alert",
            "speed_alert",
            "traffic_sign_alert",
            "start_button",
            "camera_360",
            "front_camera",
            "rear_camera",
            "side_cameras",
            "cruise_control",
            "fatigue_detection",
            "driving_modes",
            "adaptive_steering",
            "power_steering",
            "eds_lock",
            "esp",
            "auto_door_lock_movement",
            "central_lock",
            "remote_lock",
            "auto_door_lock",
            "particle_filter",
            "immobilizer",
            "intarder",
            "isofix",
            "hydraulic_kit",
            "tyre_kit",
            "service_book",
            "msr",
            "driver_profiles",
            "retarder",
            "front_parking_sensor",
            "rear_parking_sensor",
            "rain_sensors",
            "light_sensors",
            "hill_start_assist",
            "keyless_system",
            "tyre_pressure_monitor",
            "auto_parking_system",
            "sos_system",
            "start_stop",
            "sport_suspension",
            "air_suspension",
            "tcs",
            "electric_tech",
            "electric_handbrake",
            "mechanical_tuning",
        ],
    },
];

export default function ComponentsCarExtrasFields({
    control,
    setValue,
    watch,
}: CarExtrasFieldsProps) {
    const { t } = getTranslation();

    const [activeGroup, setActiveGroup] = useState<string | null>(null);
    const toggleAccordion = (index: number) => {
        setActiveGroup((prev) => (prev === String(index) ? null : String(index)));
    };

    useEffect(() => {
        groupedExtras.forEach((group, index) => {
            setValue(`extras.${index}`, {
                group: group.group,
                items: [],
            }, { shouldDirty: false });
        });
    }, [setValue]);

    return (
        <>
            {groupedExtras.map((group, groupIndex) => {
                const selectedItems = watch(`extras.${groupIndex}.items`) || [];

                return (
                    <div key={group.group} className="col-span-12 pb-4">
                        <div className="rounded border border-[#d3d3d3] dark:border-[#1b2e4b]">
                            <button
                                type="button"
                                className={`font-bold text-base flex w-full items-center p-4 text-white-dark dark:bg-[#1b2e4b] ${activeGroup === String(groupIndex) ? "!text-primary" : ""
                                    }`}
                                onClick={() => toggleAccordion(groupIndex)}
                            >
                                {t(`cars.extras.group.${group.group}`)}
                                <span className="text-sm text-muted ml-2 ">
                                    {selectedItems.length}/{group.items.length}
                                </span>

                                <div
                                    className={`ltr:ml-auto rtl:mr-auto ${activeGroup === String(groupIndex) ? "rotate-180" : ""
                                        }`}
                                >
                                    <IconCaretDown />
                                </div>
                            </button>

                            <AnimateHeight duration={300} height={activeGroup === String(groupIndex) ? "auto" : 0}>
                                <Controller
                                    control={control}
                                    name={`extras.${groupIndex}.items`}
                                    defaultValue={[]}
                                    render={({ field: { value, onChange } }) => {
                                        const safeValue = Array.isArray(value) ? value : [];

                                        return (
                                            <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 p-4">
                                                {group.items.map((item) => (
                                                    <label key={item} className="inline-flex items-center gap-2">
                                                        <input
                                                            type="checkbox"
                                                            checked={safeValue.includes(item)}
                                                            onChange={() => {
                                                                const newValue = safeValue.includes(item)
                                                                    ? safeValue.filter((v: string) => v !== item)
                                                                    : [...safeValue, item];

                                                                onChange(newValue);
                                                            }}
                                                            className="form-checkbox outline-primary peer"
                                                        />
                                                        <span className="peer-checked:text-primary">{t(`cars.extras.${item}`)}</span>
                                                    </label>
                                                ))}
                                            </div>
                                        );
                                    }}
                                />
                            </AnimateHeight>
                        </div>
                    </div>
                );
            })}
        </>
    );
}