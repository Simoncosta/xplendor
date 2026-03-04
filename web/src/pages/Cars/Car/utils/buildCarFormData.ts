export function buildCarFormData(values: any, opts?: { isUpdate?: boolean }) {
    const fd = new FormData();
    const isUpdate = Boolean(opts?.isUpdate);

    const toBool = (v: any) => (v === true || v === 1 || v === "1" || v === "true");
    const isNil = (v: any) => v === null || v === undefined;

    const appendScalar = (key: string, value: any) => {
        if (isNil(value)) return;
        if (typeof value === "boolean") return fd.append(key, value ? "1" : "0");
        if (typeof value === "number" || typeof value === "string") return fd.append(key, String(value));
        fd.append(key, String(value));
    };

    // 1) Campos simples (exclui arrays/objetos especiais)
    const ignoreKeys = new Set([
        "stored_images",
        "images_preview",

        "images",
        "images_meta",

        "existing_images",
        "existing_images_meta",

        "extras",
        "lifestyle",

        "exterior_360_images",
        "exterior_360_meta",
    ]);

    Object.entries(values).forEach(([key, value]) => {
        if (ignoreKeys.has(key)) return;
        if (typeof value === "object" && value !== null) return; // não mandar objects desconhecidos
        appendScalar(key, value);
    });

    // 2) existing_images + meta (SÓ no update)
    if (isUpdate) {
        (values.existing_images ?? []).forEach((v: any, i: number) => {
            if (!isNil(v)) fd.append(`existing_images[${i}]`, String(v));
        });

        (values.existing_images_meta ?? []).forEach((m: any, i: number) => {
            if (!m) return;
            fd.append(`existing_images_meta[${i}][order]`, String(m.order ?? i + 1));
            fd.append(`existing_images_meta[${i}][is_primary]`, toBool(m.is_primary) ? "1" : "0");
        });
    }

    // 3) novas images + meta (create e update)
    (values.images ?? []).forEach((file: any, i: number) => {
        if (file instanceof File) fd.append(`images[${i}]`, file);
    });

    (values.images_meta ?? []).forEach((m: any, i: number) => {
        if (!m) return;
        fd.append(`images_meta[${i}][order]`, String(m.order ?? i + 1));
        fd.append(`images_meta[${i}][is_primary]`, toBool(m.is_primary) ? "1" : "0");
    });

    // 4) extras
    // 4) extras (SUPORTA array CarExtrasGroup[])
    if (Array.isArray(values.extras)) {
        values.extras.forEach((g: any, i: number) => {
            if (!g) return;

            // group
            if (g.group) fd.append(`extras[${i}][group]`, String(g.group));

            // items[]
            (g.items ?? []).forEach((item: any, j: number) => {
                if (item === null || item === undefined) return;
                fd.append(`extras[${i}][items][${j}]`, String(item));
            });
        });
    } else if (values.extras && typeof values.extras === "object") {
        // fallback: se algum dia enviares map {group: string[]}
        Object.entries(values.extras).forEach(([group, items]: any) => {
            if (!Array.isArray(items)) return;
            items.forEach((item: any, idx: number) => {
                if (item === null || item === undefined) return;
                fd.append(`extras_map[${group}][${idx}]`, String(item));
            });
        });
    }

    // 5) lifestyle
    (values.lifestyle ?? []).forEach((v: any, i: number) => {
        if (isNil(v)) return;
        fd.append(`lifestyle[${i}]`, typeof v === "object" ? JSON.stringify(v) : String(v));
    });

    // 6) exterior_360
    (values.exterior_360_images ?? []).forEach((file: any, i: number) => {
        if (file instanceof File) fd.append(`exterior_360_images[${i}]`, file);
    });

    (values.exterior_360_meta ?? []).forEach((m: any, i: number) => {
        if (!m) return;
        fd.append(`exterior_360_meta[${i}][order]`, String(m.order ?? i + 1));
    });

    return fd;
}