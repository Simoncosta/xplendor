import { act, fireEvent, render, screen } from "@testing-library/react";
import ComposeModal, { quoteHeaderFor, replySubject } from "./ComposeModal";
import { attachMailboxFile, deleteMailboxAttachment, getMailboxComposeContext, sendMailboxMessage, uploadMailboxAttachment } from "helpers/laravel_helper";
import { toast } from "react-toastify";

jest.mock("helpers/laravel_helper", () => ({
    sendMailboxMessage: jest.fn(), getMailboxComposeContext: jest.fn(), uploadMailboxAttachment: jest.fn(), attachMailboxFile: jest.fn(), deleteMailboxAttachment: jest.fn(),
}));
// E-mail 5b: quem vê os Ficheiros (por omissão, ninguém) e o seletor (um botão que escolhe um ficheiro).
let mockFiles = false;
jest.mock("contexts/ModulesContext", () => ({ useAccess: () => ({ has: (m: string) => m === "ficheiros" && mockFiles, can: (p: string) => p === "ficheiros.ver" && mockFiles }) }));
jest.mock("pages/Files/FilePickerModal", () => function PickerStub({ onPick, mode }: any) {
    return <button type="button" onClick={() => onPick({ id: 77, name: "catálogo.pdf", size_bytes: 3000 })}>Escolher catálogo ({mode})</button>;
});
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() } }));
// O Quill não corre no jsdom: um campo de texto ligado ao Formik da mesma forma (name → HTML).
jest.mock("Components/Common/XInputTextareaQuill", () => function QuillStub({ name }: { name: string }) {
    const { useField } = jest.requireActual("formik");
    const [field, , helpers] = useField(name);
    return <textarea aria-label="Mensagem" value={field.value} onChange={(e: any) => helpers.setValue(e.target.value)} />;
});

const fill = (label: string, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } });

describe("Escrever uma mensagem (E-mail 5)", () => {
    beforeEach(() => {
        jest.clearAllMocks();
        mockFiles = false;
        (getMailboxComposeContext as jest.Mock).mockResolvedValue({ data: { attachments: { max_files: 3, max_bytes: 10000, max_file_bytes: 6000 } } });
        (deleteMailboxAttachment as jest.Mock).mockResolvedValue({});
    });

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
            reply_to: { folder: "INBOX", uid: 7 }, customer_id: 12, car_id: null, attachments: [] });
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

    const pick = (...files: File[]) => fireEvent.change(screen.getByTestId("mail-compose-file"), { target: { files } });
    const file = (name: string, size: number) => new File([new Uint8Array(size)], name);

    it("E-mail 5b: do computador, carrega logo com o progresso, envia os identificadores e não apaga o que foi enviado", async () => {
        let progress: (p: number) => void = () => undefined;
        let finish: (v: any) => void = () => undefined;
        (uploadMailboxAttachment as jest.Mock).mockImplementation((_c: number, _f: File, onProgress: (p: number) => void) => {
            progress = onProgress;
            return new Promise((resolve) => { finish = resolve; });
        });
        (sendMailboxMessage as jest.Mock).mockResolvedValue({ message: "ok", data: { message: { id: 1 } } });
        const { unmount } = render(<ComposeModal isOpen toggle={jest.fn()} companyId={5} initial={{ to: "a@exemplo.invalid", subject: "Proposta" }} />);
        await act(async () => { await Promise.resolve(); });
        fill("Mensagem", "<p>Segue.</p>");
        pick(file("proposta.pdf", 2048));
        expect(uploadMailboxAttachment).toHaveBeenCalledWith(5, expect.any(File), expect.any(Function));
        expect(screen.getByText("proposta.pdf")).toBeInTheDocument();
        act(() => progress(40));
        expect(screen.getByLabelText("A carregar proposta.pdf")).toBeInTheDocument();
        expect(screen.getByRole("button", { name: /Enviar/ })).toBeDisabled(); // espera que acabem de carregar
        await act(async () => { finish({ data: { attachment: { id: "11111111-1111-4111-8111-111111111111", name: "proposta.pdf", size: 2048 } } }); });
        expect(screen.getByText(/1 de 3 anexos · 2 KB de 0 MB/)).toBeInTheDocument();
        await act(async () => { fireEvent.click(screen.getByRole("button", { name: /Enviar/ })); });
        expect((sendMailboxMessage as jest.Mock).mock.calls[0][1].attachments).toEqual(["11111111-1111-4111-8111-111111111111"]);
        unmount();
        expect(deleteMailboxAttachment).not.toHaveBeenCalled();
    });

    it("E-mail 5b: arrastar para a janela também anexa; executáveis, tamanho e número recusados no ecrã, com o motivo", async () => {
        (uploadMailboxAttachment as jest.Mock).mockResolvedValue({ data: { attachment: { id: "22222222-2222-4222-8222-222222222222", name: "x", size: 10 } } });
        render(<ComposeModal isOpen toggle={jest.fn()} companyId={5} />);
        await act(async () => { await Promise.resolve(); });
        const zone = screen.getByTestId("mail-compose-drop");
        fireEvent.dragOver(zone, { dataTransfer: { files: [] } });
        expect(screen.getByTestId("mail-compose-dropzone")).toHaveTextContent("Largue aqui para anexar");
        await act(async () => { fireEvent.drop(zone, { dataTransfer: { files: [file("foto.jpg", 100)] } }); });
        expect(screen.queryByTestId("mail-compose-dropzone")).not.toBeInTheDocument();
        expect(uploadMailboxAttachment).toHaveBeenCalledTimes(1);

        await act(async () => { pick(file("instalar.exe", 10), file("enorme.pdf", 7000), file("b.pdf", 10), file("c.pdf", 10), file("d.pdf", 10)); });
        expect(screen.getByText("Os ficheiros .exe não se podem anexar (são programas ou scripts).")).toBeInTheDocument();
        expect(screen.getByText("O ficheiro tem mais de 0 MB.")).toBeInTheDocument();
        expect(screen.getByText("No máximo 3 anexos por mensagem.")).toBeInTheDocument();
        expect(uploadMailboxAttachment).toHaveBeenCalledTimes(3); // foto, b e c
    });

    it("E-mail 5b: o motivo do servidor (vírus) fica no anexo; remover e cancelar apagam os carregados; o texto fica", async () => {
        (uploadMailboxAttachment as jest.Mock)
            .mockRejectedValueOnce({ __status: 422, message: "O antivírus encontrou um vírus em \"fatura.pdf\". O anexo não foi carregado." })
            .mockResolvedValueOnce({ data: { attachment: { id: "33333333-3333-4333-8333-333333333333", name: "bom.pdf", size: 10 } } })
            .mockResolvedValueOnce({ data: { attachment: { id: "44444444-4444-4444-8444-444444444444", name: "outro.pdf", size: 10 } } });
        const { unmount } = render(<ComposeModal isOpen toggle={jest.fn()} companyId={5} />);
        await act(async () => { await Promise.resolve(); });
        fill("Mensagem", "<p>Texto que fica.</p>");
        await act(async () => { pick(file("fatura.pdf", 10)); });
        expect(screen.getByText(/O antivírus encontrou um vírus/)).toBeInTheDocument();
        await act(async () => { pick(file("bom.pdf", 10), file("outro.pdf", 10)); });
        fireEvent.click(screen.getByRole("button", { name: "Remover bom.pdf" }));
        expect(deleteMailboxAttachment).toHaveBeenCalledWith(5, "33333333-3333-4333-8333-333333333333");
        expect(screen.getByLabelText("Mensagem")).toHaveValue("<p>Texto que fica.</p>");
        unmount(); // cancelar sem enviar
        expect(deleteMailboxAttachment).toHaveBeenCalledWith(5, "44444444-4444-4444-8444-444444444444");
    });

    it("E-mail 5b: \"Dos Ficheiros\" só para quem os vê, e o ficheiro escolhido entra na lista", async () => {
        const { unmount } = render(<ComposeModal isOpen toggle={jest.fn()} companyId={5} limits={{ max_files: 10, max_bytes: 20971520, max_file_bytes: 20971520 }} />);
        expect(screen.queryByRole("button", { name: /Dos Ficheiros/ })).not.toBeInTheDocument();
        expect(getMailboxComposeContext).not.toHaveBeenCalled(); // com os limites dados, não os pede
        unmount();
        mockFiles = true;
        (attachMailboxFile as jest.Mock).mockResolvedValue({ data: { attachment: { id: "55555555-5555-4555-8555-555555555555", name: "catálogo.pdf", size: 3000 } } });
        render(<ComposeModal isOpen toggle={jest.fn()} companyId={5} limits={{ max_files: 10, max_bytes: 20971520, max_file_bytes: 20971520 }} />);
        fireEvent.click(screen.getByRole("button", { name: /Dos Ficheiros/ }));
        await act(async () => { fireEvent.click(screen.getByRole("button", { name: "Escolher catálogo (attachment)" })); });
        expect(attachMailboxFile).toHaveBeenCalledWith(5, 77);
        expect(screen.getByText("catálogo.pdf")).toBeInTheDocument();
        expect(screen.getByText(/1 de 10 anexos · 3 KB de 20 MB/)).toBeInTheDocument();
    });
});
