import { Alert, Col, Row } from "reactstrap";
import { useFormikContext } from "formik";
import { useEffect, useMemo, useState } from "react";
import { toast } from "react-toastify";

import { FilePond, registerPlugin } from "react-filepond";
import "filepond/dist/filepond.min.css";
import FilePondPluginImageExifOrientation from "filepond-plugin-image-exif-orientation";
import FilePondPluginImagePreview from "filepond-plugin-image-preview";
import FilePondPluginFileValidateType from "filepond-plugin-file-validate-type";
import FilePondPluginFileValidateSize from "filepond-plugin-file-validate-size";
import "filepond-plugin-image-preview/dist/filepond-plugin-image-preview.css";

import type { ICarUpdatePayload } from "common/models/car.model";
import XButton from "Components/Common/XButton";
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
    external_images?: Array<{
        id: number;
        external_url: string;
        is_primary?: boolean;
        sort_order?: number | null;
    }>;
};

export default function CarImagesDataFields({ isEdit, companyId }: { isEdit: boolean; companyId?: number }) {
    const { values, setFieldValue } = useFormikContext<ICarFormValues>();

    // Estado UI do FilePond
    const [files, setFiles] = useState<PondFile[]>([]);
    const [isDownloadingZip, setIsDownloadingZip] = useState(false);

    const API_BASE = process.env.REACT_APP_PUBLIC_URL ?? "http://localhost:8001";
    const carId = Number((values as any)?.id ?? 0);
    const internalStoredCount = ((values as any)?.stored_images as CarStoredImage[] | undefined)?.length ?? 0;
    const externalImagesCount = ((values as any)?.external_images as ICarFormValues["external_images"] | undefined)?.length ?? 0;
    const newImagesCount = Array.isArray(values.images) ? values.images.length : 0;
    const hasInternalImages = internalStoredCount > 0 || newImagesCount > 0;
    const hasExternalImages = externalImagesCount > 0;

    const toAbsoluteUrl = (storagePath: string) => {
        const normalized = storagePath.replace(/^\/storage\//, "");
        return `${API_BASE}/api/media/${normalized}`;
    };

    const totalImages = useMemo(() => {
        if (files.length > 0) {
            return files.length + externalImagesCount;
        }

        return internalStoredCount + newImagesCount + externalImagesCount;
    }, [externalImagesCount, files.length, internalStoredCount, newImagesCount]);

    const hasAnyImagesForDownload = hasInternalImages || hasExternalImages;
    const canDownloadZip = isEdit && Number(companyId) > 0 && carId > 0 && hasAnyImagesForDownload;
    const imagesCounterLabel = totalImages === 1
        ? "1 imagem"
        : `${totalImages} imagens`;
    const imagesCounterDescription = !hasAnyImagesForDownload
        ? imagesCounterLabel
        : hasInternalImages && hasExternalImages
            ? `${imagesCounterLabel} disponíveis (${internalStoredCount + newImagesCount} internas, ${externalImagesCount} externas)`
            : hasExternalImages
                ? `${imagesCounterLabel} (externas)`
                : imagesCounterLabel;

    const getDownloadFilename = (contentDisposition: string | null, fallbackCarId: number) => {
        if (contentDisposition) {
            const utf8Match = contentDisposition.match(/filename\*=UTF-8''([^;]+)/i);
            if (utf8Match?.[1]) {
                return decodeURIComponent(utf8Match[1]);
            }

            const asciiMatch = contentDisposition.match(/filename="?([^"]+)"?/i);
            if (asciiMatch?.[1]) {
                return asciiMatch[1];
            }
        }

        return `carro-${fallbackCarId || "imagens"}.zip`;
    };

    const handleDownloadZip = async () => {
        if (!canDownloadZip) {
            return;
        }

        const authUser = sessionStorage.getItem("authUser");
        const token = authUser ? JSON.parse(authUser)?.token : null;

        setIsDownloadingZip(true);

        try {
            const response = await fetch(`${API_BASE}/api/v1/companies/${companyId}/cars/${carId}/images/download`, {
                method: "GET",
                headers: token ? { Authorization: `Bearer ${token}` } : undefined,
            });

            if (!response.ok) {
                throw new Error("download_failed");
            }

            const blob = await response.blob();
            const filename = getDownloadFilename(response.headers.get("content-disposition"), carId);
            const downloadUrl = URL.createObjectURL(blob);
            const link = document.createElement("a");

            link.href = downloadUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(downloadUrl);

            toast("Download iniciado com sucesso", {
                position: "top-right",
                hideProgressBar: false,
                className: "bg-success text-white",
            });
        } catch (error) {
            toast("Erro ao gerar ficheiro", {
                position: "top-right",
                hideProgressBar: false,
                className: "bg-danger text-white",
            });
        } finally {
            setIsDownloadingZip(false);
        }
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
        // 1) identificar stored vs new
        const isStored = (it: any) => {
            const sp = it?.getMetadata?.("storage_path");
            return typeof sp === "string" && sp.startsWith("/storage/");
        };

        const storedItems = pondItems.filter(isStored);
        const newItems = pondItems.filter((it) => it?.file instanceof File);

        // 2) actualizar metadados COM a ordem actual (crítico)
        storedItems.forEach((it: any, idx: number) => {
            it.setMetadata("order", idx + 1);
        });

        newItems.forEach((it: any, idx: number) => {
            it.setMetadata("order", idx + 1);
        });

        // 3) existing_images (estado final)
        const existing_images = storedItems.map((it: any) =>
            String(it.getMetadata("storage_path"))
        );

        // 4) meta alinhado pela ordem actual (idx)
        const existing_images_meta = storedItems.map((it: any, idx: number) => ({
            order: idx + 1,
            is_primary: Boolean(it.getMetadata("is_primary")),
        }));

        // 5) novas imagens
        const images: File[] = newItems.map((it: any) => it.file);

        const images_meta = newItems.map((it: any, idx: number) => ({
            order: idx + 1,
            is_primary: Boolean(it.getMetadata("is_primary")) || false,
        }));

        // 6) garantir 1 primary total
        const total = [...existing_images_meta, ...images_meta];
        const primaryIndex = total.findIndex((m) => m.is_primary);

        if (primaryIndex === -1 && total.length > 0) {
            // mete primary na primeira imagem do pond (stored ou new)
            if (storedItems.length) {
                storedItems[0].setMetadata("is_primary", true);
                existing_images_meta[0].is_primary = true;
            } else if (newItems.length) {
                newItems[0].setMetadata("is_primary", true);
                images_meta[0].is_primary = true;
            }
        } else if (primaryIndex !== -1) {
            // normaliza: só 1 primary
            storedItems.forEach((it: any, i: number) => it.setMetadata("is_primary", i === primaryIndex));
            newItems.forEach((it: any, i: number) =>
                it.setMetadata("is_primary", storedItems.length + i === primaryIndex)
            );

            existing_images_meta.forEach((m, i) => (m.is_primary = i === primaryIndex));
            images_meta.forEach((m, i) => (m.is_primary = storedItems.length + i === primaryIndex));
        }

        setFieldValue("existing_images", existing_images);
        setFieldValue("existing_images_meta", existing_images_meta);
        setFieldValue("images", images);
        setFieldValue("images_meta", images_meta);
    };

    return (
        <div className="mt-4">
            <div className="mb-2 border-bottom pb-2 d-flex align-items-center justify-content-between gap-3 flex-wrap">
                <div>
                    <h5 className="card-title mb-1">Imagens da Viatura</h5>
                    <p className="text-muted mb-0 fs-13">{imagesCounterDescription}</p>
                </div>
                <XButton
                    type="button"
                    variant="primary"
                    soft
                    rounded
                    icon={<i className="ri-download-2-line" />}
                    loading={isDownloadingZip}
                    disabled={!hasAnyImagesForDownload || isDownloadingZip || !isEdit || Number(companyId) <= 0 || carId <= 0}
                    onClick={handleDownloadZip}
                >
                    {isDownloadingZip ? "A preparar download..." : "Descarregar fotos (.zip)"}
                </XButton>
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
                            labelIdle='Arraste suas imagens aqui pra dentrou ou <span class="filepond--label-action">clique aqui</span>'
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
                        />
                    </div>
                </Col>
            </Row>
        </div>
    );
}
