/** Ficheiros: o gestor de ficheiros da empresa (GET /companies/{id}/files). */
export interface FileFolderDto {
    id: number;
    parent_id: number | null;
    name: string;
    system: boolean;
    system_key: string | null;
    system_root: string | null;
    deleted_at: string | null;
    purge_after: string | null;
    path?: { id: number; name: string }[];
}

export type FileKind = "imagem" | "video" | "pdf" | "documento";

export interface StoredFileDto {
    id: number;
    name: string;
    extension: string | null;
    mime: string | null;
    kind: FileKind;
    size_bytes: number | null;
    folder_id: number | null;
    system: boolean;
    source: string;
    created_at: string | null;
    deleted_at: string | null;
    purge_after: string | null;
    media_asset_id: number | null;
    media_status: string | null;
    in_use: number;
    thumb_url: string | null;
    preview_url: string | null;
    url: string | null;
    download_url: string | null;
    /** Só nos atalhos ("Guardar numa pasta"). */
    shortcut_id?: number;
}

export interface FileDetailDto extends StoredFileDto {
    usage: { id: number; title: string; publish_date: string | null; stage: string }[];
    shortcuts: { id: number; folder_id: number; folder: string | null }[];
}

export interface FilesPayload {
    tree: FileFolderDto[];
    folder: FileFolderDto | null;
    folders: FileFolderDto[];
    files: StoredFileDto[];
    shortcuts: StoredFileDto[];
    usage: { bytes: Record<string, number>; total: number; quota: number; kinds: Record<string, string> };
    can: { create: boolean; edit: boolean; delete: boolean; editorial: boolean };
    limits: { document_bytes: number; image_bytes: number; video_bytes: number; chunk_bytes: number };
}

export interface FilesTrashPayload {
    folders: FileFolderDto[];
    files: StoredFileDto[];
    shortcuts: (StoredFileDto & { shortcut_id: number; shortcut_deleted_at: string | null })[];
    days: number;
}

/** As imagens e os vídeos vão pelo envio em partes (com miniaturas). */
export const MEDIA_EXTENSIONS = ["jpg", "jpeg", "png", "webp", "mp4", "mov"];

export const KIND_META: Record<FileKind, { icon: string; color: string; label: string }> = {
    imagem: { icon: "ri-gallery-fill", color: "success", label: "Imagem" },
    video: { icon: "ri-video-fill", color: "danger", label: "Vídeo" },
    pdf: { icon: "ri-file-pdf-fill", color: "danger", label: "PDF" },
    documento: { icon: "ri-file-text-fill", color: "secondary", label: "Documento" },
};

/** Ícone de cada pasta do sistema. */
export const SYSTEM_ICON: Record<string, string> = {
    editorial: "ri-calendar-todo-line", ocr: "ri-bill-line", xplendor: "ri-money-euro-circle-line", suporte: "ri-customer-service-2-line",
    satisfacao: "ri-emotion-happy-line", viaturas: "ri-car-line", marca: "ri-copyright-line", blog: "ri-article-line", equipa: "ri-team-line",
    modelos: "ri-file-word-line",
};

export const fileSrc = (url: string | null | undefined) => (url ? (/^https?:\/\//.test(url) ? url : `${process.env.REACT_APP_PUBLIC_URL ?? ""}${url}`) : undefined);

export const fmtBytes = (b: number | null | undefined): string => {
    const v = b ?? 0;
    if (v >= 1024 ** 3) return `${(v / 1024 ** 3).toLocaleString("pt-PT", { maximumFractionDigits: 2 })} GB`;
    if (v >= 1024 ** 2) return `${(v / 1024 ** 2).toLocaleString("pt-PT", { maximumFractionDigits: 1 })} MB`;
    return `${Math.max(v > 0 ? 1 : 0, Math.round(v / 1024)).toLocaleString("pt-PT")} KB`;
};

export const fmtDate = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric" }) : "");
