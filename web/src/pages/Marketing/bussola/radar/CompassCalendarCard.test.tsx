import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import CompassCalendarCard from "./CompassCalendarCard";
import { getCompassCalendar, reactCompassCalendar, reactRadar } from "helpers/laravel_helper";
import type { CompassCalendarData } from "common/models/radar.model";

jest.mock("react-router-dom", () => ({ Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a> }), { virtual: true });
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() }, ToastContainer: () => null }));
jest.mock("contexts/ModulesContext", () => ({
    useCan: () => [true, null],
    useAccess: () => ({ modules: ["linha_editorial"], isRoot: false }),
}));
jest.mock("helpers/laravel_helper", () => ({
    getCompassCalendar: jest.fn(), reactCompassCalendar: jest.fn(() => Promise.resolve({ message: "Ideia criada na Linha Editorial, com o gancho e as fontes, sem texto." })),
    reactRadar: jest.fn(), getRadar: jest.fn(), ignoreRadar: jest.fn(), muteRadar: jest.fn(), unmuteRadar: jest.fn(), updateRadarSettings: jest.fn(),
}));

const today = new Date().toLocaleDateString("sv-SE", { timeZone: "Europe/Lisbon" });
const data = (over: Partial<CompassCalendarData> = {}): CompassCalendarData => ({
    calendar: [{
        key: "local.empresa.feriado_municipal", occurrence: today, title: "Feriado municipal do concelho", starts_on: today, ends_on: today,
        note: "O dia que a empresa indicou em Configurações › Calendário.", source: { name: "Configurações da empresa (Feriado municipal)", url: null, format: "definição" },
        confirmed_on: null, confirmed_by: "empresa", when: { date: today, is_today: true, label: "Publicar hoje", target: null }, reacted: false,
    }],
    can_act: true, can_act_reason: null, formats: ["Imagem única", "Carrossel"], ...over,
});

describe("Bússola 5: o \"Vem aí\" sem o módulo radar", () => {
    beforeEach(() => {
        (getCompassCalendar as jest.Mock).mockReset().mockResolvedValue({ data: data() });
        (reactCompassCalendar as jest.Mock).mockClear();
    });

    it("mostra o feriado municipal da empresa, com a fonte sem ligação nem data de confirmação", async () => {
        render(<CompassCalendarCard companyId={7} />);
        expect(await screen.findByText("Feriado municipal do concelho")).toBeInTheDocument();
        const source = screen.getByText("Fonte: Configurações da empresa (Feriado municipal)");
        expect(source.closest("a")).toBeNull();
        expect(screen.queryByText(/confirmada a/)).toBeNull();
    });

    it("\"Reagir\" usa a rota do calendário, não a do Radar", async () => {
        render(<CompassCalendarCard companyId={7} />);
        fireEvent.click(await screen.findByRole("button", { name: "Reagir" }));
        fireEvent.click(await screen.findByTestId("radar-react-submit"));
        await waitFor(() => expect(reactCompassCalendar).toHaveBeenCalledWith(7, expect.objectContaining({ calendar_key: "local.empresa.feriado_municipal", occurrence: today })));
        expect(reactRadar).not.toHaveBeenCalled();
    });

    it("sem datas, diz que não há", async () => {
        (getCompassCalendar as jest.Mock).mockResolvedValue({ data: data({ calendar: [] }) });
        render(<CompassCalendarCard companyId={7} />);
        expect(await screen.findByTestId("radar-upcoming-empty")).toBeInTheDocument();
    });
});
