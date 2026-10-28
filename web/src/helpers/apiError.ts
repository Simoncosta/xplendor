/**
 * O motivo de um erro da API, para mostrar a quem usa a app. O interceptor do axios (api_helper)
 * devolve o corpo dos erros 4xx tal como vem do servidor ({ message, errors, __status }), por isso
 * `err.response` não existe aqui: quem lia `err.response.data.message` mostrava sempre o texto por
 * omissão e escondia o motivo de um 422. Ordem: o primeiro erro de validação, a mensagem, e só
 * depois o texto por omissão. Os erros 5xx e de rede chegam como texto técnico (em inglês): fica o
 * texto por omissão.
 */
export function apiErrorMessage(err: unknown, fallback: string): string {
    if (!err || typeof err !== "object") return fallback;
    const e = err as { errors?: Record<string, unknown>; message?: unknown; response?: { data?: { errors?: Record<string, unknown>; message?: unknown } } };
    const body = e.errors !== undefined || e.message !== undefined ? e : e.response?.data ?? {};
    const firstError = body.errors ? Object.values(body.errors).flat().find((m) => typeof m === "string" && m.trim() !== "") : undefined;
    if (typeof firstError === "string") return firstError;
    if (typeof body.message === "string" && body.message.trim() !== "") return body.message;
    return fallback;
}

/** O estado HTTP de um erro da API (o interceptor guarda-o em __status). */
export function apiErrorStatus(err: unknown): number | undefined {
    if (!err || typeof err !== "object") return undefined;
    const e = err as { __status?: number; response?: { status?: number } };
    return e.__status ?? e.response?.status;
}
