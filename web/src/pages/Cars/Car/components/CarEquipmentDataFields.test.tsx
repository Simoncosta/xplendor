import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { Formik } from "formik";
import CarEquipmentDataFields from "./CarEquipmentDataFields";

type Values = Record<string, any>;

const renderWith = (initial: Values) => {
    const latest: { values: Values } = { values: initial };
    render(
        <Formik initialValues={initial} onSubmit={() => undefined}>
            {({ values }) => {
                latest.values = values;
                return <CarEquipmentDataFields isEdit />;
            }}
        </Formik>,
    );
    return latest;
};

const extras = (safety: string[], comfort: string[] = []) => [
    { group: "comfort_multimedia", items: comfort },
    { group: "exterior_equipment", items: [] },
    { group: "interior_equipment", items: [] },
    { group: "safety_performance", items: safety },
];

describe("CarEquipmentDataFields", () => {
    it("um carro continua com os 4 grupos de sempre", () => {
        renderWith({ vehicle_type: "car", extras: extras(["ABS"]), extrasByGroup: undefined });
        for (const title of ["Conforto & Multimédia", "Equipamento Exterior", "Equipamento Interior", "Segurança & Desempenho"]) {
            expect(screen.getByText(title)).toBeInTheDocument();
        }
        expect(screen.queryByText("Cabine de condução e segurança")).not.toBeInTheDocument();
    });

    it("uma caravana também mantém os 4 grupos", () => {
        renderWith({ vehicle_type: "caravan", extras: extras([]), extrasByGroup: undefined });
        expect(screen.getByText("Segurança & Desempenho")).toBeInTheDocument();
        expect(screen.queryByText("Cabine de condução e segurança")).not.toBeInTheDocument();
    });

    it("uma autocaravana mostra a lista única; marcar e desmarcar grava os textos de hoje e mantém os de fora da lista", async () => {
        const latest = renderWith({
            vehicle_type: "motorhome",
            extras: extras(["Fecho Centralizado com Comando a Distância"], ["Android Auto"]),
            extrasByGroup: undefined,
            vehicle_attributes: { security: { has_alarm: false } },
        });
        expect(screen.getByText("Cabine de condução e segurança")).toBeInTheDocument();
        expect(screen.queryByText("Segurança & Desempenho")).not.toBeInTheDocument();
        expect(screen.getByText("Outros equipamentos")).toBeInTheDocument();

        // A variante conta como marcado.
        await waitFor(() => expect(screen.getByLabelText("Fecho centralizado")).toBeChecked());

        fireEvent.click(screen.getByLabelText("Ecrã tátil"));
        await waitFor(() => {
            const comfort = latest.values.extras.find((g: any) => g.group === "comfort_multimedia").items;
            expect(comfort).toEqual(["Android Auto", "Ecrã Táctil ou Touch Screen"]);
        });

        fireEvent.click(screen.getByLabelText("Fecho centralizado"));
        await waitFor(() => {
            const safety = latest.values.extras.find((g: any) => g.group === "safety_performance").items;
            expect(safety).toEqual([]);
        });

        fireEvent.click(screen.getByLabelText("Alarme"));
        await waitFor(() => expect(latest.values.vehicle_attributes.security.has_alarm).toBe(true));

        // O extra fora da lista continua gravado e aparece em "Outros equipamentos".
        expect(latest.values.extras.map((g: any) => g.group)).toEqual([
            "comfort_multimedia", "exterior_equipment", "interior_equipment", "safety_performance",
        ]);
        expect(screen.getByLabelText("Android Auto")).toBeChecked();
    });
});
