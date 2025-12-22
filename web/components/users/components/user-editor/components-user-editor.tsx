'use client';

import { Controller, useForm } from "react-hook-form";
import { useEffect, useState } from "react";
import Select from 'react-select';
import { getTranslation } from "@/i18n";
import UButton from "@/components/u-button";
import UInput from "@/components/u-input";
import { IUserEditorProps } from "./model/condominium-user.model";
import { IUsers } from "../../models/user.model";
import { useSelector } from "react-redux";
import { IRootState } from "@/store";

export default function ComponentsUserEditor({
    record,
    onSave,
    onCancel,
    disabled = false,
}: IUserEditorProps) {
    const { t } = getTranslation();

    const genders = [
        { label: t('grid.gender.male'), value: 'male' },
        { label: t('grid.gender.female'), value: 'female' },
    ];

    // Context
    const { user } = useSelector((state: IRootState) => state.auth);

    // States
    const [loading, setLoading] = useState(false);
    const [avatarUser, setAvatarUser] = useState<string | null>(null);

    const {
        register,
        handleSubmit,
        watch,
        control,
        setValue,
        formState: { errors },
        reset,
    } = useForm<IUsers>({
        defaultValues: {}
    });

    useEffect(() => {
        if (record) {
            reset(record);
        }
    }, [record]);

    const onSubmit = (data: IUsers) => {
        if (onSave) {
            setLoading(true);
            if (!avatarUser) {
                delete data.avatar;
            }

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
            <div className="w-full col-span-12 flex justify-center">
                <div
                    className="relative w-24 h-24 rounded-full border border-gray-300 dark:border-white flex items-center justify-center bg-gray-100 dark:bg-gray-700 cursor-pointer overflow-hidden group"
                    onClick={(e) => {
                        // Evita conflito: só abre seletor se não clicou no botão de download
                        const target = e.target as HTMLElement;
                        if (!target.closest('.download-btn')) {
                            document.getElementById('avatar')?.click();
                        }
                    }}
                >
                    {(avatarUser || watch('avatar')) ? (
                        <img
                            // @ts-ignore
                            src={
                                typeof avatarUser === 'string'
                                    ? avatarUser
                                    : typeof watch('avatar') === 'string'
                                        ? resolveImage(String(watch('avatar')))
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
                            Avatar
                        </span>
                    )}

                    {/* Input escondido */}
                    <input
                        type="file"
                        accept="image/*"
                        hidden
                        id="avatar"
                        onChange={(e) => {
                            const file = e.target.files?.[0];
                            if (!file) return;

                            // cria preview
                            const previewURL = URL.createObjectURL(file);
                            setAvatarUser(previewURL);

                            // Atualizar o campo do React Hook Form → ESSENCIAL
                            setValue("avatar", file, { shouldValidate: true });

                            // limpa input
                            e.target.value = "";
                        }}
                    />
                </div>
            </div>

            <section className="col-span-12 grid grid-cols-12 gap-4">
                <h2 className='col-span-12 flex items-center font-extrabold border-b text-lg my-2 capitalize'>
                    {t('company.form.registration_data')}
                </h2>

                <UInput
                    required
                    type='text'
                    label={t('users.form.name')}
                    name='name'
                    register={register}
                    className='col-span-12 md:col-span-8'
                />
                <UInput
                    type='email'
                    label='Email'
                    name='email'
                    register={register}
                    className='col-span-12 md:col-span-4'
                    disabled={!!record?.id}
                    required={!record?.id}
                />

                <UInput
                    type='text'
                    label={t('company.form.phone')}
                    name='phone'
                    register={register}
                    className='col-span-4'
                />
                <UInput
                    type='date'
                    label={t('grid.birthdate')}
                    name='birthdate'
                    register={register}
                    className='col-span-4'
                />
                <Controller
                    name="gender"
                    control={control}
                    render={({ field }) => (
                        <div className="col-span-4">
                            <label className="block font-medium mb-1">
                                {t('grid.gender')}:
                            </label>
                            <Select
                                placeholder={t('config.form.select')}
                                className="custom-select"
                                classNamePrefix="custom-select"
                                options={genders}
                                value={genders.find((r: any) => r.value === field.value)}
                                onChange={(value: any) => {
                                    field.onChange(value?.value || null);
                                }}
                            />
                        </div>
                    )}
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