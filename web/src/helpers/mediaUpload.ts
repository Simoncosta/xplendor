import { getMediaAsset, sendMediaChunk, startMediaUpload } from "helpers/laravel_helper";
import type { MediaAssetDto } from "common/models/editorialWorkflow.model";

/**
 * Envio de um ficheiro em partes de 8 MB, retomável: se uma parte falhar (rede), volta a
 * tentar a partir do ponto que o servidor confirmar (409 traz received_bytes). No fim espera
 * pelo processamento (miniaturas e capa) e devolve o media pronto ou rejeitado.
 */
const MAX_RETRIES = 5;
const wait = (ms: number) => new Promise((r) => setTimeout(r, ms));

export async function uploadMediaFile(companyId: number, file: File, onProgress: (fraction: number) => void): Promise<MediaAssetDto> {
    const mime = file.type || (file.name.toLowerCase().endsWith(".mov") ? "video/quicktime" : "");
    const start: any = await startMediaUpload(companyId, { name: file.name, size: file.size, mime });
    const id: string = start.data.id;
    const chunkBytes: number = start.data.chunk_bytes;
    let offset: number = start.data.received_bytes ?? 0;
    let asset: MediaAssetDto | null = start.data.asset ?? null;
    let retries = 0;

    while (!asset && offset < file.size) {
        const end = Math.min(offset + chunkBytes, file.size);
        try {
            const r: any = await sendMediaChunk(companyId, id, offset, file.slice(offset, end), (loaded) => onProgress(Math.min(1, (offset + loaded) / file.size)));
            offset = r.data.received_bytes;
            asset = r.data.asset ?? null;
            retries = 0;
            onProgress(offset / file.size);
        } catch (e: any) {
            if (e?.__status === 409 && typeof e?.errors?.received_bytes === "number") {
                offset = e.errors.received_bytes; // retoma do ponto confirmado pelo servidor
                continue;
            }
            if (e?.__status && e.__status < 500 && e.__status !== 429) throw e; // erro de validação: não adianta repetir
            if (++retries > MAX_RETRIES) throw e;
            await wait(1000 * retries);
        }
    }
    if (!asset) throw new Error("O envio não terminou.");

    // Processamento em fila: espera pelas miniaturas (até cerca de 3 minutos).
    for (let i = 0; i < 90 && asset.status === "processing"; i++) {
        await wait(2000);
        const r: any = await getMediaAsset(companyId, asset.id);
        asset = r.data as MediaAssetDto;
    }

    return asset;
}
