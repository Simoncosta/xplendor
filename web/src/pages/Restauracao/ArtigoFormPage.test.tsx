import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import ArtigoFormPage from "./ArtigoFormPage";

const mockCopy = jest.fn();
const mockGetArticle = jest.fn();
const mockRefresh = jest.fn();
const mockReadPoll = jest.fn();
let mockFinishRead: (r: any) => void = () => undefined;
const mockRead = jest.fn();

jest.mock("react-router-dom", () => ({
    useNavigate: () => jest.fn(),
    useParams: () => ({ pingwinId: "101208" }),
    useLocation: () => ({ search: "", state: null }),
    Link: ({ to, children, className, ...rest }: any) => <a href={to} className={className} data-testid={rest["data-testid"]}>{children}</a>,
}), { virtual: true });
jest.mock("helpers/laravel_helper", () => ({
    getArticleCopy: (...a: unknown[]) => mockCopy(...a), getArticle: (...a: unknown[]) => mockGetArticle(...a), refreshArticle: (...a: unknown[]) => mockRefresh(...a),
    getArticleRead: (...a: unknown[]) => mockReadPoll(...a), getArticleFormLookups: jest.fn(), createArticle: jest.fn(), getArticleCreation: jest.fn(),
    updateArticle: jest.fn(), getArticleEdition: jest.fn(), deleteArticle: jest.fn(), getArticleDeletion: jest.fn(), getPingwinArticleSupplierPrices: jest.fn(),
}));
jest.mock("./useArticleReadPolling", () => ({ useArticleReadPolling: () => ({ read: mockRead }) }));
jest.mock("./useArticleWritePolling", () => ({ useArticleWritePolling: () => ({ busy: false, submit: jest.fn() }) }));
jest.mock("./useSupplierPricesStaging", () => ({ useSupplierPricesStaging: () => ({ hasPending: false, setClean: jest.fn(), buildPackage: jest.fn() }) }));
jest.mock("./ArtigoComprasTab", () => ({ __esModule: true, default: () => <div /> }));
jest.mock("./fichas/FichaTecnicaResumo", () => ({ __esModule: true, default: () => <div /> }));
jest.mock("./stock/ArtigoStockTab", () => ({ __esModule: true, default: () => <div /> }));
jest.mock("./faturas/ArticleSupplierCodes", () => ({ __esModule: true, default: () => <div /> }));
jest.mock("./faturas/ArticleGroupingSwitch", () => ({ __esModule: true, default: () => <div /> }));
jest.mock("contexts/WorkingCompanyContext", () => ({ useWorkingCompanyId: () => 5 }));
jest.mock("Components/Common/PageHeader", () => ({ __esModule: true, default: () => null }));
jest.mock("Components/Common/InfoTip", () => ({ __esModule: true, default: () => null }));
jest.mock("Components/Common/ActionsMenu", () => ({ __esModule: true, default: () => null }));
jest.mock("helpers/swal", () => ({ confirmAction: jest.fn() }));
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() }, ToastContainer: () => null }));
// Um select simples: o nome visível é o da opção escolhida; desativado como o verdadeiro.
jest.mock("Components/Common/Select", () => ({
    __esModule: true,
    default: ({ options, value, disabled, onChange }: any) => (
        <select value={value} disabled={disabled} onChange={(e) => onChange(e.target.value)}>
            <option value="" />
            {options.map((o: any) => <option key={o.value} value={o.value}>{o.label}</option>)}
        </select>
    ),
}));

const copy = {
    catalog_item_id: 3, pingwin_id: "101208", code: "101208", description: "COMIDA STAFF 23%", family: "Família \\ Staff", product_status: "Ativo", is_active: true,
    saleprice_cents: null, purchaseprice_cents: 1590, forsale: false, forpurchase: true, read_at: "2026-10-19T04:00:00Z", live_read_at: null,
    units: {
        base: { label: "Unidade base", id: "11001", name: "Unidade (UN)" },
        purchase: { label: "Unidade de compra", id: "11001", name: "Unidade (UN)" },
        sale: { label: "Unidade de venda", id: null, name: "Unidade" },
        stock: { label: "Unidade de stock", id: null, name: null },
    },
    form: { family_id: "F12", status_id: null, product_type_id: null, product_type: null, taxgroup_id: "1003001", taxgroup: "Normal", stockconfig_id: "7501",
        forproduction: null, setexpireday: null, weight: null, label_unit: { id: null, name: null }, volume_unit: { id: null, name: null } },
    unit_changes: [],
};
const units = [{ id: "11001", description: "Unidade (UN)" }, { id: "11002", description: "Quilograma (KG)" }];
const liveRead = { status: "ready", data: { changes: [{ field: "purchase", label: "Unidade de compra", from: "Unidade (UN)", to: "Quilograma (KG)" }],
    copy: { ...copy, live_read_at: "2026-10-26T09:00:00Z", units: { ...copy.units, purchase: { label: "Unidade de compra", id: "11002", name: "Quilograma (KG)" } } },
    article: {
        maindataset: { code: "101208", description: "COMIDA STAFF 23%", status: "1", family_id: "F12", forsale: 0, forpurchase: 1, forproduction: 0,
            base_unit_id: "11002", default_purchase_unit_id: "11002", taxgroup_id: "1003001", stockconfig_id: "7501", product_type: "1" },
        saleprice_cents: null, purchaseprice_cents: 1590, catalog_item_id: 3, supplier_prices: [], supplier_tables: [],
        lookups: { families: [{ id: "F12", description: "Staff", complete_name: "Família \\ Staff", isleaf: 1, deleted: false }], lkstatus: [{ id: "1", description: "Ativo" }],
            product_type: [{ id: "1", description: "Normal" }], lktaxgroup: [{ id: "1003001", description: "Normal" }], lk_stockconfig: [{ id: "7501", description: "Com stock" }],
            units: { base: units, sale: units, purchase: units } },
    },
} };
const description = () => screen.getByDisplayValue("COMIDA STAFF 23%");
const labelOf = (text: string) => screen.getByText(text, { selector: "label" });

describe("ArtigoFormPage (o artigo abre logo com a cópia)", () => {
    beforeEach(() => {
        [mockCopy, mockGetArticle, mockRefresh, mockReadPoll, mockRead].forEach((m) => m.mockReset());
        mockCopy.mockResolvedValue({ data: copy });
        // A leitura ao vivo fica pendente até o teste a terminar (o worker do PingWin simulado).
        mockRead.mockImplementation((start: () => unknown) => {
            start();
            return new Promise((resolve) => { mockFinishRead = resolve; });
        });
    });

    it("abre logo o formulário completo com a cópia, desativado, com «Dados de dd/mm às hh:mm, a atualizar…»", async () => {
        render(<ArtigoFormPage />);
        expect(await screen.findByDisplayValue("COMIDA STAFF 23%")).toBeDisabled();
        expect(screen.getByTestId("article-stale")).toHaveTextContent("Dados de 19/10 às 05:00, a atualizar…");
        // Os valores da cópia nos campos (a unidade de compra e o preço), todos desativados.
        expect(screen.getByDisplayValue("15,90")).toBeDisabled();
        // A unidade base e a de compra (as duas «Unidade (UN)» na cópia), desativadas.
        const copyUnits = screen.getAllByDisplayValue("Unidade (UN)");
        expect(copyUnits).toHaveLength(2);
        copyUnits.forEach((el) => expect(el).toBeDisabled());
        expect(screen.getByTestId("article-save")).toBeDisabled();
        // Sem ecrã intermédio: é o formulário (o mesmo pedido de leitura de sempre).
        expect(screen.queryByTestId("article-copy")).toBeNull();
        expect(mockGetArticle).toHaveBeenCalledWith(5, "101208");
        expect(mockRefresh).not.toHaveBeenCalled();
    });

    it("com a leitura do PingWin, os valores atualizam-se no mesmo formulário, ativam-se e os que mudaram ficam assinalados", async () => {
        render(<ArtigoFormPage />);
        await screen.findByDisplayValue("COMIDA STAFF 23%");
        await act(async () => { mockFinishRead(liveRead); });
        await waitFor(() => expect(description()).toBeEnabled());
        expect(screen.getByTestId("article-save")).toBeEnabled();
        const liveUnits = screen.getAllByDisplayValue("Quilograma (KG)");
        expect(liveUnits).toHaveLength(2);
        liveUnits.forEach((el) => expect(el).toBeEnabled());
        // Os campos que mudaram face à cópia: a unidade base e a de compra; o resto não.
        expect(within(labelOf("Unidade de compra")).getByTestId("field-changed")).toHaveTextContent("mudou");
        expect(within(labelOf("Unidade base")).getByTestId("field-changed")).toBeInTheDocument();
        expect(within(labelOf("Descrição *")).queryByTestId("field-changed")).toBeNull();
        expect(screen.getAllByTestId("field-changed")).toHaveLength(2);
        // O resumo do que mudou fica.
        expect(screen.getByTestId("article-changes")).toHaveTextContent("Unidade de compra: Unidade (UN) → Quilograma (KG)");
        expect(screen.getByTestId("article-read-at")).toHaveTextContent("Lido do PingWin a 26/10 às 09:00");
    });

    it("sem resposta do PingWin, continua desativado com o aviso e «Tentar de novo»", async () => {
        render(<ArtigoFormPage />);
        await screen.findByDisplayValue("COMIDA STAFF 23%");
        await act(async () => { mockFinishRead({ status: "error", error_message: "Não foi possível ligar ao PingWin neste momento." }); });
        const alert = await screen.findByTestId("article-copy-error");
        expect(alert).toHaveTextContent("Não foi possível ligar ao PingWin neste momento. Estes são os dados guardados de 19/10 às 05:00. Só se pode editar depois de uma leitura nova do PingWin.");
        expect(description()).toBeDisabled();
        expect(screen.getByTestId("article-save")).toBeDisabled();
        expect(screen.getByTestId("article-stale")).toHaveTextContent("Dados de 19/10 às 05:00");
        // "Tentar de novo": a releitura de sempre (com o limite de pedidos), e o formulário ativa-se quando chega.
        fireEvent.click(within(alert).getByRole("button", { name: "Tentar de novo" }));
        await waitFor(() => expect(mockRefresh).toHaveBeenCalledWith(5, "101208"));
        await act(async () => { mockFinishRead(liveRead); });
        await waitFor(() => expect(description()).toBeEnabled());
    });

    it("sem cópia, mostra o formulário vazio e desativado com «A ler do PingWin…»", async () => {
        mockCopy.mockRejectedValue({ __status: 404, message: "Artigo não encontrado." });
        render(<ArtigoFormPage />);
        expect(await screen.findByTestId("article-stale")).toHaveTextContent("A ler do PingWin…");
        const desc = screen.getByPlaceholderText("Nome do artigo");
        expect(desc).toHaveValue("");
        expect(desc).toBeDisabled();
        expect(screen.getByTestId("article-save")).toBeDisabled();
    });

    it("liga ao artigo no Guião de unidades, com o caminho de volta, também a partir do aviso de mudança de unidade", async () => {
        const warning = "A unidade de compra mudou de Unidade (UN) para Quilograma (KG) a 26/10/2026: as 19 linhas de compra anteriores estão em Unidade (UN).";
        mockCopy.mockResolvedValue({ data: { ...copy, unit_changes: [{ field: "purchase", from: "Unidade (UN)", to: "Quilograma (KG)", changed_at: "2026-10-26T01:40:00Z", history_lines: 19, warning }] } });
        render(<ArtigoFormPage />);
        const link = "/restauracao/auditoria/guiao?artigo=101208&voltar=%2Fapp%2Fcadastros%2Fartigos%2F101208";
        expect(await screen.findByTestId("article-unit-changes")).toHaveTextContent(warning);
        expect(screen.getByTestId("unit-changes-guide")).toHaveAttribute("href", link);
        expect(screen.getByTestId("article-guide-link")).toHaveAttribute("href", link);
    });
});
