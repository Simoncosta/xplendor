import { getTranslation } from "@/i18n";
import { Controller } from "react-hook-form";
import Select from 'react-select';
import { FiscalFieldsProps } from "./model/fiscal.model";
import UInput from "@/components/u-input";

export default function ComponentsFiscalFields({
    register,
    control,
    disabled = false,
    setValue,
    watch
}: FiscalFieldsProps) {

    // Translate
    const { t } = getTranslation();

    const vatValue = [
        { value: 23, label: "23% - Continente" },
        { value: 22, label: "22% - Região autónoma da Madeira" },
        { value: 16, label: "16% - Região autónoma dos Açores" },
    ]

    return (
        <>
            <UInput
                type='text'
                label={t('company.form.registry_office')}
                name='registry_office'
                register={register}
                className='col-span-12 md:col-span-3'
            />
            <UInput
                type='text'
                label={t('company.form.registry_office_number')}
                name='registry_office_number'
                register={register}
                className='col-span-12 md:col-span-3'
            />
            <UInput
                type='text'
                label={t('company.form.capital_social')}
                name='capital_social'
                register={register}
                className='col-span-12 md:col-span-3'
            />
            <UInput
                type='text'
                label='NIB'
                name='nib'
                register={register}
                className='col-span-12 md:col-span-3'
            />
            <div className="col-span-12 sm:col-span-3">
                <Controller
                    name="vat_value"
                    control={control}
                    render={({ field }) => (
                        <>
                            <label className="block font-medium mb-1">{t('company.form.vat_value')}:</label>
                            <Select
                                placeholder={t('config.form.select')}
                                className="custom-select"
                                classNamePrefix="custom-select"
                                options={vatValue}
                                value={
                                    field.value
                                        ? vatValue.find((d: any) => d.value === field.value)
                                        : 23
                                }
                                onChange={async (value: any) => {
                                    field.onChange(value?.value || 23);
                                }}
                            />
                        </>
                    )}
                />
            </div>
            <UInput
                type='number'
                label={t('company.form.registration_fees')}
                name='registration_fees'
                register={register}
                className='col-span-12 md:col-span-3'
            />
            <UInput
                type='url'
                label={t('company.form.credit_intermediation_link')}
                name='credit_intermediation_link'
                register={register}
                className='col-span-12 md:col-span-6'
            />
            <UInput
                type='checkbox'
                label={t('company.form.export_promotion_price')}
                name='export_promotion_price'
                register={register}
                className='col-span-12 md:col-span-3'

            />
        </>
    )
}