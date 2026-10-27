import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import MailConversation from "./MailConversation";
import { getCarMailMessages, getCustomerMailMessages, getMailboxComposeContext, sendMailboxMessage } from "helpers/laravel_helper";

jest.mock("helpers/laravel_helper", () => ({ getCustomerMailMessages: jest.fn(), getCarMailMessages: jest.fn(), getMailboxComposeContext: jest.fn(), sendMailboxMessage: jest.fn(),
    uploadMailboxAttachment: jest.fn(), attachMailboxFile: jest.fn(), deleteMailboxAttachment: jest.fn(() => Promise.resolve({})) }));
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() } }));
jest.mock("Components/Common/XInputTextareaQuill", () => function QuillStub({ name }: { name: string }) {
    const { useField } = jest.requireActual("formik");
    const [field, , helpers] = useField(name);
    return <textarea aria-label="Mensagem" value={field.value} onChange={(e) => helpers.setValue(e.target.value)} />;
});

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

    it("E-mail 5: \"Escrever ao cliente\" abre com o endereço e a assinatura, envia ligado ao cliente e a conversa recarrega", async () => {
        (getCustomerMailMessages as jest.Mock).mockResolvedValue({ data: { messages: [] } });
        (getMailboxComposeContext as jest.Mock).mockResolvedValue({ data: { to: "joana@cliente.invalid", name: "Joana Cliente", consent: true, blocked: null,
            mailbox: { email: "matilde@exemplo.pt", signature: "Matilde" } } });
        (sendMailboxMessage as jest.Mock).mockResolvedValue({ message: "Mensagem enviada pela sua caixa.", data: { message: { id: 3 } } });
        render(<MailConversation companyId={5} customerId={12} />);
        fireEvent.click(await screen.findByTestId("mail-write-customer"));
        expect(await screen.findByText("Escrever a Joana Cliente")).toBeInTheDocument();
        expect(getMailboxComposeContext).toHaveBeenCalledWith(5, { customerId: 12, carId: undefined });
        expect(screen.getByLabelText("Para")).toHaveValue("joana@cliente.invalid");
        expect(screen.getByTestId("mail-compose-signature")).toHaveTextContent("Matilde");
        fireEvent.change(screen.getByLabelText("Assunto"), { target: { value: "A sua viatura" } });
        fireEvent.change(screen.getByLabelText("Mensagem"), { target: { value: "<p>Está pronta.</p>" } });
        await act(async () => { fireEvent.click(screen.getByRole("button", { name: /Enviar/ })); });
        expect((sendMailboxMessage as jest.Mock).mock.calls[0][1]).toMatchObject({ to: "joana@cliente.invalid", customer_id: 12, car_id: null, reply_to: null });
        await waitFor(() => expect(getCustomerMailMessages).toHaveBeenCalledTimes(2));
    });

    it("E-mail 5: sem consentimento de contacto, avisa e não deixa enviar", async () => {
        (getCarMailMessages as jest.Mock).mockResolvedValue({ data: { messages: [] } });
        (getMailboxComposeContext as jest.Mock).mockResolvedValue({ data: { to: "rita@compradora.invalid", name: "Rita", consent: false, blocked: null, mailbox: { email: "m@exemplo.pt", signature: null } } });
        render(<MailConversation companyId={5} carId={9} />);
        fireEvent.click(await screen.findByTestId("mail-write-customer"));
        expect(await screen.findByTestId("mail-compose-blocked")).toHaveTextContent("O cliente não deu consentimento para contacto (RGPD)");
        fireEvent.change(screen.getByLabelText("Mensagem"), { target: { value: "<p>Olá</p>" } });
        expect(screen.getByRole("button", { name: /Enviar/ })).toBeDisabled();
        expect(getMailboxComposeContext).toHaveBeenCalledWith(5, { customerId: undefined, carId: 9 });
    });

    it("E-mail 5b: a mensagem enviada mostra os anexos (só o nome e o tamanho)", async () => {
        (getCustomerMailMessages as jest.Mock).mockResolvedValue({ data: { messages: [
            { id: 1, direction: "out", from: "m@exemplo.pt", to: "joana@cliente.invalid", subject: "Proposta", date: "2026-10-27T12:00:00Z", text: "Segue.", html: null, status: "enviada",
                attachments: [{ name: "proposta.pdf", size: 204800 }, { name: "foto.jpg", size: 512 }] },
        ] } });
        render(<MailConversation companyId={5} customerId={12} />);
        expect(await screen.findByTestId("mail-conversation-attachments")).toHaveTextContent("proposta.pdf (200 KB), foto.jpg (512 B)");
    });
});
