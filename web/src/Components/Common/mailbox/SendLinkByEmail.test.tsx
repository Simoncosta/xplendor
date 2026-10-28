import { act, fireEvent, render, screen } from "@testing-library/react";
import SendLinkByEmail from "./SendLinkByEmail";
import { getMailboxLinkContext, sendMailboxMessage } from "helpers/laravel_helper";
import { isImpersonating } from "helpers/impersonation";

jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() } }));
jest.mock("helpers/laravel_helper", () => ({
    getMailboxLinkContext: jest.fn(), sendMailboxMessage: jest.fn(), getMailboxComposeContext: jest.fn(),
    uploadMailboxAttachment: jest.fn(), attachMailboxFile: jest.fn(), deleteMailboxAttachment: jest.fn(),
}));
jest.mock("helpers/impersonation", () => ({ isImpersonating: jest.fn(() => false) }));
jest.mock("helpers/roles", () => ({ sessionUser: () => ({ id: 4 }) }));
jest.mock("Components/Common/XInputTextareaQuill", () => function QuillStub({ name }: { name: string }) {
    const { useField } = jest.requireActual("formik");
    const [field, , helpers] = useField(name);
    return <textarea aria-label="Mensagem" value={field.value} onChange={(e: any) => helpers.setValue(e.target.value)} />;
});

const URL = "https://app.exemplo.invalid/enviar-documentos#Abc123";
const context = (over: any = {}) => ({ data: {
    to: "joana@cliente.invalid", name: "Joana Cliente", consent: true, subject: "Documentos para o financiamento · Quebom",
    body: `<p>Olá, Joana Cliente,</p><p><a href="${URL}">${URL}</a></p><p>O link é pessoal e válido até 03/11/2026.</p>`, expires_at: "2026-11-03T23:59:59Z",
    link: { kind: "financing", id: 31 }, mailbox: { email: "matilde@exemplo.pt", signature: "Matilde Exemplo" }, blocked: null,
    attachments: { max_files: 10, max_bytes: 20971520, max_file_bytes: 20971520 }, ...over,
} });

/** E-mail 9 · "Enviar por email" nos cartões do financiamento e da retoma. */
describe("Enviar o link por email", () => {
    beforeEach(() => { jest.clearAllMocks(); (isImpersonating as jest.Mock).mockReturnValue(false); });

    it("abre a janela preenchida (endereço, assunto, texto com o link e a assinatura) e envia com o link, em JSON", async () => {
        (getMailboxLinkContext as jest.Mock).mockResolvedValue(context());
        (sendMailboxMessage as jest.Mock).mockResolvedValue({ message: "ok", data: { message: { id: 1 } } });
        const onSent = jest.fn();
        render(<SendLinkByEmail companyId={5} kind="financing" linkId={31} onSent={onSent} />);
        await act(async () => { fireEvent.click(screen.getByTestId("send-link-31")); });
        expect(getMailboxLinkContext).toHaveBeenCalledWith(5, "financing", 31);
        expect(screen.getByText("Enviar o link do financiamento")).toBeInTheDocument();
        expect(screen.getByLabelText("Para")).toHaveValue("joana@cliente.invalid");
        expect(screen.getByLabelText("Assunto")).toHaveValue("Documentos para o financiamento · Quebom");
        expect((screen.getByLabelText("Mensagem") as HTMLTextAreaElement).value).toContain(URL);
        expect(screen.getByTestId("mail-compose-signature")).toHaveTextContent("Matilde Exemplo");
        // A pessoa muda o texto antes de enviar.
        fireEvent.change(screen.getByLabelText("Mensagem"), { target: { value: `<p>Boa tarde! ${URL}</p>` } });
        await act(async () => { fireEvent.click(screen.getByRole("button", { name: /^Enviar$/ })); });
        expect(sendMailboxMessage).toHaveBeenCalledWith(5, expect.objectContaining({ to: "joana@cliente.invalid", body: `<p>Boa tarde! ${URL}</p>`, link: { kind: "financing", id: 31 } }));
        expect(onSent).toHaveBeenCalled();
    });

    it("sem caixa configurada, leva a \"Ligar a minha caixa\"", async () => {
        (getMailboxLinkContext as jest.Mock).mockResolvedValue(context({ mailbox: null, blocked: "Ligue primeiro a sua caixa de correio no Perfil." }));
        render(<SendLinkByEmail companyId={5} kind="trade_in" linkId={8} variant="link" />);
        await act(async () => { fireEvent.click(screen.getByTestId("send-link-8")); });
        expect(screen.getByTestId("send-link-no-mailbox")).toBeInTheDocument();
        expect(screen.getByRole("link", { name: /Ligar a minha caixa/ })).toHaveAttribute("href", "/app/users/4#mailbox");
        expect(screen.queryByTestId("mail-compose")).not.toBeInTheDocument();
    });

    it("sem consentimento de contacto, o aviso e o envio desligado", async () => {
        (getMailboxLinkContext as jest.Mock).mockResolvedValue(context({ consent: false }));
        render(<SendLinkByEmail companyId={5} kind="financing" linkId={31} />);
        await act(async () => { fireEvent.click(screen.getByTestId("send-link-31")); });
        expect(screen.getByTestId("mail-compose-blocked")).toHaveTextContent("O cliente não deu consentimento para contacto (RGPD)");
        expect(screen.getByRole("button", { name: /^Enviar$/ })).toBeDisabled();
    });

    it("durante a impersonation, o botão não existe", () => {
        (isImpersonating as jest.Mock).mockReturnValue(true);
        const { container } = render(<SendLinkByEmail companyId={5} kind="financing" linkId={31} />);
        expect(container).toBeEmptyDOMElement();
    });
});
