import { getTranslation } from "@/i18n";
import { Controller } from "react-hook-form";
import Select from 'react-select';
import UInput from "@/components/u-input";
import { SocialFieldsProps } from "./model/social.model";

export default function ComponentsSocialFields({
    register,
    control,
    disabled = false,
    setValue,
    watch
}: SocialFieldsProps) {

    // Translate
    const { t } = getTranslation();

    const leadDistribution = [
        { value: 'manual', label: t('company.form.lead_distribution.manual') },
        { value: 'automatic_latest', label: t('company.form.lead_distribution.automatic_latest') },
        { value: 'automatic_less', label: t('company.form.lead_distribution.automatic_less') },
    ]

    return (
        <>
            <UInput
                type='text'
                label={t('company.form.facebook_page_id')}
                name='facebook_page_id'
                register={register}
                className='col-span-12 md:col-span-4'
            />
            <UInput
                type='text'
                label={t('company.form.facebook_pixel_id')}
                name='facebook_pixel_id'
                register={register}
                className='col-span-12 md:col-span-4'
            />
            <UInput
                type='text'
                label={t('company.form.facebook_access_token')}
                name='facebook_access_token'
                register={register}
                className='col-span-12 md:col-span-4'
            />
            <UInput
                type='text'
                label={t('company.form.website')}
                name='website'
                register={register}
                className='col-span-12 md:col-span-5'
            />
            <UInput
                type='number'
                label={t('company.form.lead_hours_pending')}
                name='lead_hours_pending'
                register={register}
                className='col-span-12 md:col-span-3'
            />
            <div className="col-span-12 sm:col-span-4">
                <Controller
                    name="lead_distribution"
                    control={control}
                    render={({ field }) => (
                        <>
                            <label className="block font-medium mb-1">{t('company.form.lead_distribution')}:</label>
                            <Select
                                placeholder={t('config.form.select')}
                                className="custom-select"
                                classNamePrefix="custom-select"
                                options={leadDistribution}
                                value={
                                    field.value
                                        ? leadDistribution.find((d: any) => d.value === field.value)
                                        : leadDistribution[0]
                                }
                                onChange={async (value: any) => {
                                    field.onChange(value?.value || leadDistribution[0].value);
                                }}
                            />
                        </>
                    )}
                />
            </div>
            <div className="col-span-12">
                <Controller
                    name="ad_text"
                    control={control}
                    render={({ field }) => (
                        <>
                            <label className="block font-medium mb-1">{t('company.form.ad_text')}:</label>
                            <textarea
                                {...field}
                                rows={3}
                                className="form-textarea resize-none placeholder:text-white-dark"
                                placeholder={t('company.form.ad_text')}
                            />
                        </>
                    )}
                />
            </div>
        </>
    )
}