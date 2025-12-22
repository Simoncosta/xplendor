'use client'

import UInput from "@/components/u-input"
import { getTranslation } from '@/i18n';
import { Controller, UseFormRegister } from "react-hook-form";
import Select from 'react-select';
import { useEffect, useMemo, useState } from "react";
import UMaskedInput from "@/components/u-masked-input";
import api from "@/services/axiosInstance";
import { AddressFieldsProps, IDistrict, IMunicipality, IParish } from "./model/address.model";
import { AddressService } from "./services/address.service";

export default function ComponentsAddressFields({
    register,
    control,
    disabled = false,
    setValue,
    watch
}: AddressFieldsProps) {

    const addressService = useMemo(() => new AddressService(api), []);

    const [districts, setDistricts] = useState<IDistrict[]>([]);
    const [municipalities, setMunicipalities] = useState<IMunicipality[]>([]);
    const [parishes, setParishes] = useState<IParish[]>([]);

    useEffect(() => {
        getDistricts();
    }, []);

    useEffect(() => {
        getMunicipalities();
    }, [watch("district_id")]);

    // Carrega freguesias quando municipality_id muda
    useEffect(() => {
        getParishes();
    }, [watch("municipality_id")]);

    const getDistricts = async () => {
        try {
            const res = await addressService.getDistricts(null);
            setDistricts(res);
        } catch (error) {
            console.error(error);
        }
    }

    const getMunicipalities = async () => {
        const districtSelected = watch("district_id");

        if (districtSelected) {
            addressService.getMunicipalities(districtSelected, null)
                .then(res => setMunicipalities(res))
                .catch(console.error);
        } else {
            setMunicipalities([]);
        }
    }

    const getParishes = async () => {
        const municipalitySelected = watch("municipality_id");

        if (municipalitySelected) {
            addressService.getParishes(municipalitySelected, null)
                .then(setParishes) // precisa criar o useState parishes
                .catch(console.error);
        } else {
            setParishes([]);
        }
    }

    const { t } = getTranslation();

    return (
        <>
            {/* <UInput
                type='text'
                label={t('company.form.country')}
                name='country'
                register={register}
                disabled={true}
                className='col-span-12 md:col-span-2'
            /> */}
            <div className="col-span-12 sm:col-span-2">
                <Controller
                    name={"postal_code"}
                    control={control}
                    rules={{ required: true }}
                    render={({ field }) => (
                        <div style={{ display: "flex", gap: 10 }}>
                            <div style={{ flex: 1 }}>
                                <UMaskedInput
                                    name="postal_code"
                                    required
                                    type="text"
                                    label={t('company.form.zip_code')}
                                    removeMaskOnSubmit={true}
                                    placeholder="0000-000"
                                    disabled={disabled}
                                    mask={[
                                        /[0-9]/, /[0-9]/, /[0-9]/, /[0-9]/, '-',
                                        /[0-9]/, /[0-9]/, /[0-9]/
                                    ]}
                                    value={field.value ?? ''}
                                    onChange={field.onChange}
                                />
                            </div>
                        </div>
                    )}
                />
            </div>
            <UInput
                required
                type='text'
                label={`${t('company.form.address_line')} 1`}
                name='address'
                register={register}
                className='col-span-12 md:col-span-10'
            />
            <div className="col-span-12 sm:col-span-4">
                <Controller
                    name="district_id"
                    control={control}
                    rules={{ required: true }}
                    render={({ field }) => (
                        <>
                            <label className="block font-medium mb-1">
                                {t('company.form.district')}
                                <span className="text-danger ml-1">*</span>
                            </label>

                            <Select
                                placeholder={t('config.form.select')}
                                className="custom-select"
                                classNamePrefix="custom-select"
                                options={districts} // useState vindo do pai
                                getOptionLabel={(opt: any) => opt.name}
                                getOptionValue={(opt: any) => String(opt.id)}

                                value={
                                    field.value
                                        ? districts.find((d: any) => d.id === field.value)
                                        : null
                                }

                                onChange={async (value: any) => {
                                    field.onChange(value?.id || null);

                                    // Limpamos municipality_id e parish_id
                                    setValue("municipality_id", null);
                                    setValue("parish_id", null);

                                    if (value?.id) {
                                        getMunicipalities();
                                    }
                                }}
                            />
                        </>
                    )}
                />
            </div>
            <div className="col-span-12 sm:col-span-4">
                <Controller
                    name="municipality_id"
                    control={control}
                    rules={{ required: true }}
                    render={({ field }) => {
                        const districtSelected = watch("district_id");

                        return (
                            <>
                                <label className="block font-medium mb-1">
                                    {t('company.form.municipality')}
                                    <span className="text-danger ml-1">*</span>
                                </label>

                                <Select
                                    placeholder={
                                        districtSelected
                                            ? t("config.form.select")
                                            : t("config.form.select.select_district_first")
                                    }
                                    className="custom-select"
                                    classNamePrefix="custom-select"

                                    isDisabled={!districtSelected}

                                    options={municipalities}
                                    getOptionLabel={(opt: any) => opt.name}
                                    getOptionValue={(opt: any) => String(opt.id)}

                                    value={
                                        field.value
                                            ? municipalities.find((m) => m.id === field.value)
                                            : null
                                    }

                                    onChange={(value: any) => {
                                        field.onChange(value?.id || null);

                                        // limpamos parishes quando troca
                                        setValue("parish_id", null);
                                    }}
                                />
                            </>
                        )
                    }}
                />
            </div>
            <div className="col-span-12 sm:col-span-4">
                <Controller
                    name="parish_id"
                    control={control}
                    rules={{ required: true }}
                    render={({ field }) => {
                        const municipalitySelected = watch("municipality_id");

                        return (
                            <>
                                <label className="block font-medium mb-1">
                                    {t('company.form.parish')}
                                    <span className="text-danger ml-1">*</span>
                                </label>

                                <Select
                                    placeholder={
                                        municipalitySelected
                                            ? t("config.form.select")
                                            : t("config.form.select.select_municipality_first")
                                    }
                                    className="custom-select"
                                    classNamePrefix="custom-select"

                                    isDisabled={!municipalitySelected}

                                    options={parishes}
                                    getOptionLabel={(opt: any) => opt.name}
                                    getOptionValue={(opt: any) => String(opt.id)}

                                    value={
                                        field.value
                                            ? parishes.find((p) => p.id === field.value)
                                            : null
                                    }

                                    onChange={(value: any) => {
                                        field.onChange(value?.id || null);
                                    }}
                                />
                            </>
                        )
                    }}
                />
            </div>
            {/* <UInput
                type='text'
                label={'Latitude'}
                name='latitude'
                register={register}
                className='col-span-12 md:col-span-6'
            />
            <UInput
                type='text'
                label={'Longitude'}
                name='longitude'
                register={register}
                className='col-span-12 md:col-span-6'
            /> */}
        </>
    )
}