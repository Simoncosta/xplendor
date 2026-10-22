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
    getFinancingLinkUrl: jest.fn(), revokeFinancingLink: jest.fn(), deleteFinancingLink: jest.fn(), deleteFinancingDocument: jest.fn(),
    deleteFinancingRequest: jest.fn(), addFinancingSecondHolder: jest.fn(), updateFinancingSettings: jest.fn(),
}));

const holder = (over: any = {}) => ({
    link_id: 21, holder: 1, customer_name: "Joana Teste", state: "ativo", expires_at: "2026-10-29T23:59:59Z", revoked_at: null,
    consented_at: "2026-10-22T11:00:00Z", signed_name: "Joana Maria Teste", relationship: null, relationship_label: null,
    phone: "910 000 000", email: "joana@exemplo.invalid", housing: "arrendada", housing_label: "Arrendada", housing_monthly_eur: 450.5, details_at: "2026-10-22T11:01:00Z",
    hidden_documents: 0,
    documents: [
        { id: 5, kind: "identificacao", kind_label: "Documento de identificação", name: "Documento de identificação 1.jpg", mime: "image/jpeg", size_bytes: 120000, created_at: "2026-10-22T11:05:00Z", scan_status: "limpo", downloadable: true },
        { id: 6, kind: "recibos", kind_label: "Recibos de vencimento", name: "Recibos de vencimento 1.pdf", mime: "application/pdf", size_bytes: 9000, created_at: "2026-10-22T11:06:00Z", scan_status: "pendente", downloadable: false },
    ],
    ...over,
});

const payload = (over: any = {}) => ({
    requests: [{
        id: 12, purpose: "financiamento", state: "ativo", items: [{ key: "identificacao", label: "Documento de identificação" }, { key: "iban", label: "Comprovativo de IBAN" }],
        irs_year: 2025, notes: null, created_at: "2026-10-22T10:00:00Z", created_by: "Vendedor", last_upload_at: "2026-10-22T11:06:00Z", purge_on: "2027-01-20",
        purged_at: null, can_add_second_holder: false,
        holders: [holder(), holder({ link_id: 22, holder: 2, customer_name: "Rui Teste", relationship: "conjuge", relationship_label: "Cônjuge", signed_name: "Rui Teste",
            housing: "propria_credito", housing_label: "Própria, com crédito à habitação", housing_monthly_eur: 600, documents: [] })],
    }],
    can: { create: true, delete: false, settings: false },
    items: [
        { key: "identificacao", label: "Documento de identificação", hint: "Frente e verso." }, { key: "iban", label: "Comprovativo de IBAN", hint: "" },
        { key: "irs_declaracao", label: "Declaração de IRS de 2025", hint: "" }, { key: "irs_nota", label: "Nota de liquidação do IRS", hint: "" }, { key: "outro", label: "Outro documento", hint: "" },
    ],
    default_items: ["identificacao", "iban", "irs_declaracao"],
    relationships: [{ value: "conjuge", label: "Cônjuge" }, { value: "outro", label: "Outro" }],
    defaults: { days: 7, max_days: 30, irs_year: 2025, irs_years: [2026, 2025, 2024] }, retention: { days: 90, min: 30, max: 365, warn_days: 7 }, antivirus_blocks: false,
    ...over,
});

describe("Documentos para financiamento (ficha da viatura)", () => {
    beforeEach(() => { mockCan = true; mockGet.mockReset(); mockCreate.mockReset(); mockDownload.mockReset(); });

    it("cada pedido junta os titulares, cada um com os seus dados, o consentimento e os documentos", async () => {
        mockGet.mockResolvedValue({ data: payload() });
        render(<FinancingDocumentsCard companyId={4} carId={105} />);
        const req = await screen.findByTestId("fin-request-12");
        expect(within(req).getByText(/os documentos apagam-se a 20\/01\/2027/)).toBeInTheDocument();
        const first = screen.getByTestId("fin-holder-21");
        expect(within(first).getByText("Titular 1")).toBeInTheDocument();
        expect(within(first).getByText("910 000 000")).toBeInTheDocument();
        expect(within(first).getByText(/450,50 €/)).toBeInTheDocument();
        expect(within(first).getByText(/por Joana Maria Teste/)).toBeInTheDocument();
        expect(within(first).getByText("Em análise")).toBeInTheDocument();
        expect(within(first).getAllByRole("button", { name: /Descarregar/ })).toHaveLength(1);
        const second = screen.getByTestId("fin-holder-22");
        expect(within(second).getByText("Titular 2 · Cônjuge")).toBeInTheDocument();
        expect(within(second).getByText("Prestação mensal")).toBeInTheDocument();
        expect(within(second).getByText("Ainda não chegou nenhum documento.")).toBeInTheDocument();
        expect(screen.queryByRole("button", { name: /Apagar o pedido/ })).not.toBeInTheDocument();
    });

    it("descarregar pede o endereço assinado à API", async () => {
        mockGet.mockResolvedValue({ data: payload() });
        mockDownload.mockResolvedValue({ data: { url: "/api/files/stored/9/x.jpg?download=1&signature=abc" } });
        const original = window.location;
        Object.defineProperty(window, "location", { configurable: true, value: { href: "" } });
        render(<FinancingDocumentsCard companyId={4} carId={105} />);
        fireEvent.click(await screen.findByRole("button", { name: "Descarregar Documento de identificação 1.jpg" }));
        expect(mockDownload).toHaveBeenCalledWith(4, 5);
        await new Promise((r) => setTimeout(r, 0));
        expect(window.location.href).toContain("signature=abc");
        Object.defineProperty(window, "location", { configurable: true, value: original });
    });

    it("pedir documentos com 2 titulares, a lista por omissão e o ano do IRS; no fim, um link para cada titular", async () => {
        mockGet.mockResolvedValue({ data: payload({ requests: [] }) });
        mockCreate.mockResolvedValue({ data: { id: 13, holders: [{ expires_at: "2026-10-29T23:59:59Z" }], urls: { 1: "http://x/app/enviar-documentos#a", 2: "http://x/app/enviar-documentos#b" } } });
        render(<FinancingDocumentsCard companyId={4} carId={105} />);
        expect(await screen.findByTestId("fin-empty")).toBeInTheDocument();
        fireEvent.click(screen.getByRole("button", { name: /Pedir documentos/ }));
        expect(screen.getByLabelText(/Comprovativo de IBAN/)).toBeChecked();
        expect(screen.getByLabelText(/Nota de liquidação do IRS/)).not.toBeChecked();
        fireEvent.click(screen.getByRole("button", { name: "2 titulares" }));
        fireEvent.change(screen.getByLabelText("Nome do primeiro titular"), { target: { value: "Rui Teste" } });
        fireEvent.change(screen.getByLabelText("Relação ou grau de parentesco com o primeiro titular"), { target: { value: "conjuge" } });
        fireEvent.change(screen.getByLabelText("Ano dos rendimentos"), { target: { value: "2024" } });
        expect(screen.getByText("Declaração de IRS de 2024")).toBeInTheDocument();
        fireEvent.click(screen.getByRole("button", { name: "Criar pedido" }));
        expect(await screen.findByTestId("fin-created")).toBeInTheDocument();
        expect(screen.getByLabelText("Link do primeiro titular")).toHaveValue("http://x/app/enviar-documentos#a");
        expect(screen.getByLabelText("Link do segundo titular")).toHaveValue("http://x/app/enviar-documentos#b");
        // A lista recarrega depois de criar: os links continuam à vista para copiar.
        await screen.findByText("Pedir documentos para financiamento");
        expect(mockGet).toHaveBeenCalledTimes(2);
        expect(screen.getByLabelText("Link do primeiro titular")).toBeInTheDocument();
        expect(mockCreate).toHaveBeenCalledWith(4, 105, expect.objectContaining({ customer_name: "Rui Teste", holders: 2, relationship: "conjuge", irs_year: 2024, items: ["identificacao", "iban", "irs_declaracao"] }));
    });

    it("sem a permissão de ver, o cartão não aparece e não chama a API", () => {
        mockCan = false;
        const { container } = render(<FinancingDocumentsCard companyId={4} carId={105} />);
        expect(container).toBeEmptyDOMElement();
        expect(mockGet).not.toHaveBeenCalled();
    });
});
