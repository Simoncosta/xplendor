import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import AdminTicketDetail from "./AdminTicketDetail";
import type { ISupportTicket } from "common/models/supportTicket.model";

// O Jest do CRA não resolve o react-router-dom 7 (exports): substitui-se só o que o ecrã usa.
const mockNavigate = jest.fn();
jest.mock("react-router-dom", () => ({ useParams: () => ({ id: "7" }), useNavigate: () => mockNavigate }), { virtual: true });
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() }, ToastContainer: () => null }));
jest.mock("Components/Common/PageHeader", () => ({ __esModule: true, default: ({ title }: any) => <h1>{title}</h1> }));
jest.mock("Components/Common/Select", () => ({ __esModule: true, default: () => null }));
jest.mock("Components/Common/useTicketTypeChange", () => ({ useTicketTypeChange: () => ({ requestTypeChange: jest.fn(), modal: null, busy: false }) }));
jest.mock("pages/Companies/CompanyProfile/setupLink/SetupLinkModal", () => ({ __esModule: true, default: () => null }));

const mockApi = {
    showAdminTicket: jest.fn(), updateAdminTicketMessage: jest.fn(), deleteAdminTicketMessage: jest.fn(), deleteAdminTicket: jest.fn(),
    deleteAdminTicketInvoice: jest.fn(), unmarkAdminTicketPaid: jest.fn(), markAdminTicketPaid: jest.fn(), uploadAdminTicketInvoice: jest.fn(),
};
jest.mock("helpers/laravel_helper", () => ({
    showAdminTicket: (...a: unknown[]) => mockApi.showAdminTicket(...a),
    updateAdminTicketMessage: (...a: unknown[]) => mockApi.updateAdminTicketMessage(...a),
    deleteAdminTicketMessage: (...a: unknown[]) => mockApi.deleteAdminTicketMessage(...a),
    deleteAdminTicket: (...a: unknown[]) => mockApi.deleteAdminTicket(...a),
    deleteAdminTicketInvoice: (...a: unknown[]) => mockApi.deleteAdminTicketInvoice(...a),
    unmarkAdminTicketPaid: (...a: unknown[]) => mockApi.unmarkAdminTicketPaid(...a),
    markAdminTicketPaid: (...a: unknown[]) => mockApi.markAdminTicketPaid(...a),
    uploadAdminTicketInvoice: (...a: unknown[]) => mockApi.uploadAdminTicketInvoice(...a),
    updateAdminTicketStatus: jest.fn(), addAdminTicketMessage: jest.fn(), setAdminTicketQuote: jest.fn(),
    markAdminTicketCompleted: jest.fn(), updateAdminTicketTask: jest.fn(), deleteAdminTicketScreenshot: jest.fn(),
}));

const can = { delete: false, delete_blocked_by: null, remove_screenshot: false, change_invoice: true };
const ticket = (over: Partial<ISupportTicket> = {}): ISupportTicket => ({
    id: 7, company_id: 3, company_name: "Cliente Lda", type: "site_change", title: "Mudar o banner", description: "Trocar.", status: "in_review",
    screenshot_url: null, author_name: "Cliente Admin", quote_status: "approved", estimated_hours: 2, quoted_amount: 50, hourly_rate: 25,
    billing_visible: true, payment_status: "invoiced", invoice_url: "/api/files/ticket-invoice/7/fatura.pdf", invoiced_at: "2027-03-20T10:00:00Z", paid_at: null,
    team: { impersonator_name: null, invoice_by_name: "Equipa Um", paid_by_name: null, deleted_at: null, deleted_by_name: null, can },
    messages: [
        { id: 1, body: "Pode ser azul?", is_staff: false, author_name: "Cliente Admin", created_at: "2027-03-18T09:00:00Z" },
        { id: 2, body: "Sim, fica azul.", is_staff: true, author_name: "Equipa Um", edited_at: "2027-03-18T10:00:00Z", created_at: "2027-03-18T09:30:00Z",
            team: { impersonated: false, can_edit: true, versions: [{ body: "Sim, fica verde.", edited_by_name: "Equipa Um", created_at: "2027-03-18T10:00:00Z" }] } },
        { id: 3, body: "Mensagem antiga.", is_staff: true, author_name: "Equipa Um", deleted: true, deleted_at: "2027-03-19T08:00:00Z", created_at: "2027-03-18T11:00:00Z",
            team: { impersonated: false, can_edit: false, versions: [] } },
    ],
    ...over,
});

beforeEach(() => Object.values(mockApi).forEach((m) => m.mockReset()));

describe("ticket no /admin", () => {
    it("o trabalho, a fatura e o pagamento aparecem em separado, e o pagamento marca-se à parte", async () => {
        mockApi.showAdminTicket.mockResolvedValue({ data: ticket() });
        mockApi.markAdminTicketPaid.mockResolvedValue({ data: ticket({ payment_status: "paid", paid_at: "2027-03-21T10:00:00Z" }) });
        render(<AdminTicketDetail />);
        const billing = await screen.findByTestId("admin-billing");
        expect(billing).toHaveTextContent("Em execução");
        expect(billing).toHaveTextContent("Fatura emitida, por pagar");
        expect(within(billing).getByText("Ver fatura")).toBeInTheDocument();
        expect(billing).toHaveTextContent("Emitida em");

        fireEvent.click(within(billing).getByRole("button", { name: /Marcar como pago/ }));
        await waitFor(() => expect(within(screen.getByTestId("admin-billing")).getByText("Anular pago")).toBeInTheDocument());
        expect(mockApi.markAdminTicketPaid).toHaveBeenCalledWith(7, expect.any(FormData));
    });

    it("anular o pago pede confirmação", async () => {
        mockApi.showAdminTicket.mockResolvedValue({ data: ticket({ payment_status: "paid", paid_at: "2027-03-21T10:00:00Z" }) });
        mockApi.unmarkAdminTicketPaid.mockResolvedValue({ data: ticket() });
        render(<AdminTicketDetail />);
        fireEvent.click(await screen.findByText("Anular pago"));
        expect(mockApi.unmarkAdminTicketPaid).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole("button", { name: "Anular" }));
        await waitFor(() => expect(mockApi.unmarkAdminTicketPaid).toHaveBeenCalledWith(7));
    });

    it("edita na própria mensagem só as que são dele, e mostra as marcas e as versões", async () => {
        mockApi.showAdminTicket.mockResolvedValue({ data: ticket() });
        mockApi.updateAdminTicketMessage.mockResolvedValue({ data: ticket() });
        render(<AdminTicketDetail />);
        const mine = await screen.findByTestId("admin-message-2");
        expect(within(screen.getByTestId("admin-message-1")).queryByLabelText("Editar a mensagem")).not.toBeInTheDocument();
        expect(within(screen.getByTestId("admin-message-3")).queryByLabelText("Editar a mensagem")).not.toBeInTheDocument();
        expect(screen.getByTestId("admin-message-3")).toHaveTextContent("Mensagem apagada pela equipa XPLENDOR");
        expect(mine).toHaveTextContent("editada");

        fireEvent.click(within(mine).getByText(/Ver versões anteriores/));
        expect(screen.getByTestId("message-versions-2")).toHaveTextContent("Sim, fica verde.");

        fireEvent.click(within(mine).getByLabelText("Editar a mensagem"));
        fireEvent.change(within(mine).getByLabelText("Texto da mensagem"), { target: { value: "Fica azul-escuro." } });
        fireEvent.click(within(mine).getByRole("button", { name: "Guardar" }));
        await waitFor(() => expect(mockApi.updateAdminTicketMessage).toHaveBeenCalledWith(7, 2, "Fica azul-escuro."));
    });

    it("apagar uma mensagem pede confirmação", async () => {
        mockApi.showAdminTicket.mockResolvedValue({ data: ticket() });
        mockApi.deleteAdminTicketMessage.mockResolvedValue({ data: ticket() });
        render(<AdminTicketDetail />);
        fireEvent.click(within(await screen.findByTestId("admin-message-2")).getByLabelText("Apagar a mensagem"));
        fireEvent.click(screen.getByRole("button", { name: "Apagar" }));
        await waitFor(() => expect(mockApi.deleteAdminTicketMessage).toHaveBeenCalledWith(7, 2));
    });

    it("apaga o pedido que criou por engano, com confirmação, e volta à lista", async () => {
        mockApi.showAdminTicket.mockResolvedValue({ data: ticket({ quote_status: "awaiting_quote", payment_status: null, invoice_url: null, team: { ...ticket().team!, can: { ...can, delete: true } } }) });
        mockApi.deleteAdminTicket.mockResolvedValue({ data: {} });
        render(<AdminTicketDetail />);
        fireEvent.click(await screen.findByRole("button", { name: /Apagar pedido/ }));
        fireEvent.click(screen.getByRole("button", { name: "Apagar" }));
        await waitFor(() => expect(mockApi.deleteAdminTicket).toHaveBeenCalledWith(7));
        expect(mockNavigate).toHaveBeenCalledWith("/admin");
    });

    it("um pedido apagado fica só para consulta", async () => {
        mockApi.showAdminTicket.mockResolvedValue({ data: ticket({ team: { ...ticket().team!, deleted_at: "2027-03-22T10:00:00Z", deleted_by_name: "Equipa Um" } }) });
        render(<AdminTicketDetail />);
        expect(await screen.findByTestId("ticket-deleted")).toHaveTextContent("por Equipa Um");
        expect(screen.queryByTestId("admin-billing")).not.toBeInTheDocument();
        expect(screen.queryByLabelText("Editar a mensagem")).not.toBeInTheDocument();
        expect(screen.queryByPlaceholderText("Responder ao stand…")).not.toBeInTheDocument();
    });
});
