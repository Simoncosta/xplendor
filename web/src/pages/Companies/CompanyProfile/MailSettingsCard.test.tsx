import { act, fireEvent, render, screen } from "@testing-library/react";
import MailSettingsCard from "./MailSettingsCard";
import { getMailSettings, saveMailSettings } from "helpers/laravel_helper";

jest.mock("helpers/laravel_helper", () => ({ getMailSettings: jest.fn(), saveMailSettings: jest.fn() }));
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() } }));

const payload = (over: any = {}) => ({ data: { retention_months: 12, default: 12, min: 3, max: 24, info: "As mensagens no servidor de e-mail não são tocadas.",
    can_edit: true, history: [], ...over } });

/** E-mail 8 · Perfil da empresa › Correio: o prazo de retenção (3 a 24 meses), só o administrador muda. */
describe("Perfil da empresa › Correio", () => {
    it("o administrador muda o prazo (de 3 a 24 meses) e vê o histórico; o motivo do servidor aparece", async () => {
        (getMailSettings as jest.Mock).mockResolvedValue(payload());
        (saveMailSettings as jest.Mock).mockResolvedValueOnce({ message: "Prazo de retenção guardado.", data: payload({ retention_months: 6,
            history: [{ before: 12, after: 6, user: "Matilde", at: "2026-10-27T18:00:00Z" }] }).data });
        render(<MailSettingsCard companyId={5} />);
        const field = await screen.findByLabelText("Retenção das mensagens guardadas");
        expect(field).toHaveValue(12);
        const save = screen.getByRole("button", { name: /Guardar/ });
        expect(save).toBeDisabled(); // nada mudou
        fireEvent.change(field, { target: { value: "25" } });
        expect(save).toBeDisabled(); // fora dos limites
        fireEvent.change(field, { target: { value: "2" } });
        expect(save).toBeDisabled();
        fireEvent.change(field, { target: { value: "6" } });
        await act(async () => { fireEvent.click(save); });
        expect(saveMailSettings).toHaveBeenCalledWith(5, 6);
        expect(screen.getByTestId("mail-settings-history")).toHaveTextContent("Matilde: 12 → 6 meses");

        (saveMailSettings as jest.Mock).mockRejectedValueOnce({ __status: 422, message: "x", errors: { "mail.retention_months": ["Retenção: indique um número inteiro de meses entre 3 e 24."] } });
        fireEvent.change(field, { target: { value: "9" } });
        await act(async () => { fireEvent.click(screen.getByRole("button", { name: /Guardar/ })); });
        expect(screen.getByTestId("mail-settings-error")).toHaveTextContent("entre 3 e 24");
    });

    it("quem não é administrador vê o prazo, sem poder mudar", async () => {
        (getMailSettings as jest.Mock).mockResolvedValue(payload({ can_edit: false, retention_months: 18 }));
        render(<MailSettingsCard companyId={5} />);
        expect(await screen.findByLabelText("Retenção das mensagens guardadas")).toBeDisabled();
        expect(screen.getByTestId("mail-settings-readonly")).toHaveTextContent("Só o administrador da empresa muda o prazo.");
        expect(screen.queryByRole("button", { name: /Guardar/ })).not.toBeInTheDocument();
    });
});
