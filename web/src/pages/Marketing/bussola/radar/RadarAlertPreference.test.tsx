import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import RadarAlertPreference from "./RadarAlertPreference";
import { getAlertPreferences, updateAlertPreferences } from "helpers/laravel_helper";

jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() } }));
jest.mock("helpers/laravel_helper", () => ({
    getAlertPreferences: jest.fn(), updateAlertPreferences: jest.fn(),
}));

describe("Bússola 7: a preferência de cada pessoa para os avisos de nota alta", () => {
    beforeEach(() => {
        (updateAlertPreferences as jest.Mock).mockResolvedValue({ data: { radar_high_score: false }, message: "Avisos de nota alta desligados." });
    });

    it("mostra ligado por omissão e desliga ao clicar", async () => {
        (getAlertPreferences as jest.Mock).mockResolvedValue({ data: { radar_high_score: true } });
        render(<RadarAlertPreference companyId={7} />);
        const toggle = await screen.findByRole("switch");
        expect(toggle).toBeChecked();
        fireEvent.click(toggle);
        await waitFor(() => expect(updateAlertPreferences).toHaveBeenCalledWith(7, { radar_high_score: false }));
        await waitFor(() => expect(screen.getByRole("switch")).not.toBeChecked());
    });

    it("sem resposta do servidor, não mostra nada", async () => {
        (getAlertPreferences as jest.Mock).mockRejectedValue(new Error("falhou"));
        const { container } = render(<RadarAlertPreference companyId={7} />);
        await waitFor(() => expect(getAlertPreferences).toHaveBeenCalled());
        expect(container).toBeEmptyDOMElement();
    });
});
