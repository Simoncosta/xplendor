import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import GlobalUnitCard from "./GlobalUnitCard";

const mockApply = jest.fn();
jest.mock("helpers/laravel_helper", () => ({ applyGlobalUnitGuide: (...a: unknown[]) => mockApply(...a), getUnitGuideWrite: jest.fn() }));

const view = (over: any = {}) => ({
    unit: { id: "CX24", name: "Caixa 24 Unidades", now: "1 unidade", says: "24 unidades" },
    right: { name: "Caixa 24 un", unit_id: null, reuse: false, create: { description: "Caixa 24 un", parent_qnt: 24 }, status: null, write_id: null, error: null },
    articles: [
        { product_id: "1", label: "1 · Água", purchases_cents: 2400, impact_cents: 2300, lines: 1, uses: ["compras"], confirmed: false, done: false },
        { product_id: "2", label: "2 · Sumo", purchases_cents: 1200, impact_cents: 1150, lines: 1, uses: ["compras"], confirmed: false, done: true },
    ],
    impact_cents: 3450, guard: null, context_product_id: "1", financial: { visible: true }, ...over,
});

describe("GlobalUnitCard (U9)", () => {
    beforeEach(() => mockApply.mockReset());

    it("cria a unidade certa uma vez, com confirmação, e mostra quantos artigos já passaram", async () => {
        mockApply.mockResolvedValue({ data: { write: { id: 7, op: "create", status: "queued", note: null, error: null, progress: [], unit_id: null } } });
        render(<GlobalUnitCard companyId={5} view={view() as any} canEdit editReason={null} onChanged={jest.fn()} />);
        expect(screen.getByTestId("guide-global-unit")).toHaveTextContent("«Caixa 24 Unidades» conta 1 unidade em vez de 24 unidades");
        expect(screen.getByTestId("guide-global-unit")).toHaveTextContent("não se anula nem se altera");
        expect(screen.getByTestId("guide-global-progress")).toHaveTextContent("1 de 2");
        fireEvent.click(screen.getByTestId("guide-global-create"));
        fireEvent.click(await screen.findByTestId("guide-global-create-confirm"));
        await waitFor(() => expect(mockApply).toHaveBeenCalledWith(5, "CX24"));
    });

    it("reutiliza a unidade que já existe: não há nada a criar", () => {
        render(<GlobalUnitCard companyId={5} view={view({ right: { name: "Grade 24", unit_id: "GRD24", reuse: true, create: null, status: "ok", write_id: null, error: null } }) as any}
            canEdit editReason={null} onChanged={jest.fn()} />);
        expect(screen.getByTestId("guide-global-right")).toHaveTextContent("Já existe no PingWin: reutiliza-se");
        expect(screen.queryByTestId("guide-global-create")).toBeNull();
    });

    it("sem a prova ou fora da lista, o botão fica desativado com o porquê", () => {
        render(<GlobalUnitCard companyId={5} view={view({ guard: { mode: "por_provar", reason: "Disponível em breve." } }) as any} canEdit editReason={null} onChanged={jest.fn()} />);
        expect(screen.getByTestId("guide-global-create")).toBeDisabled();
    });
});
