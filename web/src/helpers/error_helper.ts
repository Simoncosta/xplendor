import { toast } from "react-toastify";

export interface ApiValidationError {
    field: string;
    messages: string[];
}

/**
 * Extrai o body de validação (com `errors` e/ou `message`) de qualquer uma
 * destas três formas que aparecem na app:
 *   A. AxiosError raw — algum caller que bypasse o interceptor: `err.response.data`
 *   B. Body desempacotado pelo interceptor 422: `err` já é `{ message, errors }`
 *   C. Body propagado via thunk (rejectWithValue) — idem ao B
 *
 * Devolve `null` se não houver nenhuma estrutura reconhecível.
 */
/**
 * Shape consolidado para erros de API. Inclui `status` (HTTP status code,
 * preservado pelo interceptor em qualquer 4xx via `__status`), e o body
 * desempacotado (`message`, `errors`).
 *
 * Status pode ser undefined em 2 caminhos legacy:
 *   - 5xx/network → interceptor devolve string, sem status estruturado
 *   - Caller que bypassa o interceptor → AxiosError raw, status em response
 */
export interface ApiErrorShape {
    status?: number;
    message?: string;
    errors?: Record<string, string[]>;
}

/**
 * MS2.g item 1 — Extracção consolidada de status+message+errors de qualquer
 * uma das 4 formas que o sistema produz:
 *   A. AxiosError raw (`err.response.data` + `err.response.status`)
 *   B. Body desempacotado pelo interceptor 4xx (MS2.g): `err = {...body, __status}`
 *   C. Body via thunk (rejectWithValue) — igual ao B
 *   D. String legacy de 5xx/network — apenas em `message`
 */
export const extractApiError = (error: any): ApiErrorShape => {
    if (!error) return {};

    // Forma D — string (5xx, network, fallback do switch)
    if (typeof error === "string") {
        return { message: error };
    }

    // Formas B / C — body desempacotado pelo interceptor com __status (MS2.g)
    if (typeof error === "object" && typeof error.__status === "number") {
        return {
            status:   error.__status,
            message:  error.message,
            errors:   error.errors,
        };
    }

    // Forma A — AxiosError raw (caller que bypassa o interceptor)
    if (typeof error === "object" && error.response) {
        const status = error.response.status;
        const body   = error.response.data ?? {};
        return {
            status:  typeof status === "number" ? status : undefined,
            message: body?.message,
            errors:  body?.errors,
        };
    }

    // Forma fallback — body cru sem __status (compatibilidade com pré-MS2.g)
    if (typeof error === "object" && (error.errors || error.message)) {
        return { message: error.message, errors: error.errors };
    }

    return {};
};

const extractValidationBody = (error: any): { message?: string; errors?: Record<string, string[]> } | null => {
    if (!error) return null;

    // Forma A — AxiosError com response
    const fromAxios = error?.response?.data;
    if (fromAxios && typeof fromAxios === "object" && (fromAxios.errors || fromAxios.message)) {
        return fromAxios;
    }

    // Formas B / C — body já entregue cru
    if (typeof error === "object" && (error.errors || error.message)) {
        return { message: error.message, errors: error.errors };
    }

    return null;
};

/**
 * Mostra toast(s) de erro a partir de qualquer um dos shapes acima.
 *
 * - Com `errors`: 1 toast por mensagem.
 * - Só com `message` (422 ou outro): 1 toast com a mensagem.
 * - Nenhum dos dois: 1 toast com `fallbackMessage`.
 */
export const showApiErrorToast = (
    error: any,
    fallbackMessage: string = "Erro ao gravar."
): void => {
    const body = extractValidationBody(error);

    if (body?.errors && typeof body.errors === "object") {
        const messages = Object.values(body.errors).flat() as string[];
        if (messages.length > 0) {
            messages.forEach(msg => {
                toast.error(msg, { position: "top-right", autoClose: 6000, hideProgressBar: false });
            });
            return;
        }
    }

    const message = body?.message ?? fallbackMessage;
    toast.error(message, { position: "top-right", autoClose: 6000, hideProgressBar: false });
};

/**
 * Extrai erros de validação 422 numa estrutura agrupada por campo.
 *
 * Devolve `null` se o erro não trouxer `errors` reconhecíveis — o caller usa
 * isso para limpar o Alert. Um 422 que venha só com `message` (sem `errors`)
 * também devolve null aqui: a mensagem aparece via `showApiErrorToast`.
 */
export const parseApiValidationErrors = (error: any): ApiValidationError[] | null => {
    const body = extractValidationBody(error);

    if (!body?.errors || typeof body.errors !== "object") {
        return null;
    }

    const result: ApiValidationError[] = [];
    for (const [field, msgs] of Object.entries(body.errors)) {
        if (Array.isArray(msgs) && msgs.length > 0) {
            result.push({ field, messages: msgs as string[] });
        }
    }

    return result.length > 0 ? result : null;
};
