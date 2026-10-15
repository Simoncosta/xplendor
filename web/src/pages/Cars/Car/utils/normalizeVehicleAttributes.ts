import type { ICarUpdatePayload } from "common/models/car.model";
import { DEFAULT_VEHICLE_ATTRIBUTES } from "slices/cars/car.defaults";
import { BED_LABELS, type BedType } from "../data/vehicleAttributes";

/**
 * Normalização dos atributos de habitação quando o editor carrega uma viatura
 * (initialValues do Formik). Saiu do CarEditor para se poder testar.
 */

// Tipos de cama válidos: os de BED_LABELS (espelha VehicleAttribute::BED_LABELS no
// servidor). Um tipo que falte aqui reverte para "outra" no init do Formik e
// perde-se no save seguinte (aconteceu à cama_sofa e à cama_popup_panoramica).
const validBedSlugs = new Set<BedType>(Object.keys(BED_LABELS) as BedType[]);

// Fix 1.A (auditoria pós Sub-fase F, 2026-06-18): preserva `capacity`.
// Antes, esta função devolvia só `{type}` e descartava `capacity` no init
// dos initialValues do Formik. Combinado com `enableReinitialize: true`,
// a capacity desaparecia sempre depois de gravar — o input caía no
// fallback `value={bed?.capacity ?? 1}` e mostrava "1".
// Clamp 1-4 espelha as constraints da Form Request backend
// (`vehicle_attributes.beds.*.capacity` → min:1 max:4) e da
// `VehicleAttribute::normalizeBedTypes`. Fallback 1 para registos legacy
// sem capacity.
export const normalizeBeds = (beds: unknown): Array<{ type: BedType; capacity: number }> => {
    if (!Array.isArray(beds)) return [];

    return beds.map((bed) => {
        const raw = typeof bed === "string" ? bed : (bed as Record<string, unknown>)?.type;
        const type = typeof raw === "string" && validBedSlugs.has(raw as BedType)
            ? (raw as BedType)
            : "outra";

        const capRaw = typeof bed === "object" && bed !== null
            ? (bed as Record<string, unknown>).capacity
            : undefined;
        const capNum = typeof capRaw === "number" ? capRaw : Number(capRaw);
        const capacity = Number.isFinite(capNum)
            ? Math.max(1, Math.min(4, Math.floor(capNum)))
            : 1;

        return { type, capacity };
    });
};

export const normalizeVehicleAttributes = (attributes?: ICarUpdatePayload["vehicle_attributes"]) => {
    const { autonomy_km, ...restAttributes } = attributes ?? {};

    // 2026-06-29 — Retro-compat dos depósitos de água: viaturas antigas
    // podem ter `clean_water_litres` / `waste_water_litres` > 0 mas ainda
    // sem as checkboxes novas. Neste caso o UI marca a checkbox como
    // true automaticamente (litros > 0 implica "tem depósito"). O JSON
    // gravado a partir do próximo save fica com a checkbox explícita.
    const hb = attributes?.habitation_basics ?? {};
    const b = (hb as any).bathroom ?? {};
    const cleanLitres = Number(b.clean_water_litres) || 0;
    const wasteLitres = Number(b.waste_water_litres) || 0;
    const bathroomWithRetroCompat = {
        ...b,
        has_clean_water_tank: b.has_clean_water_tank === true || cleanLitres > 0,
        has_waste_water_tank: b.has_waste_water_tank === true || wasteLitres > 0,
    };

    return {
        ...DEFAULT_VEHICLE_ATTRIBUTES,
        ...restAttributes,
        // A chave gravada é `autonomy_km` (o campo do formulário também). Antes passava
        // para `autonomy`, que nenhum campo lê: a autonomia aparecia vazia ao reabrir e
        // o save seguinte apagava-a (o servidor substitui os atributos todos).
        autonomy_km: autonomy_km ?? (Number(restAttributes.autonomy) || undefined),
        beds: normalizeBeds(restAttributes.beds),
        has_bathroom:
            attributes?.has_bathroom === true
            || attributes?.has_bathroom === 1
            || String(attributes?.has_bathroom ?? "") === "1",
        has_kitchen:
            attributes?.has_kitchen === true
            || attributes?.has_kitchen === 1
            || String(attributes?.has_kitchen ?? "") === "1",
        habitation_basics: {
            ...hb,
            bathroom: bathroomWithRetroCompat,
        },
    };
};
