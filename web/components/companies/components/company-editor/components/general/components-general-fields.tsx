import { getTranslation } from "@/i18n";
import { GeneralFieldsProps } from "./model/general.model";
import UInput from "@/components/u-input";

export default function ComponentsGeneralFields({
    register,
    control,
    disabled = false,
    setValue,
    watch
}: GeneralFieldsProps) {

    // Translate
    const { t } = getTranslation();

    return (
        <>
            <UInput
                required
                type='text'
                label='NIF'
                name='nipc'
                register={register}
                className='col-span-12 md:col-span-2'
            />
            <UInput
                required
                type='text'
                label={t('company.form.name')}
                name='fiscal_name'
                register={register}
                className='col-span-12 md:col-span-5'
            />
            <UInput
                required
                type='text'
                label={t('company.form.commercial_name')}
                name='trade_name'
                register={register}
                className='col-span-12 md:col-span-5'
            />
            <UInput
                type='text'
                label={t('company.form.responsible_name')}
                name='responsible_name'
                register={register}
                className='col-span-12 md:col-span-6'
            />
            <UInput
                type='email'
                label='Email'
                name='email'
                register={register}
                className='col-span-12 md:col-span-3'
            />
            <UInput
                type='email'
                label={t('company.form.invoice_email')}
                name='invoice_email'
                register={register}
                className='col-span-12 md:col-span-3'
            />
            <UInput
                type='text'
                label={t('company.form.phone')}
                name='phone'
                register={register}
                className='col-span-12 md:col-span-3'
            />
            <UInput
                type='text'
                label={t('company.form.mobile')}
                name='mobile'
                register={register}
                className='col-span-12 md:col-span-3'
            />
            <UInput
                type='url'
                label='Website'
                name='website'
                register={register}
                className='col-span-12 md:col-span-6'
            />
        </>
    )
}