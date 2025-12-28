import { getTranslation } from "@/i18n";
import Select from 'react-select';
import UInput from "@/components/u-input";
import { Controller } from "react-hook-form";
import { AdditionalDataFieldsProps } from "./model/additional-data.model";

export default function ComponentsAdditionalDataFields({
    register,
    control,
    disabled = false,
    setValue,
    watch
}: AdditionalDataFieldsProps) {

    // Translate
    const { t } = getTranslation();

    const warrantyOptions = [
        { label: t('cars.warranty.factory'), value: 'factory' },
        { label: t('cars.warranty.optional'), value: 'optional' },
        { label: t('cars.warranty.no_warranty'), value: 'no_warranty' },
        { label: t('cars.warranty.yes'), value: 'yes' },
        { label: t('cars.warranty.consult'), value: 'consult' },
        { label: t('cars.warranty.3_months'), value: '3_months' },
        { label: t('cars.warranty.6_months'), value: '6_months' },
        { label: t('cars.warranty.1_year_factory'), value: '1_year_factory' },
        { label: t('cars.warranty.12m_10000km'), value: '12m_10000km' },
        { label: t('cars.warranty.12m_15000km'), value: '12m_15000km' },
        { label: t('cars.warranty.18m_mutual'), value: '18m_mutual' },
        { label: t('cars.warranty.18m_10000km'), value: '18m_10000km' },
        { label: t('cars.warranty.18m_12000km'), value: '18m_12000km' },
        { label: t('cars.warranty.24m_mutual'), value: '24m_mutual' },
        { label: t('cars.warranty.36m_or_18m_mutual'), value: '36m_or_18m_mutual' },
        { label: t('cars.warranty.1y_mutual'), value: '1y_mutual' },
        { label: t('cars.warranty.2y'), value: '2y' },
        { label: t('cars.warranty.2y_purchased'), value: '2y_purchased' },
        { label: t('cars.warranty.2y_factory'), value: '2y_factory' },
        { label: t('cars.warranty.2y_30000km'), value: '2y_30000km' },
        { label: t('cars.warranty.2y_40000km'), value: '2y_40000km' },
        { label: t('cars.warranty.2y_mutual'), value: '2y_mutual' },
        { label: t('cars.warranty.3y'), value: '3y' },
        { label: t('cars.warranty.3y_purchased'), value: '3y_purchased' },
        { label: t('cars.warranty.3y_factory'), value: '3y_factory' },
        { label: t('cars.warranty.4y'), value: '4y' },
        { label: t('cars.warranty.4y_factory'), value: '4y_factory' },
        { label: t('cars.warranty.5y'), value: '5y' },
        { label: t('cars.warranty.5y_factory'), value: '5y_factory' },
        { label: t('cars.warranty.6y_factory'), value: '6y_factory' },
        { label: t('cars.warranty.7y_factory'), value: '7y_factory' },
        { label: t('cars.warranty.8y'), value: '8y' },
    ];

    return (
        <>
            <UInput
                name="co2_emissions"
                type="text"
                label="CO2"
                register={register}
                className="col-span-12 md:col-span-3"
            />
            <UInput
                name="toll_class"
                type="text"
                label={t('cars.form.toll_class')}
                register={register}
                className="col-span-12 md:col-span-3"
            />
            <UInput
                name="cylinders"
                type="text"
                label={t('cars.form.cylinders')}
                register={register}
                className="col-span-12 md:col-span-3"
            />
            <div className="col-span-12 sm:col-span-3">
                <Controller
                    name="warranty_available"
                    control={control}
                    render={({ field }) => (
                        <>
                            <label className="block font-medium mb-1">
                                {t('cars.form.warranty_available')}:
                            </label>

                            <Select
                                placeholder={t('config.form.select')}
                                className="custom-select"
                                classNamePrefix="custom-select"
                                options={warrantyOptions}
                                value={warrantyOptions.find((s: any) => s.value === field.value)}
                                onChange={async (value: any) => {
                                    field.onChange(value?.value || null);
                                }}
                            />
                        </>
                    )}
                />
            </div>
            <UInput
                name="warranty_due_date"
                type="date"
                label={t('cars.form.warranty_due_date')}
                register={register}
                className="col-span-12 md:col-span-2"
            />
            <UInput
                name="warranty_km"
                type="number"
                label={t('cars.form.warranty_km')}
                register={register}
                className="col-span-12 md:col-span-2"
            />
            <UInput
                name="service_records"
                type="number"
                label={t('cars.form.service_records')}
                register={register}
                className="col-span-12 md:col-span-2"
            />
            <UInput
                name="has_spare_key"
                type="checkbox"
                label={t('cars.form.spare_key')}
                register={register}
                className="col-span-12 md:col-span-2"
            />
            <UInput
                name="has_manuals"
                type="checkbox"
                label={t('cars.form.manuals')}
                register={register}
                className="col-span-12 md:col-span-1"
            />
        </>
    )
}
