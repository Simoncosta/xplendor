import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import HabituaisExcluidosPage from "./HabituaisExcluidosPage";

/** Finanças › Configuração › Entidades excluídas dos habituais: as ativas com "Repor" e o histórico. */
const mockApi = { list: jest.fn(), restore: jest.fn() };
jest.mock("helpers/laravel_helper", () => ({
    getHabitualExclusions: (...a: unknown[]) => mockApi.list(...a),
    restoreHabitual: (...a: unknown[]) => mockApi.restore(...a),
}));
jest.mock("contexts/WorkingCompanyContext", () => ({ useWorkingCompanyId: () => 5 }));
jest.mock("Components/Common/PageHeader", () => ({ __esModule: true, default: ({ title }: any) => <h1>{title}</h1> }));
jest.mock("Components/Common/InfoTip", () => ({ __esModule: true, default: () => null }));
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() } }));

const rows = [
    { id: 2, entity_pingwin_id: "E-W", supplier: "WELCOME", scope: "mes", month: "2026-09", avg_cents: 3500000, reason: "Pagamento pontual", by: "Ana", at: "2026-10-19T10:00:00+01:00", active: true, removed_at: null, removed_by: null },
    { id: 1, entity_pingwin_id: "E-F", supplier: "FOLHA", scope: "sempre", month: "2026-08", avg_cents: 100000, reason: null, by: "Ana", at: "2026-10-18T10:00:00+01:00", active: false, removed_at: "2026-10-19T09:00:00+01:00", removed_by: "Rui" },
];

beforeEach(() => {
    mockApi.list.mockReset().mockResolvedValue({ data: { exclusions: rows } });
    mockApi.restore.mockReset().mockResolvedValue({ message: "WELCOME volta a contar como habitual." });
});

test("mostra as excluídas e o histórico; Repor volta a pôr a entidade nos habituais", async () => {
    render(<HabituaisExcluidosPage />);
    const row = await screen.findByTestId("exclusion-2");
    expect(row).toHaveTextContent("WELCOME");
    expect(row).toHaveTextContent("Não vem em setembro de 2026");
    expect(row).toHaveTextContent("Pagamento pontual");
    expect(row).toHaveTextContent(/35\s?000,00\s€/);
    expect(screen.getByTestId("exclusions-history-table")).toHaveTextContent("Não é habitual desde agosto de 2026");
    expect(screen.getByTestId("exclusions-history-table")).toHaveTextContent("Rui");
    fireEvent.click(within(row).getByText("Repor"));
    await waitFor(() => expect(mockApi.restore).toHaveBeenCalledWith(5, 2));
    await waitFor(() => expect(mockApi.list).toHaveBeenCalledTimes(2));
});
