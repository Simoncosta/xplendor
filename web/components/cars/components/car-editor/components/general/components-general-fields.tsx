import { useEffect, useMemo, useState } from "react";
import { getTranslation } from "@/i18n";
import Select from 'react-select';
import { GeneralFieldsProps } from "./model/general.model";
import UInput from "@/components/u-input";
import { Controller } from "react-hook-form";
import { CarBrandService } from "@/components/car-brands/service/car-brands.service";
import api from "@/services/axiosInstance";
import { ICarBrand } from "@/components/car-brands/model/car-brands.model";
import { CarModelService } from "@/components/car-models/service/car-models.service";

export default function ComponentsGeneralFields({
    register,
    control,
    disabled = false,
    setValue,
    watch
}: GeneralFieldsProps) {

    // Translate
    const { t } = getTranslation();

    // Cache
    const carBrandsService = useMemo(() => new CarBrandService(api), []);
    const carModelsService = useMemo(() => new CarModelService(api), []);

    // State
    const [carBrands, setCarBrands] = useState<ICarBrand[]>([]);
    const [carModels, setCarModels] = useState<ICarBrand[]>([]);

    // Effects
    useEffect(() => {
        carBrandsService.getCarBrands(null).then(res => setCarBrands(res));
    }, []);

    useEffect(() => {
        if (watch("car_brand_id")) {
            carModelsService.getCarModels(null, { car_brand_id: Number(watch("car_brand_id")) })
                .then(res => setCarModels(res));
        }
    }, [watch("car_brand_id")]);

    const statuses = [
        { value: "draft", label: t('cars.status.draft') },
        { value: "active", label: t('cars.status.active') },
        { value: "inactive", label: t('cars.status.inactive') },
        { value: "sold", label: t('cars.status.sold') },
        { value: "available_soon", label: t('cars.status.available_soon') },
    ];

    const origins = [
        { value: "national", label: t('cars.origin.national') },
        { value: "imported", label: t('cars.origin.imported') },
    ];

    const months = [
        { value: 1, label: 'Janeiro' },
        { value: 2, label: 'Fevereiro' },
        { value: 3, label: 'Março' },
        { value: 4, label: 'Abril' },
        { value: 5, label: 'Maio' },
        { value: 6, label: 'Junho' },
        { value: 7, label: 'Julho' },
        { value: 8, label: 'Agosto' },
        { value: 9, label: 'Setembro' },
        { value: 10, label: 'Outubro' },
        { value: 11, label: 'Novembro' },
        { value: 12, label: 'Dezembro' },
    ];

    const years = Array.from({ length: 27 }, (_, i) => new Date().getFullYear() - i);

    const yearOptions = years.map(year => ({
        label: year,
        value: year
    }));

    const fuelTypeOptions = [
        { label: t('cars.fuel_type.diesel'), value: 'diesel' },
        { label: t('cars.fuel_type.petrol'), value: 'petrol' },
        { label: t('cars.fuel_type.hybrid'), value: 'hybrid' },
        { label: t('cars.fuel_type.electric'), value: 'electric' },
        { label: t('cars.fuel_type.plugin_hybrid'), value: 'plugin_hybrid' },
        { label: t('cars.fuel_type.gpl'), value: 'gpl' },
        { label: t('cars.fuel_type.cng'), value: 'cng' },
        { label: t('cars.fuel_type.hydrogen'), value: 'hydrogen' },
        { label: t('grid.other'), value: 'other' },
    ];

    const transmissionOptions = [
        { label: t('cars.transmission.manual'), value: 'manual' },
        { label: t('cars.transmission.automatic'), value: 'automatic' },
        { label: t('cars.transmission.semi_automatic'), value: 'semi_automatic' },
        { label: t('cars.transmission.dual_clutch'), value: 'dual_clutch' },
        { label: t('cars.transmission.cvt'), value: 'cvt' },
        { label: t('grid.other'), value: 'other' },
    ];

    return (
        <>
            <div className="col-span-12 sm:col-span-3">
                <Controller
                    name="status"
                    control={control}
                    rules={{ required: true }}
                    render={({ field }) => (
                        <>
                            <label className="block font-medium mb-1">
                                Status:
                                <span className="text-danger ml-1">*</span>
                            </label>

                            <Select
                                placeholder={t('config.form.select')}
                                className="custom-select"
                                classNamePrefix="custom-select"
                                options={statuses}
                                value={statuses.find((s: any) => s.value === field.value)}
                                onChange={async (value: any) => {
                                    field.onChange(value?.value || null);
                                }}
                                required
                            />
                        </>
                    )}
                />
            </div>
            <div className="col-span-12 sm:col-span-3">
                <Controller
                    name="origin"
                    control={control}
                    rules={{ required: true }}
                    render={({ field }) => (
                        <>
                            <label className="block font-medium mb-1">
                                {t('cars.form.origin')}:
                                <span className="text-danger ml-1">*</span>
                            </label>

                            <Select
                                placeholder={t('config.form.select')}
                                className="custom-select"
                                classNamePrefix="custom-select"
                                options={origins}
                                value={origins.find((s: any) => s.value === field.value)}
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
                className="col-span-3"
                name="license_plate"
                type="text"
                label={t('grid.license_plate')}
                register={register}
            />
            <UInput
                className="col-span-3"
                name="vin"
                type="text"
                label="VIN"
                register={register}
            />
            <div className="col-span-12 sm:col-span-4">
                <Controller
                    name="car_brand_id"
                    control={control}
                    rules={{ required: true }}
                    render={({ field }) => (
                        <>
                            <label className="block font-medium mb-1">
                                {t('grid.brand')}:
                                <span className="text-danger ml-1">*</span>
                            </label>

                            <Select
                                placeholder={t('config.form.select')}
                                className="custom-select"
                                classNamePrefix="custom-select"
                                options={carBrands}
                                getOptionLabel={(option) => option.name}
                                getOptionValue={(option) => option.id}
                                value={carBrands.find((s: any) => s.id === field.value)}
                                onChange={async (value: any) => {
                                    field.onChange(value?.id || null);
                                    setValue("car_model_id", null);
                                }}
                                required
                            />
                        </>
                    )}
                />
            </div>
            <div className="col-span-12 sm:col-span-4">
                <Controller
                    name="car_model_id"
                    control={control}
                    rules={{ required: true }}
                    render={({ field }) => (
                        <>
                            <label className="block font-medium mb-1">
                                {t('grid.model')}:
                                <span className="text-danger ml-1">*</span>
                            </label>

                            <Select
                                placeholder={t('config.form.select')}
                                className="custom-select"
                                classNamePrefix="custom-select"
                                options={[
                                    {
                                        label: 'Recomendados',
                                        options: carModels
                                            .filter((m: any) => m.type === 'recommended')
                                            .map((m: any) => ({ value: m.id, label: m.name }))
                                    },
                                    {
                                        label: 'Outros',
                                        options: carModels
                                            .filter((m: any) => m.type === 'other')
                                            .map((m: any) => ({ value: m.id, label: m.name }))
                                    }
                                ]}
                                value={carModels
                                    .map(m => ({ value: m.id, label: m.name }))
                                    .find(option => option.value === field.value)
                                }
                                onChange={value => {
                                    field.onChange(value?.value || null);
                                }}
                                isDisabled={!watch("car_brand_id")}
                                formatGroupLabel={formatGroupLabel}
                                required
                            />
                        </>
                    )}
                />
            </div>
            <div className="col-span-12 sm:col-span-2">
                <Controller
                    name="registration_month"
                    control={control}
                    rules={{ required: true }}
                    render={({ field }) => (
                        <>
                            <label className="block font-medium mb-1">
                                {t('grid.month')}:
                                <span className="text-danger ml-1">*</span>
                            </label>

                            <Select
                                placeholder={t('config.form.select')}
                                className="custom-select"
                                classNamePrefix="custom-select"
                                options={months}
                                value={months.find((s: any) => s.value === field.value)}
                                onChange={async (value: any) => {
                                    field.onChange(value?.value || null);
                                }}
                                isDisabled={!watch("car_brand_id")}
                                required
                            />
                        </>
                    )}
                />
            </div>
            <div className="col-span-12 sm:col-span-2">
                <Controller
                    name="registration_year"
                    control={control}
                    rules={{ required: true }}
                    render={({ field }) => (
                        <>
                            <label className="block font-medium mb-1">
                                {t('grid.year')}:
                                <span className="text-danger ml-1">*</span>
                            </label>

                            <Select
                                placeholder={t('config.form.select')}
                                className="custom-select"
                                classNamePrefix="custom-select"
                                options={yearOptions}
                                value={yearOptions.find((s: any) => s.value === field.value)}
                                onChange={async (value: any) => {
                                    field.onChange(value?.value || null);
                                }}
                                isDisabled={!watch("car_brand_id")}
                                required
                            />
                        </>
                    )}
                />
            </div>
            <div className="col-span-12 sm:col-span-3">
                <Controller
                    name="fuel_type"
                    control={control}
                    rules={{ required: true }}
                    render={({ field }) => (
                        <>
                            <label className="block font-medium mb-1">
                                {t('grid.fuel_type')}:
                                <span className="text-danger ml-1">*</span>
                            </label>

                            <Select
                                placeholder={t('config.form.select')}
                                className="custom-select"
                                classNamePrefix="custom-select"
                                options={fuelTypeOptions}
                                value={fuelTypeOptions.find((s: any) => s.value === field.value)}
                                onChange={async (value: any) => {
                                    field.onChange(value?.value || null);
                                }}
                                isDisabled={!watch("car_brand_id")}
                                required
                            />
                        </>
                    )}
                />
            </div>
            <UInput
                name="engine_capacity_cc"
                type="text"
                label={t('cars.form.engine_capacity_cc')}
                register={register}
                className="col-span-12 md:col-span-3"
                required
            />
            <UInput
                name="power_hp"
                type="text"
                label={t('cars.form.power_hp')}
                register={register}
                className="col-span-12 md:col-span-3"
                required
            />
            <UInput
                name="doors"
                type="text"
                label={t('cars.form.doors')}
                register={register}
                className="col-span-12 md:col-span-3"
                required
            />
            <div className="col-span-12 sm:col-span-3">
                <Controller
                    name="transmission"
                    control={control}
                    rules={{ required: true }}
                    render={({ field }) => (
                        <>
                            <label className="block font-medium mb-1">
                                {t('grid.transmission')}:
                                <span className="text-danger ml-1">*</span>
                            </label>

                            <Select
                                placeholder={t('config.form.select')}
                                className="custom-select"
                                classNamePrefix="custom-select"
                                options={transmissionOptions}
                                value={transmissionOptions.find((s: any) => s.value === field.value)}
                                onChange={async (value: any) => {
                                    field.onChange(value?.value || null);
                                }}
                                isDisabled={!watch("car_brand_id")}
                                required
                            />
                        </>
                    )}
                />
            </div>
            <UInput
                name="version"
                type="text"
                label={t('cars.form.version')}
                register={register}
                className="col-span-12 md:col-span-3"
                required
            />
            <UInput
                name="public_version_name"
                type="text"
                label={t('cars.form.public_version_name')}
                register={register}
                className="col-span-12 md:col-span-6"
            />
        </>
    )
}

const formatGroupLabel = (data: any) => (
    <div className="flex items-center justify-between">
        <span>{data.label}</span>
        <span>{data.options.length}</span>
    </div>
);