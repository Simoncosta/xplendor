import { render, screen } from "@testing-library/react";
import SupportTicketDetail from "./SupportTicketDetail";
import type { ISupportTicket } from "common/models/supportTicket.model";

// O Jest do CRA não resolve o react-router-dom 7 (exports): substitui-se só o que o ecrã usa.
jest.mock("react-router-dom", () => ({ useParams: () => ({ id: "7" }), useNavigate: () => jest.fn() }), { virtual: true });
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() }, ToastContainer: () => null }));
jest.mock("Components/Common/PageHeader", () => ({ __esModule: true, default: ({ title }: any) => <h1>{title}</h1> }));
jest.mock("contexts/WorkingCompanyContext", () => ({ useWorkingCompanyId: () => 3 }));
const mockShow = jest.fn();
jest.mock("helpers/laravel_helper", () => ({
    showSupportTicket: (...a: unknown[]) => mockShow(...a),
    addSupportTicketMessage: jest.fn(),
    decideSupportTicketQuote: jest.fn(),
}));

const ticket = (over: Partial<ISupportTicket> = {}): ISupportTicket => ({
    id: 7, company_id: 3, type: "site_change", title: "Mudar o banner", description: "Trocar.", status: "in_review", screenshot_url: null,
    quote_status: "approved", estimated_hours: 2, quoted_amount: 50, hourly_rate: 25, billing_visible: true,
    payment_status: "pending", invoice_url: null, messages: [], ...over,
});

beforeEach(() => mockShow.mockReset());

describe("pedido de suporte, do lado da cliente", () => {
    it("fatura emitida e por pagar: diz isso, com Ver fatura, e nunca diz que está pago", async () => {
        mockShow.mockResolvedValue({ data: ticket({ payment_status: "invoiced", invoice_url: "/api/files/ticket-invoice/7/fatura.pdf" }) });
        render(<SupportTicketDetail />);
        const progress = await screen.findByTestId("quote-progress");
        expect(progress).toHaveTextContent("Em execução.");
        expect(progress).toHaveTextContent("Fatura emitida. A aguardar pagamento.");
        expect(screen.getByText("Ver fatura")).toBeInTheDocument();
        expect(document.body).not.toHaveTextContent(/Pagamento (confirmado|recebido)/);
    });

    it("pago e concluído aparecem em separado", async () => {
        mockShow.mockResolvedValue({ data: ticket({ quote_status: "completed", payment_status: "paid" }) });
        render(<SupportTicketDetail />);
        const progress = await screen.findByTestId("quote-progress");
        expect(progress).toHaveTextContent("Trabalho concluído.");
        expect(progress).toHaveTextContent("Pagamento recebido.");
    });

    it("concluído antes de pagar: não diz que está pago", async () => {
        mockShow.mockResolvedValue({ data: ticket({ quote_status: "completed", payment_status: "pending" }) });
        render(<SupportTicketDetail />);
        expect(await screen.findByTestId("quote-progress")).toHaveTextContent("Trabalho concluído.");
        expect(document.body).not.toHaveTextContent(/Pagamento (confirmado|recebido)/);
    });

    it("na conversa: a marca de editada e de apagada, e a mensagem do root na sessão como cliente aparece da equipa", async () => {
        mockShow.mockResolvedValue({ data: ticket({ messages: [
            { id: 1, body: "Texto corrigido.", is_staff: true, author_name: "Equipa Um", edited_at: "2027-03-18T10:00:00Z", created_at: "2027-03-18T09:00:00Z" },
            { id: 2, body: null, is_staff: true, author_name: "Equipa Um", deleted: true, deleted_at: "2027-03-19T08:00:00Z", created_at: "2027-03-18T11:00:00Z" },
            { id: 3, body: "Escrita na sessão como cliente.", is_staff: true, author_name: "Equipa Um", created_at: "2027-03-18T12:00:00Z" },
        ] }) });
        render(<SupportTicketDetail />);
        expect(await screen.findByTestId("message-edited")).toHaveTextContent("(editada,");
        expect(screen.getByTestId("message-deleted")).toHaveTextContent("Mensagem apagada pela equipa XPLENDOR");
        expect(screen.getAllByText("Equipa XPLENDOR")).toHaveLength(3);
    });
});
