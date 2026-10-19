import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import ComprasSugeridasPage from "./ComprasSugeridasPage";

/** Compras sugeridas: o erro medido à vista, o aviso sem stock contado, editar a quantidade e copiar o texto. */
const mockGet = jest.fn();
jest.mock("helpers/laravel_helper", () => ({ getPurchaseSuggestions: (...a: any[]) => mockGet(...a) }));
jest.mock("contexts/WorkingCompanyContext", () => ({ useWorkingCompanyId: () => 5 }));
jest.mock("Components/Common/PageHeader", () => ({ __esModule: true, default: ({ title, filters }: any) => <div><h1>{title}</h1>{filters}</div> }));
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() }, ToastContainer: () => null }));

const payload = {
    today: "2026-10-15",
    suppliers: [{ key: "502000001", name: "Makro", next_delivery: "2026-10-20", usual_days: [2], interval_days: 7, total_cents: 1000, lines: [{
        product_id: "BATATA", code: "P1", description: "Batata", suggested: 2, unit: "Saco 5 kg", pack_factor: 5, pack_confirmed: true, weekly_median_base: 10, need_base: 10,
        stock_base: null, base_unit: "quilograma", last_purchase: { date: "2026-10-06", qty: 2, unit: "Saco 5 kg", document: "VFT 1" }, price_cents: 500, total_cents: 1000,
    }] }],
    forecast: { factor: 1, predicted: null, average: null, note: "Sem previsão para a semana seguinte: sem ajuste.", holidays: {} },
    stock: null,
    error: { pct: 18.5, weeks: [{ week: "2026-09-14", error_pct: 18.5, bought_cents: 1000 }] },
    financial: { visible: true, note: null },
};

beforeEach(() => mockGet.mockReset().mockResolvedValue({ data: payload }));

test("mostra o erro medido, o aviso e recalcula o total ao editar", async () => {
    render(<ComprasSugeridasPage />);
    expect(await screen.findByText("Makro")).toBeInTheDocument();
    expect(screen.getByTestId("suggestions-error")).toHaveTextContent("Erro medido: 19 % (últimas 4 semanas)");
    expect(screen.getByTestId("suggestions-no-stock")).toBeInTheDocument();
    expect(screen.getByText(/Próxima entrega provável/)).toHaveTextContent("terça-feira, 20/10");
    fireEvent.change(screen.getByLabelText("Quantidade de Batata"), { target: { value: "3" } });
    expect(screen.getAllByText(/15,00\s€/).length).toBeGreaterThan(0);
});

test("copiar texto para o fornecedor", async () => {
    const write = jest.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText: write } });
    render(<ComprasSugeridasPage />);
    fireEvent.click(await screen.findByTestId("supplier-copy"));
    await waitFor(() => expect(write).toHaveBeenCalledWith("Bom dia,\nPara a entrega de terça-feira, 20/10, pedimos:\n- 2 Saco 5 kg de Batata\nObrigado."));
});
