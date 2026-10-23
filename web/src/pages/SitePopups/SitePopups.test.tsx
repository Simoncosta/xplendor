import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import SitePopupsPage from "./index";

jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn(), info: jest.fn() }, ToastContainer: () => null }));
jest.mock("contexts/WorkingCompanyContext", () => ({ useWorkingCompanyId: () => 4 }));
jest.mock("Components/Common/PageHeader", () => ({ __esModule: true, default: ({ title }: any) => <h1>{title}</h1> }));
// O seletor devolve logo uma imagem; o recortador simulado comunica as medidas e um recorte 4:3.
jest.mock("pages/Files/FilePickerModal", () => ({
    __esModule: true,
    default: ({ isOpen, onPick }: any) => (isOpen ? <button onClick={() => onPick({ id: 55, kind: "imagem", mime: "image/jpeg", url: "/f/55.jpg", thumb_url: "/f/55-t.jpg" })}>escolher-55</button> : null),
}));
jest.mock("react-easy-crop", () => ({
    __esModule: true,
    default: ({ onMediaLoaded, onCropComplete }: any) => (
        <div>
            <button onClick={() => onMediaLoaded({ naturalWidth: 1600, naturalHeight: 1200 })}>carregar</button>
            <button onClick={() => onCropComplete({ x: 10, y: 5, width: 50, height: 50 }, { x: 160, y: 60, width: 800, height: 600 })}>recortar</button>
        </div>
    ),
}));
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

    it("imagem: a medida recomendada, o recorte 4:3 com a faixa do telemóvel, os píxeis e o envio do recorte", async () => {
        mockGet.mockResolvedValue({ data: { popups: [], can: { create: true, edit: true, delete: true } } });
        mockCreate.mockResolvedValue({ data: popup() });
        render(<SitePopupsPage />);
        await screen.findByTestId("popups-empty");
        fireEvent.click(screen.getByRole("button", { name: /Novo aviso/ }));
        expect(await screen.findByTestId("popup-recommended")).toHaveTextContent("Recomendado: 960 × 720 px (4:3), até 200 KB.");
        fireEvent.click(screen.getByRole("button", { name: "Escolher nos Ficheiros" }));
        fireEvent.click(screen.getByText("escolher-55"));
        expect(screen.getByTestId("popup-cropper")).toBeInTheDocument();
        expect(screen.getByTestId("popup-safe-overlay")).toBeInTheDocument();
        fireEvent.click(screen.getByText("carregar"));
        fireEvent.click(screen.getByText("recortar"));
        expect(screen.getByTestId("popup-pixels")).toHaveTextContent("Imagem original: 1600 × 1200 px · recorte: 800 × 600 px");
        expect(screen.getByTestId("popup-pixels-warning")).toHaveTextContent("menos de 960 × 720 px");
        expect(screen.getByTestId("preview-desktop")).toBeInTheDocument();
        expect(screen.getByTestId("preview-mobile-375")).toBeInTheDocument();
        expect(screen.getByTestId("preview-mobile-430")).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText("Título"), { target: { value: "Com imagem" } });
        fireEvent.click(screen.getByRole("button", { name: "Guardar" }));
        await waitFor(() => expect(mockCreate).toHaveBeenCalled());
        expect(mockCreate).toHaveBeenCalledWith(4, expect.objectContaining({ image_file_id: 55, image_crop: { x: 0.1, y: 0.05, width: 0.5, height: 0.5 } }));
    });
});
