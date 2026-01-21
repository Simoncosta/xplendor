import { getTranslation } from "@/i18n";
import { Controller } from "react-hook-form";
import Select from 'react-select';
import UInput from "@/components/u-input";
import { SocialFieldsProps } from "./model/social.model";
import IconFacebook from "@/components/icon/icon-facebook";
import IconInstagram from "@/components/icon/icon-instagram";
import IconGoogle from "@/components/icon/icon-google";
import IconVideo from "@/components/icon/icon-video";

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
            <div className="col-span-6 flex">
                <div className="flex items-center justify-center rounded bg-[#eee] px-3 font-semibold ltr:mr-2 rtl:ml-2 dark:bg-[#1b2e4b]">
                    <IconFacebook className="w-5 h-5" />
                </div>
                <UInput
                    type='text'
                    label={"Facebook"}
                    placeholder="https://www.facebook.com/profile.php?id=61572335614167"
                    name='facebook'
                    register={register}
                    className="w-full"
                />
            </div>
            <div className="col-span-6 flex">
                <div className="flex items-center justify-center rounded bg-[#eee] px-3 font-semibold ltr:mr-2 rtl:ml-2 dark:bg-[#1b2e4b]">
                    <IconInstagram className="w-5 h-5" />
                </div>
                <UInput
                    type='text'
                    label={"Instagram"}
                    placeholder="https://www.instagram.com/p_a.automoveis/"
                    name='instagram'
                    register={register}
                    className="w-full"
                />
            </div>
            <div className="col-span-6 flex">
                <div className="flex items-center justify-center rounded bg-[#eee] px-3 font-semibold ltr:mr-2 rtl:ml-2 dark:bg-[#1b2e4b]">
                    <IconGoogle className="w-5 h-5" />
                </div>
                <UInput
                    type='text'
                    label={"Google"}
                    placeholder="https://share.google/TIkLetlzkEUQHsWLf"
                    name='google'
                    register={register}
                    className="w-full"
                />
            </div>
            <div className="col-span-6 flex">
                <div className="flex items-center justify-center rounded bg-[#eee] px-3 font-semibold ltr:mr-2 rtl:ml-2 dark:bg-[#1b2e4b]">
                    <IconVideo className="w-5 h-5" />
                </div>
                <UInput
                    type='text'
                    label={"Youtube"}
                    placeholder="https://www.youtube.com/@paautomoveis"
                    name='youtube'
                    register={register}
                    className="w-full"
                />
            </div>
            <div className="col-span-6 flex">
                <div className="flex items-center justify-center rounded bg-[#eee] px-3 font-semibold ltr:mr-2 rtl:ml-2 dark:bg-[#1b2e4b]">
                    www
                </div>
                <UInput
                    type='text'
                    label={"Website"}
                    name='website'
                    placeholder="https://paautomoveis.pt/"
                    register={register}
                    className="w-full"
                />
            </div>
        </>
    )
}