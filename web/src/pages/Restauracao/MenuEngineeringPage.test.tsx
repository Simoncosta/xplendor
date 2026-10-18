import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import MenuEngineeringPage, { periodRange } from "./MenuEngineeringPage";

const mockGet = jest.fn();
// O Jest deste projeto não resolve o react-router-dom: os parâmetros do URL ficam num estado local.
jest.mock("react-router-dom", () => {
    const React = jest.requireActual("react");
    return { useSearchParams: () => { const [p, setP] = React.useState(new URLSearchParams()); return [p, (next: Record<string, string>) => setP(new URLSearchParams(next))]; } };
}, { virtual: true });
jest.mock("helpers/laravel_helper", () => ({
    getMenuEngineering: (...a: unknown[]) => mockGet(...a), recalculateMenuEngineering: jest.fn(), updateMenuEngineeringSettings: jest.fn(),
}));
jest.mock("contexts/WorkingCompanyContext", () => ({ useWorkingCompanyId: () => 5 }));
jest.mock("Components/Common/PageHeader", () => ({ __esModule: true, default: () => null }));
jest.mock("Components/Common/PageCard", () => ({ __esModule: true, default: ({ children, status }: any) => <div>{status}{children}</div> }));
jest.mock("Components/Common/InfoTip", () => ({ __esModule: true, default: () => null }));
jest.mock("Components/Common/Select", () => ({ __esModule: true, default: () => <div /> }));
jest.mock("pages/Auditoria/AuditButton", () => ({ __esModule: true, default: () => null }));
jest.mock("./menu/MatrixTab", () => ({ __esModule: true, default: () => <div data-testid="matrix" /> }));
jest.mock("react-apexcharts", () => ({ __esModule: true, default: () => <div /> }));
jest.mock("./menu/TableTab", () => ({ __esModule: true, default: () => <div data-testid="table" /> }));
jest.mock("./menu/CoverageTab", () => ({ __esModule: true, default: () => <div /> }));
jest.mock("./menu/FoodCostTab", () => ({ __esModule: true, default: () => <div /> }));

const snapshot = {
    id: 1, calculated_at: "2026-10-18T04:30:00Z", period_from: "2026-07-20", period_to: "2026-10-17", location_id: null, price_mode: "media",
    period: { from: "2026-07-20", to: "2026-10-17", days: 90 },
    summary: {
        revenue_eur: 565842, articles: 265, with_bom: 142, included: 135, excluded: { preco_zero: 37 },
        coverage: { confirmado_pct: 20.5, parcial_pct: 50.1, sem_custo_pct: 29.4, with_cost_pct: 70.6 }, fichas_to_target: 3, coverage_target_pct: 80,
        food_cost_theoretical: { pct: 28.7, coverage_pct: 70.6 }, food_cost_purchases: { month: "2026-09", avg3_pct: 27.4, total_pct: 24.6 }, compare_allowed: false,
        classes: { estrela: 10, cavalo: 8, quebra_cabecas: 5, cao: 4 }, pareto: { articles: 228, top_articles: 15, top_articles_pct: 6.6, revenue_pct: 80 }, top5: [],
    },
    families: [], food_cost: { months: [], latest: null, families: {}, excluded_families: [] }, pareto: [], worklist: [],
};
const response = (mode: string) => ({ data: { locations: [], running: null, settings: { include_zero_price: false, include_low_volume: false, include_retired: false, include_uber_eats: false },
    exclusions: [], can_manage: true, labels: {}, targets: { food_cost_pct: 35, confirmed_pct: 80 }, snapshot: { ...snapshot, price_mode: mode },
    items: [{ product_id: "SSAL", description: "S/Sal", family: "Comidas › Acompanhamentos", qty: 40, excluded: "preco_zero", excluded_reason: "Preço 0 (modificador)" }], financial: { visible: true, note: null } } });

describe("MenuEngineeringPage", () => {
    beforeEach(() => { mockGet.mockReset(); window.matchMedia = jest.fn().mockImplementation(() => ({ matches: false, addEventListener: jest.fn(), removeEventListener: jest.fn() })); });

    it("mostra o topo e o interruptor muda o custo em todo o ecrã (média de 90 dias ↔ último custo)", async () => {
        mockGet.mockImplementation((_c: number, p: any) => Promise.resolve(response(p.mode)));
        render(<MenuEngineeringPage />);
        expect(await screen.findByTestId("menu-kpi-coverage")).toHaveTextContent("Custo confirmado20,5%");
        expect(screen.getByTestId("menu-kpi-purchases")).toHaveTextContent("27,4%");
        expect(screen.getByTestId("menu-kpi-purchases")).toHaveTextContent("Abaixo do alvo de 35%");
        expect(screen.getByTestId("menu-kpi-provisional")).toHaveTextContent("Provisório: inclui custos parciais");
        expect(screen.getByTestId("menu-kpi-mini-matrix")).toHaveTextContent("10Estrela");
        expect(screen.getByTestId("menu-excluded-alert")).toHaveTextContent("1 pratos ficam fora da matriz: preço 0 (37).");
        expect(screen.getByTestId("matrix")).toBeInTheDocument();
        expect(mockGet).toHaveBeenLastCalledWith(5, expect.objectContaining({ mode: "media" }));
        fireEvent.click(screen.getByText("Último custo"));
        await waitFor(() => expect(mockGet).toHaveBeenLastCalledWith(5, expect.objectContaining({ mode: "ultimo" })));
        fireEvent.click(screen.getByText("Tabela"));
        expect(await screen.findByTestId("table")).toBeInTheDocument();
    });

    it("sem cálculo, diz que está a calcular", async () => {
        mockGet.mockResolvedValue({ data: { ...response("media").data, snapshot: null, running: { status: "queued", at: "" } } });
        render(<MenuEngineeringPage />);
        expect(await screen.findByText(/A calcular a engenharia de menu/)).toBeInTheDocument();
    });

    it("o período: os últimos N dias até ontem", () => {
        expect(periodRange(30, new Date(2026, 9, 18))).toEqual({ from: "2026-09-18", to: "2026-10-17" });
        expect(periodRange(0)).toEqual({});
    });
});
