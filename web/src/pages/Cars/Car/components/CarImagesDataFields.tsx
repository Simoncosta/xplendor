import { Alert, Col, Row } from "reactstrap";
import { useFormikContext } from "formik";
import { useEffect, useState } from "react";

import { FilePond, registerPlugin } from "react-filepond";
import "filepond/dist/filepond.min.css";
import FilePondPluginImageExifOrientation from "filepond-plugin-image-exif-orientation";
import FilePondPluginImagePreview from "filepond-plugin-image-preview";
import FilePondPluginFileValidateType from "filepond-plugin-file-validate-type";
import FilePondPluginFileValidateSize from "filepond-plugin-file-validate-size";
import "filepond-plugin-image-preview/dist/filepond-plugin-image-preview.css";

import type { ICarUpdatePayload } from "common/models/car.model";
import styles from "../../../../assets/scss/CarImagesDataFields.module.scss";


registerPlugin(
    FilePondPluginImageExifOrientation,
    FilePondPluginImagePreview,
    FilePondPluginFileValidateType,
    FilePondPluginFileValidateSize
);

type PondFile = any;

export type CarStoredImage = {
    id: number;
    image: string; // "/storage/..."
    is_primary?: 0 | 1 | boolean;
    order?: number;
};

export type CarImageMeta = {
    key: string;
    is_primary?: boolean;
    order?: number;
};

export type ICarFormValues = ICarUpdatePayload & {
    // novas (upload)
    images: File[];
    images_meta: CarImageMeta[];

    // existentes (vindas do backend)
    stored_images?: CarStoredImage[];
};

export default function CarImagesDataFields({ isEdit }: { isEdit: boolean }) {
    const { values, setFieldValue } = useFormikContext<ICarFormValues>();

    // Estado UI do FilePond
    const [files, setFiles] = useState<PondFile[]>([]);

    const API_BASE = process.env.REACT_APP_PUBLIC_URL ?? "http://localhost:8000";

    const toAbsoluteUrl = (storagePath: string) => {
        const normalized = storagePath.replace(/^\/storage\//, "");
        return `${API_BASE}/api/media/${normalized}`;
    };

    // Inicializa FilePond com imagens já existentes (edição)
    useEffect(() => {
        if (!isEdit) return;
        if (files.length) return;

        const stored = (values as any)?.stored_images as CarStoredImage[] | undefined;
        if (!stored?.length) return;

        const initial = [...stored]
            .sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
            .map((img) => ({
                source: toAbsoluteUrl(img.image),
                options: {
                    type: "local" as const,
                    metadata: {
                        storage_path: img.image,
                        is_primary: Boolean(img.is_primary),
                        order: img.order ?? 0,
                        stored_id: img.id,
                    },
                },
            }));

        setFiles(initial);

        // ✅ IMPORTANTÍSSIMO: sincroniza o Formik logo no load
        setTimeout(() => {
            // FilePond items locais aqui ainda são objetos simples,
            // então faz uma sync manual baseada no `initial`
            setFieldValue("existing_images", initial.map(i => i.options.metadata.storage_path));
            setFieldValue(
                "existing_images_meta",
                initial.map((i, idx) => ({
                    order: idx + 1,
                    is_primary: Boolean(i.options.metadata.is_primary),
                }))
            );
        }, 0);
    }, [isEdit, (values as any)?.stored_images, files.length]);

    const syncFormikFromPond = (pondItems: any[]) => {
        const isStored = (it: any) => {
            // item "local" não tem File, e tem metadata storage_path
            const sp = it?.getMetadata?.("storage_path");
            return typeof sp === "string" && sp.startsWith("/storage/");
        };

        const storedItems = pondItems.filter(isStored);
        const newItems = pondItems.filter((it) => it?.file instanceof File);

        // existing_images: usa SEMPRE storage_path
        const existing_images = storedItems.map((it) => String(it.getMetadata("storage_path")));

        // meta das existentes alinhado por índice
        const existing_images_meta = storedItems.map((it: any, idx: number) => {
            const isPrimary = Boolean(it?.getMetadata?.("is_primary"));
            return { order: idx + 1, is_primary: isPrimary };
        });

        // novas imagens
        const images: File[] = newItems.map((it: any) => it.file);

        const images_meta = newItems.map((_: any, idx: number) => ({
            order: idx + 1,
            is_primary: false,
        }));

        // garantir 1 primary total
        const totalHasPrimary =
            existing_images_meta.some((m) => m.is_primary) ||
            images_meta.some((m) => m.is_primary);

        if (!totalHasPrimary) {
            if (existing_images_meta.length) existing_images_meta[0].is_primary = true;
            else if (images_meta.length) images_meta[0].is_primary = true;
        }

        setFieldValue("existing_images", existing_images);
        setFieldValue("existing_images_meta", existing_images_meta);
        setFieldValue("images", images);
        setFieldValue("images_meta", images_meta);
    };

    return (
        <div className="mt-4">
            <div className="mb-2 border-bottom pb-2">
                <h5 className="card-title">Imagens da Viatura</h5>
            </div>


            <Row>
                <Col lg={12}>
                    <Alert color="primary">
                        <strong>Requisitos das imagens:</strong>
                        <ul className="mb-0 mt-2">
                            <li>Proporção recomendada: <b>16:9</b></li>
                            <li>Exemplos: <b>1245x701</b></li>
                            <li>Formatos: <b>JPG, PNG ou WEBP</b></li>
                            <li>Tamanho máximo: <b>10MB por imagem</b></li>
                            <li>Máximo de <b>60 imagens</b></li>
                        </ul>
                    </Alert>
                </Col>
                <Col lg={12}>
                    <div className={styles.carImagesPond}>
                        <FilePond
                            files={files}
                            onupdatefiles={(nextFiles) => {
                                setFiles(nextFiles);
                                syncFormikFromPond(nextFiles);
                            }}
                            onreorderfiles={(nextFiles) => {
                                setFiles(nextFiles);
                                syncFormikFromPond(nextFiles);
                            }}
                            allowMultiple
                            allowReorder
                            maxFiles={60}
                            acceptedFileTypes={["image/jpeg", "image/png", "image/webp"]}
                            allowFileTypeValidation
                            allowFileSizeValidation
                            maxFileSize="10MB"
                            labelMaxFileSizeExceeded="Ficheiro demasiado grande"
                            labelMaxFileSize="Tamanho máximo por ficheiro: {filesize}"
                            labelFileTypeNotAllowed="Formato não suportado"
                            fileValidateTypeLabelExpectedTypes="Só JPG, PNG ou WEBP"
                            name="images"
                            className="filepond filepond-input-multiple"
                            labelIdle='Arraste suas images ou <span class="filepond--label-action">clique aqui</span>'
                            server={{
                                load: (source, load, error, progress, abort) => {
                                    const controller = new AbortController();

                                    fetch(String(source), { signal: controller.signal })
                                        .then(async (res) => {
                                            if (!res.ok) throw new Error("load failed");
                                            const blob = await res.blob();
                                            load(blob);
                                        })
                                        .catch(() => error("Erro ao carregar imagem"))
                                        .finally(() => progress(true, 1, 1));

                                    return {
                                        abort: () => {
                                            controller.abort();
                                            abort();
                                        },
                                    };
                                },
                            }}
                            styleItemPanelAspectRatio="1:1"
                        // stylePanelLayout="compact"
                        />
                    </div>
                </Col>
            </Row>
        </div>
    );
}