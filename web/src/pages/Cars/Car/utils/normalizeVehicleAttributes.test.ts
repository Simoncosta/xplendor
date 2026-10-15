import { normalizeBeds, normalizeVehicleAttributes } from "./normalizeVehicleAttributes";
import { buildCarFormData } from "./buildCarFormData";
import { BED_LABELS } from "../data/vehicleAttributes";

describe("normalizeVehicleAttributes (o editor a carregar uma viatura)", () => {
    it("mantém a cama pop-up panorâmica e a cama de sofá (não passam a Outra)", () => {
        expect(normalizeBeds([
            { type: "cama_popup_panoramica", capacity: 2 },
            { type: "cama_sofa", capacity: 1 },
            { type: "tipo_inventado", capacity: 1 },
        ])).toEqual([
            { type: "cama_popup_panoramica", capacity: 2 },
            { type: "cama_sofa", capacity: 1 },
            { type: "outra", capacity: 1 },
        ]);
        // Todos os tipos com rótulo são válidos ao carregar.
        for (const type of Object.keys(BED_LABELS)) {
            expect(normalizeBeds([{ type, capacity: 1 }])[0].type).toBe(type);
        }
    });

    it("a autonomia fica em autonomy_km e volta a seguir no save", () => {
        const values = normalizeVehicleAttributes({ autonomy_km: 650 } as any);
        expect(values.autonomy_km).toBe(650);
        const fd = buildCarFormData({ vehicle_type: "motorhome", vehicle_attributes: values }, { isUpdate: true });
        expect(fd.get("vehicle_attributes[autonomy_km]")).toBe("650");
    });

    it("um registo antigo com `autonomy` passa para autonomy_km", () => {
        expect(normalizeVehicleAttributes({ autonomy: 400 } as any).autonomy_km).toBe(400);
        expect(normalizeVehicleAttributes({} as any).autonomy_km).toBeUndefined();
    });
});
