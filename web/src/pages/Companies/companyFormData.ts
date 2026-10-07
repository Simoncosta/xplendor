/**
 * O FormData de criar ou editar uma empresa (perfil e modal da empresa): o logótipo vai como
 * "logo", os objetos em JSON e os booleanos como "1"/"0" (a regra `boolean` do Laravel não
 * aceita "true"/"false"). "is_agency" não segue no pedido: marca-se depois da criação.
 */
export function companyFormData(values: Record<string, any>, isEdit: boolean): FormData {
    const formData = new FormData();
    const logoOf = (value: any): File | null => {
        if (value instanceof File) return value;
        if (value instanceof FileList && value.length > 0) return value[0];
        if (Array.isArray(value) && value[0] instanceof File) return value[0];
        return null;
    };

    Object.entries(values).forEach(([key, value]) => {
        if (value === null || value === undefined || key === "is_agency") return;
        if (key === "logo_file") {
            const file = logoOf(value);
            if (file) formData.append("logo", file);
            return;
        }
        if (typeof value === "object" && !(value instanceof File)) formData.append(key, JSON.stringify(value));
        else if (typeof value === "boolean") formData.append(key, value ? "1" : "0");
        else formData.append(key, String(value));
    });

    if (isEdit) formData.append("_method", "PUT");
    return formData;
}

/** A primeira mensagem de erro de uma resposta 4xx (o helper da API rejeita com o corpo). */
export const companyErrorText = (e: any, fallback: string): string => {
    const body = e?.response?.data ?? e;
    const first = body?.errors ? (Object.values(body.errors).flat()[0] as string) : null;
    return first || (typeof body === "string" ? body : body?.message) || fallback;
};
