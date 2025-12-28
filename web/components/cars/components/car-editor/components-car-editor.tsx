'use client';

import { useForm, SubmitHandler, Controller, set } from "react-hook-form"
import { Component, useEffect, useState } from "react";
import { getTranslation } from '@/i18n';
import UButton from "@/components/u-button";
import UInput from "@/components/u-input";
import { ICarEditorProps } from "./model/car-editor.model";
import { ICar } from "../../models/cars.model";
import ComponentsGeneralFields from "./components/general/components-general-fields";
import ComponentsCarDetailsFields from "./components/car-details/components-car-details-fields";
import ComponentsAdditionalDataFields from "./components/additional-data/additional-data-fields";
import ComponentsImagesFields from "./components/car-images/components-images-fields";
import ComponentsPriceFields from "./components/prices/components-price-fields";
import ComponentsCarExtrasFields from "./components/car-extras/components-car-extras-fields";

export default function ComponentsCarEditor({
    record,
    onSave,
    onCancel,
    disabled = false,
}: ICarEditorProps) {
    const { t } = getTranslation();
    const [loading, setLoading] = useState(false);

    const {
        register,
        handleSubmit,
        watch,
        control,
        setValue,
        formState: { errors },
        reset,
    } = useForm<ICar>({
        defaultValues: {}
    });

    useEffect(() => {
        if (record) {
            reset(record);
        }
    }, [record]);

    const onSubmit = (data: ICar) => {
        console.log('%c🚀 SUBMIT DISPARADO COM OS DADOS:', 'color: blue; font-weight: bold;', data);
        if (onSave) {
            setLoading(true);

            onSave(data, (cancel: boolean) => {
                setLoading(false);
                if (!cancel) {
                    return
                }
            });
        }
    };

    return (
        <form onSubmit={handleSubmit(onSubmit)} className={'panel grid grid-cols-12 gap-4'}>
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
                    {t('cars.form.car_details')}
                </h2>
                <ComponentsCarDetailsFields
                    register={register}
                    control={control}
                    setValue={setValue}
                    watch={watch}
                />
            </section>
            <section className="col-span-12 grid grid-cols-12 gap-4">
                <h2 className='col-span-12 flex items-center font-extrabold border-b text-lg my-2 capitalize'>
                    {t('cars.form.additional_data')}
                </h2>
                <ComponentsAdditionalDataFields
                    register={register}
                    control={control}
                    setValue={setValue}
                    watch={watch}
                />
            </section>
            <section className="col-span-12 grid grid-cols-12 gap-4">
                <h2 className='col-span-12 flex items-center font-extrabold border-b text-lg my-2 capitalize'>
                    {t('grid.price')}
                </h2>
                <ComponentsPriceFields
                    register={register}
                    control={control}
                    setValue={setValue}
                    watch={watch}
                />
            </section>
            <section className="col-span-12 grid grid-cols-12 gap-4">
                <h2 className='col-span-12 flex items-center font-extrabold border-b text-lg my-2 capitalize'>
                    {t('grid.equipment')}
                </h2>
                <ComponentsCarExtrasFields
                    register={register}
                    control={control}
                    setValue={setValue}
                    watch={watch}
                />
            </section>
            <section className="col-span-12 grid grid-cols-12 gap-4">
                <h2 className='col-span-12 flex items-center font-extrabold border-b text-lg my-2 capitalize'>
                    {t('cars.form.car_images')}
                </h2>
                <ComponentsImagesFields
                    register={register}
                    control={control}
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