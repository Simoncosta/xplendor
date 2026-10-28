import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { toast } from "react-toastify";
import Inbox from "./Inbox";
import { getCustomers, getMailboxFolders, getMailboxMessages, getMailboxThread, importMailboxAttachment, linkMailboxMessage, sendMailboxMessage, setMailboxSeenMany, getMailboxComposeContext, deleteMailboxAttachment } from "helpers/laravel_helper";
import { downloadGet, fetchBlob } from "helpers/download_helper";
import { adjustMailboxUnread, refreshMailboxUnread } from "Components/Common/mailbox/useMailboxUnread";

jest.mock("react-router-dom", () => ({ Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a> }), { virtual: true });
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() } }));
jest.mock("Components/Common/mailbox/useMailboxUnread", () => ({ refreshMailboxUnread: jest.fn(), adjustMailboxUnread: jest.fn() }));
jest.mock("Components/Common/XInputTextareaQuill", () => function QuillStub({ name }: { name: string }) {
    const { useField } = jest.requireActual("formik");
    const [field, , helpers] = useField(name);
    return <textarea aria-label="Mensagem" value={field.value} onChange={(e: any) => helpers.setValue(e.target.value)} />;
});
// E-mail 7: quem pode carregar faturas (o módulo das Faturas e restauracao.criar); por omissão, ninguém.
let mockAccess = { faturas: false, criar: false };
jest.mock("contexts/ModulesContext", () => ({
    useAccess: () => ({ has: (m: string) => m === "restauracao_faturas" && mockAccess.faturas, can: (p: string) => p === "restauracao.criar" && mockAccess.criar }),
}));
jest.mock("helpers/download_helper", () => ({ fetchBlob: jest.fn(), downloadGet: jest.fn() }));
jest.mock("helpers/laravel_helper", () => ({
    getMailboxFolders: jest.fn(), getMailboxMessages: jest.fn(), getMailboxThread: jest.fn(), setMailboxSeenMany: jest.fn(), linkMailboxMessage: jest.fn(),
    getCustomers: jest.fn(), sendMailboxMessage: jest.fn(), importMailboxAttachment: jest.fn(),
    getMailboxComposeContext: jest.fn(() => Promise.resolve({ data: {} })), uploadMailboxAttachment: jest.fn(), attachMailboxFile: jest.fn(), deleteMailboxAttachment: jest.fn(() => Promise.resolve({})),
    mailboxAttachmentPath: (c: number, u: number, f: string, i: number, inline: boolean) => `/companies/${c}/my-mailbox/messages/${u}/attachments/${i}?folder=${f}${inline ? "&inline=1" : ""}`,
}));

const folders = [{ path: "INBOX", name: "Entrada", special: "inbox" }, { path: "INBOX.Sent", name: "Enviados", special: "sent" }];
const oficina = { name: "Oficina Exemplo", address: "oficina@fornecedor.invalid" };
const items = [
    { uid: 8, thread: "orc", subject: "RES: Orçamento da reparação", from: { name: "Matilde", address: "matilde@exemplo.pt" }, date: "2026-10-27T17:00:00Z", seen: true, flagged: false, has_attachments: false },
    { uid: 7, thread: "orc", subject: "Orçamento da reparação", from: oficina, date: "2026-10-27T16:00:00Z", seen: false, flagged: false, has_attachments: true },
    { uid: 3, thread: "bol", subject: "As novidades da semana", from: { name: null, address: "boletim@novidades.invalid" }, date: "2026-10-20T10:00:00Z", seen: true, flagged: false, has_attachments: false },
];
const full = (uid: number, over: any = {}) => ({
    uid, folder: "INBOX", subject: "Orçamento da reparação", from: oficina, to: [{ name: null, address: "matilde@exemplo.pt" }], cc: [], date: "2026-10-27T16:00:00Z",
    text: "Segue o orçamento.", html: '<p>Segue o orçamento.</p><img data-xp-src="https://rastreio.invalid/p.gif">', external_images: 1, seen: false, customer: null,
    attachments: [{ index: 1, name: "orcamento.pdf", mime: "application/pdf", size: 2048, previewable: true }, { index: 3, name: "desenho.svg", mime: "image/svg+xml", size: 89, previewable: false }],
    ...over,
});
const reply = full(8, { subject: "RES: Orçamento da reparação", from: { name: "Matilde", address: "matilde@exemplo.pt" }, to: [oficina], date: "2026-10-27T17:00:00Z",
    text: "Obrigada, aprovado.", html: null, external_images: 0, seen: true, attachments: [] });

const openThread = async () => {
    render(<Inbox companyId={5} email="matilde@exemplo.pt" profileLink="/users/4#mailbox" />);
    const rows = await screen.findAllByTestId("mail-thread");
    fireEvent.click(rows[0]);
    return screen.findByTestId("mail-detail");
};

describe("A caixa de entrada (E-mail 4)", () => {
    beforeEach(() => {
        jest.clearAllMocks();
        (getMailboxFolders as jest.Mock).mockResolvedValue({ data: { folders } });
        (getMailboxMessages as jest.Mock).mockResolvedValue({ data: { items, total: 3, per_page: 25, unread: 1 } });
        (getMailboxThread as jest.Mock).mockImplementation((_c: number, _f: string, uids: number[]) => Promise.resolve({ data: { messages: uids.map((uid) => (uid === 8 ? reply : full(uid))) } }));
        (setMailboxSeenMany as jest.Mock).mockResolvedValue({ data: { unread: 0 } });
        (getMailboxComposeContext as jest.Mock).mockResolvedValue({ data: {} });
        (deleteMailboxAttachment as jest.Mock).mockResolvedValue({});
        (global as any).URL.createObjectURL = jest.fn(() => "blob:pre-visualizacao");
        (global as any).URL.revokeObjectURL = jest.fn();
    });

    it("a lista agrupa por conversa, com o número, o clipe e as por ler destacadas", async () => {
        render(<Inbox companyId={5} email="matilde@exemplo.pt" profileLink="/users/4#mailbox" />);
        const rows = await screen.findAllByTestId("mail-thread");
        expect(rows).toHaveLength(2);
        expect(rows[0]).toHaveClass("unread");
        expect(rows[0]).toHaveTextContent("Oficina, eu");
        expect(within(rows[0]).getByTestId("mail-thread-count")).toHaveTextContent("2");
        expect(within(rows[0]).getByTestId("mail-clip")).toBeInTheDocument();
        expect(within(rows[1]).queryByTestId("mail-clip")).not.toBeInTheDocument();
        expect(within(rows[1]).queryByTestId("mail-thread-count")).not.toBeInTheDocument();
        expect(within(screen.getByTestId("mail-folders")).getAllByRole("link").map((a) => a.textContent?.trim())).toEqual(["Entrada1", "Com estrela", "Enviados"]);
    });

    it("abrir mostra a conversa em página inteira (a lista sai), marca as por ler e \"Voltar\" regressa", async () => {
        await openThread();
        expect(screen.queryByTestId("mail-list")).not.toBeInTheDocument();
        expect(await screen.findByTestId("mail-subject")).toHaveTextContent("RES: Orçamento da reparação");
        expect(screen.getByTestId("mail-thread-size")).toHaveTextContent("2 mensagens");
        const msgs = await screen.findAllByTestId("mail-message");
        expect(msgs[0]).toHaveTextContent("Oficina Exemplo"); // da mais antiga para a mais recente
        expect(msgs[1]).toHaveTextContent("Eu");
        await waitFor(() => expect(setMailboxSeenMany).toHaveBeenCalledWith(5, "INBOX", [7], true)); // só a por ler (a 8 já estava lida), num só pedido
        expect(getMailboxThread).toHaveBeenCalledWith(5, "INBOX", [8, 7]); // a conversa toda num só pedido
        fireEvent.click(screen.getByRole("button", { name: "Voltar" }));
        expect(await screen.findByTestId("mail-list")).toBeInTheDocument();
    });

    it("o \"Retroceder\" do navegador volta à lista", async () => {
        await openThread();
        await act(async () => { window.history.replaceState({}, ""); window.dispatchEvent(new PopStateEvent("popstate", { state: {} })); });
        expect(await screen.findByTestId("mail-list")).toBeInTheDocument();
    });

    it("\"Marcar como por ler\" marca a conversa toda e volta à lista", async () => {
        await openThread();
        await screen.findAllByTestId("mail-message");
        await act(async () => { fireEvent.click(screen.getByRole("button", { name: /Marcar como por ler/ })); });
        expect(setMailboxSeenMany).toHaveBeenLastCalledWith(5, "INBOX", [8, 7], false); // a conversa toda, num só pedido
        expect(await screen.findByTestId("mail-list")).toBeInTheDocument();
    });

    it("a mensagem por ler vem aberta com as imagens externas bloqueadas até \"Mostrar imagens\"; o iframe não corre scripts", async () => {
        await openThread();
        await screen.findByTestId("mail-images-blocked");
        const frame = screen.getByTestId("mail-frame");
        expect(frame.getAttribute("sandbox")).not.toContain("allow-scripts");
        expect(frame.getAttribute("srcdoc")).not.toContain(' src="https://rastreio');
        fireEvent.click(screen.getByRole("button", { name: "Mostrar imagens" }));
        expect(screen.getByTestId("mail-frame").getAttribute("srcdoc")).toContain(' src="https://rastreio.invalid/p.gif"');
    });

    it("anexos: \"Ver\" abre a pré-visualização ao vivo (só PDF e imagens); \"Descarregar\" descarrega", async () => {
        (fetchBlob as jest.Mock).mockResolvedValue({ ok: true, blob: new Blob(["%PDF"]), filename: "orcamento.pdf" });
        (downloadGet as jest.Mock).mockResolvedValue({ ok: true });
        await openThread();
        const box = await screen.findByTestId("mail-attachments", {}, { timeout: 3000 });
        expect(box).toHaveTextContent("2 anexos");
        expect(within(box).getAllByRole("button", { name: /Ver/ })).toHaveLength(1); // o SVG não tem "Ver"
        await act(async () => { fireEvent.click(within(box).getByRole("button", { name: /Ver/ })); });
        expect(fetchBlob).toHaveBeenCalledWith("/companies/5/my-mailbox/messages/7/attachments/1?folder=INBOX&inline=1");
        expect(within(await screen.findByTestId("mail-preview")).getByTitle("orcamento.pdf")).toHaveAttribute("src", "blob:pre-visualizacao");
        await act(async () => { fireEvent.click(within(box).getAllByRole("button", { name: /Descarregar/ })[1]); });
        expect(downloadGet).toHaveBeenCalledWith("/companies/5/my-mailbox/messages/7/attachments/3?folder=INBOX", "desenho.svg");
    });

    it("\"Ligar a um cliente\" numa mensagem da conversa", async () => {
        (getCustomers as jest.Mock).mockResolvedValue({ data: { data: [{ id: 12, name: "Joana Cliente", email: "joana@cliente.invalid" }] } });
        (linkMailboxMessage as jest.Mock).mockResolvedValue({ message: "Mensagem ligada a Joana Cliente.", data: { customer: { id: 12, name: "Joana Cliente" } } });
        await openThread();
        await screen.findAllByTestId("mail-message");
        fireEvent.click((await screen.findAllByRole("button", { name: /Ligar a um cliente/ }))[0]);
        const results = await screen.findByTestId("link-customer-results");
        const joana = await within(results).findByText("Joana Cliente");
        await act(async () => { fireEvent.click(joana); });
        expect(linkMailboxMessage).toHaveBeenCalledWith(5, 7, "INBOX", 12);
        expect(await screen.findByTestId("mail-customer")).toHaveTextContent("Ligada a Joana Cliente");
    });

    it("abrir uma conversa por ler tira logo do número (antes da resposta do servidor) e depois acerta", async () => {
        let release: (v: any) => void = () => undefined;
        (setMailboxSeenMany as jest.Mock).mockImplementation(() => new Promise((r) => { release = r; }));
        render(<Inbox companyId={5} email="matilde@exemplo.pt" profileLink="/users/4#mailbox" />);
        const rows = await screen.findAllByTestId("mail-thread");
        (refreshMailboxUnread as jest.Mock).mockClear();
        fireEvent.click(rows[0]);
        expect(adjustMailboxUnread).toHaveBeenCalledWith(-1); // a 7 estava por ler; a 8 já estava lida
        expect(within(screen.getByTestId("mail-folders")).getAllByRole("link")[0]).toHaveTextContent(/^Entrada$/); // de 1 para 0, já
        expect(refreshMailboxUnread).not.toHaveBeenCalled(); // ainda à espera do servidor
        await act(async () => { release({ data: { unread: 0 } }); });
        expect(setMailboxSeenMany).toHaveBeenCalledWith(5, "INBOX", [7], true);
        await waitFor(() => expect(refreshMailboxUnread).toHaveBeenCalled());
    });

    it("\"Marcar como por ler\" soma logo ao número", async () => {
        await openThread();
        await screen.findAllByTestId("mail-message");
        (adjustMailboxUnread as jest.Mock).mockClear();
        await act(async () => { fireEvent.click(screen.getByRole("button", { name: /Marcar como por ler/ })); });
        expect(adjustMailboxUnread).toHaveBeenCalledWith(2); // as duas mensagens da conversa
    });

    it("se o servidor recusar \"marcar como lida\", o ecrã volta atrás e diz porquê", async () => {
        (setMailboxSeenMany as jest.Mock).mockRejectedValue({ message: "Indique se a mensagem fica lida ou por ler." });
        render(<Inbox companyId={5} email="matilde@exemplo.pt" profileLink="/users/4#mailbox" />);
        const rows = await screen.findAllByTestId("mail-thread");
        fireEvent.click(rows[0]);
        await waitFor(() => expect(toast.error).toHaveBeenCalledWith("Não foi possível marcar como lida: Indique se a mensagem fica lida ou por ler."));
        expect(adjustMailboxUnread).toHaveBeenNthCalledWith(1, -1);
        expect(adjustMailboxUnread).toHaveBeenLastCalledWith(1); // volta atrás
        fireEvent.click(screen.getByRole("button", { name: "Voltar" }));
        expect((await screen.findAllByTestId("mail-thread"))[0]).toHaveClass("unread");
    });

    it("E-mail 5: \"Escrever\" abre uma mensagem nova, com a assinatura da caixa", async () => {
        (sendMailboxMessage as jest.Mock).mockResolvedValue({ message: "ok", data: { message: { id: 1 } } });
        render(<Inbox companyId={5} email="matilde@exemplo.pt" signature="Matilde" profileLink="/users/4#mailbox" />);
        await screen.findAllByTestId("mail-thread");
        fireEvent.click(screen.getByTestId("mail-compose-open"));
        expect(await screen.findByText("Nova mensagem")).toBeInTheDocument();
        expect(screen.getByLabelText("Para")).toHaveValue("");
        expect(screen.getByTestId("mail-compose-signature")).toHaveTextContent("Matilde");
        expect(screen.queryByTestId("mail-compose-quote")).not.toBeInTheDocument();
        fireEvent.change(screen.getByLabelText("Para"), { target: { value: "a@exemplo.invalid" } });
        fireEvent.change(screen.getByLabelText("Mensagem"), { target: { value: "<p>Olá</p>" } });
        await act(async () => { fireEvent.click(screen.getByRole("button", { name: /Enviar/ })); });
        expect((sendMailboxMessage as jest.Mock).mock.calls[0][1]).toMatchObject({ to: "a@exemplo.invalid", reply_to: null });
    });

    it("E-mail 5: \"Responder\" responde a quem enviou, com \"Re:\" e no mesmo fio; à minha própria mensagem, responde a quem a mandei", async () => {
        (sendMailboxMessage as jest.Mock).mockResolvedValue({ message: "ok", data: { message: { id: 1 } } });
        await openThread();
        const replies = await screen.findAllByTestId("mail-reply");
        fireEvent.click(replies[0]); // a da oficina (a mais antiga, por ler: aberta)
        expect(await screen.findByText("Responder", { selector: ".modal-title" })).toBeInTheDocument();
        expect(screen.getByLabelText("Para")).toHaveValue("oficina@fornecedor.invalid");
        expect(screen.getByLabelText("Assunto")).toHaveValue("Re: Orçamento da reparação");
        expect(screen.getByTestId("mail-compose-quote")).toHaveTextContent("Oficina Exemplo escreveu:");
        fireEvent.change(screen.getByLabelText("Mensagem"), { target: { value: "<p>Obrigada</p>" } });
        await act(async () => { fireEvent.click(screen.getByRole("button", { name: /Enviar/ })); });
        expect((sendMailboxMessage as jest.Mock).mock.calls[0][1]).toMatchObject({ reply_to: { folder: "INBOX", uid: 7 }, to: "oficina@fornecedor.invalid" });

        // A mais recente é minha ("RES: ..."): o assunto fica, e responde à oficina.
        fireEvent.click(screen.getByTestId("mail-reply-latest"));
        expect(await screen.findByLabelText("Assunto")).toHaveValue("RES: Orçamento da reparação");
        expect(screen.getByLabelText("Para")).toHaveValue("oficina@fornecedor.invalid");
    });

    it("E-mail 7: sem a permissão de carregar faturas, o botão não existe", async () => {
        mockAccess = { faturas: true, criar: false };
        await openThread();
        expect(await screen.findAllByTestId("mail-attachments")).not.toHaveLength(0);
        expect(screen.queryByTestId("mail-invoice-import")).not.toBeInTheDocument();
    });

    it("E-mail 7: \"Importar para as Faturas\" só no PDF e nas imagens, e o resultado fica no anexo com a ligação", async () => {
        mockAccess = { faturas: true, criar: true };
        (getMailboxThread as jest.Mock).mockResolvedValue({ data: { messages: [full(7, {
            attachments: [{ index: 1, name: "fatura.pdf", mime: "application/pdf", size: 2048, previewable: true }, { index: 2, name: "talao.png", mime: "image/png", size: 900, previewable: true },
                { index: 3, name: "desenho.svg", mime: "image/svg+xml", size: 89, previewable: false }] })] } });
        (importMailboxAttachment as jest.Mock)
            .mockResolvedValueOnce({ message: "Fatura criada.", data: { id: 41, status: "processing" } })
            .mockRejectedValueOnce({ __status: 409, message: "Já carregada: abrir a fatura #12.", errors: { code: "ficheiro_duplicado", existing_id: 12 } });
        await openThread();
        const buttons = await screen.findAllByTestId("mail-invoice-import");
        expect(buttons).toHaveLength(2); // o SVG não
        fireEvent.click(buttons[0]);
        expect(await screen.findByText(/Fatura criada/)).toBeInTheDocument();
        expect(importMailboxAttachment).toHaveBeenCalledWith(5, 7, "INBOX", 1);
        expect(screen.getByRole("link", { name: "abrir a fatura #41" })).toHaveAttribute("href", "/restauracao/faturas/41");
        fireEvent.click(screen.getByTestId("mail-invoice-import"));
        expect(await screen.findByText(/Já existe/)).toBeInTheDocument();
        expect(screen.getByRole("link", { name: "abrir a fatura #12" })).toHaveAttribute("href", "/restauracao/faturas/12");
        mockAccess = { faturas: false, criar: false };
    });
});
