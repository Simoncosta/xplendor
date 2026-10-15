import { act, fireEvent, render, screen } from "@testing-library/react";
import CountLineEditor from "./CountLineEditor";
import { CountLine } from "common/models/stockcount.model";

// O componente não fala com a API (a gravação é o onSave); o módulo da API não entra no teste.
jest.mock("helpers/laravel_helper", () => ({}));

const line = (over: Partial<CountLine> = {}): CountLine => ({
    id: 7, product_id: "584955579139631978", code: "100415", description: "COCA COLA ZERO 0.35", abc: "A", zone_id: null, unit: "Unidade",
    units: [{ unit: "Unidade", factor: 1, source: "stock" }, { unit: "Grade 24", factor: 24, source: "confirmado" }],
    entries: [], qty_base: null, counted_at: null, recount_requested: false, theoretical: null, confirm_status: null, confirmed_qty: null, ...over,
});

describe("CountLineEditor", () => {
    beforeEach(() => jest.useFakeTimers());
    afterEach(() => jest.useRealTimers());

    it("soma várias unidades, mostra o total e grava sozinho depois de parar de escrever", async () => {
        const onSave = jest.fn().mockResolvedValue({ qty_base: 53 });
        render(<CountLineEditor line={line()} onSave={onSave} debounceMs={500} />);
        fireEvent.change(screen.getByLabelText("Quantidade de COCA COLA ZERO 0.35"), { target: { value: "5" } });
        fireEvent.click(screen.getByTestId("count-add-unit"));
        const second = screen.getByLabelText("Quantidade de COCA COLA ZERO 0.35 (2)");
        fireEvent.change(second, { target: { value: "2" } });
        expect(screen.getByTestId("count-total")).toHaveTextContent("= 53 unidades");
        expect(onSave).not.toHaveBeenCalled();
        await act(async () => { jest.advanceTimersByTime(600); });
        expect(onSave).toHaveBeenCalledTimes(1);
        expect(onSave).toHaveBeenCalledWith([{ qty: "5", unit: "Unidade" }, { qty: "2", unit: "Grade 24" }]);
        expect(await screen.findByTestId("count-saved")).toHaveTextContent("Gravado");
    });

    it("o botão 0 grava zero contado (não é o mesmo que vazio)", async () => {
        const onSave = jest.fn().mockResolvedValue({ qty_base: 0 });
        render(<CountLineEditor line={line()} onSave={onSave} debounceMs={100} />);
        fireEvent.click(screen.getByTestId("count-zero"));
        await act(async () => { jest.advanceTimersByTime(200); });
        expect(onSave).toHaveBeenCalledWith([{ qty: "0", unit: "Unidade" }]);
    });

    it("contagem cega: sem teórico, não aparece; com teórico (gestor, não cega), aparece", () => {
        const { unmount } = render(<CountLineEditor line={line()} onSave={jest.fn()} />);
        expect(screen.queryByTestId("count-theoretical")).toBeNull();
        unmount();
        render(<CountLineEditor line={line({ theoretical: 1896 })} onSave={jest.fn()} />);
        expect(screen.getByTestId("count-theoretical")).toHaveTextContent("Teórico: 1 896");
    });
});
