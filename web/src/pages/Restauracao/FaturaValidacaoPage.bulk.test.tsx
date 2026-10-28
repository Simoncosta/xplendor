import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import FaturaValidacaoPage from "./FaturaValidacaoPage";
import LineArticleCell from "./faturas/LineArticleCell";

/**
 * Parte 8 · B1 e B2 no ecrã da fatura: as caixas de seleção nas linhas, "Ligar selecionadas a…"
 * (POST .../lines/article-bulk, em JSON), "Aplicar às linhas com a mesma descrição" e a etiqueta
 * "Ligada pela memória", que se pode desfazer.
 */
const mockApi = { get: jest.fn(), launchGet: jest.fn(), bulk: jest.fn() };
jest.mock("react-router-dom", () => ({
    useNavigate: () => jest.fn(),
    useParams: () => ({ id: "7" }),
    Link: ({ to, children, className }: any) => <a href={to} className={className}>{children}</a>,
}), { virtual: true });
jest.mock("helpers/laravel_helper", () => ({
    getOcrInvoice: (...a: unknown[]) => mockApi.get(...a),
    getOcrLaunch: (...a: unknown[]) => mockApi.launchGet(...a),
    bulkAssociateOcrLines: (...a: unknown[]) => mockApi.bulk(...a),
    getOcrInvoiceImageBlob: () => Promise.reject(new Error("sem imagem")),
    updateOcrInvoice: jest.fn(), revalidateOcrInvoice: jest.fn(), reprocessOcrInvoice: jest.fn(), getOcrManualChanges: jest.fn(), launchOcrInvoice: jest.fn(),
    closeOcrLaunch: jest.fn(), setOcrPaperCheck: jest.fn(), refreshArticle: jest.fn(), getArticleRead: jest.fn(), voidOcrLaunch: jest.fn(), setOcrLineLaunchUnit: jest.fn(),
    searchOcrPingwinLink: jest.fn(), confirmOcrPingwinLink: jest.fn(), unlinkOcrPingwinLink: jest.fn(), associateOcrLineArticle: jest.fn(), unlinkOcrLineArticle: jest.fn(),
    createOcrLineArticle: jest.fn(), acceptOcrLineSuggestions: jest.fn(), deleteOcrInvoice: jest.fn(), makeOcrLineCodePrincipal: jest.fn(), correctOcrLineArticleVat: jest.fn(),
    applyOcrGeneralExpense: jest.fn(),
}));
jest.mock("contexts/WorkingCompanyContext", () => ({ useWorkingCompanyId: () => 5 }));
jest.mock("Components/Common/PageHeader", () => ({ __esModule: true, default: () => null }));
jest.mock("Components/Common/InfoTip", () => ({ __esModule: true, default: () => null }));
jest.mock("react-select", () => ({ __esModule: true, default: () => null }));
jest.mock("Components/Common/Select", () => ({ __esModule: true, default: ({ ariaLabel }: any) => <span aria-label={ariaLabel} /> }));
// A célula do artigo, simplificada: o artigo ligado e o "Aplicar às linhas com a mesma descrição".
jest.mock("./faturas/LineArticleCell", () => ({ __esModule: true, default: ({ link, onSameDescription }: any) => (
    <span>{link?.article?.description}{link?.article && <button type="button" onClick={onSameDescription}>Mesma descrição</button>}</span>
) }));
// A pesquisa de artigos, simplificada: o título e um artigo para escolher.
jest.mock("./faturas/ArticlePicker", () => ({ __esModule: true, default: ({ isOpen, title, lineLabel, onPick }: any) => (isOpen ? (
    <div data-testid="picker">{title} · {lineLabel}<button type="button" onClick={() => onPick({ id: 99 })}>ENVELOPES</button></div>
) : null) }));
jest.mock("./faturas/CreateArticleModal", () => ({ __esModule: true, default: () => null }));
jest.mock("./faturas/GuidesCard", () => ({ __esModule: true, default: () => null }));
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn(), info: jest.fn(), warning: jest.fn() }, ToastContainer: () => null }));

const line = (o: any) => ({ supplier_code: null, unit: "UN", quantity: 1, unit_price: 1, line_total: 1, net_total: 1, discount_pct: 0, vat_rate: 23, vat_code: "1",
    promo_code: null, discount: 0, discount_code: null, discount_label: null, article: null, link_state: "por_ligar", link_method: null, link_confidence: null,
    suggestions: [], creating: null, supplier_code_status: null, supplier_code_error: null, ...o });

const invoice = () => ({
    id: 7, status: "por_validar", confidence: 90, model: "m", prompt_version: "v", synced_to_pingwin: false, error_message: null, supplier_id: null,
    supplier_name: "PAPELARIA EXEMPLO", supplier_nif: "123456783", number: "FT 1", issue_date: "2026-10-07", lines_total: 3, buyer_nif: "514148497", atcud: null,
    doc_type: "FT", qr_ok: true, source: "qr", lines_source: "texto", pages: 1, attempts: 1, tokens_in: 1, tokens_out: 1, cost_usd: 0, duration_ms: 1,
    check_status: "confere", check_diff: [],
    lines: [
        line({ id: 21, item: "Envelope C5 branco", article: { id: 99, code: "E1", description: "ENVELOPES" }, link_state: "ligada", link_method: "manual" }),
        line({ id: 22, item: "ENVELOPE C5, BRANCO" }),
        line({ id: 23, item: "Caixa 40x30" }),
    ],
    summary: { goods_total: 0, commercial_discount: 0, taxable_base: 0, vat_total: 0, withholding: 0, financial_discount: 0, total: 3, vat_breakdown: [] },
    articles_summary: { total: 3, linked: 1, suggested: 0, unlinked: 2, ready: false }, delete_block: null, discounts: [], ignored_lines: [],
    manual_changes_known: true, reread_unmatched: [], next_to_launch_id: null,
});
const payload = { articles_summary: { total: 3, linked: 3, suggested: 0, unlinked: 0, ready: true }, line_links: [] };

beforeEach(() => {
    Object.values(mockApi).forEach((f) => f.mockReset());
    window.scrollTo = jest.fn() as any;
    mockApi.get.mockResolvedValue({ data: { invoice: invoice(), pingwin: null, suppliers: [] } });
    mockApi.launchGet.mockResolvedValue({ data: { can_launch: false, guards: [], lines: [], estimate: {}, supplier: null, docreference: {}, defaults: {}, draft: null, writes: [],
        close: { mode: null, can_close: false, blockers: [] }, discount_warnings: [] } });
    mockApi.bulk.mockResolvedValue({ message: "2 linhas ligadas ao artigo.", data: payload });
});

test("selecionar linhas e Ligar selecionadas a… liga-as todas ao artigo escolhido", async () => {
    render(<FaturaValidacaoPage />);
    fireEvent.click(await screen.findByTestId("line-select-1"));
    fireEvent.click(screen.getByTestId("line-select-2"));
    expect(screen.getByTestId("lines-selection")).toHaveTextContent("2 selecionadas");

    fireEvent.click(screen.getByTestId("lines-bulk-link"));
    expect(screen.getByTestId("picker")).toHaveTextContent("Ligar selecionadas a… · 2 linhas selecionadas");
    fireEvent.click(within(screen.getByTestId("picker")).getByText("ENVELOPES"));
    await waitFor(() => expect(mockApi.bulk).toHaveBeenCalledWith(5, 7, [22, 23], 99));
    await waitFor(() => expect(screen.queryByTestId("lines-selection")).not.toBeInTheDocument()); // a seleção acaba
});

test("selecionar todas marca as linhas guardadas", async () => {
    render(<FaturaValidacaoPage />);
    fireEvent.click(await screen.findByTestId("lines-select-all"));
    expect(screen.getByTestId("lines-selection")).toHaveTextContent("3 selecionadas");
});

test("Aplicar às linhas com a mesma descrição liga as outras com a descrição igual (sem acentos nem pontuação)", async () => {
    render(<FaturaValidacaoPage />);
    fireEvent.click(await screen.findByText("Mesma descrição"));
    await waitFor(() => expect(mockApi.bulk).toHaveBeenCalledWith(5, 7, [22], 99));
});

test("a célula diz Ligada pela memória e deixa desfazer", () => {
    const Real = jest.requireActual("./faturas/LineArticleCell").default as typeof LineArticleCell;
    const onUnlink = jest.fn();
    render(<Real link={{ article: { id: 99, code: "E1", description: "ENVELOPES" } as any, link_state: "ligada", link_method: "memoria", link_confidence: 0.95, suggestions: [],
        creating: null, supplier_code_status: null, supplier_code_error: null }} saved busy={false} onAccept={jest.fn()} onAssociate={jest.fn()} onCreate={jest.fn()} onUnlink={onUnlink} />);
    expect(screen.getByTestId("line-link-method")).toHaveTextContent("Ligada pela memória");
    fireEvent.click(screen.getByRole("button", { name: "Mais ações do artigo" }));
    fireEvent.click(screen.getByText("Desfazer a ligação"));
    expect(onUnlink).toHaveBeenCalled();
});
