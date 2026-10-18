import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import TableTab from "./TableTab";
import { mi } from "./menuFormat.test";

const mockItems = jest.fn();
const mockDownload = jest.fn();
jest.mock("helpers/laravel_helper", () => ({
    getMenuEngineeringItems: (...a: unknown[]) => mockItems(...a),
    menuEngineeringCsvPath: (c: number, q: string) => `/companies/${c}/restaurant/menu-engineering/items?format=csv&${q}`,
}));
jest.mock("helpers/download_helper", () => ({ downloadGet: (...a: unknown[]) => mockDownload(...a) }));
jest.mock("Components/Common/Select", () => ({ __esModule: true, default: () => <div /> }));
jest.mock("Components/Common/DataTable", () => ({
    __esModule: true,
    useDataColumns: (_k: string, defs: any[]) => defs,
    default: ({ data, server, columns }: any) => (
        <div>
            {columns.map((c: any) => <button key={c.id} onClick={() => server.onSortChange({ id: c.id, key: c.sortKey, desc: true })}>{`ordenar ${c.id}`}</button>)}
            {data.map((r: any) => <div key={r.product_id} data-testid="row">{r.description} · {columns.find((c: any) => c.id === "cost").cell(r)}</div>)}
        </div>
    ),
}));

describe("TableTab", () => {
    beforeEach(() => { jest.useFakeTimers(); mockItems.mockReset(); mockDownload.mockReset(); });
    afterEach(() => jest.useRealTimers());

    it("pede a página ao servidor, ordena no servidor e exporta o CSV com os mesmos filtros", async () => {
        mockItems.mockResolvedValue({ data: { items: [mi(), mi({ product_id: "P2", description: "Coca Cola 0", confidence: "parcial", cost_eur: 0 })], total: 2 } });
        render(<TableTab companyId={5} params={{ mode: "ultimo" }} families={[]} hidden={false} onOpen={jest.fn()} refreshKey={0} />);
        await act(async () => { jest.advanceTimersByTime(250); });
        expect(mockItems).toHaveBeenCalledWith(5, expect.objectContaining({ mode: "ultimo", excluded: "0", page: 1, perPage: 50 }));
        expect((await screen.findAllByTestId("row"))[1]).toHaveTextContent("Coca Cola 0 · pelo menos 0,00 €");

        fireEvent.click(screen.getByText("ordenar margin"));
        await act(async () => { jest.advanceTimersByTime(250); });
        await waitFor(() => expect(mockItems).toHaveBeenLastCalledWith(5, expect.objectContaining({ sort: "margin", dir: "desc" })));

        fireEvent.click(screen.getByTitle("Exportar CSV"));
        expect(mockDownload).toHaveBeenCalledWith(expect.stringContaining("format=csv&mode=ultimo&excluded=0&sort=margin&dir=desc"), "engenharia-de-menu.csv");
    });

    it("sem as Finanças, não deixa ordenar pelos euros", async () => {
        mockItems.mockResolvedValue({ data: { items: [], total: 0 } });
        render(<TableTab companyId={5} params={{}} families={[]} hidden onOpen={jest.fn()} refreshKey={0} />);
        await act(async () => { jest.advanceTimersByTime(250); });
        fireEvent.click(screen.getByText("ordenar margin"));
        await act(async () => { jest.advanceTimersByTime(250); });
        expect(mockItems).toHaveBeenLastCalledWith(5, expect.objectContaining({ sort: undefined }));
    });
});
