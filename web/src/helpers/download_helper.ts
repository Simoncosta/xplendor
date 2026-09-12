import axios from "axios";

/**
 * DMS Caminho B — descarregar ficheiros (.docx) da API autenticada.
 *
 * Usa uma instância axios PRÓPRIA (sem os interceptors globais, que assumem
 * JSON e desempacotam response.data) para controlar responseType blob e ler o
 * corpo de erro (422 com a lista de variáveis não reconhecidas).
 */
const API_URL = process.env.REACT_APP_API_URL ?? "";

function authHeaders(): Record<string, string> {
    try {
        const raw = sessionStorage.getItem("authUser");
        const token = raw ? JSON.parse(raw)?.token : null;
        return token ? { Authorization: `Bearer ${token}` } : {};
    } catch {
        return {};
    }
}

function filenameFrom(headers: any, fallback: string): string {
    const cd = headers?.["content-disposition"] || headers?.["Content-Disposition"];
    if (typeof cd === "string") {
        const m = cd.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
        if (m?.[1]) return decodeURIComponent(m[1]);
    }
    return fallback;
}

export function saveBlob(blob: Blob, filename: string): void {
    const href = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = href;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(href), 1000);
}

type DownloadResult = { ok: true } | { ok: false; status: number; body: any };

async function request(method: "get" | "post", path: string, fallbackName: string): Promise<DownloadResult> {
    const client = axios.create({ baseURL: API_URL });
    try {
        const res = method === "get"
            ? await client.get(path, { responseType: "blob", headers: authHeaders() })
            : await client.post(path, {}, { responseType: "blob", headers: authHeaders() });
        saveBlob(res.data as Blob, filenameFrom(res.headers, fallbackName));
        return { ok: true };
    } catch (err: any) {
        const status = err?.response?.status ?? 0;
        let body: any = null;
        const blob = err?.response?.data;
        if (blob && typeof blob.text === "function") {
            try { body = JSON.parse(await blob.text()); } catch { /* not json */ }
        }
        return { ok: false, status, body };
    }
}

export const downloadGet = (path: string, fallbackName = "documento.docx") => request("get", path, fallbackName);
export const downloadPost = (path: string, fallbackName = "documento.docx") => request("post", path, fallbackName);

type FetchDocResult =
    | { ok: true; blob: Blob; filename: string }
    | { ok: false; status: number; body: any };

/**
 * POST que DEVOLVE o ficheiro (blob + nome) SEM o descarregar — para
 * pré-visualizar antes. Em erro, lê o corpo JSON (422 com missing).
 */
export async function postDocx(path: string, payload: any = {}, fallbackName = "documento.docx"): Promise<FetchDocResult> {
    const client = axios.create({ baseURL: API_URL });
    try {
        const res = await client.post(path, payload, {
            responseType: "blob",
            headers: { ...authHeaders(), "Content-Type": "application/json" },
        });
        return { ok: true, blob: res.data as Blob, filename: filenameFrom(res.headers, fallbackName) };
    } catch (err: any) {
        const status = err?.response?.status ?? 0;
        let body: any = null;
        const blob = err?.response?.data;
        if (blob && typeof blob.text === "function") {
            try { body = JSON.parse(await blob.text()); } catch { /* not json */ }
        }
        return { ok: false, status, body };
    }
}
