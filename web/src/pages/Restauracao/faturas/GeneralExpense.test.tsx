import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import GeneralExpenseCard from "./GeneralExpenseCard";
import GeneralExpenseModal from "./GeneralExpenseModal";
import { OcrGeneralExpense } from "common/models/ocr.model";

/**
 * Faturas de despesas gerais: o aviso na fatura ("Despesa geral: N linhas, 1 por taxa", a taxa sem
 * artigo, o "Aplicar a esta fatura" e o atalho do root) e a definição nos Fornecedores (um artigo por
 * taxa, escolhido entre os que já existem, sem "Criar artigo").
 */
const mockApi = { get: jest.fn(), set: jest.fn(), search: jest.fn() };
jest.mock("helpers/laravel_helper", () => ({
    getSupplierGeneralExpense: (...a: unknown[]) => mockApi.get(...a),
    setSupplierGeneralExpense: (...a: unknown[]) => mockApi.set(...a),
    searchOcrArticles: (...a: unknown[]) => mockApi.search(...a),
}));
jest.mock("react-router-dom", () => ({ Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a> }), { virtual: true });
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() } }));

beforeEach(() => Object.values(mockApi).forEach((f) => f.mockReset()));

const ctt: OcrGeneralExpense = {
    supplier_id: 9, lines: 5, ignored_zero: 2, missing_rates: [],
    rates: [{ rate: 0, lines: 5, total_cents: 1210, article: { id: 7, code: "DG0", description: "DESPESAS GERAIS ISENTO" } }],
};

describe("na fatura", () => {
    test("mostra uma linha por taxa, as de 0,00 € que não contam, e aplica à fatura", () => {
        const onApply = jest.fn();
        render(<GeneralExpenseCard expense={ctt} supplierId={9} isRoot={false} canApply applying={false} onApply={onApply} />);
        expect(screen.getByTestId("general-expense-title")).toHaveTextContent("Despesa geral: 5 linhas, 1 por taxa");
        expect(screen.getByTestId("general-expense")).toHaveTextContent("isenta: 5 →");
        expect(screen.getByTestId("general-expense")).toHaveTextContent("2 a 0,00 € não contam.");
        expect(screen.queryByTestId("general-expense-shortcut")).not.toBeInTheDocument(); // só o root
        fireEvent.click(screen.getByTestId("general-expense-apply"));
        expect(onApply).toHaveBeenCalled();
    });

    test("a taxa sem artigo aparece no aviso; o root tem o atalho para os Fornecedores", () => {
        const missing = { ...ctt, missing_rates: [23], rates: [...ctt.rates, { rate: 23, lines: 1, total_cents: 200, article: null }] };
        render(<GeneralExpenseCard expense={missing} supplierId={9} isRoot canApply={false} applying={false} onApply={jest.fn()} />);
        expect(screen.getByTestId("general-expense-missing")).toHaveTextContent("Falta o artigo de despesa da taxa 23 %: essas linhas ficam por ligar.");
        expect(screen.getByTestId("general-expense-shortcut")).toHaveAttribute("href", "/restauracao/fornecedores?despesas=9");
        expect(screen.queryByTestId("general-expense-apply")).not.toBeInTheDocument(); // já lançada
    });

    test("fornecedor sem marca: nada para quem não é root, só o atalho para o root", () => {
        const { container, rerender } = render(<GeneralExpenseCard expense={null} supplierId={9} isRoot={false} canApply={false} applying={false} onApply={jest.fn()} />);
        expect(container).toBeEmptyDOMElement();
        rerender(<GeneralExpenseCard expense={null} supplierId={9} isRoot canApply={false} applying={false} onApply={jest.fn()} />);
        expect(screen.getByTestId("general-expense-shortcut")).toHaveTextContent("Marcar o fornecedor como despesas gerais");
    });
});

describe("nos Fornecedores (root)", () => {
    test("marca o fornecedor com o artigo de uma taxa, escolhido entre os que existem", async () => {
        mockApi.get.mockResolvedValue({ data: { supplier_id: 9, enabled: false, articles: { 23: null, 13: null, 6: null, 0: null }, rates: [23, 13, 6, 0], changed_at: null } });
        mockApi.search.mockResolvedValue({ data: { articles: [{ id: 7, code: "DG0", description: "DESPESAS GERAIS ISENTO", unit: null, family: null, last_purchase: null,
            bought_from_supplier: false, supplier_codes: [], approximate: false }] } });
        mockApi.set.mockResolvedValue({ message: "Fornecedor marcado como despesas gerais (sem stock).", data: {} });
        const onClose = jest.fn();
        render(<GeneralExpenseModal companyId={5} supplier={{ id: 9, name: "CORREIOS EXEMPLO" }} onClose={onClose} />);

        fireEvent.click(await screen.findByTestId("general-expense-enabled"));
        expect(screen.getByTestId("general-expense-save")).toBeDisabled(); // sem nenhum artigo
        fireEvent.click(within(screen.getByTestId("general-expense-rate-0")).getByText("Escolher"));
        fireEvent.click(await screen.findByText("DESPESAS GERAIS ISENTO", {}, { timeout: 2000 }));
        expect(screen.queryByText("Criar artigo")).not.toBeInTheDocument(); // não cria artigos no PingWin
        expect(screen.getByTestId("general-expense-rate-0")).toHaveTextContent("DESPESAS GERAIS ISENTO");
        expect(screen.getByTestId("general-expense-rate-23")).toHaveTextContent("Sem artigo: as linhas desta taxa ficam por ligar.");

        fireEvent.click(screen.getByTestId("general-expense-save"));
        await waitFor(() => expect(mockApi.set).toHaveBeenCalledWith(5, 9, { enabled: true, articles: { 23: null, 13: null, 6: null, 0: 7 } }));
        expect(onClose).toHaveBeenCalled();
    });

    test("desmarcar envia só enabled: false", async () => {
        mockApi.get.mockResolvedValue({ data: { supplier_id: 9, enabled: true, articles: { 23: null, 13: null, 6: null, 0: { id: 7, code: "DG0", description: "DESPESAS GERAIS ISENTO" } },
            rates: [23, 13, 6, 0], changed_at: "2026-10-28T10:00:00+00:00" } });
        mockApi.set.mockResolvedValue({ message: "ok", data: {} });
        render(<GeneralExpenseModal companyId={5} supplier={{ id: 9, name: "CORREIOS EXEMPLO" }} onClose={jest.fn()} />);
        fireEvent.click(await screen.findByTestId("general-expense-enabled"));
        fireEvent.click(screen.getByTestId("general-expense-save"));
        await waitFor(() => expect(mockApi.set).toHaveBeenCalledWith(5, 9, { enabled: false }));
    });
});
