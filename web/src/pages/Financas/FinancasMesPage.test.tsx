import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import FinancasMesPage, { lastMonth } from "./FinancasMesPage";
import { summarySentence } from "./mes/ResumoTab";
import FechoMesPage from "./FechoMesPage";
import { documentsCsv } from "./mes/DocumentosTab";

/**
 * Finanças › Mês por camadas: o Resumo (o estado do mês, os cartões, os grupos e os 6 meses), o detalhe
 * no painel (o resultado por grupo e família, um grupo, o dinheiro), o Trabalho do mês só com Finanças ›
 * Editar (os contadores, os documentos com a classificação rápida e o "Fechar no PingWin", os pagamentos,
 * o envio à contabilidade e os habituais em falta) e o fecho do mês para imprimir.
 */
const mockNavigate = jest.fn();
const mockApi = { month: jest.fn(), classify: jest.fn(), close: jest.fn(), batch: jest.fn(), sent: jest.fn(), pay: jest.fn(), payables: jest.fn(),
    exclude: jest.fn(), restore: jest.fn(), preview: jest.fn(), send: jest.fn(), importSheet: jest.fn(), files: jest.fn(), zip: jest.fn(), sheet: jest.fn(), undo: jest.fn(), unsent: jest.fn(), edit: jest.fn() };
jest.mock("react-router-dom", () => {
    const React = jest.requireActual("react");
    return {
        useNavigate: () => mockNavigate,
        useSearchParams: () => { const [p, setP] = React.useState(new URLSearchParams("mes=2026-09")); return [p, (n: URLSearchParams) => setP(new URLSearchParams(n))]; },
        Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a>,
    };
}, { virtual: true });
jest.mock("helpers/laravel_helper", () => ({
    getFinanceMonth: (...a: unknown[]) => mockApi.month(...a),
    classifyFinanceDocument: (...a: unknown[]) => mockApi.classify(...a),
    closeSupplierDocuments: (...a: unknown[]) => mockApi.close(...a),
    getSupplierDocumentCloseBatch: (...a: unknown[]) => mockApi.batch(...a),
    markFinanceSent: (...a: unknown[]) => mockApi.sent(...a),
    unmarkFinanceSent: (...a: unknown[]) => mockApi.unsent(...a),
    registerFinancePayment: (...a: unknown[]) => mockApi.pay(...a),
    undoFinancePayment: (...a: unknown[]) => mockApi.undo(...a),
    getFinancePayables: (...a: unknown[]) => mockApi.payables(...a),
    previewAccountantEmail: (...a: unknown[]) => mockApi.preview(...a),
    sendAccountantEmail: (...a: unknown[]) => mockApi.send(...a),
    importAccountingSheet: (...a: unknown[]) => mockApi.importSheet(...a),
    getFinanceAccountingFiles: (...a: unknown[]) => mockApi.files(...a),
    downloadFinanceZip: (...a: unknown[]) => mockApi.zip(...a),
    downloadFinanceSheet: (...a: unknown[]) => mockApi.sheet(...a),
    editFinanceDocument: (...a: unknown[]) => mockApi.edit(...a),
    excludeHabitual: (...a: unknown[]) => mockApi.exclude(...a),
    restoreHabitual: (...a: unknown[]) => mockApi.restore(...a),
}));
jest.mock("contexts/WorkingCompanyContext", () => ({ useWorkingCompanyId: () => 5 }));
jest.mock("Components/Common/PageHeader", () => ({ __esModule: true, default: ({ title }: any) => <h1>{title}</h1> }));
// O "?" mostra o texto como rótulo (para os testes lerem a explicação sem o tooltip).
jest.mock("Components/Common/InfoTip", () => ({ __esModule: true, default: ({ label, text }: any) => <span data-testid={`tip-${label}`} aria-label={label} title={typeof text === "string" ? text : undefined} /> }));
jest.mock("react-apexcharts", () => ({ __esModule: true, default: () => <div data-testid="chart" /> }));
jest.mock("Components/Common/Select", () => ({
    __esModule: true,
    default: ({ ariaLabel, options, value, onChange }: any) => (
        <select aria-label={ariaLabel} value={value ?? ""} onChange={(e) => onChange(options.find((o: any) => String(o.value) === e.target.value)?.value)}>
            <option value="">—</option>{options.map((o: any) => <option key={String(o.value)} value={o.value}>{o.label}</option>)}
        </select>
    ),
}));
jest.mock("pages/Restauracao/faturas/SupplierDocumentLinesModal", () => ({ __esModule: true, default: ({ docheaderId }: any) => <div data-testid="lines-modal">{docheaderId}</div> }));
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn(), info: jest.fn() }, ToastContainer: () => null }));

const payment = { state: "pago", label: "Pago em 28/09", paid_on: "2026-09-28", paid_estimated: false, open_cents: 0, days_overdue: 0 };
const doc = (o: any) => ({
    docheader_id: "D1", doc_date: "2026-09-03", supplier: "Carnes", entity_pingwin_id: "E-CA", doctype: "Fatura de fornecedor", docconfig_id: "1209", document: "DOC/D1",
    supplier_doc: null, group: "comida", group_label: "Comida", family: "COMPRAS › COMIDAS", families: ["COMPRAS › COMIDAS"], net_cents: 10000, vat_cents: 2300, total_cents: 12300,
    parts: [], unclassified_cents: 0, unclassified_reason: null, out_of_result: false, docstatus_id: "8002", docstatus: "Fechado", open: false, due_date: "2026-09-30",
    payment, ocr_invoice_id: null, close_problems: null, new_type: null, tax_number: "501111111", has_file: false,
    accounting: { situation: "Nota de liquidação", situation_manual: false, sent_on: null, send_method: null, send_method_label: null, note: null, sent: false }, ...o,
});
const data = {
    month: "2026-09", month_label: "setembro de 2026", today: "2026-10-09",
    sentence: "Setembro de 2026: vendas de 5 000,00 €, gastos de 1 486,50 €, resultado de 3 513,50 € (70,3 %). Saíram 186,45 €; faltam pagar 258,30 € até ao fim do mês.",
    explain: "Gasto é quando a fatura chega; pago é quando o dinheiro sai.",
    warnings: ["Vendas: há vendas por artigo em 2 dos 30 dias do mês."],
    provisional: { count: 1, cents: 100000, text: "Provisório: faltam 1 documento habitual (cerca de 1 000,00 €).",
        missing: [{ entity_pingwin_id: "E-FOLHA", supplier: "FOLHA DE OBRIGAÇÕES", months: 2, avg_cents: 100000, last_document: "DOC/FO8", last_date: "2026-08-01" }] } as any,
    stores: [{ id: 1, name: "Baixa" }], store_id: null,
    cards: {
        sales: { cents: 500000, store_cents: null, days: 2, days_in_month: 30, source: "Vendas por artigo." },
        expenses: { cents: 148650, open_cents: 6150, open_count: 1, unclassified_cents: 3000, source: "Faturas." },
        result: { cents: 351350, pct: 70.3, prev_cents: 396000, prev_pct: 99, estimated_cents: 251350 as number | null, estimated_pct: 50.3 as number | null, source: "Vendas menos gastos." },
        cash: { paid_cents: 18645, due_by_month_end_cents: 25830, overdue_cents: 28290, source: "Conta corrente." },
    },
    result: {
        rows: [
            { kind: "grupo", group: "comida", label: "Comida", cents: 13000, pct: 2.6, prev_cents: 4000, prev_pct: 1, avg3_cents: 1333, avg3_pct: 1 },
            { kind: "familia", group: "comida", label: "COMPRAS › COMIDAS", cents: 13000, pct: 2.6, prev_cents: 4000, prev_pct: 1, avg3_cents: 1333, avg3_pct: 1 },
            { kind: "total", group: null, label: "Total dos gastos", cents: 148650, pct: 29.7, prev_cents: 4000, prev_pct: 1, avg3_cents: 1333, avg3_pct: 1 },
        ],
        out_of_result: [{ label: "COMPRAS › DIVERSOS › Cauções de embalagens - fornecedores", cents: 1000, reason: "Caução ou vasilhame: é um depósito, não um gasto." }],
        food_cost: { pct: 2.9, comida_pct: 1.6, bebida_pct: 1.3, avg3_pct: null, source: "O mesmo cálculo da Engenharia de menu." },
        chart: [{ month: "2026-09", label: "setembro de 2026", sales_cents: 500000, expenses_cents: 148650, result_cents: 351350 }],
        vat: { sales_vat_cents: 65000, purchases_vat_cents: 8625, estimate_cents: 56375, docs_without_vat: 2, note: "Estimativa; a declaração é do contabilista." },
        store_note: "O resultado é da empresa toda: por loja só as vendas, porque as compras entram no Economato.",
    },
    cash: {
        weeks: [{ from: "2026-09-01", to: "2026-09-06", in_cents: 565000, out_cents: 0, due_cents: 0 }], overdue_before_month_cents: 0,
        entries_note: "Entradas: as vendas com IVA, assumidas como recebidas no próprio dia. A separação entre cartão e numerário ainda não existe.",
        payments: [{ docheader_id: "D4", supplier: "EDP", document: "DOC/D4", doctype: "Fatura de fornecedor", doc_date: "2026-09-12", due_date: "2026-09-15", total_cents: 24600,
            state: "vencido", label: "Vencido há 24 dias", paid_on: null, paid_estimated: false, open_cents: 24600, days_overdue: 24 }],
        outflows: [], next_30_days: [{ from: "2026-10-09", to: "2026-10-15", cents: 0 }], overdue_cents: 28290, total_payable_cents: 125830,
        reconciliation: { screen_total_cents: 125830, cc_real_balance_cents: 125830, ok: true, mismatches: [], pingwin_balance_cents: 128175, auto_paid_difference_cents: 2345, note: "O por pagar é o saldo real." },
        paid_date_note: "A data de pagamento é a da nota com o mesmo valor.", cc_synced_at: "2026-10-09T12:00:00+01:00",
    },
    documents: {
        rows: [
            doc({ docheader_id: "D7", document: "DOC/D7", supplier: "Fornecedor novo", group: "por_classificar", group_label: "Por classificar", family: null, families: ["Por classificar"],
                unclassified_cents: 3000, unclassified_reason: "Linha sem artigo e o fornecedor não tem família habitual." }),
            doc({ docheader_id: "S9", document: "DOC/S9", supplier: "Fornecedor novo", docconfig_id: "777001", group: "por_classificar", group_label: "Por classificar", family: null,
                families: ["Por classificar"], unclassified_cents: 500000, unclassified_reason: "Tipo de documento novo (Despesa de salários): crie uma regra.",
                new_type: { docconfig_id: "777001", label: "Despesa de salários" } }),
            doc({ docheader_id: "FO8", document: "DOC/FO8", supplier: "FOLHA DE OBRIGAÇÕES", group: "pessoal", group_label: "Pessoal",
                parts: [{ group: "pessoal", group_label: "Pessoal", family: "OUTROS DEVEDORES/CREDORES › ESTADO › IMPOSTO DO SELO", pingwin_family: "OUTROS DEVEDORES/CREDORES › ESTADO › IMPOSTO DO SELO",
                    rule: { id: 1, label: "Artigos 100833 a 100835 de FOLHA DE OBRIGAÇÕES → Pessoal" }, cents: 100000, source: "regra", reason: null }],
                payment: { state: "vencido", label: "Vencido há 3 dias", paid_on: null, paid_estimated: false, open_cents: 100000, days_overdue: 3, payment: null } }),
            doc({ docheader_id: "D9", document: "DOC/D9", supplier: "Bebidas", open: true, docstatus_id: "8001", docstatus: "Aberto", total_cents: 6150, close_problems: [] }),
            doc({}),
        ],
        unclassified: [] as any[],
        open: { count: 1, total_cents: 6150, net_cents: 6150 },
    },
    groups: [{ key: "comida", label: "Comida" }, { key: "bebida", label: "Bebidas" }, { key: "fse", label: "Fornecimentos e serviços externos" }],
    can: { classify: true, close: true },
};
data.documents.unclassified = [data.documents.rows[0], data.documents.rows[1]];

beforeEach(() => {
    Object.values(mockApi).forEach((f) => f.mockReset());
    mockNavigate.mockReset();
    mockApi.month.mockResolvedValue({ data });
});

test("Resumo: o estado do mês provisório, os quatro números, os grupos e os 6 meses; o detalhe por família abre no painel", async () => {
    render(<FinancasMesPage />);
    // Provisório: falta a folha de obrigações; o resultado estimado ao lado do real. No Resumo, euros sem cêntimos.
    expect(await screen.findByTestId("finance-provisional")).toHaveTextContent(/Provisório\s*Faltam 1 documento habitual \(cerca de 1000\s€\)|Faltam 1 documento habitual \(cerca de 1 000\s€\)/);
    expect(screen.getByTestId("finance-status")).toHaveTextContent("Setembro de 2026");
    expect(screen.getByTestId("finance-warnings")).toHaveTextContent("2 dos 30 dias");
    expect(screen.getByTestId("card-sales")).toHaveTextContent(/5000\s€|5 000\s€/);
    expect(screen.getByTestId("card-expenses-open")).toHaveTextContent(/Inclui 1 documento em aberto \(62\s€\)/);
    expect(screen.getByTestId("card-result-estimated")).toHaveTextContent(/2514\s€|2 514\s€/);
    expect(screen.getByTestId("card-result-estimated")).toHaveTextContent("estimado");
    expect(screen.getByTestId("card-result")).toHaveTextContent(/Real até agora: 3514\s€|Real até agora: 3 514\s€/);
    expect(screen.getByTestId("card-cash")).toHaveTextContent(/Vencido\s*283\s€/);
    expect(screen.getByTestId("donut-group-comida")).toHaveTextContent(/Comida\s*130\s€\s*2,6\s%/);
    expect(screen.getByTestId("donut-total")).toHaveTextContent(/Custos\s*1487\s€|Custos\s*1 487\s€/);
    expect(screen.getAllByTestId("chart")).toHaveLength(2); // o donut e os 6 meses
    // A frase do topo, numa linha, com os números dos cartões.
    expect(screen.getByTestId("finance-summary-sentence")).toHaveTextContent(
        /^Em setembro de 2026 vendeu 5\s?000\s€\. Os custos lançados são 1\s?487\s€, faltam cerca de 1\s?000\s€ em documentos habituais e o resultado estimado é 2\s?514\s€\.$/);
    expect(mockApi.month).toHaveBeenCalledWith(5, { month: "2026-09", store: null });
    // O detalhe: grupo e família, food cost, IVA estimado, fora do resultado.
    fireEvent.click(screen.getByTestId("detail-resultado"));
    const panel = await screen.findByTestId("detail-panel");
    expect(within(panel).getByTestId("result-group-comida")).toHaveTextContent("Comida");
    expect(within(panel).getByTestId("result-food-cost")).toHaveTextContent("2,9 %");
    expect(within(panel).getByTestId("result-vat")).toHaveTextContent("Estimativa; a declaração é do contabilista.");
    expect(within(panel).getByTestId("result-out")).toHaveTextContent("Cauções de embalagens");
    expect(within(panel).getByText(data.result.store_note)).toBeInTheDocument();
});

test("Resumo de um mês sem habituais em falta: Completo (não é um fecho) e só o resultado real", async () => {
    mockApi.month.mockResolvedValue({ data: { ...data, provisional: null, cards: { ...data.cards, result: { ...data.cards.result, estimated_cents: null, estimated_pct: null } } } });
    render(<FinancasMesPage />);
    expect(await screen.findByTestId("finance-complete")).toHaveTextContent("Completo");
    expect(screen.queryByText("Fechado")).toBeNull();
    expect(screen.getByTestId("tip-De onde vem: Completo")).toHaveAttribute("title", "Não falta nenhum documento habitual.");
    expect(screen.getByTestId("finance-summary-sentence")).toHaveTextContent(/^Em setembro de 2026 vendeu 5\s?000\s€, os custos foram 1\s?487\s€ e o resultado é 3\s?514\s€\.$/);
    expect(screen.queryByTestId("finance-provisional")).toBeNull();
    expect(screen.queryByTestId("card-result-estimated")).toBeNull();
    expect(screen.getByTestId("card-result-real")).toHaveTextContent(/3514\s€|3 514\s€/);
});

test("um grupo abre o detalhe: as famílias em barras, os 5 fornecedores e os documentos", async () => {
    render(<FinancasMesPage />);
    fireEvent.click(await screen.findByTestId("donut-group-comida"));
    const g = await screen.findByTestId("group-detail-comida");
    expect(within(g).getByTestId("group-families")).toHaveTextContent("COMPRAS › COMIDAS");
    expect(within(g).getByTestId("group-suppliers")).toHaveTextContent("Carnes");
    expect(within(g).getByTestId("group-docs")).toHaveTextContent("DOC/D1");
    expect(within(g).getByTestId("group-food-cost")).toHaveTextContent("1,6 %");
});

test("sem Finanças › Editar: só o Resumo; os links antigos dos Documentos abrem o painel, sem o Trabalho do mês", async () => {
    mockApi.month.mockResolvedValue({ data: { ...data, can: { classify: false, close: false } } });
    render(<FinancasMesPage />);
    await screen.findByTestId("finance-summary");
    expect(screen.queryByTestId("finance-tabs")).toBeNull();
    expect(screen.queryByText("Trabalho do mês")).toBeNull();
    expect(screen.queryByTestId("finance-work")).toBeNull();
    // "Ver quais" mostra a lista ali mesmo, sem os botões de editar.
    fireEvent.click(screen.getByTestId("see-missing"));
    expect(screen.getByTestId("finance-missing")).toHaveTextContent("FOLHA DE OBRIGAÇÕES");
    expect(screen.queryByText("Não vem este mês")).toBeNull();
    // O Dinheiro abre a tesouraria só para ver.
    fireEvent.click(screen.getByTestId("detail-dinheiro"));
    expect(await screen.findByTestId("cash-weeks")).toBeInTheDocument();
    expect(screen.queryByTestId("regularize")).toBeNull();
});

test("Trabalho do mês: os contadores abrem a lista filtrada", async () => {
    render(<FinancasMesPage />);
    fireEvent.click(await screen.findByTestId("finance-tab-trabalho"));
    expect(screen.getByTestId("work-por_classificar")).toHaveTextContent(/Por classificar.*2/);
    expect(screen.getByTestId("work-vencido")).toHaveTextContent(/Vencidos.*1/);
    expect(screen.getByTestId("work-habituais")).toHaveTextContent(/Habituais em falta.*1/);
    fireEvent.click(screen.getByTestId("work-vencido"));
    expect(screen.getByLabelText("Estado")).toHaveValue("vencido");
    expect(screen.getByTestId("doc-FO8")).toBeInTheDocument();
    expect(screen.queryByTestId("doc-D1")).toBeNull();
    fireEvent.click(screen.getByTestId("work-por_classificar"));
    expect(screen.getByLabelText("Família")).toHaveValue("por_classificar");
    expect(screen.getByTestId("doc-D7")).toBeInTheDocument();
    expect(screen.queryByTestId("doc-FO8")).toBeNull();
    fireEvent.click(screen.getByTestId("work-sec-fecho"));
    fireEvent.click(screen.getByTestId("work-close-generate"));
    expect(mockNavigate).toHaveBeenCalledWith("/financas/mes/fecho?mes=2026-09");
});

test("Tesouraria: semanas, pagamentos com os vencidos e a conferência com a conta corrente", async () => {
    render(<FinancasMesPage />);
    fireEvent.click(await screen.findByTestId("finance-tab-trabalho"));
    fireEvent.click(screen.getByTestId("work-sec-pagamentos"));
    expect(screen.getByText(data.cash.entries_note)).toBeInTheDocument();
    expect(screen.getByTestId("cash-weeks")).toHaveTextContent("01/09 a 06/09");
    expect(screen.getByTestId("cash-payments")).toHaveTextContent("Vencido há 24 dias");
    expect(screen.getByTestId("cash-reconciliation")).toHaveTextContent("Bate, fornecedor a fornecedor.");
    fireEvent.click(screen.getByText("DOC/D4"));
    expect(screen.getByTestId("lines-modal")).toHaveTextContent("D4");
});

test("Documentos: por classificar no topo com a classificação rápida", async () => {
    mockApi.classify.mockResolvedValue({ message: "Classificado: sempre assim para Fornecedor novo." });
    render(<FinancasMesPage />);
    fireEvent.click(await screen.findByTestId("finance-tab-trabalho"));
    const box = screen.getByTestId("docs-unclassified");
    expect(box).toHaveTextContent("Linha sem artigo e o fornecedor não tem família habitual.");
    fireEvent.change(within(box).getByLabelText("Grupo de DOC/D7"), { target: { value: "fse" } });
    fireEvent.click(within(box).getByText("Sempre para este fornecedor"));
    await waitFor(() => expect(mockApi.classify).toHaveBeenCalledWith(5, { scope: "fornecedor", docheader_id: "D7", group: "fse" }));
    await waitFor(() => expect(mockApi.month).toHaveBeenCalledTimes(2)); // volta a carregar
});

test("Documentos em Aberto: destacados, com o aviso e o Fechar no PingWin", async () => {
    mockApi.close.mockResolvedValue({ data: { batch: "B1", closes: [{ id: 1, docheader_id: "D9", document: "DOC/D9", status: "pendente" }] } });
    render(<FinancasMesPage />);
    fireEvent.click(await screen.findByTestId("finance-tab-trabalho"));
    expect(screen.getByTestId("docs-open")).toHaveTextContent(/Em aberto: 1 documento \(61,50\s€\) ainda não estão fechados no PingWin; não contam no stock./);
    expect(screen.getByTestId("doc-D9")).toHaveClass("table-warning");
    fireEvent.click(within(screen.getByTestId("doc-D9")).getByText("Fechar no PingWin"));
    expect(screen.getByTestId("close-docs-warning")).toHaveTextContent("Depois de fechada, a fatura entra no stock e na conta corrente. Para corrigir, é preciso anular.");
    fireEvent.click(screen.getByTestId("close-docs-confirm"));
    await waitFor(() => expect(mockApi.close).toHaveBeenCalledWith(5, ["D9"]));
    expect(await screen.findByText("A fechar…")).toBeInTheDocument();
});

test("Gerar fecho do mês abre a página para imprimir", async () => {
    render(<FinancasMesPage />);
    await screen.findByTestId("finance-status");
    fireEvent.click(screen.getByTestId("finance-close-month"));
    expect(mockNavigate).toHaveBeenCalledWith("/financas/mes/fecho?mes=2026-09");
});

test("o fecho do mês: o Resumo primeiro (em tabelas) e depois o Resultado, para imprimir ou guardar em PDF", async () => {
    const print = jest.fn();
    window.print = print;
    render(<FechoMesPage />);
    expect(await screen.findByText("Fecho de setembro de 2026")).toBeInTheDocument();
    expect(screen.getByTestId("print-provisional")).toHaveTextContent("Provisório");
    expect(screen.getByTestId("finance-missing")).toHaveTextContent("FOLHA DE OBRIGAÇÕES");
    expect(screen.getByTestId("finance-summary-sentence")).toHaveTextContent("Em setembro de 2026 vendeu");
    expect(screen.queryByTestId("print-complete")).toBeNull();
    expect(screen.getByTestId("donut-legend")).toHaveTextContent("Comida");
    expect(screen.getByTestId("resumo-months")).toHaveTextContent("Setembro de 2026");
    expect(screen.getByTestId("result-table")).toHaveTextContent("Total dos gastos");
    // O Resumo vem antes do Resultado.
    expect(screen.getByTestId("finance-summary").compareDocumentPosition(screen.getByTestId("result-table")) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(screen.queryByTestId("chart")).toBeNull();
    fireEvent.click(screen.getByTestId("finance-print-button"));
    expect(print).toHaveBeenCalled();
});

test("CSV dos documentos e o mês por omissão", () => {
    const csv = documentsCsv([doc({}) as any]);
    expect(csv.split("\n")[0]).toBe("Data;Fornecedor;Tipo;Número;Família;Grupo;Sem IVA;IVA;Total;Estado no PingWin;Pagamento;Vencimento");
    expect(csv.split("\n")[1]).toBe("2026-09-03;Carnes;Fatura de fornecedor;DOC/D1;COMPRAS › COMIDAS;Comida;100,00;23,00;123,00;Fechado;Pago em 28/09;2026-09-30");
    expect(lastMonth(new Date(2026, 9, 19))).toBe("2026-09");
    expect(lastMonth(new Date(2026, 0, 5))).toBe("2025-12");
});

test("Documentos: a regra aplicada com a família do PingWin, Criar regra para o tipo novo e as colunas da contabilidade", async () => {
    render(<FinancasMesPage />);
    fireEvent.click(await screen.findByTestId("finance-tab-trabalho"));
    expect(screen.getByTestId("rule-FO8")).toHaveTextContent("Regra: Artigos 100833 a 100835 de FOLHA DE OBRIGAÇÕES → Pessoal · no PingWin: OUTROS DEVEDORES/CREDORES › ESTADO › IMPOSTO DO SELO");
    expect(screen.getByTestId("create-rule-S9")).toHaveAttribute("href", "/financas/configuracao/regras?tipo=tipo_documento&valor=777001&rotulo=Despesa%20de%20sal%C3%A1rios");
    const table = screen.getByTestId("docs-table");
    for (const h of ["Forma de pagamento", "Pago em", "Enviado em", "Forma de envio", "Nota"]) expect(within(table).getByText(h)).toBeInTheDocument();
    expect(within(screen.getByTestId("doc-D1")).getByText("Por enviar")).toBeInTheDocument();
    // Filtro "Por enviar à contabilidade".
    fireEvent.change(screen.getByLabelText("Estado"), { target: { value: "por_enviar" } });
    expect(screen.getByTestId("doc-D1")).toBeInTheDocument();
});

test("Marcar como enviado e registar um pagamento nos escolhidos", async () => {
    mockApi.sent.mockResolvedValue({ message: "1 documentos marcados." });
    mockApi.pay.mockResolvedValue({ message: "Pagamento registado na XPLENDOR." });
    render(<FinancasMesPage />);
    fireEvent.click(await screen.findByTestId("finance-tab-trabalho"));
    fireEvent.click(screen.getByLabelText("Escolher DOC/FO8"));
    fireEvent.click(screen.getByTestId("mark-sent"));
    fireEvent.click(screen.getByTestId("sent-confirm"));
    await waitFor(() => expect(mockApi.sent).toHaveBeenCalledWith(5, expect.objectContaining({ docheader_ids: ["FO8"], send_method: "email" })));

    fireEvent.click(screen.getByLabelText("Escolher DOC/FO8"));
    fireEvent.click(within(screen.getByTestId("docs-selection")).getByText("Registar pagamento"));
    const modal = screen.getByTestId("pay-modal");
    expect(within(modal).getByText("Lançar a nota de liquidação no PingWin")).toBeDisabled();
    expect(within(modal).getByLabelText(/Disponível em breve/)).toBeInTheDocument();
    fireEvent.change(within(modal).getByLabelText("Forma de pagamento"), { target: { value: "transferencia" } });
    fireEvent.click(within(modal).getByTestId("pay-confirm"));
    await waitFor(() => expect(mockApi.pay).toHaveBeenCalledWith(5, expect.objectContaining({ docheader_ids: ["FO8"], method: "transferencia" })));
});

test("Enviar ao contabilista: a pré-visualização primeiro e o envio só com o clique", async () => {
    mockApi.preview.mockResolvedValue({ data: { to: "contas@example.com", subject: "Resto: documentos de setembro de 2026", lines: ["Seguem os documentos."],
        documents: [{ docheader_id: "D1", number: "FT A/77", supplier: "Carnes", date: "03/09/2026", value: "123,00 €" }],
        attachments: [{ name: "contabilidade-2026-09.xlsx", bytes: 8000 }], missing: [{ docheader_id: "D1", document: "DOC/D1", supplier: "Carnes" }], bytes: 8000, can_send: true, problems: [] } });
    mockApi.send.mockResolvedValue({ message: "Enviado." });
    render(<FinancasMesPage />);
    fireEvent.click(await screen.findByTestId("finance-tab-trabalho"));
    fireEvent.click(screen.getByTestId("work-sec-contabilidade"));
    expect(screen.getByLabelText("Estado")).toHaveValue("por_enviar");
    fireEvent.click(screen.getByLabelText("Escolher DOC/D1"));
    fireEvent.click(screen.getByTestId("send-accountant"));
    const preview = await screen.findByTestId("email-preview");
    expect(preview).toHaveTextContent("contas@example.com");
    expect(preview).toHaveTextContent("Resto: documentos de setembro de 2026");
    expect(preview).toHaveTextContent("contabilidade-2026-09.xlsx");
    expect(preview).toHaveTextContent("Sem ficheiro na XPLENDOR: DOC/D1.");
    expect(mockApi.send).not.toHaveBeenCalled();
    fireEvent.click(screen.getByTestId("email-send"));
    await waitFor(() => expect(mockApi.send).toHaveBeenCalledWith(5, { month: "2026-09", docheader_ids: ["D1"] }));
});

test("Importar a folha antiga mostra o que não emparelhou", async () => {
    mockApi.importSheet.mockResolvedValue({ data: { matched: 1, unmatched: [{ row: 8, nif: "999999990", titular: "Desconhecido", number: "FT 9/9", reason: "Sem documento com este NIF e este número." }] } });
    render(<FinancasMesPage />);
    fireEvent.click(await screen.findByTestId("finance-tab-trabalho"));
    expect(screen.queryByTestId("import-sheet")).toBeNull(); // a barra da contabilidade fica no Envio à contabilidade
    fireEvent.click(screen.getByTestId("work-sec-contabilidade"));
    fireEvent.click(screen.getByTestId("import-sheet"));
    fireEvent.change(screen.getByLabelText("Folha em XLSX"), { target: { files: [new File(["x"], "folha.xlsx")] } });
    fireEvent.click(screen.getByTestId("import-confirm"));
    const res = await screen.findByTestId("import-result");
    expect(res).toHaveTextContent("1 linhas emparelhadas; 1 sem par.");
    expect(res).toHaveTextContent("FT 9/9");
});

test("Tesouraria: Regularizar vários documentos de um fornecedor com um só pagamento", async () => {
    mockApi.payables.mockResolvedValue({ data: { suppliers: [{ supplier_id: 3, supplier: "EDP", entity_pingwin_id: "E-EDP", total_cents: 49200, overdue_cents: 24600, documents: [
        { docheader_id: "D4", document: "DOC/D4", doc_date: "2026-09-12", due_date: "2026-09-15", open_cents: 24600, overdue: true },
        { docheader_id: "D44", document: "DOC/D44", doc_date: "2026-10-12", due_date: "2026-10-30", open_cents: 24600, overdue: false }] }] } });
    mockApi.pay.mockResolvedValue({ message: "2 pagamentos registados na XPLENDOR." });
    render(<FinancasMesPage />);
    fireEvent.click(await screen.findByTestId("finance-tab-trabalho"));
    fireEvent.click(screen.getByTestId("work-sec-pagamentos"));
    fireEvent.click(screen.getByTestId("regularize"));
    fireEvent.change(await screen.findByLabelText("Fornecedor"), { target: { value: "3" } });
    fireEvent.click(screen.getByLabelText("Escolher todos"));
    fireEvent.click(screen.getByTestId("regularize-next"));
    const modal = screen.getByTestId("pay-modal");
    expect(modal).toHaveTextContent(/492,00\s€/);
    fireEvent.change(within(modal).getByLabelText("Forma de pagamento"), { target: { value: "debito_direto" } });
    fireEvent.click(within(modal).getByTestId("pay-confirm"));
    await waitFor(() => expect(mockApi.pay).toHaveBeenCalledWith(5, expect.objectContaining({ docheader_ids: ["D4", "D44"], method: "debito_direto" })));
});

test("Documentos habituais em falta: Não vem este mês pede confirmação e recalcula", async () => {
    mockApi.exclude.mockResolvedValue({ message: "FOLHA DE OBRIGAÇÕES: não vem este mês." });
    render(<FinancasMesPage />);
    await screen.findByTestId("finance-provisional");
    fireEvent.click(screen.getByTestId("see-missing")); // com Finanças › Editar: Trabalho do mês › Habituais em falta
    expect(screen.getByTestId("work-sec-habituais")).toHaveClass("active");
    fireEvent.click(within(screen.getByTestId("missing-E-FOLHA")).getByText("Não vem este mês"));
    const modal = screen.getByTestId("habitual-modal");
    expect(modal).toHaveTextContent("FOLHA DE OBRIGAÇÕES sai da lista só em setembro de 2026.");
    fireEvent.change(within(modal).getByLabelText("Motivo (opcional)"), { target: { value: "Vem em outubro" } });
    fireEvent.click(within(modal).getByTestId("habitual-confirm"));
    await waitFor(() => expect(mockApi.exclude).toHaveBeenCalledWith(5, { entity_pingwin_id: "E-FOLHA", scope: "mes", month: "2026-09", avg_cents: 100000, reason: "Vem em outubro" }));
    await waitFor(() => expect(mockApi.month).toHaveBeenCalledTimes(2)); // recalcula logo
});

test("Não é habitual e repor, em Trabalho do mês › Habituais em falta", async () => {
    mockApi.month.mockResolvedValue({ data: { ...data, habitual_excluded: [{ id: 4, entity_pingwin_id: "E-X", supplier: "WELCOME", scope: "sempre", month: "2026-09", avg_cents: 3500000, reason: null, by: "Root", at: null }] } });
    mockApi.exclude.mockResolvedValue({ message: "ok" });
    mockApi.restore.mockResolvedValue({ message: "WELCOME volta a contar como habitual." });
    render(<FinancasMesPage />);
    fireEvent.click(await screen.findByTestId("see-missing"));
    expect(screen.getByTestId("finance-excluded")).toHaveTextContent("WELCOME (não é habitual, por Root)");
    fireEvent.click(within(screen.getByTestId("finance-excluded")).getByText("Repor"));
    await waitFor(() => expect(mockApi.restore).toHaveBeenCalledWith(5, 4));
    fireEvent.click(within(screen.getByTestId("missing-E-FOLHA")).getByText("Não é habitual"));
    expect(screen.getByTestId("habitual-modal")).toHaveTextContent("deixa de entrar nos documentos habituais a partir de setembro de 2026");
    fireEvent.click(screen.getByTestId("habitual-confirm"));
    await waitFor(() => expect(mockApi.exclude).toHaveBeenCalledWith(5, expect.objectContaining({ scope: "sempre", reason: null })));
});

test("o fecho do mês deixa de dizer Provisório quando não falta nenhum documento habitual", async () => {
    mockApi.month.mockResolvedValue({ data: { ...data, provisional: null, cards: { ...data.cards, result: { ...data.cards.result, estimated_cents: null, estimated_pct: null } } } });
    render(<FechoMesPage />);
    expect(await screen.findByText("Fecho de setembro de 2026")).toBeInTheDocument();
    expect(screen.queryByTestId("print-provisional")).toBeNull();
    expect(screen.getByTestId("print-complete")).toHaveTextContent("Completo");
    expect(screen.getByTestId("print-complete")).toHaveAttribute("title", "Não falta nenhum documento habitual.");
    expect(screen.queryByText("Fechado")).toBeNull();
    expect(screen.queryByTestId("finance-provisional")).toBeNull();
    expect(screen.queryByTestId("card-result-estimated")).toBeNull();
});

test("a frase do topo: com uma loja escolhida e com resultado negativo, provisório e completo", () => {
    const store = { ...data.cards.sales, store_cents: 120000 };
    // Provisório: a loja, os custos lançados da empresa toda, os habituais em falta e o estimado negativo.
    const prov = { ...data, cards: { ...data.cards, sales: store, result: { ...data.cards.result, estimated_cents: -50000 } } } as any;
    expect(summarySentence(prov, "Baixa")).toMatch(
        /^Em setembro de 2026, Baixa vendeu 1\s?200\s€\. Na empresa toda, os custos lançados são 1\s?487\s€, faltam cerca de 1\s?000\s€ em documentos habituais e o resultado estimado é negativo, -500\s€\.$/);
    // Completo: sem os habituais, o resultado real.
    const done = { ...data, provisional: null, cards: { ...data.cards, sales: store, result: { ...data.cards.result, cents: -20000, estimated_cents: null, estimated_pct: null } } } as any;
    expect(summarySentence(done, "Baixa")).toMatch(/^Em setembro de 2026, Baixa vendeu 1\s?200\s€; na empresa toda, os custos foram 1\s?487\s€ e o resultado é negativo, -200\s€\.$/);
    expect(summarySentence(done, null)).toMatch(/^Em setembro de 2026 vendeu 5\s?000\s€, os custos foram 1\s?487\s€ e o resultado é negativo, -200\s€\.$/);
});
