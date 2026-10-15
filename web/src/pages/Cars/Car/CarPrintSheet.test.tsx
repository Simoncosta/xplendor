import { render, screen, waitFor } from "@testing-library/react";
import CarPrintSheet from "./CarPrintSheet";

// O Jest do CRA não resolve o react-router-dom 7 (exports): substitui-se só o que a ficha usa.
jest.mock("react-router-dom", () => ({
    useParams: () => ({ companyId: "1", id: "7" }),
    useNavigate: () => jest.fn(),
}), { virtual: true });

const mockGetCarPrintSheet = jest.fn();
jest.mock("helpers/laravel_helper", () => ({
    getCarPrintSheet: (...args: unknown[]) => mockGetCarPrintSheet(...args),
}));

const extras = (byGroup: Record<string, string[]>) =>
    ["comfort_multimedia", "exterior_equipment", "interior_equipment", "safety_performance"].map((group) => ({
        group,
        items: byGroup[group] ?? [],
    }));

const vehicleAttributes = {
    dimensions: { length_m: 7.2 },
    autonomy_km: 600,
    beds: [{ type: "cama_popup_panoramica", capacity: 2 }],
    habitation_basics: {
        has_kitchen: true, has_bathroom: true,
        kitchen: { has_stove: true, stove_burners: 3, stove_type: "gas" },
        bathroom: { has_toilet: true },
    },
    energy_climate: { cabin_battery_count: 2, cabin_battery_brand: "Varta", cabin_battery_ah: 95 },
    exterior: { has_awning: true },
    security: { has_alarm: true, has_gas_lock: true },
    chassis_structure: { chassis_type: "alko", has_remifront: true, has_cabin_blackouts: true, has_window_blackouts: true },
    interior_furniture: { has_rotating_seats: true, has_curtains: true },
    living_room: { layout: "l_shape" },
};

const sheet = (vehicleType: string) => ({
    vehicle_type: vehicleType,
    vehicle_attributes: vehicleAttributes,
    extras: extras({
        comfort_multimedia: ["Ecrã Táctil ou Touch Screen", "Android Auto"],
        exterior_equipment: ["Jantes de Liga Leve"],
        safety_performance: ["Fecho Centralizado com Comando a Distância"],
    }),
    specs: { fuel_type: "diesel", transmission: "manual", gear_count: 6, power_hp: 140, engine_capacity_cc: 2300, doors: 3, segment: null, exterior_color: null, is_metallic: false, interior_color: null },
    state: { condition: "used", origin: "national", is_trade_in: false, has_spare_key: false, has_manuals: false },
    headline_stats: { seats: 4, sleeps: 2, gross_weight_kg: 3500, mileage_km: 50000 },
    price: { gross: 60000, promo_gross: null, hide_price_online: false },
    registration: { year: 2020, month: 5 },
    company: { logo_path: null, trade_name: "Quebom", fiscal_name: "Quebom Lda" },
    brand: { id: 1, name: "Fiat" }, model: { id: 1, name: "Ducato" }, version: null, category: null,
    license_plate: "AA-00-AA", vin: null, warranty_months: null, engine_brand: "Fiat",
});

const groupsOnScreen = () =>
    Array.from(document.querySelectorAll(".ps-group")).map((g) => ({
        title: g.querySelector(".ps-group-title")?.textContent,
        body: g.querySelector(".ps-group-body")?.textContent ?? "",
    }));

describe("CarPrintSheet", () => {
    it("autocaravana: os grupos seguem as secções do formulário, com os rótulos da lista única", async () => {
        mockGetCarPrintSheet.mockResolvedValue({ data: sheet("motorhome") });
        render(<CarPrintSheet />);
        await waitFor(() => expect(screen.getByText("Cabine de condução e segurança")).toBeInTheDocument());

        const groups = groupsOnScreen();
        expect(groups.map((g) => g.title)).toEqual([
            "Dimensões", "Motor e ficha", "Estado", "Camas",
            "Energia e autonomia", "Sala e cozinha", "Duche e WC",
            "Cabine de condução e segurança", "Exterior", "Outros equipamentos",
        ]);
        const body = (title: string) => groups.find((g) => g.title === title)!.body;
        expect(body("Motor e ficha")).toContain("Velocidades 6");
        expect(body("Camas")).toContain("Cama pop-up panorâmica (dorme 2)");
        expect(body("Energia e autonomia")).toContain("Autonomia 600 km");
        expect(body("Energia e autonomia")).toContain("2 bat. cabine (Varta, 95 Ah)");
        expect(body("Sala e cozinha")).toContain("Fogão a gás de 3 bocas");
        expect(body("Sala e cozinha")).toContain("Tapa-luz janelas");
        expect(body("Sala e cozinha")).not.toContain("Tapa-luz cabine");
        const cabin = body("Cabine de condução e segurança");
        for (const item of ["Estores Remifront", "Tapa-luz cabine", "Chassis Alko", "Fechadura gás", "Ecrã tátil", "Alarme", "Bancos giratórios", "Fecho centralizado"]) {
            expect(cabin).toContain(item);
        }
        expect(cabin).not.toContain("Ecrã Táctil ou Touch Screen");
        expect(body("Exterior")).toContain("Jantes de liga leve");
        expect(body("Outros equipamentos")).toBe("Android Auto");
    });

    it("caravana: os grupos de sempre", async () => {
        mockGetCarPrintSheet.mockResolvedValue({ data: sheet("caravan") });
        render(<CarPrintSheet />);
        await waitFor(() => expect(screen.getByText("Cabine")).toBeInTheDocument());
        const groups = groupsOnScreen();
        expect(groups.map((g) => g.title)).toEqual([
            "Dimensões", "Motor e ficha", "Estado",
            "Cozinha", "Casa de banho", "Energia", "Exterior", "Chassis e clarabóias", "Interior", "Segurança", "Camas",
            "Cabine",
        ]);
        const body = (title: string) => groups.find((g) => g.title === title)!.body;
        expect(body("Segurança")).toBe("Alarme · Fechadura gás");
        expect(body("Chassis e clarabóias")).toBe("Chassis Alko · Tapa-luz janelas · Tapa-luz cabine");
        expect(body("Interior")).toBe("Sala: sala em L · Cortinas");
        expect(body("Cabine")).toContain("Ecrã Táctil ou Touch Screen");
    });
});
