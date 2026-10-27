import { act, fireEvent, render, screen } from "@testing-library/react";
import ComposeModal, { quoteHeaderFor, replySubject } from "./ComposeModal";
import { sendMailboxMessage } from "helpers/laravel_helper";
import { toast } from "react-toastify";

jest.mock("helpers/laravel_helper", () => ({ sendMailboxMessage: jest.fn() }));
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() } }));
// O Quill não corre no jsdom: um campo de texto ligado ao Formik da mesma forma (name → HTML).
jest.mock("Components/Common/XInputTextareaQuill", () => function QuillStub({ name }: { name: string }) {
    const { useField } = jest.requireActual("formik");
    const [field, , helpers] = useField(name);
    return <textarea aria-label="Mensagem" value={field.value} onChange={(e: any) => helpers.setValue(e.target.value)} />;
});

const fill = (label: string, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } });

describe("Escrever uma mensagem (E-mail 5)", () => {
    beforeEach(() => jest.clearAllMocks());

    it("envia tal como o servidor espera: Para, Cc, assunto, o HTML do editor, a resposta e as ligações", async () => {
        (sendMailboxMessage as jest.Mock).mockResolvedValue({ message: "Mensagem enviada pela sua caixa.", data: { message: { id: 1 } } });
        const toggle = jest.fn();
        const onSent = jest.fn();
        render(<ComposeModal isOpen toggle={toggle} companyId={5} title="Responder" initial={{ to: "oficina@fornecedor.invalid", subject: "Re: Orçamento" }}
            replyTo={{ folder: "INBOX", uid: 7 }} quoteHeader="Em 27 de outubro de 2026, às 16:00, Oficina escreveu:" customerId={12} signature={"Matilde\nQuebom"} onSent={onSent} />);
        expect(screen.getByTestId("mail-compose-signature")).toHaveTextContent("-- Matilde Quebom");
        expect(screen.getByTestId("mail-compose-quote")).toHaveTextContent("Em 27 de outubro de 2026, às 16:00, Oficina escreveu: A mensagem original vai citada por baixo.");
        const send = screen.getByRole("button", { name: /Enviar/ });
        expect(send).toBeDisabled(); // sem texto
        fireEvent.click(screen.getByRole("button", { name: "Cc" }));
        fill("Cc", "colega@exemplo.invalid");
        fill("Mensagem", "<p>Aprovado.</p>");
        await act(async () => { fireEvent.click(send); });
        expect(sendMailboxMessage).toHaveBeenCalledWith(5, { to: "oficina@fornecedor.invalid", cc: "colega@exemplo.invalid", subject: "Re: Orçamento", body: "<p>Aprovado.</p>",
            reply_to: { folder: "INBOX", uid: 7 }, customer_id: 12, car_id: null });
        expect(toast.success).toHaveBeenCalledWith("Mensagem enviada pela sua caixa.");
        expect(onSent).toHaveBeenCalledWith({ id: 1 });
        expect(toggle).toHaveBeenCalled();
    });

    it("se o servidor recusar, mostra o motivo e o texto fica", async () => {
        (sendMailboxMessage as jest.Mock).mockRejectedValue({ __status: 422, message: "A sua caixa chegou ao limite de 30 envios por hora. Tente mais tarde." });
        const toggle = jest.fn();
        render(<ComposeModal isOpen toggle={toggle} companyId={5} />);
        fill("Para", "a@exemplo.invalid");
        fill("Assunto", "Olá");
        fill("Mensagem", "<p>Um texto comprido que não se pode perder.</p>");
        await act(async () => { fireEvent.click(screen.getByRole("button", { name: /Enviar/ })); });
        expect(screen.getByTestId("mail-compose-error")).toHaveTextContent("A sua caixa chegou ao limite de 30 envios por hora. Tente mais tarde.");
        expect(screen.getByLabelText("Mensagem")).toHaveValue("<p>Um texto comprido que não se pode perder.</p>");
        expect(screen.getByLabelText("Assunto")).toHaveValue("Olá");
        expect(toggle).not.toHaveBeenCalled();
        // O erro de validação do Laravel (errors) também aparece.
        (sendMailboxMessage as jest.Mock).mockRejectedValue({ __status: 422, message: "The given data was invalid.", errors: { to: ["Indique pelo menos um destinatário."] } });
        await act(async () => { fireEvent.click(screen.getByRole("button", { name: /Enviar/ })); });
        expect(screen.getByTestId("mail-compose-error")).toHaveTextContent("Indique pelo menos um destinatário.");
    });

    it("com um motivo que impede o envio (sem consentimento), avisa e não deixa enviar", () => {
        render(<ComposeModal isOpen toggle={jest.fn()} companyId={5} initial={{ to: "joana@cliente.invalid" }} customerId={12}
            blocked="O cliente não deu consentimento para contacto (RGPD): não se pode escrever-lhe." />);
        fill("Mensagem", "<p>Olá</p>");
        expect(screen.getByTestId("mail-compose-blocked")).toHaveTextContent("não deu consentimento");
        expect(screen.getByRole("button", { name: /Enviar/ })).toBeDisabled();
    });

    it("o assunto da resposta leva \"Re: \" uma vez e a frase da citação é a do servidor", () => {
        expect(replySubject("Orçamento")).toBe("Re: Orçamento");
        expect(replySubject("Re: Orçamento")).toBe("Re: Orçamento");
        expect(replySubject("RES: Orçamento")).toBe("RES: Orçamento");
        expect(replySubject("")).toBe("Re: (sem assunto)");
        expect(quoteHeaderFor("2026-10-27T17:00:00Z", { name: "Boletim", address: "boletim@novidades.invalid" })).toBe("Em 27 de outubro de 2026, às 17:00, Boletim escreveu:");
        expect(quoteHeaderFor(null, { name: null, address: "a@exemplo.invalid" })).toBe("Em data desconhecida, a@exemplo.invalid escreveu:");
    });
});
