import { render, screen, within } from "@testing-library/react";
import TradeInCard from "./TradeInCard";

jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn(), info: jest.fn() } }));
const mockGet = jest.fn();
jest.mock("helpers/laravel_helper", () => ({
    getLeadTradeIn: (...a: unknown[]) => mockGet(...a),
    createLeadTradeInLink: jest.fn(), deleteFinancingDocument: jest.fn(), deleteTradeInFile: jest.fn(), getFinancingDocumentDownload: jest.fn(),
    getTradeInFileDownload: jest.fn(), getTradeInLinkUrl: jest.fn(), revokeTradeInLink: jest.fn(),
    getCarFinancingLinks: jest.fn(), getLeadFinancingLinks: jest.fn(), createCarFinancingLink: jest.fn(), createLeadFinancingLink: jest.fn(),
    getFinancingLinkUrl: jest.fn(), revokeFinancingLink: jest.fn(), deleteFinancingLink: jest.fn(), deleteFinancingRequest: jest.fn(),
    addFinancingSecondHolder: jest.fn(), updateFinancingSettings: jest.fn(),
}));
jest.mock("contexts/ModulesContext", () => ({ useCan: () => [true, null] }));

const doc = (id: number, kind: string, personal = false) => ({
    id, kind, kind_label: kind, name: `${kind} 1.jpg`, mime: "image/jpeg", size_bytes: 1000, created_at: "2026-10-22T10:00:00Z", scan_status: "limpo",
    downloadable: true, personal, thumb_url: personal ? null : `/api/files/stored/${id}/x.jpg?signature=s`,
});
const payload = (seeDua: boolean) => ({
    trade_in: { brand: "Renault", model: "Clio", year: 2014, km: 142000 },
    requests: [{ id: 3, purpose: "retoma", state: "ativo", items: [], irs_year: null, notes: "Pequeno risco na porta.", created_at: "2026-10-22T09:00:00Z", created_by: null,
        last_upload_at: null, purge_on: null, purged_at: null, can_add_second_holder: false,
        holders: [{ link_id: 7, holder: 1, customer_name: "C", state: "ativo", expires_at: "2026-10-23T09:00:00Z", revoked_at: null, consented_at: null, signed_name: null,
            relationship: null, relationship_label: null, phone: null, email: null, housing: null, housing_label: null, housing_monthly_eur: null, details_at: null,
            hidden_documents: seeDua ? 0 : 1, documents: seeDua ? [doc(1, "dua", true), doc(2, "exterior")] : [doc(2, "exterior")] }] }],
    can: { request: true, delete: true, see_dua: seeDua, delete_dua: seeDua }, defaults: { days: 7, max_days: 30 }, antivirus_blocks: false,
});

describe("Retoma no detalhe da lead", () => {
    it("mostra a viatura, as observações, as fotografias em grelha e o DUA a quem tem o Financiamento", async () => {
        mockGet.mockResolvedValue({ data: payload(true) });
        render(<TradeInCard companyId={4} leadId={9} />);
        expect(await screen.findByText("Renault Clio")).toBeInTheDocument();
        expect(screen.getByText(/142\s000 km/)).toBeInTheDocument();
        expect(screen.getByText("«Pequeno risco na porta.»")).toBeInTheDocument();
        expect(within(screen.getByTestId("trade-in-photos")).getAllByRole("img")).toHaveLength(1);
        expect(screen.getByText("dua 1.jpg")).toBeInTheDocument();
    });

    it("sem a área Financiamento, o DUA não aparece (só o aviso de que chegou)", async () => {
        mockGet.mockResolvedValue({ data: payload(false) });
        render(<TradeInCard companyId={4} leadId={9} />);
        expect(await screen.findByText(/só o veem as pessoas com acesso ao Financiamento/)).toBeInTheDocument();
        expect(screen.queryByText("dua 1.jpg")).not.toBeInTheDocument();
    });
});
