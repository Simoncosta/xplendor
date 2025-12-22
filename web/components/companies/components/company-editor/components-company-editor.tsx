'use client';

import { useForm, SubmitHandler, Controller, set } from "react-hook-form"
import { Component, useEffect, useState } from "react";
import { ICompany } from "../../models/companies.model";
import { ICompanyEditorProps } from "./model/company-editor.model";
import { getTranslation } from '@/i18n';
import UButton from "@/components/u-button";
import UInput from "@/components/u-input";
import ComponentsAddressFields from "./components/address/components-address-fields";
import ComponentsGeneralFields from "./components/general/components-general-fields";
import ComponentsFiscalFields from "./components/fiscal/components-fiscal-fields";
import ComponentsSocialFields from "./components/social/components-social-fields";

export default function ComponentsCompanyEditor({
    record,
    onSave,
    onCancel,
    disabled = false,
}: ICompanyEditorProps) {
    const { t } = getTranslation();
    const [loading, setLoading] = useState(false);
    const [logoCompany, setLogoCompany] = useState<string | null>(null);

    const {
        register,
        handleSubmit,
        watch,
        control,
        setValue,
        formState: { errors },
        reset,
    } = useForm<ICompany>({
        defaultValues: {}
    });

    useEffect(() => {
        if (record) {
            reset(record);
        }
    }, [record]);

    const onSubmit = (data: ICompany) => {
        if (onSave) {
            setLoading(true);
            // if (!logoCompany) {
            //     delete data.logo;
            // }

            onSave(data, (cancel: boolean) => {
                setLoading(false);
                if (!cancel) {
                    return
                }
            });
        }
    };

    const resolveImage = (img?: string) => {
        if (!img) return undefined;

        // se já for URL completa
        if (img.startsWith("http")) return img;

        // se for asset local do Next
        if (img.startsWith("/assets") || img.startsWith("/img")) return img;

        return `${process.env.NEXT_PUBLIC_API_URL}/storage/${img}`;
    }

    return (
        <form onSubmit={handleSubmit(onSubmit)} className={'panel grid grid-cols-12 gap-4'}>
            {/* <div className="w-full col-span-12 flex justify-center">
                <div
                    className="relative w-24 h-24 rounded-full border border-gray-300 dark:border-white flex items-center justify-center bg-gray-100 dark:bg-gray-700 cursor-pointer overflow-hidden group"
                    onClick={(e) => {
                        // Evita conflito: só abre seletor se não clicou no botão de download
                        const target = e.target as HTMLElement;
                        if (!target.closest('.download-btn')) {
                            document.getElementById('logo')?.click();
                        }
                    }}
                >
                    {(logoCompany || watch('logo')) ? (
                        <img
                            // @ts-ignore
                            src={
                                typeof logoCompany === 'string'
                                    ? logoCompany
                                    : typeof watch('logo') === 'string'
                                        ? resolveImage(String(watch('logo')))
                                        : undefined
                            }
                            alt={`Logo ${t('company.title')}`}
                            className="w-full h-full object-cover"
                            onError={(e) => {
                                (e.target as HTMLImageElement).style.display = 'none';
                            }}
                        />
                    ) : (
                        <span className="text-gray-500 dark:text-gray-300 text-sm font-medium text-center">
                            Logo {t('company.title')}
                        </span>
                    )}

                    <input
                        type="file"
                        accept="image/*"
                        hidden
                        id="logo"
                        onChange={(e) => {
                            const file = e.target.files?.[0];
                            if (!file) return;

                            // cria preview
                            const previewURL = URL.createObjectURL(file);
                            setLogoCompany(previewURL);

                            // Atualizar o campo do React Hook Form → ESSENCIAL
                            setValue("logo", file, { shouldValidate: true });

                            // limpa input
                            e.target.value = "";
                        }}
                    />
                </div>
            </div> */}

            {
                !record?.id && (
                    <section className="col-span-12 grid grid-cols-12 gap-4">
                        <h2 className='col-span-12 flex items-center font-extrabold border-b text-lg my-2 capitalize'>
                            {t('users.title')}
                        </h2>
                        <UInput
                            type='text'
                            label={t('users.form.name')}
                            name='name_user'
                            register={register}
                            className='col-span-12 md:col-span-8'
                            required
                        />
                        <UInput
                            type='email'
                            label={"Email"}
                            name='email_user'
                            register={register}
                            className='col-span-12 md:col-span-4'
                            required
                        />
                    </section>
                )
            }
            <section className="col-span-12 grid grid-cols-12 gap-4">
                <h2 className='col-span-12 flex items-center font-extrabold border-b text-lg my-2 capitalize'>
                    {t('company.form.registration_data')}
                </h2>
                <ComponentsGeneralFields
                    register={register}
                    control={control}
                    setValue={setValue}
                    watch={watch}
                />
            </section>
            <section className="col-span-12 grid grid-cols-12 gap-4">
                <h2 className='col-span-12 flex items-center font-extrabold border-b text-lg my-2 capitalize'>
                    {t('company.form.address')}
                </h2>
                <ComponentsAddressFields
                    register={register}
                    control={control}
                    setValue={setValue}
                    watch={watch}
                />
            </section>
            <section className="col-span-12 grid grid-cols-12 gap-4">
                <h2 className='col-span-12 flex items-center font-extrabold border-b text-lg my-2 capitalize'>
                    {t('company.form.fiscal')}
                </h2>
                <ComponentsFiscalFields
                    register={register}
                    control={control}
                    disabled={disabled}
                    setValue={setValue}
                    watch={watch}
                />
            </section>
            <section className="col-span-12 grid grid-cols-12 gap-4">
                <h2 className='col-span-12 flex items-center font-extrabold border-b text-lg my-2 capitalize'>
                    {t('company.form.social_media')}
                </h2>
                <ComponentsSocialFields
                    register={register}
                    control={control}
                    disabled={disabled}
                    setValue={setValue}
                    watch={watch}
                />
            </section>

            <div className="col-span-12 flex justify-end mt-4 gap-2">
                <UButton className="w-max" isOutline isRounded type="submit">
                    {t('config.save')}
                </UButton>
                <UButton className="w-max" variants="danger" isOutline isRounded type="button" onClick={() => { onCancel && onCancel(true) }}>
                    {t('config.cancel')}
                </UButton>
            </div>
        </form>
    );
}