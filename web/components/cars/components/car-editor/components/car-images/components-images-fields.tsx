import { useEffect, useMemo, useState } from "react";
import { ReactSortable } from 'react-sortablejs';
import { getTranslation } from "@/i18n";
import ImageUploading, { ImageListType } from 'react-images-uploading';
import { ImagesFieldsProps } from "./model/images.model";
import IconX from "@/components/icon/icon-x";
import { Controller } from "react-hook-form";

export default function ComponentsImagesFields({
    register,
    control,
    disabled = false,
    setValue,
    watch
}: ImagesFieldsProps) {

    // Translate
    const { t } = getTranslation();

    const maxNumber = 69;

    return (
        <div className="custom-file-container col-span-12" data-upload-id="mySecondImage">
            <Controller
                control={control}
                name="images"
                defaultValue={[]}
                render={({ field: { onChange, value } }) => (
                    <>
                        <ImageUploading
                            multiple
                            value={value}
                            onChange={(imageList: ImageListType) => onChange(imageList)}
                            maxNumber={maxNumber}
                            dataURLKey="dataURL"
                        >
                            {({ imageList, onImageUpload, onImageRemove, onImageRemoveAll, dragProps }) => (
                                <>
                                    <div className="label-container">
                                        <label>{t('upload.images')}</label>
                                        <button
                                            type="button"
                                            className="custom-file-container__image-clear"
                                            title="Clear Image"
                                            onClick={() => {
                                                onImageRemoveAll();
                                            }}
                                        >
                                            <IconX />
                                        </button>
                                    </div>
                                    <div className="upload__image-wrapper">
                                        <button
                                            type="button"
                                            className="custom-file-container__custom-file__custom-file-control"
                                            onClick={onImageUpload}
                                        >
                                            {t('upload.select_images')}
                                        </button>

                                        <ReactSortable
                                            tag={"div"}
                                            list={value}
                                            setList={(newList) => onChange(newList)}
                                            className="grid grid-cols-1 gap-4 sm:grid-cols-4 mt-16"
                                            {...dragProps}
                                        >
                                            {imageList.map((image, index) => (
                                                <div key={index} className="custom-file-container__image-preview relative">
                                                    <button
                                                        type="button"
                                                        className="custom-file-container__image-clear absolute top-0 left-0 block w-fit rounded-full bg-dark-light p-0.5 dark:bg-dark dark:text-white-dark"
                                                        title="Clear Image"
                                                        onClick={() => onImageRemove(index)}
                                                    >
                                                        <IconX className="w-3 h-3" />
                                                    </button>
                                                    <img src={image.dataURL ?? `${process.env.NEXT_PUBLIC_API_URL}${image.image}`} alt={`img-${index}`} className="!max-h-48 w-full rounded object-cover shadow cursor-move" />
                                                </div>
                                            ))}
                                        </ReactSortable>
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
    )
}
