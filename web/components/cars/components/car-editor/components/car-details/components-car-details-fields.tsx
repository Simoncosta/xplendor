import { CarDetailsFieldsProps } from "./model/car-details.model";
import { getTranslation } from "@/i18n";
import Select from 'react-select';
import UInput from "@/components/u-input";
import { Controller } from "react-hook-form";

export default function ComponentsCarDetailsFields({
    register,
    control,
    disabled = false,
    setValue,
    watch
}: CarDetailsFieldsProps) {
    // Translate
    const { t } = getTranslation();

    const segmentOptions = [
        { label: t('cars.segment.smal_city_car'), value: 'smal_city_car' },
        { label: t('cars.segment.city_car'), value: 'city_car' },
        { label: t('cars.segment.utility'), value: 'utility' },
        { label: t('cars.segment.sedan'), value: 'sedan' },
        { label: t('cars.segment.wagon'), value: 'wagon' },
        { label: t('cars.segment.minivam'), value: 'minivam' },
        { label: t('cars.segment.suv_tt'), value: 'suv_tt' },
        { label: t('cars.segment.convertible'), value: 'convertible' },
        { label: t('cars.segment.coupe'), value: 'coupe' },
        { label: t('cars.segment.pick_up'), value: 'pick_up' },
        { label: t('cars.segment.goods_pick_up'), value: 'goods_pick_up' },
        { label: t('cars.segment.vans'), value: 'vans' },
        { label: t('cars.segment.light_commercial'), value: 'light_commercial' },
        { label: t('cars.segment.motorbikes'), value: 'motorbikes' },
        { label: t('cars.segment.boat'), value: 'boat' },
        { label: t('cars.segment.machines_outros'), value: 'machines_outros' },
        { label: t('cars.segment.motorhomes'), value: 'motorhomes' },
        { label: t('cars.segment.caravan'), value: 'caravan' },
        { label: t('cars.segment.mobile_homes'), value: 'mobile_homes' },
        { label: t('cars.segment.tow_truck'), value: 'tow_truck' },
        { label: t('cars.segment.heavy_goods_vehicle'), value: 'heavy_goods_vehicle' },
        { label: t('cars.segment.heavy_passenger_vehicle'), value: 'heavy_passenger_vehicle' },
        { label: t('cars.segment.trailer'), value: 'trailer' },
        { label: t('cars.segment.semi_trailer'), value: 'semi_trailer' },
        { label: t('cars.segment.commerciais_to_35t'), value: 'commerciais_to_35t' },
        { label: t('cars.segment.backhoe_loader'), value: 'backhoe_loader' },
        { label: t('cars.segment.quadricycle'), value: 'quadricycle' },
        { label: t('cars.segment.special_cleaning_vehicle'), value: 'special_cleaning_vehicle' },
        { label: t('cars.segment.tractor'), value: 'tractor' },
        { label: t('cars.segment.truck_cranes'), value: 'truck_cranes' },
        { label: t('cars.segment.mini_excavators'), value: 'mini_excavators' },
        { label: t('cars.segment.generators'), value: 'generators' },
        { label: t('cars.segment.compressors'), value: 'compressors' },
        { label: t('cars.segment.concrete_mixers'), value: 'concrete_mixers' },
        { label: t('cars.segment.forklifts'), value: 'forklifts' },
        { label: t('cars.segment.aerial_lifts'), value: 'aerial_lifts' },
        { label: t('cars.segment.multifunction_machines'), value: 'multifunction_machines' },
        { label: t('cars.segment.bulldozer'), value: 'bulldozer' },
        { label: t('cars.segment.excavators'), value: 'excavators' },
        { label: t('cars.segment.telescopic_cranes'), value: 'telescopic_cranes' },
        { label: t('cars.segment.dumpers'), value: 'dumpers' },
        { label: t('cars.segment.wheel_loaders'), value: 'wheel_loaders' },
        { label: t('cars.segment.compactor_rollers'), value: 'compactor_rollers' },
        { label: t('cars.segment.motor_graders'), value: 'motor_graders' },
        { label: t('cars.segment.kart'), value: 'kart' },
        { label: t('cars.segment.ampirrol'), value: 'ampirrol' },
    ];

    const colorOptions = [
        { label: t('cars.color.blue'), value: 'blue' },
        { label: t('cars.color.light_blue'), value: 'light_blue' },
        { label: t('cars.color.yellow'), value: 'yellow' },
        { label: t('cars.color.dark_blue'), value: 'dark_blue' },
        { label: t('cars.color.beige'), value: 'beige' },
        { label: t('cars.color.bordeaux'), value: 'bordeaux' },
        { label: t('cars.color.white'), value: 'white' },
        { label: t('cars.color.brown'), value: 'brown' },
        { label: t('cars.color.champagne'), value: 'champagne' },
        { label: t('cars.color.grey'), value: 'grey' },
        { label: t('cars.color.anthracite_grey'), value: 'anthracite_grey' },
        { label: t('cars.color.silver_grey'), value: 'silver_grey' },
        { label: t('cars.color.orange'), value: 'orange' },
        { label: t('cars.color.black'), value: 'black' },
        { label: t('cars.color.pink'), value: 'pink' },
        { label: t('cars.color.purple'), value: 'purple' },
        { label: t('cars.color.light_green'), value: 'light_green' },
        { label: t('cars.color.dark_green'), value: 'dark_green' },
        { label: t('cars.color.red'), value: 'red' },
        { label: t('cars.color.other'), value: 'other' },
    ];

    const conditionOptions = [
        { label: t('cars.condition.new'), value: 'new' },
        { label: t('cars.condition.used'), value: 'used' },
        { label: t('cars.condition.like_new'), value: 'like_new' },
        { label: t('cars.condition.good'), value: 'good' },
        { label: t('cars.condition.service'), value: 'service' },
        { label: t('cars.condition.trade_in'), value: 'trade_in' },
        { label: t('cars.condition.classic'), value: 'classic' },
    ];

    return (
        <>
            <div className="col-span-12 sm:col-span-3">
                <Controller
                    name="segment"
                    control={control}
                    rules={{ required: true }}
                    render={({ field }) => (
                        <>
                            <label className="block font-medium mb-1">
                                {t('cars.form.segment')}:
                                <span className="text-danger ml-1">*</span>
                            </label>

                            <Select
                                placeholder={t('config.form.select')}
                                className="custom-select"
                                classNamePrefix="custom-select"
                                options={segmentOptions}
                                value={segmentOptions.find((s: any) => s.value === field.value)}
                                onChange={async (value: any) => {
                                    field.onChange(value?.value || null);
                                }}
                                required
                            />
                        </>
                    )}
                />
            </div>
            <UInput
                name="seats"
                type="number"
                label={t('cars.form.seats')}
                register={register}
                className="col-span-12 md:col-span-3"
                required
            />
            <div className="col-span-12 sm:col-span-3">
                <Controller
                    name="exterior_color"
                    control={control}
                    rules={{ required: true }}
                    render={({ field }) => (
                        <>
                            <label className="block font-medium mb-1">
                                {t('cars.form.exterior_color')}:
                                <span className="text-danger ml-1">*</span>
                            </label>

                            <Select
                                placeholder={t('config.form.select')}
                                className="custom-select"
                                classNamePrefix="custom-select"
                                options={colorOptions}
                                value={colorOptions.find((s: any) => s.value === field.value)}
                                onChange={async (value: any) => {
                                    field.onChange(value?.value || null);
                                }}
                                required
                            />
                        </>
                    )}
                />
            </div>
            <UInput
                name="is_metallic"
                type="checkbox"
                label={t('cars.form.metallic')}
                register={register}
                className="col-span-12 md:col-span-3"
            />
            <div className="col-span-12 sm:col-span-3">
                <Controller
                    name="condition"
                    control={control}
                    rules={{ required: true }}
                    render={({ field }) => (
                        <>
                            <label className="block font-medium mb-1">
                                {t('cars.form.condition')}:
                                <span className="text-danger ml-1">*</span>
                            </label>

                            <Select
                                placeholder={t('config.form.select')}
                                className="custom-select"
                                classNamePrefix="custom-select"
                                options={conditionOptions}
                                value={conditionOptions.find((s: any) => s.value === field.value)}
                                onChange={async (value: any) => {
                                    field.onChange(value?.value || null);
                                }}
                                required
                            />
                        </>
                    )}
                />
            </div>
            <UInput
                name="mileage_km"
                type="text"
                label={t('cars.form.mileage_km')}
                register={register}
                className="col-span-12 md:col-span-3"
            />
            <div className="col-span-12 sm:col-span-3">
                <Controller
                    name="interior_color"
                    control={control}
                    render={({ field }) => (
                        <>
                            <label className="block font-medium mb-1">
                                {t('cars.form.interior_color')}:
                            </label>

                            <Select
                                placeholder={t('config.form.select')}
                                className="custom-select"
                                classNamePrefix="custom-select"
                                options={colorOptions}
                                value={colorOptions.find((s: any) => s.value === field.value)}
                                onChange={async (value: any) => {
                                    field.onChange(value?.value || null);
                                }}
                            />
                        </>
                    )}
                />
            </div>
        </>
    )
}