import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import FaturaValidacaoPage from "./FaturaValidacaoPage";

/**
 * Faturas › Validar: o desconto de promoção na linha (valor, desconto com o código, líquido), o
 * "IVA por taxa", ficar no ecrã depois de "Validar e guardar", "Revalidar", "Reler do documento"
 * (manter ou substituir as alterações manuais), "Gravar como fechada" (com e sem as unidades
 * confirmadas) e "Ocultar imagem" (a escolha fica no navegador).
 */
const mockNavigate = jest.fn();
const mockApi = {
    get: jest.fn(), update: jest.fn(), revalidate: jest.fn(), reprocess: jest.fn(), changes: jest.fn(),
    launchGet: jest.fn(), launch: jest.fn(), close: jest.fn(),
};
// O Jest deste projeto não resolve o react-router-dom: o mínimo que a página usa.
jest.mock("react-router-dom", () => ({
    useNavigate: () => mockNavigate,
    useParams: () => ({ id: "7" }),
    Link: ({ to, children, className }: any) => <a href={to} className={className}>{children}</a>,
}), { virtual: true });
jest.mock("helpers/laravel_helper", () => ({
    getOcrInvoice: (...a: unknown[]) => mockApi.get(...a),
    updateOcrInvoice: (...a: unknown[]) => mockApi.update(...a),
    revalidateOcrInvoice: (...a: unknown[]) => mockApi.revalidate(...a),
    reprocessOcrInvoice: (...a: unknown[]) => mockApi.reprocess(...a),
    getOcrManualChanges: (...a: unknown[]) => mockApi.changes(...a),
    getOcrLaunch: (...a: unknown[]) => mockApi.launchGet(...a),
    launchOcrInvoice: (...a: unknown[]) => mockApi.launch(...a),
    closeOcrLaunch: (...a: unknown[]) => mockApi.close(...a),
    getOcrInvoiceImageBlob: () => Promise.reject(new Error("sem imagem")),
    voidOcrLaunch: jest.fn(), setOcrLineLaunchUnit: jest.fn(), searchOcrPingwinLink: jest.fn(), confirmOcrPingwinLink: jest.fn(), unlinkOcrPingwinLink: jest.fn(),
    associateOcrLineArticle: jest.fn(), unlinkOcrLineArticle: jest.fn(), createOcrLineArticle: jest.fn(), acceptOcrLineSuggestions: jest.fn(),
    deleteOcrInvoice: jest.fn(), makeOcrLineCodePrincipal: jest.fn(), correctOcrLineArticleVat: jest.fn(),
}));
jest.mock("contexts/WorkingCompanyContext", () => ({ useWorkingCompanyId: () => 5 }));
jest.mock("Components/Common/PageHeader", () => ({ __esModule: true, default: () => null }));
jest.mock("Components/Common/InfoTip", () => ({ __esModule: true, default: () => null }));
jest.mock("react-select", () => ({ __esModule: true, default: () => null }));
jest.mock("Components/Common/Select", () => ({ __esModule: true, default: ({ ariaLabel }: any) => <span aria-label={ariaLabel} /> }));
jest.mock("./faturas/LineArticleCell", () => ({ __esModule: true, default: () => null }));
jest.mock("./faturas/ArticlePicker", () => ({ __esModule: true, default: () => null }));
jest.mock("./faturas/CreateArticleModal", () => ({ __esModule: true, default: () => null }));
jest.mock("./faturas/GuidesCard", () => ({ __esModule: true, default: () => null }));
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn(), info: jest.fn(), warning: jest.fn() }, ToastContainer: () => null }));

const line = (o: any) => ({ supplier_code: null, unit: "UN", discount_pct: 0, vat_rate: 6, vat_code: "4", promo_code: null, discount: 0, discount_code: null, discount_label: null,
    article: null, link_state: "por_ligar", link_method: null, link_confidence: null, suggestions: [], creating: null, supplier_code_status: null, supplier_code_error: null, ...o });

const invoice = (o: any = {}) => ({
    id: 7, status: "por_validar", confidence: 90, model: "claude-opus-5-5", prompt_version: "linhas-v3", synced_to_pingwin: false, error_message: null,
    supplier_id: null, supplier_name: "MAKRO", supplier_nif: "502030712", number: "FAC 02202202601/009539", issue_date: "2026-10-07", lines_total: 32.38,
    buyer_nif: "514148497", atcud: "X-9539", doc_type: "FT", qr_ok: true, source: "qr+imagem", lines_source: "imagem", pages: 3, attempts: 1,
    tokens_in: 1, tokens_out: 1, cost_usd: 0, duration_ms: 1, check_status: "confere", check_diff: [],
    lines: [
        line({ id: 11, item: "ÓLEO 5LT STA FILOMENA", quantity: 3, unit_price: 9.99, line_total: 29.97, vat_rate: 13, vat_code: "5", promo_code: "45238",
            discount: 3.78, discount_code: "45238", discount_label: "Leve Mais Pague Menos", net_total: 26.19 }),
        line({ id: 12, item: "PÃO HOT DOG MAXI 320G BIMBO", quantity: 4, unit_price: 1.67, line_total: 6.68, promo_code: null, net_total: 6.68 }),
    ],
    summary: { goods_total: 0, commercial_discount: 0, taxable_base: 0, vat_total: 0, withholding: 0, financial_discount: 0, total: 0, vat_breakdown: [] },
    articles_summary: { total: 2, linked: 0, suggested: 0, unlinked: 2, ready: false }, delete_block: null,
    discounts: [
        { id: 1, code: "45238", label: "Leve Mais Pague Menos", amount: 3.78, vat_code: "5", vat_rate: 13, status: "atribuido", warning: null, allocations: [{ line_id: 11, amount: 3.78 }] },
        { id: 2, code: "43520", label: "Leve Mais Pague Menos", amount: 0.96, vat_code: "4", vat_rate: 6, status: "por_atribuir", warning: null, allocations: [] },
    ],
    vat_by_rate: { source: "qr", ok: false, lines_without_rate: [], rows: [
        { rate: 6, codes: ["4"], read_base: 5.72, read_vat: 0.34, calc_base: 6.68, calc_vat: 0.4, diff_base: 0.96, diff_vat: 0.06, ok: false, explained_by: [12], explained_by_discounts: ["43520"] },
        { rate: 13, codes: ["5"], read_base: 26.19, read_vat: 3.4, calc_base: 26.19, calc_vat: 3.4, diff_base: 0, diff_vat: 0, ok: true, explained_by: [], explained_by_discounts: [] },
    ] },
    ignored_lines: ["+ 0,13 TAXA IEC INCL. PREÇO U.V."], manual_changes_known: true, reread_unmatched: [], next_to_launch_id: 6,
    ...o,
});

const preview = (o: any = {}) => ({
    can_launch: true, guards: [{ key: "descontos", ok: true, label: "Descontos no seu artigo", message: null }], lines: [],
    estimate: { target_cents: 3238, computed_cents: 3238, net_cents: 3000, tax_cents: 238, adjustment_cents: 0 },
    supplier: { id: 1, name: "MAKRO", pingwin_id: "S" }, docreference: { number: "FAC 02202202601/009539", truncated: false, date: "2026-10-07" },
    defaults: { store: null, store_code: null, serie: null, at: null }, draft: null, writes: [],
    close: { mode: "launch", can_close: true, blockers: [], closed_at: null, closed_by_name: null }, discount_warnings: [],
    ...o,
});

beforeEach(() => {
    Object.values(mockApi).forEach((f) => f.mockReset());
    mockNavigate.mockReset();
    window.localStorage.clear();
    window.scrollTo = jest.fn() as any; // o jsdom não o implementa
    mockApi.get.mockResolvedValue({ data: { invoice: invoice(), pingwin: null, suppliers: [] } });
    mockApi.launchGet.mockResolvedValue({ data: preview() });
});

test("a linha mostra o valor, o desconto com o código e o líquido; o IVA por taxa mostra a diferença e quem a explica", async () => {
    render(<FaturaValidacaoPage />);
    expect(await screen.findByTestId("line-discount-0")).toHaveTextContent(/Leve Mais Pague Menos 45238: −3,78\s€/);
    expect(screen.getByTestId("line-discount-0")).toHaveTextContent(/Líquido 26,19\s€/);
    expect(screen.queryByTestId("line-discount-1")).toBeNull();
    // A linha que explica a diferença dos 6 % fica destacada.
    expect(screen.getByTestId("line-1")).toHaveClass("table-warning");
    const row = screen.getByTestId("vat-row-6");
    expect(row).toHaveTextContent("Explica: linha 2 · PÃO HOT DOG MAXI 320G BIMBO");
    expect(row).toHaveTextContent("Explica: desconto por atribuir 43520");
    expect(screen.getByTestId("discount-unassigned")).toHaveTextContent("Desconto por atribuir");
    expect(screen.getByTestId("ignored-lines")).toHaveTextContent("TAXA IEC INCL.");
});

test("Validar e guardar fica no ecrã, com a confirmação e os passos seguintes", async () => {
    mockApi.update.mockResolvedValue({ data: { invoice: invoice({ status: "validada" }) } });
    render(<FaturaValidacaoPage />);
    fireEvent.click(await screen.findByText(/Validar e guardar/));
    const panel = await screen.findByTestId("saved-panel");
    expect(mockNavigate).not.toHaveBeenCalled();
    expect(mockApi.update.mock.calls[0][2].lines[0]).toMatchObject({ id: 11, line_total: 29.97, promo_code: "45238" });
    for (const t of ["Gravar no PingWin como rascunho", "Gravar no PingWin como fechada", "Próxima fatura por lançar", "Voltar à lista"]) {
        expect(within(panel).getByText(t)).toBeInTheDocument();
    }
    fireEvent.click(within(panel).getByTestId("next-invoice"));
    expect(mockNavigate).toHaveBeenCalledWith("/restauracao/faturas/6");
    fireEvent.click(within(panel).getByText("Voltar à lista"));
    expect(mockNavigate).toHaveBeenLastCalledWith("/restauracao/faturas");
});

test("Revalidar envia as linhas de agora (com o código da promoção corrigido) e mostra o resultado", async () => {
    mockApi.revalidate.mockResolvedValue({ data: { invoice: invoice({ vat_by_rate: { ...invoice().vat_by_rate, ok: true, rows: [] }, discounts: [] }) } });
    render(<FaturaValidacaoPage />);
    fireEvent.change(await screen.findByLabelText("Código da promoção, linha 2"), { target: { value: "43520" } });
    fireEvent.click(screen.getByTestId("revalidate"));
    await waitFor(() => expect(mockApi.revalidate).toHaveBeenCalled());
    expect(mockApi.revalidate.mock.calls[0][2].lines[1]).toMatchObject({ id: 12, promo_code: "43520" });
    await waitFor(() => expect(screen.queryByTestId("vat-row-6")).toBeNull());
});

test("Reler do documento mostra o que foi mudado à mão e pergunta: manter ou substituir", async () => {
    mockApi.changes.mockResolvedValue({ data: { available: true, changes: [
        { type: "alterada", line_id: 12, item: "PÃO HOT DOG MAXI 320G BIMBO", fields: [{ field: "quantity", label: "Quantidade", read: 3, current: 4 }] },
    ] } });
    mockApi.reprocess.mockResolvedValue({ data: { status: "processing" } });
    render(<FaturaValidacaoPage />);
    fireEvent.click(await screen.findByTestId("reread"));
    expect(await screen.findByTestId("reread-changes")).toHaveTextContent("Alterada: PÃO HOT DOG MAXI 320G BIMBO · Quantidade: 3 → 4");
    expect(screen.getByLabelText(/Manter as alterações manuais/)).toBeChecked();
    fireEvent.click(screen.getByTestId("reread-confirm"));
    await waitFor(() => expect(mockApi.reprocess).toHaveBeenCalledWith(5, 7, { keep_manual: true }));
});

test("Reler sem alterações manuais substitui (e diz)", async () => {
    mockApi.changes.mockResolvedValue({ data: { available: true, changes: [] } });
    mockApi.reprocess.mockResolvedValue({ data: { status: "processing" } });
    render(<FaturaValidacaoPage />);
    fireEvent.click(await screen.findByTestId("reread"));
    expect(await screen.findByTestId("reread-no-changes")).toBeInTheDocument();
    fireEvent.click(screen.getByTestId("reread-confirm"));
    await waitFor(() => expect(mockApi.reprocess).toHaveBeenCalledWith(5, 7, { keep_manual: false }));
});

test("Gravar como fechada: sem as unidades confirmadas diz qual falta; com elas pede confirmação com o aviso", async () => {
    mockApi.launchGet.mockResolvedValueOnce({ data: preview({ close: { mode: "launch", can_close: false, closed_at: null, closed_by_name: null,
        blockers: [{ key: "unidades_confirmadas", label: "Unidades confirmadas no Guião de unidades", message: "Falta confirmar as unidades no Guião de unidades: ÓLEO 5LT." }] } }) });
    const { unmount } = render(<FaturaValidacaoPage />);
    const blockers = await screen.findByTestId("close-blockers");
    expect(blockers).toHaveTextContent("Falta confirmar as unidades no Guião de unidades: ÓLEO 5LT.");
    expect(within(blockers).getByRole("link", { name: /Abrir o Guião de unidades/ })).toHaveAttribute("href", "/restauracao/auditoria/guiao");
    expect(screen.getByTestId("launch-closed")).toBeDisabled();
    unmount();

    mockApi.launch.mockResolvedValue({ data: { ...preview(), write: { id: 9 } } });
    render(<FaturaValidacaoPage />);
    fireEvent.click(await screen.findByTestId("launch-closed"));
    expect(await screen.findByTestId("close-warning")).toHaveTextContent("Depois de fechada, a fatura entra no stock e na conta corrente. Para corrigir, é preciso anular.");
    fireEvent.click(within(screen.getByTestId("launch-modal")).getByText("Gravar como fechada"));
    await waitFor(() => expect(mockApi.launch).toHaveBeenCalledWith(5, 7, { close: true }));
});

test("Ocultar imagem: a tabela ocupa a largura toda e a escolha fica no navegador", async () => {
    const { unmount } = render(<FaturaValidacaoPage />);
    const toggle = await screen.findByTestId("toggle-image");
    expect(toggle).toHaveTextContent("Ocultar imagem");
    fireEvent.click(toggle);
    expect(screen.getByTestId("image-col")).toHaveClass("d-none");
    expect(screen.getByTestId("toggle-image")).toHaveTextContent("Mostrar imagem");
    expect(window.localStorage.getItem("xp-fatura-imagem-oculta")).toBe("1");
    unmount();

    render(<FaturaValidacaoPage />);
    expect(await screen.findByTestId("toggle-image")).toHaveTextContent("Mostrar imagem");
    expect(screen.getByTestId("image-col")).toHaveClass("d-none");
});
