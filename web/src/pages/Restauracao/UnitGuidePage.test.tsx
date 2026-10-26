import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import UnitGuidePage from "./UnitGuidePage";

const mockQueue = jest.fn();
const mockView = jest.fn();
const mockPreview = jest.fn();
const mockConfirm = jest.fn();
const mockSkip = jest.fn();
const mockAdmin = jest.fn();
const mockAi = jest.fn();
const mockAll = jest.fn();
const mockUndo = jest.fn();
const mockNavigate = jest.fn();
let mockInitialSearch = "";
// O Jest deste projeto não resolve o react-router-dom: os parâmetros do URL ficam num estado local.
jest.mock("react-router-dom", () => {
    const React = jest.requireActual("react");
    return {
        useSearchParams: () => { const [p, setP] = React.useState(() => new URLSearchParams(mockInitialSearch)); return [p, (next: URLSearchParams) => setP(new URLSearchParams(next))]; },
        useNavigate: () => mockNavigate,
        Link: ({ to, children, className }: any) => <a href={to} className={className}>{children}</a>,
    };
}, { virtual: true });
jest.mock("helpers/laravel_helper", () => ({
    getUnitGuideQueue: (...a: unknown[]) => mockQueue(...a), getUnitGuide: (...a: unknown[]) => mockView(...a), previewUnitGuide: (...a: unknown[]) => mockPreview(...a),
    confirmUnitGuide: (...a: unknown[]) => mockConfirm(...a), skipUnitGuide: (...a: unknown[]) => mockSkip(...a), sendAdminUnitGuide: (...a: unknown[]) => mockAdmin(...a),
    askAiUnitGuide: (...a: unknown[]) => mockAi(...a), adjustUnitGuide: jest.fn(),
    confirmAllUnitGuide: (...a: unknown[]) => mockAll(...a), undoUnitGuideBatch: (...a: unknown[]) => mockUndo(...a), getGlobalUnitGuide: jest.fn(), notApplicableUnitGuide: jest.fn(), infoUnitGuide: jest.fn(),
    applyUnitGuideStep: jest.fn(), getUnitGuideWrite: jest.fn(),
}));
jest.mock("contexts/WorkingCompanyContext", () => ({ useWorkingCompanyId: () => 5 }));
jest.mock("contexts/ModulesContext", () => ({ useCan: () => [true, null] }));
jest.mock("Components/Common/PageHeader", () => ({ __esModule: true, default: () => null }));
jest.mock("Components/Common/InfoTip", () => ({ __esModule: true, default: () => null }));
jest.mock("pages/Auditoria/AuditButton", () => ({ __esModule: true, default: () => null }));
jest.mock("pages/Restauracao/fichas/RecipeEditor", () => ({ __esModule: true, default: () => <div data-testid="recipe-editor" /> }));

const queue = { data: { total: 3, counts: { por_identificar: 1, administrador: 0, mudar: 1, ajuda: 1, certo: 1 }, financial: { visible: true }, items: [
    { verdict: "mudar", verdict_text: "Converter as fichas para quilograma.", product_id: "100", label: "100 · Café em grão", family: "COMPRAS › CAFÉ", spend_90d_eur: 300, state: "sugerido", por_identificar: false, confidence: 0.9, findings: 1, impact_cents: 3000, pending_cents: null, assigned: false, skipped: false },
    { verdict: "ajuda", verdict_text: "Responda: como chega?", product_id: "200", label: "200 · Açúcar", family: "COMPRAS › MERCEARIA", spend_90d_eur: 39, state: "por_identificar", por_identificar: true, confidence: 0.4, findings: 1, impact_cents: null, pending_cents: 3900, assigned: false, skipped: false },
    { verdict: "certo", verdict_text: "Compra-se ao kg e usa-se ao kg nas fichas.", product_id: "300", label: "300 · Bife", family: "CARNES", spend_90d_eur: 34255, state: "sugerido", por_identificar: false, confidence: 1, findings: 0, impact_cents: null, pending_cents: null, assigned: false, skipped: false },
] } };

const question = { id: "uso", kind: "choice", text: "Cafe: como se mede nas fichas?", options: [{ value: "dose_peso", label: "Em gramas" }, { value: "peca", label: "À peça (unidade)" }], answer: null };
const view = (pid: string, over: any = {}) => ({ data: {
    article: { product_id: pid, code: pid, label: pid === "100" ? "100 · Café em grão" : "200 · Açúcar", description: "Café", family: "COMPRAS", base_unit: "Unidade", base_family: "un", url: `/cadastros/artigos/${pid}` },
    profile: { state: pid === "100" ? "sugerido" : "por_identificar", base_confirmed: null, type: null, count_unit: null, confirmed_by: null, confirmed_at: null, reason: null, info_text: null, has_photo: false, photo_url: null, skipped: false },
    answers: {}, por_identificar: pid === "200", confidence: pid === "200" ? 0.4 : 0.9,
    seen: [{ source: "faturas", text: "Fornecedor A: 20 un a 15,00 €/un (300 €) desde maio, na descrição «CAFE GRAO 1KG»." }, { source: "fichas", text: "Entra em 2 fichas, de 0,007 un a 0,014 un por porção." }],
    verdict: pid === "200" ? { key: "ajuda", title: "Precisamos da sua ajuda.", text: "Responda: como chega?", tone: "secondary" }
        : pid === "300" ? { key: "certo", title: "Está certo: nada a mudar.", text: "Compra-se ao kg e usa-se ao kg nas fichas.", tone: "success" }
            : { key: "mudar", title: "Precisa de mudança.", text: "Converter as fichas para quilograma.", tone: "warning" },
    confidence_label: { label: pid === "200" ? "Baixa" : "Alta", score: 0.9, why: "Todas as compras têm a quantidade base conhecida." },
    changes: pid === "100" ? ["Converter as fichas para quilograma."] : [],
    evidence: { purchases: [], packaging: null, recipes: [], contradictions: [], spend_90d_eur: 300 },
    proposal: { identified: pid !== "200", type: pid === "100" ? "embalado_peso" : null, type_label: pid === "100" ? "Embalado, usado ao peso ou ao volume" : "Por identificar", base: pid === "100" ? "kg" : null,
        base_name: "Quilograma", base_label: pid === "100" ? "Quilograma" : null, dose: null, current_base: "un", current_base_label: "Unidade", old_unit_is_pack: false, recipes_use_pack: false,
        conflicts: pid === "200" ? ["Há fornecedores que o descrevem em saquetas e outros a granel (kg)."] : [], purchase_units: pid === "100" ? [{ supplier: "Fornecedor A", nif: "1", unit_key: "11001", unit_id: "11001", unit_label: "Unidade", proposed_unit: "Embalagem 1 kg", factor: 1, why: "Lido na descrição do fornecedor: «1KG».", answer_key: "k", pingwin_factor: 1, label: "1 Embalagem 1 kg = 1 kg", confirmed: false }] : [],
        count_unit: pid === "100" ? { label: "Embalagem 1 kg", factor: 1, approximate: false } : null, deactivate: [], base_change: pid === "100",
        base_change_warning: pid === "100" ? { title: "Mudar a unidade base de unidade para quilograma", text: "A XPLENDOR não faz esta mudança.", paths: ["Com o stock a zero.", "Depois de uma contagem.", "Criar um artigo novo."] } : null,
        questions: [question], learned: null, complete: pid === "100" },
    impact: { recipes: pid === "100" ? [{ product_id: "D1", label: "Café expresso", bom_id: "B1", before: "0,007 Unidade", after: "7 g", after_qnt: 0.007, cost_before_eur: 0.105, cost_after_eur: 0.105, sold_qty_90d: 30 }] : [],
        stock: [], invoices: ["Faturas de Fornecedor A em «Unidade»: lançar como Embalagem 1 kg (1 = 1 kg)."], count: "Contar em embalagem 1 kg.", price_before_eur: 15, price_after_eur: 15,
        recipes_monthly_delta_eur: 0, monthly_eur: 10, recipes_note: null },
    findings: [], ai: { available: false, reason: "Indisponível: o serviço de sugestões não está configurado. Contacte o suporte da XPLENDOR.", suggestion: null },
    apply: { proven: false, guard: { mode: "por_provar", reason: "Disponível em breve." }, steps: [], manual: ["No PingWin, em Unidades, crie «Embalagem 1 kg»."], can_apply: false },
    recipes_conversion: { ready: false, needs_base_change: false, items: [] }, history: [], financial: { visible: true }, ...over,
} });

describe("UnitGuidePage", () => {
    beforeEach(() => {
        [mockQueue, mockView, mockPreview, mockConfirm, mockSkip, mockAdmin, mockAi, mockAll, mockUndo, mockNavigate].forEach((m) => m.mockReset());
        mockInitialSearch = "";
        localStorage.setItem("xp-unit-guide-help-seen", "1");
        mockQueue.mockResolvedValue(queue);
        mockView.mockImplementation((_c: number, pid: string) => Promise.resolve(view(pid)));
    });

    it("mostra a fila pelo Pareto, o que vimos, as perguntas, a proposta e o que muda", async () => {
        render(<UnitGuidePage />);
        expect(await screen.findByTestId("guide-article")).toHaveTextContent("100 · Café em grão");
        expect(screen.getByTestId("guide-position")).toHaveTextContent("1 de 3");
        expect(screen.getByTestId("guide-verdict")).toHaveTextContent("Precisa de mudança.Converter as fichas para quilograma.");
        expect(screen.getByTestId("guide-seen-faturas")).toHaveTextContent("Faturas");
        expect(screen.getByTestId("guide-seen-faturas")).toHaveTextContent("20 un a 15,00 €/un (300 €) desde maio");
        expect(screen.getByTestId("guide-seen-fichas")).toHaveTextContent("Fichas");
        expect(screen.getByTestId("guide-confidence")).toHaveTextContent("Confiança alta");
        expect(screen.getByTestId("guide-decide")).toHaveClass("xp-guide-actions");
        expect(screen.getByTestId("guide-base")).toHaveTextContent("Quilograma");
        expect(screen.getByTestId("guide-impact-recipes")).toHaveTextContent("0,007 Unidade7 g");
        expect(screen.getByTestId("guide-base-warning")).toHaveTextContent("Mudar a unidade base de unidade para quilograma");
        expect(screen.getByTestId("guide-monthly")).toHaveTextContent("10,00 €");
        expect(mockView).toHaveBeenCalledWith(5, "100");
    });

    it("uma resposta refaz a proposta sem gravar; Confirmar envia as respostas", async () => {
        mockPreview.mockImplementation((_c: number, pid: string) => Promise.resolve(view(pid)));
        mockConfirm.mockResolvedValue({ message: "Confirmado.", data: view("100").data });
        render(<UnitGuidePage />);
        await screen.findByTestId("guide-article");
        fireEvent.click(screen.getByText("Em gramas"));
        await waitFor(() => expect(mockPreview).toHaveBeenCalledWith(5, "100", { uso: "dose_peso" }));
        fireEvent.click(screen.getByTestId("guide-confirm"));
        await waitFor(() => expect(mockConfirm).toHaveBeenCalledWith(5, "100", { uso: "dose_peso" }));
    });

    it("Saltar passa ao artigo seguinte", async () => {
        mockSkip.mockResolvedValue({});
        render(<UnitGuidePage />);
        await screen.findByTestId("guide-article");
        fireEvent.click(screen.getByTestId("guide-skip"));
        await waitFor(() => expect(mockSkip).toHaveBeenCalledWith(5, "100"));
        await waitFor(() => expect(screen.getByTestId("guide-position")).toHaveTextContent("2 de 3"));
    });

    it("por identificar: as três saídas, a IA desativada com o porquê, e enviar ao administrador", async () => {
        mockAdmin.mockResolvedValue({ message: "Enviado.", data: view("200").data });
        render(<UnitGuidePage />);
        await screen.findByTestId("guide-article");
        fireEvent.click(screen.getByText("Seguinte"));
        expect(await screen.findByTestId("guide-unknown")).toBeInTheDocument();
        expect(screen.getByTestId("guide-proposal")).toHaveTextContent("Ainda por identificar: Há fornecedores que o descrevem em saquetas");
        expect(screen.getByTestId("guide-ai")).toBeDisabled();
        expect(screen.getByLabelText(/indisponível: Indisponível: o serviço de sugestões não está configurado/)).toBeInTheDocument();
        expect(screen.getByTestId("guide-confirm")).toBeDisabled();
        fireEvent.click(screen.getByTestId("guide-admin-send"));
        await waitFor(() => expect(mockAdmin).toHaveBeenCalledWith(5, "200", undefined));
    });

    it("a fila separa por veredicto e confirma em bloco os que estão certos, com revisão e desfazer", async () => {
        mockAll.mockResolvedValue({ message: "1 artigo confirmado na XPLENDOR.", data: { batch: "bloco-1", confirmed: ["300"], skipped: [] } });
        mockUndo.mockResolvedValue({ data: { batch: "bloco-1", undone: ["300"] } });
        render(<UnitGuidePage />);
        await screen.findByTestId("guide-article");
        expect(screen.getByTestId("guide-group-mudar")).toHaveTextContent("Precisa de mudança");
        expect(screen.getByTestId("guide-group-ajuda")).toHaveTextContent("Por identificar");
        expect(screen.getByTestId("guide-group-certo")).toHaveTextContent("Está certo, só confirmar");
        expect(screen.getByTestId("guide-group-certo")).toHaveTextContent(/34\s255\s€comprado em 90 dias/);
        fireEvent.click(screen.getByTestId("guide-confirm-all"));
        expect(await screen.findByTestId("guide-bulk")).toHaveTextContent("só na XPLENDOR (nada no PingWin)");
        fireEvent.click(screen.getByTestId("guide-bulk-confirm"));
        await waitFor(() => expect(mockAll).toHaveBeenCalledWith(5, ["300"]));
        fireEvent.click(await screen.findByText("Desfazer"));
        await waitFor(() => expect(mockUndo).toHaveBeenCalledWith(5, "bloco-1"));
    });

    it("um artigo certo diz que nada muda; o «Como funciona» abre na primeira visita", async () => {
        localStorage.removeItem("xp-unit-guide-help-seen");
        render(<UnitGuidePage />);
        expect(await screen.findByTestId("guide-help")).toHaveTextContent("O Guião confirma, artigo a artigo");
        fireEvent.click(screen.getByText("Percebi"));
        expect(localStorage.getItem("xp-unit-guide-help-seen")).toBe("1");
        fireEvent.click(screen.getByText("300 · Bife"));
        expect(await screen.findByTestId("guide-nothing-changes")).toHaveTextContent("Nada muda nas fichas, no stock nem nas faturas.");
        expect(screen.getByTestId("guide-verdict")).toHaveTextContent("Está certo: nada a mudar.");
    });

    it("a pesquisa vai ao servidor (nome sem acentos, código) com o estado, e um resultado abre a ficha", async () => {
        render(<UnitGuidePage />);
        await screen.findByTestId("guide-article");
        const acucar = { ...queue.data.items[1], code: "P200" };
        mockQueue.mockResolvedValue({ data: { ...queue.data, total: 1, items: [acucar] } });
        // O nome sem acentos nem maiúsculas: a pesquisa corre no servidor, sobre todo o Guião.
        fireEvent.change(screen.getByLabelText("Pesquisar no Guião"), { target: { value: "ACUCAR" } });
        await waitFor(() => expect(mockQueue).toHaveBeenLastCalledWith(5, "todos", { q: "ACUCAR", estado: null }));
        expect(await screen.findByTestId("guide-count")).toHaveTextContent("1 resultado em todo o Guião");
        expect(screen.getByTestId("guide-queue")).toHaveTextContent("200 · Açúcar · P200");
        // O código do PingWin.
        fireEvent.change(screen.getByLabelText("Pesquisar no Guião"), { target: { value: "P200" } });
        await waitFor(() => expect(mockQueue).toHaveBeenLastCalledWith(5, "todos", { q: "P200", estado: null }));
        // O estado, com a pesquisa.
        fireEvent.change(screen.getByLabelText("Estado"), { target: { value: "por_rever" } });
        await waitFor(() => expect(mockQueue).toHaveBeenLastCalledWith(5, "todos", { q: "P200", estado: "por_rever" }));
        // Clicar no resultado abre a ficha desse artigo, com as mesmas perguntas.
        fireEvent.click(screen.getByRole("button", { name: /200 · Açúcar/ }));
        await waitFor(() => expect(mockView).toHaveBeenLastCalledWith(5, "200"));
        expect(await screen.findByText("Precisamos da sua ajuda.")).toBeInTheDocument();
        // Sem resultados: diz-se, e limpa-se.
        mockQueue.mockResolvedValue({ data: { ...queue.data, total: 0, items: [] } });
        fireEvent.change(screen.getByLabelText("Pesquisar no Guião"), { target: { value: "nada disto" } });
        expect(await screen.findByText("Nenhum artigo do Guião corresponde à pesquisa.")).toBeInTheDocument();
    });

    it("?artigo= abre esse artigo, não o primeiro por responder", async () => {
        mockInitialSearch = "artigo=300";
        render(<UnitGuidePage />);
        expect(await screen.findByText("Está certo: nada a mudar.")).toBeInTheDocument();
        expect(mockView).toHaveBeenCalledWith(5, "300");
        expect(mockView).not.toHaveBeenCalledWith(5, "100");
        expect(screen.getByTestId("guide-position")).toHaveTextContent("3 de 3");
    });

    it("?artigo= de um artigo fora do Guião diz porquê e deixa a pesquisa", async () => {
        mockInitialSearch = "artigo=999";
        mockView.mockRejectedValue({ __status: 404, message: "Este artigo não está no Guião de unidades: é um artigo de agrupamento (lança-se sempre com quantidade 1)." });
        render(<UnitGuidePage />);
        expect(await screen.findByTestId("guide-not-in-guide")).toHaveTextContent("é um artigo de agrupamento");
        expect(screen.getByTestId("guide-position")).toHaveTextContent("Fora desta lista");
        expect(screen.getByLabelText("Pesquisar no Guião")).toBeInTheDocument();
    });

    it("«Voltar à fatura» leva ao caminho interno e recusa os externos", async () => {
        mockInitialSearch = "artigo=100&voltar=" + encodeURIComponent("/app/restauracao/faturas/12");
        const { unmount } = render(<UnitGuidePage />);
        await screen.findByTestId("guide-article");
        fireEvent.click(within(screen.getByTestId("guide-back")).getByRole("button", { name: /Voltar à fatura/ }));
        expect(mockNavigate).toHaveBeenCalledWith("/restauracao/faturas/12");
        unmount();
        for (const bad of ["https://exemplo.com/app/x", "//exemplo.com/app/", "/app//exemplo.com", "/appx/faturas", "data:text/html,ola", "/restauracao/faturas/12", "/app/\\exemplo.com"]) {
            mockInitialSearch = "artigo=100&voltar=" + encodeURIComponent(bad);
            const utils = render(<UnitGuidePage />);
            await screen.findByTestId("guide-article");
            expect(screen.queryByTestId("guide-back")).not.toBeInTheDocument();
            utils.unmount();
        }
    });
});
