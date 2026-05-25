export function buildCarFormData(values: any, opts?: { isUpdate?: boolean }) {
    const fd = new FormData();

    // ✅ FIX 1: detecta update automaticamente (se não vier opts)
    const isUpdate = opts?.isUpdate ?? Boolean(values?.id);

    const toBool = (v: any) => (v === true || v === 1 || v === "1" || v === "true");
    const isNil = (v: any) => v === null || v === undefined;

    const appendScalar = (key: string, value: any) => {
        if (isNil(value)) return;
        if (typeof value === "boolean") return fd.append(key, value ? "1" : "0");
        if (typeof value === "number" || typeof value === "string") return fd.append(key, String(value));
        fd.append(key, String(value));
    };

    const ignoreKeys = new Set([
        "stored_images",
        "images_preview",

        "images",
        "images_meta",

        "existing_images",
        "existing_images_meta",

        "extras",
        "lifestyle",
        "vehicle_attributes",

        "exterior_360_images",
        "exterior_360_meta",
    ]);

    const hasMotorFields = values.vehicle_type !== "caravan";

    const motorFieldKeys = new Set([
        "fuel_type",
        "engine_capacity_cc",
        "power_hp",
        "transmission",
    ]);

    Object.entries(values).forEach(([key, value]) => {
        if (ignoreKeys.has(key)) return;
        if (!hasMotorFields && motorFieldKeys.has(key)) return;
        if (typeof value === "object" && value !== null) return;
        appendScalar(key, value);
    });

    // Envia existentes quando for update.
    // existing_images_present=1 é o sentinel que distingue "array vazio intencional"
    // de "chave ausente" — FormData não consegue representar um array vazio,
    // por isso sem o sentinel o backend interpreta como "não tocar nas imagens".
    if (isUpdate && Array.isArray(values.existing_images)) {
        fd.append("existing_images_present", "1");
        values.existing_images.forEach((v: any, i: number) => {
            if (!isNil(v)) fd.append(`existing_images[${i}]`, String(v));
        });
    }

    if (isUpdate && Array.isArray(values.existing_images_meta)) {
        values.existing_images_meta.forEach((m: any, i: number) => {
            if (!m) return;
            fd.append(`existing_images_meta[${i}][order]`, String(m.order ?? i + 1));
            fd.append(`existing_images_meta[${i}][is_primary]`, toBool(m.is_primary) ? "1" : "0");
        });
    }

    // novas images + meta (create e update)
    (values.images ?? []).forEach((file: any, i: number) => {
        if (file instanceof File) fd.append(`images[${i}]`, file);
    });

    (values.images_meta ?? []).forEach((m: any, i: number) => {
        if (!m) return;
        fd.append(`images_meta[${i}][order]`, String(m.order ?? i + 1));
        fd.append(`images_meta[${i}][is_primary]`, toBool(m.is_primary) ? "1" : "0");
    });

    // extras (array CarExtrasGroup[])
    if (Array.isArray(values.extras)) {
        values.extras.forEach((g: any, i: number) => {
            if (!g) return;
            if (g.group) fd.append(`extras[${i}][group]`, String(g.group));
            (g.items ?? []).forEach((item: any, j: number) => {
                if (item === null || item === undefined) return;
                fd.append(`extras[${i}][items][${j}]`, String(item));
            });
        });
    }

    const vehicleAttributes = values.vehicle_attributes ?? {};

    const appendVehicleAttr = (prefix: string, value: any) => {
        if (isNil(value) || value === "") return;

        if (Array.isArray(value)) {
            value.forEach((item: any, i: number) => {
                if (isNil(item) || item === "") return;
                if (typeof item === "object") {
                    Object.entries(item).forEach(([childKey, childValue]) => {
                        if (isNil(childValue) || childValue === "") return;
                        fd.append(`${prefix}[${i}][${childKey}]`, String(childValue));
                    });
                    return;
                }
                fd.append(`${prefix}[${i}]`, String(item));
            });
            return;
        }

        if (typeof value === "object") {
            Object.entries(value).forEach(([subKey, subValue]) => {
                appendVehicleAttr(`${prefix}[${subKey}]`, subValue);
            });
            return;
        }

        if (typeof value === "boolean") {
            fd.append(prefix, value ? "1" : "0");
            return;
        }

        fd.append(prefix, String(value));
    };

    if (values.vehicle_type === "motorhome" || values.vehicle_type === "caravan") {
        fd.append("vehicle_attributes[_sync]", "1");

        Object.entries(vehicleAttributes).forEach(([key, value]) => {
            appendVehicleAttr(`vehicle_attributes[${key}]`, value);
        });
    } else if (isUpdate) {
        fd.append("vehicle_attributes[_sync]", "1");
    }

    // lifestyle
    (values.lifestyle ?? []).forEach((v: any, i: number) => {
        if (isNil(v)) return;
        fd.append(`lifestyle[${i}]`, typeof v === "object" ? JSON.stringify(v) : String(v));
    });

    // exterior_360
    (values.exterior_360_images ?? []).forEach((file: any, i: number) => {
        if (file instanceof File) fd.append(`exterior_360_images[${i}]`, file);
    });

    (values.exterior_360_meta ?? []).forEach((m: any, i: number) => {
        if (!m) return;
        fd.append(`exterior_360_meta[${i}][order]`, String(m.order ?? i + 1));
    });

    return fd;
}
