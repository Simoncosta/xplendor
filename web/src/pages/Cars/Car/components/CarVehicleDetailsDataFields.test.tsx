import { render, screen, fireEvent, waitFor, within } from "@testing-library/react";
import { Formik } from "formik";
import CarVehicleDetailsDataFields from "./CarVehicleDetailsDataFields";
import CarEquipmentDataFields from "./CarEquipmentDataFields";

// As categorias das autocaravanas vêm do servidor; no teste, uma lista vazia.
jest.mock("helpers/laravel_helper", () => ({
    getCarCategories: jest.fn(() => Promise.resolve({ data: [] })),
}));

type Values = Record<string, any>;

const extras = (byGroup: Record<string, string[]>) =>
    ["comfort_multimedia", "exterior_equipment", "interior_equipment", "safety_performance"].map((group) => ({
        group,
        items: byGroup[group] ?? [],
    }));

const vehicleAttributes = {
    dimensions: {}, weights: {}, beds: [], autonomy_km: 600,
    habitation_basics: { has_kitchen: false, has_bathroom: false, kitchen: {}, bathroom: {} },
    energy_climate: {}, exterior: {}, security: { has_alarm: false }, chassis_structure: {},
    interior_furniture: { has_rotating_seats: false }, living_room: {},
};

// O editor monta os dois: as secções (CarVehicleDetailsDataFields) e o contentor do
// equipamento, que sincroniza `extrasByGroup` com `extras`.
const renderEditor = (initial: Values) => {
    const latest: { values: Values } = { values: initial };
    render(
        <Formik initialValues={initial} onSubmit={() => undefined}>
            {({ values }) => {
                latest.values = values;
                return (
                    <>
                        <CarVehicleDetailsDataFields isEdit />
                        <CarEquipmentDataFields isEdit />
                    </>
                );
            }}
        </Formik>,
    );
    return latest;
};

const headers = () => Array.from(document.querySelectorAll(".accordion-header strong")).map((el) => el.textContent?.trim());

/** O XInput não liga o rótulo ao campo: procura-se pelo name. */
const byName = (name: string) => Array.from(document.querySelectorAll<HTMLInputElement>(`input[name="${name}"]`));

const group = (values: Values, key: string) => values.extras.find((g: any) => g.group === key).items;

describe("CarVehicleDetailsDataFields: ordem das secções", () => {
    it("autocaravana: Dimensões e Pesos, Energia e autonomia, Sala e cozinha, Duche e WC, Cabine de condução e segurança, Exterior", async () => {
        renderEditor({ vehicle_type: "motorhome", extras: extras({}), extrasByGroup: undefined, vehicle_attributes: vehicleAttributes });
        await waitFor(() => expect(headers()).toEqual([
            "Dimensões e Pesos",
            "Energia e autonomia",
            "Sala e cozinha",
            "Duche e WC",
            "Cabine de condução e segurança",
            "Outros equipamentos",
            "Exterior",
        ]));
        // A autonomia aparece uma vez, em Energia e autonomia.
        const autonomy = byName("vehicle_attributes.autonomy_km");
        expect(autonomy).toHaveLength(1);
        expect(autonomy[0].closest(".accordion-item")?.id).toBe("hab-acc-m-energy");
        // Alarme e Bancos giratórios aparecem uma só vez (na lista única).
        expect(screen.getAllByLabelText("Alarme")).toHaveLength(1);
        expect(screen.getAllByLabelText("Bancos giratórios")).toHaveLength(1);
        // O tapa-luz da cabine está na Cabine; o das janelas, em Sala e cozinha.
        expect(screen.getByLabelText("Tapa-luz cabine").closest("#hab-acc-m-cabin")).not.toBeNull();
        expect(screen.getByLabelText("Tapa-luz janelas").closest("#hab-acc-m-living")).not.toBeNull();
    });

    it("caravana: os acordeões de sempre, pela ordem de sempre", async () => {
        renderEditor({ vehicle_type: "caravan", extras: extras({}), extrasByGroup: undefined, vehicle_attributes: vehicleAttributes });
        await waitFor(() => expect(headers()).toEqual([
            "Dimensões e Pesos",
            "Cozinha",
            "Casa de Banho",
            "Energia e Aquecimento",
            "Exterior",
            "Segurança e Fechaduras",
            "Chassis e Estrutura",
            "Cabine",
            "Mobiliário Interior",
            "Sala",
            // Os 4 grupos de extras (CarEquipmentDataFields).
            "Conforto & Multimédia",
            "Equipamento Exterior",
            "Equipamento Interior",
            "Segurança & Desempenho",
        ]));
        expect(byName("vehicle_attributes.autonomy_km")[0].closest("#hab-acc-1")).not.toBeNull();
        // Nas caravanas, o Alarme continua nos dois sítios de sempre: o atributo e o extra.
        expect(screen.getAllByLabelText("Alarme").map((el) => el.closest(".accordion-item")?.id)).toEqual(["hab-acc-6", "extras-acc-4"]);
        expect(screen.getByLabelText("Bancos giratórios").closest("#hab-acc-10")).not.toBeNull();
    });
});

describe("Cabine de condução e segurança (autocaravana)", () => {
    it("marcar e desmarcar grava os textos de hoje; os extras fora da lista mantêm-se; as jantes estão no Exterior", async () => {
        const latest = renderEditor({
            vehicle_type: "motorhome",
            extras: extras({
                safety_performance: ["Fecho Centralizado com Comando a Distância"],
                comfort_multimedia: ["Android Auto"],
            }),
            extrasByGroup: undefined,
            vehicle_attributes: vehicleAttributes,
        });

        // A variante conta como marcado.
        await waitFor(() => expect(screen.getByLabelText("Fecho centralizado")).toBeChecked());

        fireEvent.click(screen.getByLabelText("Ecrã tátil"));
        await waitFor(() => expect(group(latest.values, "comfort_multimedia")).toEqual(["Android Auto", "Ecrã Táctil ou Touch Screen"]));

        fireEvent.click(screen.getByLabelText("Fecho centralizado"));
        await waitFor(() => expect(group(latest.values, "safety_performance")).toEqual([]));

        fireEvent.click(screen.getByLabelText("Alarme"));
        await waitFor(() => expect(latest.values.vehicle_attributes.security.has_alarm).toBe(true));

        fireEvent.click(screen.getByLabelText("Jantes de liga leve"));
        await waitFor(() => expect(group(latest.values, "exterior_equipment")).toEqual(["Jantes de Liga Leve"]));

        // "Outros equipamentos" mostra o extra fora da lista e não as jantes.
        const others = document.getElementById("hab-acc-m-others") as HTMLElement;
        expect(within(others).getByLabelText("Android Auto")).toBeChecked();
        expect(within(others).queryByLabelText("Jantes de Liga Leve")).toBeNull();

        expect(latest.values.extras.map((g: any) => g.group)).toEqual([
            "comfort_multimedia", "exterior_equipment", "interior_equipment", "safety_performance",
        ]);
    }, 30000); // o formulário inteiro volta a desenhar-se a cada clique
});
