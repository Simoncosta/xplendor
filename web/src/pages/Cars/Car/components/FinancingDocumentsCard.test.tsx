import { fireEvent, render, screen, within } from "@testing-library/react";
import FinancingDocumentsCard from "./FinancingDocumentsCard";

let mockCan = true;
jest.mock("contexts/ModulesContext", () => ({ useCan: () => [mockCan, mockCan ? null : "Sem permissão."] }));
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn(), info: jest.fn() } }));

const mockGet = jest.fn();
const mockCreate = jest.fn();
const mockDownload = jest.fn();
jest.mock("helpers/laravel_helper", () => ({
    getCarFinancingLinks: (...a: unknown[]) => mockGet(...a),
    getLeadFinancingLinks: (...a: unknown[]) => mockGet(...a),
    createCarFinancingLink: (...a: unknown[]) => mockCreate(...a),
    createLeadFinancingLink: (...a: unknown[]) => mockCreate(...a),
    getFinancingDocumentDownload: (...a: unknown[]) => mockDownload(...a),
    getFinancingLinkUrl: jest.fn(), revokeFinancingLink: jest.fn(), deleteFinancingLink: jest.fn(), deleteFinancingDocument: jest.fn(), updateFinancingSettings: jest.fn(),
}));

const payload = (over: any = {}) => ({
    links: [{
        id: 12, customer_name: "Joana Teste", state: "ativo", items: [{ key: "cc", label: "Cartão de Cidadão" }, { key: "iban", label: "Comprovativo de IBAN" }],
        expires_at: "2026-10-27T23:59:59Z", created_at: "2026-10-20T10:00:00Z", created_by: "Vendedor", consented_at: "2026-10-20T11:00:00Z",
        last_upload_at: "2026-10-20T11:05:00Z", purge_on: "2027-01-18",
        documents: [
            { id: 5, kind: "cc", kind_label: "Cartão de Cidadão", name: "Cartão de Cidadão 1.jpg", mime: "image/jpeg", size_bytes: 120000, created_at: "2026-10-20T11:05:00Z", scan_status: "nao_analisado", downloadable: true },
            { id: 6, kind: "cc", kind_label: "Cartão de Cidadão", name: "Cartão de Cidadão 2.pdf", mime: "application/pdf", size_bytes: 9000, created_at: "2026-10-20T11:06:00Z", scan_status: "pendente", downloadable: false },
        ],
    }, {
        id: 11, customer_name: null, state: "apagado", items: [{ key: "recibos", label: "Recibos de vencimento" }], expires_at: "2026-06-01T00:00:00Z",
        created_at: "2026-05-25T10:00:00Z", created_by: null, consented_at: null, last_upload_at: null, purge_on: null, documents: [],
    }],
    can: { create: true, delete: false, settings: false },
    items: [{ key: "cc", label: "Cartão de Cidadão", hint: "Frente e verso." }, { key: "iban", label: "Comprovativo de IBAN", hint: "" }, { key: "outro", label: "Outro documento", hint: "" }],
    defaults: { days: 7, max_days: 30 }, retention: { days: 90, min: 30, max: 365, warn_days: 7 }, antivirus_blocks: false,
    ...over,
});

describe("Documentos para financiamento (ficha da viatura)", () => {
    beforeEach(() => { mockCan = true; mockGet.mockReset(); mockCreate.mockReset(); mockDownload.mockReset(); });

    it("mostra os links com o estado e os documentos; em análise não se descarrega; sem permissão de apagar, sem botões de apagar", async () => {
        mockGet.mockResolvedValue({ data: payload() });
        render(<FinancingDocumentsCard companyId={4} carId={105} />);
        const active = await screen.findByTestId("fin-link-12");
        expect(within(active).getByText("Ativo")).toBeInTheDocument();
        expect(within(active).getByText("Joana Teste")).toBeInTheDocument();
        expect(within(active).getByText("Em análise")).toBeInTheDocument();
        expect(within(active).getAllByRole("button", { name: /Descarregar/ })).toHaveLength(1);
        expect(within(active).queryByRole("button", { name: /Apagar/ })).not.toBeInTheDocument();
        expect(within(active).getByText(/os documentos apagam-se a 18\/01\/2027/)).toBeInTheDocument();
        const purged = screen.getByTestId("fin-link-11");
        expect(within(purged).getByText("Apagado")).toBeInTheDocument();
        expect(within(purged).getByText("Cliente (dados apagados)")).toBeInTheDocument();
        expect(screen.getByText(/apagam-se automaticamente 90 dias depois do último envio/)).toBeInTheDocument();
    });

    it("descarregar pede o endereço assinado à API (a descarga fica registada no servidor)", async () => {
        // O jsdom não navega: troca-se o location por um objeto simples só neste teste.
        const original = window.location;
        Object.defineProperty(window, "location", { configurable: true, value: { href: "" } });
        mockGet.mockResolvedValue({ data: payload() });
        mockDownload.mockResolvedValue({ data: { url: "/api/files/stored/9/x.jpg?download=1&signature=abc" } });
        render(<FinancingDocumentsCard companyId={4} carId={105} />);
        fireEvent.click(await screen.findByRole("button", { name: "Descarregar Cartão de Cidadão 1.jpg" }));
        expect(mockDownload).toHaveBeenCalledWith(4, 5);
        await screen.findByTestId("fin-link-12");
        await new Promise((r) => setTimeout(r, 0));
        expect(window.location.href).toContain("/api/files/stored/9/x.jpg?download=1&signature=abc");
        Object.defineProperty(window, "location", { configurable: true, value: original });
    });

    it("pedir documentos: nome, documentos e validade; no fim mostra o link para copiar", async () => {
        mockGet.mockResolvedValue({ data: payload({ links: [] }) });
        mockCreate.mockResolvedValue({ data: { id: 13, url: "http://localhost/app/enviar-documentos#abc", expires_at: "2026-10-27T23:59:59Z" } });
        render(<FinancingDocumentsCard companyId={4} carId={105} />);
        expect(await screen.findByTestId("fin-empty")).toBeInTheDocument();
        fireEvent.click(screen.getByRole("button", { name: /Pedir documentos/ }));
        fireEvent.change(screen.getByLabelText("Nome do cliente"), { target: { value: "Rui Teste" } });
        fireEvent.click(screen.getByLabelText(/Outro documento/));
        fireEvent.change(screen.getByLabelText("Descrição do outro documento"), { target: { value: "Declaração da entidade patronal" } });
        fireEvent.click(screen.getByRole("button", { name: "Criar link" }));
        expect(await screen.findByTestId("fin-created")).toBeInTheDocument();
        expect(screen.getByLabelText("Link para o cliente")).toHaveValue("http://localhost/app/enviar-documentos#abc");
        expect(mockCreate).toHaveBeenCalledWith(4, 105, expect.objectContaining({ customer_name: "Rui Teste", other: "Declaração da entidade patronal", days: 7 }));
        expect(mockCreate.mock.calls[0][2].items).toEqual(expect.arrayContaining(["cc", "outro"]));
    });

    it("sem a permissão de ver, o cartão não aparece e não chama a API", () => {
        mockCan = false;
        const { container } = render(<FinancingDocumentsCard companyId={4} carId={105} />);
        expect(container).toBeEmptyDOMElement();
        expect(mockGet).not.toHaveBeenCalled();
    });
});
