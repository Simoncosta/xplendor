import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import RegrasClassificacaoPage from "./RegrasClassificacaoPage";

/** Finanças › Configuração › Regras de classificação: a lista, o histórico, criar, desativar e "Criar regra" vindo do mês. */
const mockApi = { list: jest.fn(), create: jest.fn(), update: jest.fn(), remove: jest.fn() };
let mockQuery = "";
let mockParams = new URLSearchParams();
// O mesmo objeto em todos os renders (como o router faz).
jest.mock("react-router-dom", () => ({
    useSearchParams: () => [mockParams, jest.fn()],
    Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a>,
}), { virtual: true });
jest.mock("helpers/laravel_helper", () => ({
    getFinanceRules: (...a: unknown[]) => mockApi.list(...a),
    createFinanceRule: (...a: unknown[]) => mockApi.create(...a),
    updateFinanceRule: (...a: unknown[]) => mockApi.update(...a),
    deleteFinanceRule: (...a: unknown[]) => mockApi.remove(...a),
}));
jest.mock("contexts/WorkingCompanyContext", () => ({ useWorkingCompanyId: () => 5 }));
jest.mock("Components/Common/PageHeader", () => ({ __esModule: true, default: ({ title }: any) => <h1>{title}</h1> }));
jest.mock("Components/Common/InfoTip", () => ({ __esModule: true, default: () => null }));
jest.mock("Components/Common/Select", () => ({
    __esModule: true,
    default: ({ ariaLabel, options, value, onChange }: any) => (
        <select aria-label={ariaLabel} value={value ?? ""} onChange={(e) => onChange(options.find((o: any) => String(o.value) === e.target.value)?.value)}>
            <option value="">—</option>{options.map((o: any) => <option key={String(o.value)} value={o.value}>{o.label}</option>)}
        </select>
    ),
}));
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() } }));

const payload = {
    rules: [{ id: 1, match_type: "artigo", match_values: ["100833", "100834", "100835"], match_label: "Artigos 100833 a 100835", entity_pingwin_id: "E-FOLHA",
        entity_name: "FOLHA DE OBRIGAÇÕES", group: "pessoal", active: true, priority: 10, label: "Artigos 100833 a 100835 de FOLHA DE OBRIGAÇÕES → Pessoal", updated_at: null }],
    events: [{ id: 1, rule_id: 1, action: "criar", before: null, after: {}, user: null, reason: "Migração: a folha de obrigações é Pessoal.", at: "2026-10-19T10:00:00+01:00" }],
    options: {
        groups: [{ value: "pessoal", label: "Pessoal" }, { value: "fse", label: "Fornecimentos e serviços externos" }],
        entities: [{ value: "E-FOLHA", label: "FOLHA DE OBRIGAÇÕES" }, { value: "E-EDP", label: "EDP" }],
        doc_types: [{ value: "777001", label: "Despesa de salários" }],
        families: [{ value: "COMPRAS › COMIDAS", label: "COMPRAS › COMIDAS" }],
    },
};

beforeEach(() => {
    Object.values(mockApi).forEach((f) => f.mockReset());
    mockQuery = "";
    mockParams = new URLSearchParams();
    mockApi.list.mockResolvedValue({ data: payload });
});

test("mostra as regras e o histórico; desativar guarda", async () => {
    mockApi.update.mockResolvedValue({ message: "Regra desativada." });
    render(<RegrasClassificacaoPage />);
    const row = await screen.findByTestId("rule-1");
    expect(row).toHaveTextContent("Artigos 100833 a 100835 de FOLHA DE OBRIGAÇÕES → Pessoal");
    expect(row).toHaveTextContent("Artigo (código): 100833, 100834, 100835 · só de FOLHA DE OBRIGAÇÕES");
    expect(screen.getByTestId("rules-history")).toHaveTextContent("Migração: a folha de obrigações é Pessoal.");
    fireEvent.click(within(row).getByText("Desativar"));
    await waitFor(() => expect(mockApi.update).toHaveBeenCalledWith(5, 1, { active: false }));
});

test("criar uma regra por entidade", async () => {
    mockApi.create.mockResolvedValue({ data: {} });
    render(<RegrasClassificacaoPage />);
    fireEvent.click(await screen.findByTestId("rule-new"));
    const modal = screen.getByTestId("rule-modal");
    fireEvent.change(within(modal).getByLabelText("Quando"), { target: { value: "entidade" } });
    fireEvent.change(within(modal).getByLabelText("Entidade"), { target: { value: "E-EDP" } });
    fireEvent.change(within(modal).getByLabelText("Grupo"), { target: { value: "fse" } });
    fireEvent.click(within(modal).getByTestId("rule-save"));
    await waitFor(() => expect(mockApi.create).toHaveBeenCalledWith(5, expect.objectContaining({ match_type: "entidade", match_values: ["E-EDP"], group: "fse", entity_name: "EDP" })));
});

test("Criar regra vindo do mês: o tipo de documento novo já vem preenchido", async () => {
    mockQuery = "tipo=tipo_documento&valor=777001&rotulo=Despesa%20de%20sal%C3%A1rios";
    mockParams = new URLSearchParams(mockQuery);
    mockApi.create.mockResolvedValue({ data: {} });
    render(<RegrasClassificacaoPage />);
    const modal = await screen.findByTestId("rule-modal");
    expect(within(modal).getByLabelText("Quando")).toHaveValue("tipo_documento");
    expect(within(modal).getByLabelText("Valor")).toHaveValue("777001");
    fireEvent.change(within(modal).getByLabelText("Grupo"), { target: { value: "pessoal" } });
    fireEvent.click(within(modal).getByTestId("rule-save"));
    await waitFor(() => expect(mockApi.create).toHaveBeenCalledWith(5, expect.objectContaining({ match_type: "tipo_documento", match_values: ["777001"], group: "pessoal",
        match_label: "Tipo de documento Despesa de salários" })));
});
