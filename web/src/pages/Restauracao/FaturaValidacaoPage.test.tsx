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
    launchGet: jest.fn(), launch: jest.fn(), close: jest.fn(), paperCheck: jest.fn(), refresh: jest.fn(), read: jest.fn(),
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
    setOcrPaperCheck: (...a: unknown[]) => mockApi.paperCheck(...a),
    getOcrInvoiceImageBlob: () => Promise.reject(new Error("sem imagem")),
    refreshArticle: (...a: unknown[]) => mockApi.refresh(...a),
    getArticleRead: (...a: unknown[]) => mockApi.read(...a),
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

test("a linha mostra a etiqueta da promoção, o líquido em destaque e o bruto; o IVA por taxa mostra a diferença e quem a explica", async () => {
    render(<FaturaValidacaoPage />);
    expect(await screen.findByTestId("line-promo-0")).toHaveTextContent(/Promoção 45238 · −3,78\s€/);
    expect(screen.getByTestId("line-discount-0")).toHaveTextContent(/26,19\s€/);
    expect(screen.getByLabelText("Valor da linha 1")).toHaveValue(29.97);
    expect(screen.queryByTestId("line-discount-1")).toBeNull();
    expect(screen.queryByTestId("line-promo-1")).toBeNull();
    expect(screen.queryByText("Promoção", { selector: "th" })).toBeNull(); // a coluna Promoção saiu
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
    fireEvent.change(await screen.findByLabelText("Quantidade, linha 2"), { target: { value: "5" } });
    fireEvent.click(screen.getByTestId("revalidate"));
    await waitFor(() => expect(mockApi.revalidate).toHaveBeenCalled());
    expect(mockApi.revalidate.mock.calls[0][2].lines[1]).toMatchObject({ id: 12, quantity: 5 });
    await waitFor(() => expect(screen.queryByTestId("vat-row-6")).toBeNull());
});

test("Promoção… no menu da linha: o código, o desconto da fatura, a repartição e Aplicar", async () => {
    mockApi.revalidate.mockResolvedValue({ data: { invoice: invoice() } });
    render(<FaturaValidacaoPage />);
    await screen.findByTestId("line-1");
    fireEvent.click(within(screen.getByTestId("line-1")).getByText("Promoção…"));
    const modal = screen.getByTestId("promo-modal");
    fireEvent.change(within(modal).getByLabelText("Código da promoção"), { target: { value: "43520" } });
    const view = within(modal).getByTestId("promo-view");
    expect(view).toHaveTextContent(/Leve Mais Pague Menos 43520: −0,96\s€/);
    expect(view).toHaveTextContent("por atribuir");
    expect(view).toHaveTextContent(/2 · PÃO HOT DOG MAXI 320G BIMBO\s*6,68\s€\s*−0,96\s€\s*5,72\s€/);
    fireEvent.click(within(modal).getByTestId("promo-apply"));
    await waitFor(() => expect(mockApi.revalidate).toHaveBeenCalled());
    expect(mockApi.revalidate.mock.calls[0][2].lines[1]).toMatchObject({ id: 12, promo_code: "43520" });
});

test("Promoção sem desconto na fatura: a etiqueta fica amarela e o modal avisa; Remover promoção", async () => {
    mockApi.get.mockResolvedValue({ data: { invoice: invoice({ lines: [invoice().lines[0], { ...invoice().lines[1], promo_code: "99999" }] }), pingwin: null, suppliers: [] } });
    mockApi.revalidate.mockResolvedValue({ data: { invoice: invoice() } });
    render(<FaturaValidacaoPage />);
    const tag = await screen.findByTestId("line-promo-1");
    expect(tag).toHaveTextContent("Promoção sem desconto na fatura");
    expect(tag).toHaveClass("bg-warning-subtle");
    fireEvent.click(tag);
    expect(within(screen.getByTestId("promo-modal")).getByTestId("promo-no-discount")).toHaveTextContent("não há nenhuma linha de desconto com o código 99999");
    fireEvent.click(screen.getByTestId("promo-remove"));
    await waitFor(() => expect(mockApi.revalidate).toHaveBeenCalled());
    expect(mockApi.revalidate.mock.calls[0][2].lines[1]).toMatchObject({ id: 12, promo_code: null });
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

// ───────────────────────────────────── notas de crédito, leitura sem QR, IEC e vários documentos (sintéticos)

const creditNote = (o: any = {}) => invoice({
    doc_type: "NC", number: "NC CX/0196", supplier_name: "Cervejas Teste, SA", supplier_nif: "508888883", buyer_nif: "509999999", atcud: "0",
    lines: [line({ id: 21, item: "Barril 50 L devolvido", quantity: 3, unit_price: 75, line_total: 225, vat_rate: 0, vat_code: null, net_total: 225 })],
    discounts: [], ignored_lines: [],
    summary: { goods_total: 225, commercial_discount: 0, taxable_base: 225, vat_total: 0, withholding: 0, financial_discount: 0, total: 225, vat_breakdown: [{ rate: 0, base: 225, vat: 0 }] },
    vat_by_rate: { source: "qr", ok: true, lines_without_rate: [], rows: [
        { rate: 0, codes: [], read_base: 225, read_vat: 0, calc_base: 225, calc_vat: 0, diff_base: 0, diff_vat: 0, ok: true, explained_by: [], explained_by_discounts: [] }] },
    ...o,
});
const ND_BLOCKED = "As notas de débito ainda se lançam à mão no PingWin. Disponível em breve.";

test("nota de crédito: diz-se como tal, os totais são um crédito e o Lançar no PingWin fica ativo", async () => {
    mockApi.get.mockResolvedValue({ data: { invoice: creditNote(), pingwin: null, suppliers: [] } });
    mockApi.update.mockResolvedValue({ data: { invoice: creditNote({ status: "validada" }) } });
    render(<FaturaValidacaoPage />);
    expect(await screen.findByTestId("doc-type")).toHaveTextContent("Nota de crédito");
    expect(screen.getByTestId("credit-note")).toHaveTextContent("lança-se no PingWin como Nota de crédito de fornecedor, em rascunho, com a data da nota e o número dela como Documento de referência");
    expect(screen.getByText("Total do crédito")).toBeInTheDocument();
    expect(screen.getByText(/Soma das linhas \(Xplendor\), crédito/)).toBeInTheDocument();
    fireEvent.click(screen.getByText(/Validar e guardar/));
    const panel = await screen.findByTestId("saved-panel");
    expect(within(panel).getByTestId("saved-launch-draft")).not.toBeDisabled();
    expect(within(panel).getByTestId("saved-launch-closed")).not.toBeDisabled();
});

test("nota de débito: o Lançar no PingWin continua desativado, com o motivo", async () => {
    mockApi.get.mockResolvedValue({ data: { invoice: creditNote({ doc_type: "ND", number: "ND 2026/3" }), pingwin: null, suppliers: [] } });
    mockApi.launchGet.mockResolvedValue({ data: preview({ can_launch: false, guards: [{ key: "tipo", ok: false, label: "Fatura (FT)", message: ND_BLOCKED }],
        close: { mode: "launch", can_close: false, blockers: [], closed_at: null, closed_by_name: null } }) });
    mockApi.update.mockResolvedValue({ data: { invoice: creditNote({ doc_type: "ND", number: "ND 2026/3", status: "validada" }) } });
    render(<FaturaValidacaoPage />);
    expect(await screen.findByTestId("doc-type")).toHaveTextContent("Nota de débito");
    expect(await screen.findByTestId("launch-guards")).toHaveTextContent(ND_BLOCKED);
    fireEvent.click(screen.getByText(/Validar e guardar/));
    const panel = await screen.findByTestId("saved-panel");
    expect(within(panel).getByTestId("saved-launch-draft")).toBeDisabled();
    expect(within(panel).getAllByLabelText(new RegExp(ND_BLOCKED.replace(/\./g, "\\.")))).toHaveLength(2);
    expect(mockApi.launch).not.toHaveBeenCalled();
});

test("uma fatura (FT) continua a poder gravar no PingWin a partir do painel", async () => {
    mockApi.update.mockResolvedValue({ data: { invoice: invoice({ status: "validada" }) } });
    render(<FaturaValidacaoPage />);
    fireEvent.click(await screen.findByText(/Validar e guardar/));
    const panel = await screen.findByTestId("saved-panel");
    expect(within(panel).getByTestId("saved-launch-draft")).not.toBeDisabled();
    expect(screen.queryByTestId("credit-note")).toBeNull();
});

test("painel Fatura validada: com uma guarda do lançamento a falhar, os dois botões ficam desativados com o motivo e a ligação para resolver", async () => {
    const UNIT_MSG = "O artigo COMIDA STAFF 23% é à unidade e a linha tem 8,09 kg: o PingWin arredonda para 9.";
    mockApi.launchGet.mockResolvedValue({ data: preview({
        can_launch: false,
        guards: [{ key: "unidades", ok: false, label: "Unidade de cada linha", message: UNIT_MSG }],
        close: { mode: "launch", can_close: false, closed_at: null, closed_by_name: null, blockers: [{ key: "unidades", label: "Unidade de cada linha", message: UNIT_MSG }] },
        planned: { lines: [{ kind: "linha", line_id: 11, item: "COMIDA STAFF", rounded_from: 8.09, qnt: 9, price: 5, discount_pct: 0, promo_cents: 0, vat_rate: 23, net_cents: 4500, tax_cents: 1035, total_cents: 5535,
            article: { id: 3, code: "101208", description: "COMIDA STAFF 23%", pingwin_id: "101208" } }], total_cents: 5535, target_cents: 5000, iec_from: null },
    }) });
    mockApi.update.mockResolvedValue({ data: { invoice: invoice({ status: "validada" }) } });
    render(<FaturaValidacaoPage />);
    fireEvent.click(await screen.findByText(/Validar e guardar/));
    const panel = await screen.findByTestId("saved-panel");
    await waitFor(() => expect(within(panel).getByTestId("saved-launch-draft")).toBeDisabled());
    expect(within(panel).getByTestId("saved-launch-closed")).toBeDisabled();
    const blocked = within(panel).getByTestId("saved-launch-blocked");
    expect(blocked).toHaveTextContent(UNIT_MSG);
    expect(within(blocked).getByRole("link", { name: /Abrir COMIDA STAFF 23% em Cadastros › Artigos/ })).toHaveAttribute("href", "/cadastros/artigos/101208");
    expect(within(blocked).getByRole("link", { name: /Abrir o Guião de unidades/ })).toHaveAttribute("href", "/restauracao/auditoria/guiao");
    fireEvent.click(within(panel).getByTestId("saved-launch-draft"));
    expect(screen.queryByTestId("launch-modal")).toBeNull();
});

test("as ligações ao Guião levam ao artigo de cada linha, com o caminho de volta à fatura", async () => {
    const back = "&voltar=%2Fapp%2Frestauracao%2Ffaturas%2F7";
    const A = { id: 3, code: "101208", description: "COMIDA STAFF 23%", pingwin_id: "101208" };
    const B = { id: 4, code: "200300", description: "ÓLEO 5LT", pingwin_id: "200300" };
    const opts = [{ value: "11001", label: "Unidade (UN)" }, { value: "11002", label: "Quilograma (KG)" }];
    mockApi.launchGet.mockResolvedValue({ data: preview({
        can_launch: false,
        guards: [{ key: "unidades", ok: false, label: "Unidade de cada linha", message: "Escolha a unidade das linhas assinaladas.",
            articles: [{ pingwin_id: "101208", description: "COMIDA STAFF 23%" }, { pingwin_id: "200300", description: "ÓLEO 5LT" }] }],
        close: { mode: "launch", can_close: false, closed_at: null, closed_by_name: null, blockers: [
            { key: "unidades", label: "Unidade de cada linha", message: "Escolha a unidade das linhas assinaladas." },
            { key: "unidades_confirmadas", label: "Unidades confirmadas no Guião de unidades", message: "Falta confirmar as unidades no Guião de unidades: ÓLEO 5LT.",
                articles: [{ pingwin_id: "200300", description: "ÓLEO 5LT" }] }] },
        lines: [
            { id: 11, position: 0, item: "Carne", ocr_unit: "KG", quantity: 8.09, article: A, unit_options: opts, unit_id: "11001", unit_source: "escolhida", unit_ok: true, unit_rounds: true },
            { id: 12, position: 1, item: "Óleo", ocr_unit: "UN", quantity: 2, article: B, unit_options: opts, unit_id: null, unit_source: null, unit_ok: false, unit_rounds: false },
        ],
    }) });
    mockApi.update.mockResolvedValue({ data: { invoice: invoice({ status: "validada" }) } });
    render(<FaturaValidacaoPage />);
    // No cartão do lançamento: a guarda das unidades, uma ligação por artigo.
    const guards = await screen.findByTestId("launch-guards");
    expect(within(guards).getByRole("link", { name: /Abrir COMIDA STAFF 23% no Guião/ })).toHaveAttribute("href", `/restauracao/auditoria/guiao?artigo=101208${back}`);
    expect(within(guards).getByRole("link", { name: /Abrir ÓLEO 5LT no Guião/ })).toHaveAttribute("href", `/restauracao/auditoria/guiao?artigo=200300${back}`);
    // Na mensagem de cada linha: o artigo dessa linha.
    const rows = within(screen.getByTestId("launch-units")).getAllByRole("link", { name: "Guião" });
    expect(rows.map((a) => a.getAttribute("href"))).toEqual([`/restauracao/auditoria/guiao?artigo=101208${back}`, `/restauracao/auditoria/guiao?artigo=200300${back}`]);
    // No painel "Fatura validada e guardada": as mesmas ligações, sem a genérica.
    fireEvent.click(await screen.findByText(/Validar e guardar/));
    const blocked = await screen.findByTestId("saved-launch-blocked");
    expect(within(blocked).getByRole("link", { name: /Abrir COMIDA STAFF 23% no Guião/ })).toHaveAttribute("href", `/restauracao/auditoria/guiao?artigo=101208${back}`);
    expect(within(blocked).getByRole("link", { name: /Abrir ÓLEO 5LT no Guião/ })).toHaveAttribute("href", `/restauracao/auditoria/guiao?artigo=200300${back}`);
    expect(within(blocked).queryByRole("link", { name: "Abrir o Guião de unidades" })).toBeNull();
});

test("o que falta para fechar liga ao artigo por confirmar no Guião", async () => {
    mockApi.launchGet.mockResolvedValue({ data: preview({ close: { mode: "launch", can_close: false, closed_at: null, closed_by_name: null,
        blockers: [{ key: "unidades_confirmadas", label: "Unidades confirmadas no Guião de unidades", message: "Falta confirmar as unidades no Guião de unidades: ÓLEO 5LT.",
            articles: [{ pingwin_id: "200300", description: "ÓLEO 5LT" }] }] } }) });
    render(<FaturaValidacaoPage />);
    const blockers = await screen.findByTestId("close-blockers");
    expect(within(blockers).getByRole("link", { name: /Abrir ÓLEO 5LT no Guião/ })).toHaveAttribute("href", "/restauracao/auditoria/guiao?artigo=200300&voltar=%2Fapp%2Frestauracao%2Ffaturas%2F7");
});

test("painel Fatura validada: só a conferência por aceitar deixa gravar em rascunho (aceita-se no modal), mas não como fechada", async () => {
    mockApi.launchGet.mockResolvedValue({ data: preview({
        can_launch: false,
        guards: [{ key: "conferencia", ok: false, label: "Linhas a conferir com o QR", message: "As linhas não conferem com o QR." }],
        close: { mode: "launch", can_close: false, closed_at: null, closed_by_name: null, blockers: [{ key: "conferencia", label: "Linhas a conferir com o QR", message: "As linhas não conferem com o QR." }] },
    }) });
    mockApi.update.mockResolvedValue({ data: { invoice: invoice({ status: "validada" }) } });
    render(<FaturaValidacaoPage />);
    fireEvent.click(await screen.findByText(/Validar e guardar/));
    const panel = await screen.findByTestId("saved-panel");
    await waitFor(() => expect(within(panel).getByTestId("saved-launch-closed")).toBeDisabled());
    expect(within(panel).getByTestId("saved-launch-draft")).not.toBeDisabled();
    expect(within(panel).queryByTestId("saved-launch-blocked")).toBeNull();
});

test("uma linha cuja unidade arredonda aparece para escolher outra unidade do artigo", async () => {
    mockApi.launchGet.mockResolvedValue({ data: preview({
        can_launch: false,
        guards: [{ key: "unidades", ok: false, label: "Unidade de cada linha", message: "Linha «Carne 0» (COMIDA STAFF 23%): a unidade escolhida para esta linha, Unidade (UN), não aceita decimais." }],
        lines: [{ id: 11, position: 0, item: "Carne 0", ocr_unit: "KG", quantity: 8.09, article: { id: 3, code: "101208", description: "COMIDA STAFF 23%", pingwin_id: "101208" },
            unit_options: [{ value: "11001", label: "Unidade (UN)" }, { value: "11002", label: "Quilograma (KG)" }], unit_id: "11001", unit_source: "escolhida", unit_ok: true, unit_rounds: true }],
    }) });
    render(<FaturaValidacaoPage />);
    const table = await screen.findByTestId("launch-units");
    expect(within(table).getByText("arredonda")).toBeInTheDocument();
    expect(within(table).getByLabelText("Unidade da linha 1")).toBeInTheDocument();

    // "Atualizar do PingWin": lê o artigo ao vivo, diz o que mudou e volta a ver as guardas.
    mockApi.refresh.mockResolvedValue({ data: { read_id: "t1" } });
    mockApi.read.mockResolvedValue({ data: { status: "ready", changes: [{ label: "Unidade de compra", from: "Unidade", to: "Quilograma (KG)" }] } });
    const before = mockApi.launchGet.mock.calls.length;
    fireEvent.click(within(table).getByTestId("launch-refresh-11"));
    await waitFor(() => expect(mockApi.refresh).toHaveBeenCalledWith(5, "101208"), { timeout: 3000 });
    await waitFor(() => expect(mockApi.launchGet.mock.calls.length).toBeGreaterThan(before), { timeout: 3000 });
    const { toast } = jest.requireMock("react-toastify");
    expect(toast.success).toHaveBeenCalledWith("Atualizado do PingWin: Unidade de compra Unidade → Quilograma (KG).");
});

test("Revalidar relê o lançamento mesmo quando a revalidação falha", async () => {
    mockApi.revalidate.mockRejectedValue(new Error("falhou"));
    render(<FaturaValidacaoPage />);
    await screen.findByTestId("launch-card");
    const before = mockApi.launchGet.mock.calls.length;
    fireEvent.click(screen.getByTestId("revalidate"));
    await waitFor(() => expect(mockApi.launchGet.mock.calls.length).toBeGreaterThan(before));
});

test("sem QR: QR ilegível, o adquirente verificado pelo texto e o IEC somado na linha", async () => {
    mockApi.get.mockResolvedValue({ data: { invoice: invoice({
        qr_ok: false, source: "sem_qr", check_status: "sem_qr", supplier_nif: "508888883", buyer_nif: "509999999", atcud: "ABCD1234-55", discounts: [], ignored_lines: [],
        lines: [line({ id: 31, item: "Refrigerante lata 33 cl", quantity: 48, unit_price: 1.25, line_total: 61.84, vat_rate: 23, vat_code: null, net_total: 61.84, iec: 1.84 }),
            line({ id: 32, item: "Caixa de vasilhame", quantity: 7, unit_price: 5, line_total: 35, vat_rate: 0, vat_code: null, net_total: 35, iec: null })],
    }), pingwin: null, suppliers: [] } });
    render(<FaturaValidacaoPage />);
    expect(await screen.findByTestId("no-qr")).toHaveTextContent("QR ilegível: o cabeçalho foi lido do documento");
    expect(screen.getByTestId("buyer-text-check")).toHaveTextContent("Adquirente 509999999: verificado pelo texto; o QR não se conseguiu ler.");
    expect(screen.getByText("QR ilegível")).toBeInTheDocument();
    expect(screen.getByTestId("line-iec-0")).toHaveTextContent(/Inclui IEC de 1,84\s€/);
    expect(screen.queryByTestId("line-iec-1")).toBeNull();
    expect(screen.queryByText(/IA\b/)).toBeNull();
});

test("vários documentos no mesmo ficheiro: a mensagem com a lista de cada um", async () => {
    const msg = "Este ficheiro tem 2 documentos. Carregue um documento por ficheiro.\n· Fatura FT A/77, emitente 508888883 (página 1)\n· Nota de crédito NC CX/0196, emitente 508888883 (página 2)";
    mockApi.get.mockResolvedValue({ data: { invoice: invoice({ status: "erro", error_message: msg, lines: [] }), pingwin: null, suppliers: [] } });
    render(<FaturaValidacaoPage />);
    const p = await screen.findByTestId("ocr-error");
    expect(p).toHaveTextContent("Este ficheiro tem 2 documentos. Carregue um documento por ficheiro.");
    expect(p).toHaveTextContent("· Nota de crédito NC CX/0196, emitente 508888883 (página 2)");
    expect(p).toHaveStyle({ whiteSpace: "pre-line" });
});

test("não é desta empresa, verificado pelo texto: diz que foi lido da fatura (não do QR)", async () => {
    mockApi.get.mockResolvedValue({ data: { invoice: invoice({ status: "nao_desta_empresa", qr_ok: false, doc_type: "FT", supplier_nif: "508888883", buyer_nif: "511111118",
        error_message: "Esta fatura não é desta empresa: o NIF do adquirente lido na fatura é 511111118, o da empresa é 509999999. Verificado pelo texto; o QR não se conseguiu ler." }), pingwin: null, suppliers: [] } });
    render(<FaturaValidacaoPage />);
    expect(await screen.findByText(/o NIF do adquirente lido na fatura é 511111118, o da empresa é 509999999/)).toBeInTheDocument();
    expect(screen.getByTestId("not-ours-read")).toHaveTextContent("Lido da fatura: emitente 508888883 · adquirente 511111118 · Fatura");
});

test("Lançar no PingWin com IEC numa coluna à parte: mostra as linhas que vão, com a do IEC e o total", async () => {
    const pl = (kind: string, item: string, qnt: number, price: number, disc: number, rate: number, net: number, tax: number) =>
        ({ kind, line_id: null, item, article: kind === "iec" ? { id: 9, code: "999", description: "IEC", pingwin_id: "PIEC" } : null, qnt, price, discount_pct: disc, promo_cents: 0, vat_rate: rate,
            net_cents: net, tax_cents: tax, total_cents: net + tax });
    mockApi.launchGet.mockResolvedValue({ data: preview({ planned: { iec_from: "VFT BOVFT/1", target_cents: 15366, total_cents: 15366, lines: [
        pl("linha", "Bebida A 24", 1, 20.88, 35.01, 23, 1357, 312), pl("linha", "Caixa A", 1, 5, 0, 0, 500, 0), pl("iec", "IEC", 1, 2.87, 0, 23, 287, 66),
    ] } }) });
    render(<FaturaValidacaoPage />);
    const t = await screen.findByTestId("launch-planned");
    expect(t).toHaveTextContent("O IEC vai numa linha à parte, como na VFT BOVFT/1");
    expect(within(t).getByTestId("planned-iec-2")).toHaveTextContent(/999 IEC\s*1\s*2,87\s*—\s*23 %\s*2,87\s€\s*3,53\s€/);
    expect(within(t).getByTestId("planned-linha-0")).toHaveTextContent("35,01 %");
    expect(within(t).getByTestId("planned-total")).toHaveTextContent(/153,66\s€/);
});

test("sem IEC nas linhas, o cartão do lançamento não mostra a lista", async () => {
    render(<FaturaValidacaoPage />);
    await screen.findByTestId("launch-guards");
    expect(screen.queryByTestId("launch-planned")).toBeNull();
});

test("a guarda do tipo mostra o tipo verdadeiro: fatura-recibo com visto, nota de débito com a sua mensagem", async () => {
    mockApi.launchGet.mockResolvedValue({ data: preview({ can_launch: false, guards: [
        { key: "tipo", ok: false, label: "Nota de débito (ND)", message: "As notas de débito ainda se lançam à mão no PingWin. Disponível em breve." }] }) });
    const { unmount } = render(<FaturaValidacaoPage />);
    expect(await screen.findByTestId("launch-guards")).toHaveTextContent("Nota de débito (ND) · As notas de débito ainda se lançam à mão no PingWin. Disponível em breve.");
    unmount();
    mockApi.launchGet.mockResolvedValue({ data: preview({ guards: [{ key: "tipo", ok: true, label: "Fatura-recibo (FR)", message: null }] }) });
    render(<FaturaValidacaoPage />);
    const g = await screen.findByTestId("launch-guards");
    expect(g).toHaveTextContent("Fatura-recibo (FR)");
    expect(g).not.toHaveTextContent("Fatura (FT)");
});

test("sem QR: Conferi os totais com o papel marca-se no cartão do lançamento e diz quem e quando", async () => {
    const missing = { key: "qr", ok: false, label: "Sem QR: adquirente, IVA lido e totais conferidos com o papel", message: 'Sem QR legível, falta: marcar "Conferi os totais com o papel".' };
    mockApi.launchGet.mockResolvedValue({ data: preview({ can_launch: false, guards: [missing], paper_check: { checked: false, at: null, by_name: null, missing: ['marcar "Conferi os totais com o papel"'] } }) });
    mockApi.paperCheck.mockResolvedValue({ data: preview({ can_launch: true, guards: [{ ...missing, ok: true, message: null }],
        paper_check: { checked: true, at: "2026-10-23T10:15:00+01:00", by_name: "Adriana", missing: [] } }) });
    render(<FaturaValidacaoPage />);
    expect(await screen.findByTestId("launch-guards")).toHaveTextContent('Sem QR legível, falta: marcar "Conferi os totais com o papel".');
    const box = within(screen.getByTestId("launch-paper-check")).getByRole("checkbox");
    expect(box).not.toBeChecked();
    fireEvent.click(box);
    await waitFor(() => expect(mockApi.paperCheck).toHaveBeenCalledWith(5, 7, true));
    expect(await screen.findByText(/Marcado por Adriana a/)).toBeInTheDocument();
    expect(within(screen.getByTestId("launch-paper-check")).getByRole("checkbox")).toBeChecked();
});

test("com QR, a caixa da conferência com o papel não aparece", async () => {
    render(<FaturaValidacaoPage />);
    await screen.findByTestId("launch-guards");
    expect(screen.queryByTestId("launch-paper-check")).toBeNull();
});

test("artigo de agrupamento: o cartão do lançamento diz quantas linhas se juntam e em quê", async () => {
    mockApi.launchGet.mockResolvedValue({ data: preview({ planned: { iec_from: null, target_cents: 40916, total_cents: 40916, lines: [
        { kind: "grupo", line_id: null, item: "COMIDA STAFF 23%", article: { id: 9, code: "908", description: "COMIDA STAFF 23%", pingwin_id: "PSTAFF" }, qnt: 1, price: 203.99,
            discount_pct: 0, promo_cents: 0, vat_rate: 23, net_cents: 20399, tax_cents: 4692, total_cents: 25091, lines: 5, line_ids: [1, 2, 3, 4, 5] },
        { kind: "linha", line_id: 6, item: "Outro", article: null, qnt: 1, price: 174.1, discount_pct: 0, promo_cents: 0, vat_rate: 23, net_cents: 17410, tax_cents: 4004, total_cents: 21414 },
    ] } }) });
    render(<FaturaValidacaoPage />);
    expect(await screen.findByTestId("launch-grouped")).toHaveTextContent(/^5 linhas juntam-se em COMIDA STAFF 23%: 1 × 203,99\s€$/);
    expect(screen.queryByTestId("launch-planned")).toBeNull(); // a tabela só aparece com IEC
});

test("talão com IVA incluído: o aviso, os dois valores em cada linha e as notas escritas à mão", async () => {
    mockApi.get.mockResolvedValue({ data: { invoice: invoice({ doc_type: "FS", prices_include_vat: true, handwritten_notes: "Pago em dinheiro", discounts: [], ignored_lines: [],
        lines: [line({ id: 41, item: "Grão 200 g", quantity: 2, unit_price: 0.965, line_total: 1.93, vat_rate: 23, vat_code: null, net_total: 1.93, vat_included: 2.38 })] }), pingwin: null, suppliers: [] } });
    render(<FaturaValidacaoPage />);
    expect(await screen.findByTestId("vat-included")).toHaveTextContent("Preços com IVA incluído: convertidos para valores sem IVA.");
    expect(screen.getByTestId("line-vat-included-0")).toHaveTextContent(/Com IVA 2,38\s€ · sem IVA 1,93\s€/);
    expect(screen.getByTestId("handwritten-notes")).toHaveTextContent("Notas escritas à mão: Pago em dinheiro");
    expect(screen.getByTestId("doc-type")).toHaveTextContent("Fatura simplificada");
});

test("fatura normal: sem o aviso do IVA incluído nem notas", async () => {
    render(<FaturaValidacaoPage />);
    await screen.findByTestId("launch-guards");
    expect(screen.queryByTestId("vat-included")).toBeNull();
    expect(screen.queryByTestId("line-vat-included-0")).toBeNull();
    expect(screen.queryByTestId("handwritten-notes")).toBeNull();
});
