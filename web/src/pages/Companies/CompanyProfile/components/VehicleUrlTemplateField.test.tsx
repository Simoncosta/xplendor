import { fireEvent, render, screen } from "@testing-library/react";
import { Formik } from "formik";
import VehicleUrlTemplateField, { VEHICLE_URL_HINT, vehicleUrlTemplateError } from "./VehicleUrlTemplateField";
import XInputCheckbox from "Components/Common/XInputCheckbox";

describe("Endereço da viatura no site (ficha com QR)", () => {
    it("valida https e {id}, como o servidor", () => {
        expect(vehicleUrlTemplateError("")).toBeNull();
        expect(vehicleUrlTemplateError("https://quebom.pt/vehicles/{id}")).toBeNull();
        expect(vehicleUrlTemplateError("http://quebom.pt/vehicles/{id}")).toMatch(/https:\/\//);
        expect(vehicleUrlTemplateError("https://quebom.pt/vehicles/")).toMatch(/\{id\}/);
    });

    it("mostra a pré-visualização do endereço de uma viatura, com os parâmetros do QR", () => {
        render(<Formik initialValues={{ vehicle_url_template: "" }} onSubmit={() => {}}><VehicleUrlTemplateField /></Formik>);
        fireEvent.change(screen.getByLabelText(/^Endereço da viatura no site:/), { target: { value: "https://quebom.pt/vehicles/{id}" } });
        const preview = screen.getByTestId("vehicle-url-preview");
        expect(preview).toHaveTextContent("https://quebom.pt/vehicles/123");
        expect(preview).toHaveTextContent("?origem=qr&utm_source=qr&utm_medium=offline&utm_campaign=ficha_stand");
    });

    it("a ajuda está no (i) ao lado do rótulo, não por baixo do campo", async () => {
        render(<Formik initialValues={{ vehicle_url_template: "" }} onSubmit={() => {}}><VehicleUrlTemplateField /></Formik>);
        expect(screen.queryByText(VEHICLE_URL_HINT)).toBeNull();
        expect(VEHICLE_URL_HINT).toBe("Usado pelo QR Code da ficha da viatura (A4). Escreva {id} no sítio do número da viatura. Sem endereço, a ficha sai sem QR.");

        fireEvent.mouseOver(screen.getByRole("button", { name: "Ajuda: Endereço da viatura no site" }));
        expect(await screen.findByText(VEHICLE_URL_HINT)).toBeInTheDocument();
    });
});

describe("Caixa de seleção com ajuda no (i)", () => {
    it("tocar no (i) mostra a ajuda e não marca a caixa", async () => {
        render(<Formik initialValues={{ billing_reminders_enabled: false }} onSubmit={() => {}}>
            <XInputCheckbox name="billing_reminders_enabled" label="Enviar lembretes de cobrança" hint="Faturas da XPLENDOR: um email por fatura." />
        </Formik>);
        expect(screen.queryByText("Faturas da XPLENDOR: um email por fatura.")).toBeNull();

        fireEvent.click(screen.getByRole("button", { name: "Ajuda: Enviar lembretes de cobrança" }));
        expect(await screen.findByText("Faturas da XPLENDOR: um email por fatura.")).toBeInTheDocument();
        expect(screen.getByLabelText("Enviar lembretes de cobrança")).not.toBeChecked();
    });
});
