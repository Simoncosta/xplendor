import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import SitePopupsPage from "./index";

jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn(), info: jest.fn() }, ToastContainer: () => null }));
jest.mock("contexts/WorkingCompanyContext", () => ({ useWorkingCompanyId: () => 4 }));
jest.mock("Components/Common/PageHeader", () => ({ __esModule: true, default: ({ title }: any) => <h1>{title}</h1> }));
jest.mock("pages/Files/FilePickerModal", () => ({ __esModule: true, default: () => null }));
const mockGet = jest.fn();
const mockCreate = jest.fn();
jest.mock("helpers/laravel_helper", () => ({
    getSitePopups: (...a: unknown[]) => mockGet(...a), createSitePopup: (...a: unknown[]) => mockCreate(...a),
    getSitePopup: jest.fn(() => Promise.resolve({ data: { stats: { series: [] } } })), updateSitePopup: jest.fn(), deleteSitePopup: jest.fn(),
}));

const popup = (over: any = {}) => ({
    id: 7, title: "Feira de outono", body: "Descontos.", image_file_id: null, image_path: null, image_url: null, button_label: "Ver", button_url: "/viaturas",
    starts_at: null, ends_at: null, pages: ["home", "vehicles"], delay_seconds: 5, frequency: "once_per_visitor", priority: 2, is_active: true, state: "ativo",
    overlaps: [8], stats: { days: 30, views: 40, clicks: 10, closes: 20, click_rate: 25 }, ...over,
});

describe("Avisos do site", () => {
    beforeEach(() => { mockGet.mockReset(); mockCreate.mockReset(); });

    it("lista os avisos com o estado, as páginas, os números e a sobreposição", async () => {
        mockGet.mockResolvedValue({ data: { popups: [popup(), popup({ id: 8, title: "Natal", state: "programado", overlaps: [7], pages: ["all"] })], can: { create: true, edit: true, delete: false } } });
        render(<SitePopupsPage />);
        const row = await screen.findByTestId("popup-7");
        expect(within(row).getByText("Ativo")).toBeInTheDocument();
        expect(within(row).getByText("Página inicial, Lista das viaturas")).toBeInTheDocument();
        expect(within(row).getByText(/40 vistas · 10 cliques · 20 fechos/)).toBeInTheDocument();
        expect(within(row).getByText(/Sobrepõe-se a Natal/)).toBeInTheDocument();
        expect(within(screen.getByTestId("popup-8")).getByText("Programado")).toBeInTheDocument();
        expect(screen.queryByRole("button", { name: /Apagar Feira/ })).not.toBeInTheDocument();
    });

    it("criar um aviso: sem a frequência sempre; a pré-visualização segue o texto", async () => {
        mockGet.mockResolvedValue({ data: { popups: [], can: { create: true, edit: true, delete: true } } });
        mockCreate.mockResolvedValue({ data: popup() });
        render(<SitePopupsPage />);
        await screen.findByTestId("popups-empty");
        fireEvent.click(screen.getByRole("button", { name: /Novo aviso/ }));
        const freq = (await screen.findByLabelText("Frequência")) as HTMLSelectElement;
        expect(Array.from(freq.options).map((o) => o.value)).toEqual(["once_per_visitor", "once_per_day", "once_per_session"]);
        fireEvent.change(screen.getByLabelText("Título"), { target: { value: "Feira de outono" } });
        expect(within(screen.getByTestId("popup-preview")).getByText("Feira de outono")).toBeInTheDocument();
        fireEvent.click(screen.getByLabelText("Página inicial"));
        fireEvent.click(screen.getByRole("button", { name: "Guardar" }));
        await waitFor(() => expect(mockCreate).toHaveBeenCalled());
        expect(mockCreate).toHaveBeenCalledWith(4, expect.objectContaining({ title: "Feira de outono", pages: ["home"], frequency: "once_per_visitor" }));
    });
});
