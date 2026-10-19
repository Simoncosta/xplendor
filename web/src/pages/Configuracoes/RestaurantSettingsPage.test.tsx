import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import RestaurantSettingsPage from "./RestaurantSettingsPage";

/** Configurações › Restauração e Auditoria: secções com InfoTip, guardar só o que muda e a autorização das anulações. */
const mockGet = jest.fn();
const mockPut = jest.fn();
jest.mock("helpers/laravel_helper", () => ({
    getRestaurantSettings: (...a: any[]) => mockGet(...a),
    updateRestaurantSettings: (...a: any[]) => mockPut(...a),
}));
jest.mock("contexts/WorkingCompanyContext", () => ({ useWorkingCompanyId: () => 5 }));
jest.mock("Components/Common/PageHeader", () => ({ __esModule: true, default: ({ title }: any) => <h1>{title}</h1> }));
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() }, ToastContainer: () => null }));

const item = (key: string, section: string, type: string, label: string, value: any, extra: any = {}) => ({
    key, section, type, label, info: `Sobre ${label}`, min: 0, max: 100, owner: false, consent: null, value, default: value, source: "defeito", ...extra,
});
const payload = {
    sections: { menu: "Engenharia de menu", auditoria: "Auditoria" },
    settings: [
        item("menu.food_cost_target_pct", "menu", "pct", "Food cost alvo (geral)", 35),
        item("audit.voids_by_employee", "auditoria", "bool", "Anulações por funcionário", false,
            { owner: true, consent: "Ao ligar, o dono autoriza mostrar padrões por funcionário ao administrador." }),
    ],
    stores: [], history: [], owner_note: "A XPLENDOR não tem um perfil de dono.", can_voids: true,
};

beforeEach(() => {
    mockGet.mockReset().mockResolvedValue({ data: payload });
    mockPut.mockReset().mockResolvedValue({ data: payload, message: "Configurações guardadas." });
});

test("mostra as secções e guarda só o que mudou", async () => {
    render(<RestaurantSettingsPage />);
    expect(await screen.findByText("Engenharia de menu")).toBeInTheDocument();
    expect(screen.getByLabelText("Sobre: Food cost alvo (geral)")).toBeInTheDocument();
    fireEvent.change(screen.getByRole("spinbutton"), { target: { value: "32" } });
    fireEvent.click(screen.getByTestId("settings-save"));
    await waitFor(() => expect(mockPut).toHaveBeenCalledWith(5, { "menu.food_cost_target_pct": 32 }, false));
});

test("ligar as anulações por funcionário pede a autorização do dono", async () => {
    render(<RestaurantSettingsPage />);
    await screen.findByText("Auditoria");
    fireEvent.click(screen.getByRole("switch"));
    fireEvent.click(screen.getByTestId("settings-save"));
    expect(await screen.findByTestId("settings-consent")).toHaveTextContent("Ao ligar, o dono autoriza mostrar padrões por funcionário ao administrador.");
    expect(mockPut).not.toHaveBeenCalled();
    fireEvent.click(screen.getByTestId("settings-consent-confirm"));
    await waitFor(() => expect(mockPut).toHaveBeenCalledWith(5, { "audit.voids_by_employee": true }, true));
});
