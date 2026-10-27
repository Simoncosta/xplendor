import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import MailConversation from "./MailConversation";
import { getCarMailMessages, getCustomerMailMessages } from "helpers/laravel_helper";

jest.mock("helpers/laravel_helper", () => ({ getCustomerMailMessages: jest.fn(), getCarMailMessages: jest.fn() }));

describe("A secção Conversa (E-mail 4)", () => {
    it("na ficha do cliente: as mensagens enviadas e recebidas, abre o texto", async () => {
        (getCustomerMailMessages as jest.Mock).mockResolvedValue({ data: { messages: [
            { id: 1, direction: "out", from: "matilde@exemplo.pt", to: "joana@cliente.invalid", subject: "A sua nova viatura", date: "2026-10-27T12:00:00Z", text: "O link", html: null, status: "enviada" },
            { id: 2, direction: "in", from: "joana@cliente.invalid", to: "matilde@exemplo.pt", subject: "Re: A sua nova viatura", date: "2026-10-27T18:30:00Z", text: "Obrigada!", html: null, status: "recebida" },
        ] } });
        render(<MailConversation companyId={5} customerId={12} />);
        expect(await screen.findByText("Re: A sua nova viatura")).toBeInTheDocument();
        expect(screen.getByText(/Para joana@cliente.invalid/)).toBeInTheDocument();
        expect(screen.getByText(/De joana@cliente.invalid/)).toBeInTheDocument();
        fireEvent.click(screen.getByText("Re: A sua nova viatura"));
        expect(screen.getByText("Obrigada!")).toBeInTheDocument();
        expect(getCustomerMailMessages).toHaveBeenCalledWith(5, 12);
    });

    it("na viatura vendida; e escondida quando o backend recusa (root fora da empresa, impersonation)", async () => {
        (getCarMailMessages as jest.Mock).mockRejectedValue({ __status: 403, message: "A equipa XPLENDOR não vê o conteúdo dos e-mails das empresas." });
        const { container } = render(<MailConversation companyId={5} carId={9} />);
        await waitFor(() => expect(getCarMailMessages).toHaveBeenCalledWith(5, 9));
        await waitFor(() => expect(container).toBeEmptyDOMElement());
    });
});
