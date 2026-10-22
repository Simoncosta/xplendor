import { act, fireEvent, render, screen } from "@testing-library/react";
import FinancingUploadPage from "./index";

const TOKEN = "A".repeat(64);

const base = (over: any = {}) => ({
    purpose: "financiamento", holder: 1, holder_notice: null,
    company: { name: "Exemplo", logo_url: null, fiscal_name: "Autocaravanas Exemplo Lda", nipc: "500019321" },
    customer_first_name: "Joana", expires_at: "2026-10-29T23:59:59Z",
    items: [{ kind: "identificacao", name: "Documento de identificação", hint: "Frente e verso.", required: true, max_files: 4, accepted_types: ["pdf", "jpg", "png", "heic"], max_file_bytes: 10485760, sent_count: 0 }],
    files: [],
    notice: { title: "Proteção dos seus dados", paragraphs: ["Quem trata os seus dados: Autocaravanas Exemplo Lda."], text: "", version: "2026-11", accepted: false,
        id_copy: "Autorizo Autocaravanas Exemplo Lda a guardar uma cópia do meu documento de identificação.", name_required: true },
    notes: null, limits: { max_files: 20, remaining_files: 20, max_file_bytes: 10485760 },
    details: { phone: null, email: null, housing: null, housing_other: null, housing_monthly_eur: null, completed: false,
        housing_options: [{ value: "arrendada", label: "Arrendada", needs_amount: true }, { value: "propria_sem_credito", label: "Própria, sem crédito", needs_amount: false }] },
    second_holder: { question: "Vai incluir um segundo titular?", help: "A inclusão de um segundo titular pode ajudar na aprovação do financiamento, porque permite considerar os rendimentos de ambos.",
        relationship_options: [{ value: "conjuge", label: "Cônjuge" }, { value: "outro", label: "Outro" }], included: false, relationship: null, relationship_other: null, url: null },
    ...over,
});
const accepted = (over: any = {}) => base({ notice: { ...base().notice, accepted: true }, ...over });
const reply = (status: number, json: any) => Promise.resolve({ status, json: () => Promise.resolve(json) } as any);

describe("Página de envio de documentos (sem conta)", () => {
    const fetchMock = jest.fn();
    beforeEach(() => {
        fetchMock.mockReset();
        (global as any).fetch = fetchMock;
        window.location.hash = `#${TOKEN}`;
    });

    it("o código vai no cabeçalho; aviso, consentimento da cópia e o nome completo antes de enviar", async () => {
        fetchMock.mockImplementationOnce(() => reply(200, { data: base() })).mockImplementationOnce(() => reply(200, { data: accepted() }));
        render(<FinancingUploadPage />);
        expect(await screen.findByText("Olá, Joana.")).toBeInTheDocument();
        expect(fetchMock.mock.calls[0][1].headers["X-Financing-Token"]).toBe(TOKEN);
        expect(fetchMock.mock.calls[0][0]).not.toContain(TOKEN);
        const go = screen.getByRole("button", { name: "Continuar" });
        fireEvent.click(screen.getByLabelText("Li e aceito o aviso de proteção de dados."));
        fireEvent.click(screen.getByLabelText(/cópia do meu documento de identificação/));
        expect(go).toBeDisabled();
        fireEvent.change(screen.getByLabelText(/O seu nome completo/), { target: { value: "Joana Maria Teste" } });
        fireEvent.click(go);
        expect(await screen.findByTestId("fu-items")).toBeInTheDocument();
        expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({ privacy: true, id_copy: true, name: "Joana Maria Teste" });
    });

    it("os dados do titular: habitação arrendada pede o valor da renda", async () => {
        fetchMock.mockImplementationOnce(() => reply(200, { data: accepted() }))
            .mockImplementationOnce(() => reply(200, { data: accepted({ details: { ...base().details, completed: true, housing: "arrendada", housing_monthly_eur: 450 } }) }));
        render(<FinancingUploadPage />);
        await screen.findByTestId("fu-details");
        fireEvent.change(screen.getByLabelText("Telemóvel"), { target: { value: "910 000 000" } });
        fireEvent.change(screen.getByLabelText("Email"), { target: { value: "joana@exemplo.invalid" } });
        fireEvent.change(screen.getByLabelText("Habitação"), { target: { value: "arrendada" } });
        fireEvent.change(screen.getByLabelText("Valor mensal da renda (€)"), { target: { value: "450" } });
        fireEvent.click(screen.getByRole("button", { name: "Guardar os dados" }));
        expect(await screen.findByText("Dados guardados.")).toBeInTheDocument();
        expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual(expect.objectContaining({ phone: "910 000 000", housing: "arrendada", housing_monthly_eur: "450" }));
    });

    it("o primeiro titular inclui o segundo e copia o link próprio dele", async () => {
        fetchMock.mockImplementationOnce(() => reply(200, { data: accepted() }))
            .mockImplementationOnce(() => reply(200, { data: accepted({ second_holder: { ...base().second_holder, included: true, relationship: "conjuge", url: "http://x/app/enviar-documentos#b" } }) }));
        render(<FinancingUploadPage />);
        const box = await screen.findByTestId("fu-second-holder");
        expect(box).toHaveTextContent("permite considerar os rendimentos de ambos");
        fireEvent.click(screen.getByRole("button", { name: "Sim, incluir um segundo titular" }));
        fireEvent.change(screen.getByLabelText("Relação ou grau de parentesco"), { target: { value: "conjuge" } });
        fireEvent.click(screen.getByRole("button", { name: "Criar o link do segundo titular" }));
        expect(await screen.findByLabelText("Link do segundo titular")).toHaveValue("http://x/app/enviar-documentos#b");
    });

    it("a página do segundo titular tem o aviso no topo e não pergunta por outro titular", async () => {
        fetchMock.mockImplementationOnce(() => reply(200, { data: accepted({ holder: 2, holder_notice: "Esta página destina-se ao segundo titular. Se não é a pessoa indicada, não continue.", second_holder: null }) }));
        render(<FinancingUploadPage />);
        expect(await screen.findByTestId("fu-holder-notice")).toHaveTextContent("Se não é a pessoa indicada, não continue.");
        expect(screen.queryByTestId("fu-second-holder")).not.toBeInTheDocument();
    });

    it("a retoma: observações e, enquanto o antivírus verifica, a verificar; se recusar, o motivo sem recarregar", async () => {
        jest.useFakeTimers();
        const retoma = (state: string) => accepted({
            purpose: "retoma", details: null, second_holder: null, notes: null,
            notice: { ...base().notice, accepted: true, id_copy: null, name_required: false },
            items: [{ kind: "exterior", name: "Fotografias do exterior", hint: "", required: true, max_files: 12, accepted_types: ["jpg", "png", "heic"], max_file_bytes: 10485760, sent_count: 1 }],
            files: [{ id: 9, kind: "exterior", name: "Fotografias do exterior 1.jpg", size_bytes: 100, state, reason: state === "recusado" ? "antivirus" : null,
                reason_text: state === "recusado" ? "A verificação de segurança recusou este ficheiro." : null, sent_at: "2026-10-22T10:00:00Z" }],
        });
        fetchMock.mockImplementationOnce(() => reply(200, { data: retoma("em_analise") })).mockImplementationOnce(() => reply(200, { data: retoma("recusado") }));
        render(<FinancingUploadPage />);
        expect(await screen.findByText("A verificar Fotografias do exterior 1.jpg…")).toBeInTheDocument();
        expect(screen.getByTestId("fu-notes")).toBeInTheDocument();
        await act(async () => { jest.advanceTimersByTime(3100); });
        expect(await screen.findByRole("alert")).toHaveTextContent("A verificação de segurança recusou este ficheiro.");
        jest.useRealTimers();
    });

    it("link expirado, revogado ou desconhecido: a mesma mensagem", async () => {
        fetchMock.mockImplementationOnce(() => reply(404, { message: "Este link já não está disponível." }));
        render(<FinancingUploadPage />);
        expect(await screen.findByText("Este link já não está disponível.")).toBeInTheDocument();
    });

    it("um código mal formado nem chega a pedir", async () => {
        window.location.hash = "#curto";
        render(<FinancingUploadPage />);
        expect(await screen.findByTestId("fu-gone")).toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
