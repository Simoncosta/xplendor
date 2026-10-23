import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import RadarAlertsCard from "./RadarAlertsCard";
import { updateAdminRadarAlerts } from "helpers/laravel_helper";
import type { RadarAdminAlerts } from "common/models/radar.model";

jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() } }));
jest.mock("helpers/laravel_helper", () => ({ updateAdminRadarAlerts: jest.fn() }));

const alerts: RadarAdminAlerts = { min_score: 85, default_min_score: 85, sent_today: 2, window: { from: "09:00", to: "20:00" }, email_enabled: false };

describe("Bússola 7: os avisos de nota alta no painel do root", () => {
    beforeEach(() => {
        (updateAdminRadarAlerts as jest.Mock).mockImplementation((d: { min_score: number }) => Promise.resolve({ message: "Guardado.", data: { ...alerts, min_score: d.min_score } }));
    });

    it("mostra o limiar, os avisos de hoje e o estado do e-mail", () => {
        render(<RadarAlertsCard alerts={alerts} onSaved={jest.fn()} />);
        expect(screen.getByLabelText(/Pontuação mínima/)).toHaveValue(85);
        expect(screen.getByTestId("radar-alerts-today")).toHaveTextContent("2 avisos enviados hoje.");
        expect(screen.getByText("E-mail desligado (chamadas externas)")).toBeInTheDocument();
        expect(screen.getByTestId("radar-alerts-save")).toBeDisabled();
    });

    it("guarda um novo limiar e recusa valores fora do intervalo", async () => {
        const onSaved = jest.fn();
        render(<RadarAlertsCard alerts={alerts} onSaved={onSaved} />);
        fireEvent.change(screen.getByLabelText(/Pontuação mínima/), { target: { value: "300" } });
        expect(screen.getByTestId("radar-alerts-save")).toBeDisabled();
        fireEvent.change(screen.getByLabelText(/Pontuação mínima/), { target: { value: "75" } });
        fireEvent.click(screen.getByTestId("radar-alerts-save"));
        await waitFor(() => expect(updateAdminRadarAlerts).toHaveBeenCalledWith({ min_score: 75 }));
        await waitFor(() => expect(onSaved).toHaveBeenCalledWith(expect.objectContaining({ min_score: 75 })));
    });
});
