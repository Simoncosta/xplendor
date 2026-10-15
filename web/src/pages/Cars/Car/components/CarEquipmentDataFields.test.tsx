import { render, screen } from "@testing-library/react";
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

    it("numa autocaravana não desenha os 4 grupos (o equipamento está na secção da cabine)", () => {
        renderWith({ vehicle_type: "motorhome", extras: extras(["ABS"]), extrasByGroup: undefined });
        expect(screen.queryByText("Segurança & Desempenho")).not.toBeInTheDocument();
    });
});
