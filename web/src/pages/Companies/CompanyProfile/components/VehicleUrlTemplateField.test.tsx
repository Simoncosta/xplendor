import { fireEvent, render, screen } from "@testing-library/react";
import { Formik } from "formik";
import VehicleUrlTemplateField, { vehicleUrlTemplateError } from "./VehicleUrlTemplateField";

describe("Endereço da viatura no site (ficha com QR)", () => {
    it("valida https e {id}, como o servidor", () => {
        expect(vehicleUrlTemplateError("")).toBeNull();
        expect(vehicleUrlTemplateError("https://quebom.pt/vehicles/{id}")).toBeNull();
        expect(vehicleUrlTemplateError("http://quebom.pt/vehicles/{id}")).toMatch(/https:\/\//);
        expect(vehicleUrlTemplateError("https://quebom.pt/vehicles/")).toMatch(/\{id\}/);
    });

    it("mostra a pré-visualização do endereço de uma viatura, com os parâmetros do QR", () => {
        render(<Formik initialValues={{ vehicle_url_template: "" }} onSubmit={() => {}}><VehicleUrlTemplateField /></Formik>);
        fireEvent.change(screen.getByLabelText("Endereço da viatura no site"), { target: { value: "https://quebom.pt/vehicles/{id}" } });
        const preview = screen.getByTestId("vehicle-url-preview");
        expect(preview).toHaveTextContent("https://quebom.pt/vehicles/123");
        expect(preview).toHaveTextContent("?origem=qr&utm_source=qr&utm_medium=offline&utm_campaign=ficha_stand");
    });
});
