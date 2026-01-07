'use client';

import { Controller, useForm } from "react-hook-form";
import { useEffect, useState } from "react";
import Select from 'react-select';
import CreatableSelect from 'react-select/creatable';
import ImageUploading from 'react-images-uploading';
import SimpleMdeReact from 'react-simplemde-editor';
import 'easymde/dist/easymde.min.css';
import { getTranslation } from "@/i18n";
import UButton from "@/components/u-button";
import UInput from "@/components/u-input";
import { useSelector } from "react-redux";
import { IRootState } from "@/store";
import { IBlogEditorProps } from "./model/blog.model";
import { IBlogs } from "../../models/blog.model";
import IconX from "@/components/icon/icon-x";

export default function ComponentsBlogEditor({
    record,
    onSave,
    onCancel,
    disabled = false,
}: IBlogEditorProps) {
    const { t } = getTranslation();

    const statusOptions = [
        { label: t('grid.blog.status.published'), value: 'published' },
        { label: t('grid.blog.status.draft'), value: 'draft' },
    ];

    // Context
    const { user } = useSelector((state: IRootState) => state.auth);

    // States
    const [loading, setLoading] = useState(false);

    const {
        register,
        handleSubmit,
        watch,
        control,
        setValue,
        formState: { errors },
        reset,
    } = useForm<IBlogs>({
        defaultValues: {}
    });

    useEffect(() => {
        if (record) {
            reset(record);
        }
    }, [record]);

    const onSubmit = (data: IBlogs) => {
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
            <section className="col-span-12 grid grid-cols-12 gap-4">
                <h2 className='col-span-12 flex items-center font-extrabold border-b text-lg my-2 capitalize'>
                    {t('company.form.registration_data')}
                </h2>
                <div className="custom-file-container col-span-12" data-upload-id="mySecondImage">
                    <Controller
                        control={control}
                        name="banner"
                        render={({ field: { onChange, value } }) => (
                            <>
                                <ImageUploading
                                    // @ts-ignore
                                    value={value ? [{ image: typeof value === 'string' ? value : value.dataURL }] : []}
                                    onChange={(imageList) => onChange(imageList[0] || null)}
                                    maxNumber={1}
                                    dataURLKey="dataURL"
                                >
                                    {({ imageList, onImageUpload, onImageRemoveAll, dragProps }) => (
                                        <>
                                            <div className="label-container">
                                                <label>Banner</label>
                                                {value && (
                                                    <button
                                                        type="button"
                                                        className="custom-file-container__image-clear"
                                                        title="Remover imagem"
                                                        onClick={() => onImageRemoveAll()}
                                                    >
                                                        <IconX />
                                                    </button>
                                                )}
                                            </div>

                                            <div className="upload__image-wrapper">
                                                <button
                                                    type="button"
                                                    className="custom-file-container__custom-file__custom-file-control"
                                                    onClick={onImageUpload}
                                                >
                                                    {t('upload.select_images')}
                                                </button>

                                                <div className="mt-16">
                                                    {value && (
                                                        <div className="custom-file-container__image-preview relative">
                                                            <button
                                                                type="button"
                                                                className="custom-file-container__image-clear absolute top-0 left-0 block w-fit rounded-full bg-dark-light p-0.5 dark:bg-dark dark:text-white-dark"
                                                                title="Remover"
                                                                onClick={() => onImageRemoveAll()}
                                                            >
                                                                <IconX className="w-3 h-3" />
                                                            </button>
                                                            <img
                                                                // @ts-ignore
                                                                src={value?.dataURL ?? `${process.env.NEXT_PUBLIC_API_URL}${value}`}
                                                                alt="banner-preview"
                                                                className="!max-h-48 w-full rounded object-cover shadow"
                                                            />
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        </>
                                    )}
                                </ImageUploading>

                                {value?.length === 0 && (
                                    <img
                                        src="/assets/images/file-preview.svg"
                                        className="m-auto w-full max-w-md"
                                        alt="preview"
                                    />
                                )}
                            </>
                        )}
                    />
                </div>
                <Controller
                    name="status"
                    control={control}
                    render={({ field }) => (
                        <div className="col-span-2">
                            <label className="block font-medium mb-2">
                                Status:<span className="text-red-500 ml-1">*</span>
                            </label>

                            <Select
                                placeholder={t('config.form.select')}
                                className="custom-select"
                                classNamePrefix="custom-select"
                                options={statusOptions}
                                value={statusOptions.find((s: any) => s.value === field.value)}
                                onChange={async (value: any) => {
                                    field.onChange(value?.value || null);
                                }}
                                required
                            />
                        </div>
                    )}
                />
                <UInput
                    name="title"
                    type="text"
                    label={t('grid.title')}
                    register={register}
                    className="col-span-12 md:col-span-5"
                    required
                    disabled={!!record?.id}
                />
                <UInput
                    name="subtitle"
                    type="text"
                    label={t('grid.subtitle')}
                    register={register}
                    className="col-span-12 md:col-span-5"
                />
                <UInput
                    name="category"
                    type="text"
                    label={t('grid.category')}
                    register={register}
                    className="col-span-12 md:col-span-4"
                    required
                />
                <UInput
                    name="excerpt"
                    type="text"
                    label={t('grid.excerpt')}
                    register={register}
                    className="col-span-12 md:col-span-4"
                />
                {/* <UInput
                    name="tags"
                    type="text"
                    label={"Tags"}
                    register={register}
                    className="col-span-12 md:col-span-4"
                /> */}
                <Controller
                    name="tags"
                    control={control}
                    defaultValue={[]} // importante
                    render={({ field }) => (
                        <div className="col-span-4">
                            <label className="block font-medium mb-2">
                                Tags:<span className="text-red-500 ml-1">*</span>
                            </label>

                            <CreatableSelect
                                isMulti
                                value={(field.value || []).map((tag: string) => ({
                                    label: tag,
                                    value: tag,
                                }))}
                                onChange={(selected) => {
                                    const values = (selected || []).map((item: any) => item.value);
                                    field.onChange(values);
                                }}
                                placeholder="Digite e pressione Enter..."
                            />
                        </div>
                    )}
                />
                <Controller
                    name="content"
                    control={control}
                    defaultValue=""
                    render={({ field }) => (
                        <div className="col-span-12">
                            <SimpleMdeReact
                                value={field.value}
                                onChange={(value) => field.onChange(value)}
                                options={{
                                    spellChecker: false,
                                    placeholder: "Escreva o conteúdo do blog aqui...",
                                    status: false,
                                    minHeight: "400px",
                                    autofocus: true,
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