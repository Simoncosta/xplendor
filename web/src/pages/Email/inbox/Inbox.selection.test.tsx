import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { toast } from "react-toastify";
import Inbox from "./Inbox";
import { getMailboxFolders, getMailboxMessages, getMailboxThread, restoreMailboxMessages, setMailboxFlag, setMailboxSeenMany, trashMailboxMessages } from "helpers/laravel_helper";

jest.mock("react-router-dom", () => ({ Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a> }), { virtual: true });
// O toast do "Desfazer" é uma função que desenha o conteúdo: guarda-se para o testar.
jest.mock("react-toastify", () => {
    const t: any = jest.fn();
    t.success = jest.fn();
    t.error = jest.fn();
    return { toast: t };
});
jest.mock("Components/Common/mailbox/useMailboxUnread", () => ({ refreshMailboxUnread: jest.fn(), adjustMailboxUnread: jest.fn() }));
jest.mock("contexts/ModulesContext", () => ({ useAccess: () => ({ has: () => false, can: () => false }) }));
jest.mock("helpers/download_helper", () => ({ fetchBlob: jest.fn(), downloadGet: jest.fn() }));
jest.mock("helpers/laravel_helper", () => ({
    getMailboxFolders: jest.fn(), getMailboxMessages: jest.fn(), getMailboxThread: jest.fn(), setMailboxSeenMany: jest.fn(), linkMailboxMessage: jest.fn(),
    setMailboxFlag: jest.fn(), trashMailboxMessages: jest.fn(), restoreMailboxMessages: jest.fn(),
    getCustomers: jest.fn(), sendMailboxMessage: jest.fn(), importMailboxAttachment: jest.fn(),
    getMailboxComposeContext: jest.fn(() => Promise.resolve({ data: {} })), uploadMailboxAttachment: jest.fn(), attachMailboxFile: jest.fn(), deleteMailboxAttachment: jest.fn(),
    mailboxAttachmentPath: () => "/x",
}));

const folders = [{ path: "INBOX", name: "Entrada", special: "inbox" }, { path: "INBOX.Trash", name: "Lixo", special: "trash" }];
const from = { name: "Oficina Exemplo", address: "oficina@fornecedor.invalid" };
const msg = (uid: number, subject: string, over: any = {}) => ({ uid, thread: `t${uid}`, subject, from, date: "2026-10-27T16:00:00Z", seen: true, flagged: false,
    has_attachments: false, message_id: `<m${uid}@exemplo.invalid>`, ...over });
const items = [msg(3, "Terceira", { seen: false }), msg(2, "Segunda")];
const starred = [msg(1, "Primeira com estrela", { flagged: true })];

const renderInbox = async () => {
    render(<Inbox companyId={5} email="matilde@exemplo.pt" profileLink="/users/4#mailbox" />);
    await screen.findByTestId("mail-list");
};
const rowOf = (subject: string) => screen.getByText(subject).closest("li") as HTMLElement;

describe("A caixa de entrada: seleção, estrela, apagar e desfazer (E-mail 10)", () => {
    beforeEach(() => {
        jest.clearAllMocks();
        (getMailboxFolders as jest.Mock).mockResolvedValue({ data: { folders } });
        (getMailboxMessages as jest.Mock).mockResolvedValue({ data: { items, starred, starred_total: 1, total: 2, per_page: 25, unread: 1 } });
        (getMailboxThread as jest.Mock).mockResolvedValue({ data: { messages: [] } });
        (setMailboxSeenMany as jest.Mock).mockResolvedValue({ data: { unread: 0 } });
        (setMailboxFlag as jest.Mock).mockResolvedValue({ data: { flagged: true } });
        (trashMailboxMessages as jest.Mock).mockResolvedValue({ message: "Mensagem movida para o Lixo.", data: { moved: 1, unread: 0, undo: { folder: "INBOX", message_ids: ["<m3@exemplo.invalid>"] } } });
        (restoreMailboxMessages as jest.Mock).mockResolvedValue({ data: { restored: 1 } });
    });

    it("as com estrela ficam no topo da Entrada, antes das outras", async () => {
        await renderInbox();
        expect(screen.getByTestId("mail-starred-title")).toHaveTextContent("Com estrela");
        expect(within(screen.getByTestId("mail-starred-list")).getByText("Primeira com estrela")).toBeInTheDocument();
        expect(within(screen.getByTestId("mail-list")).queryByText("Primeira com estrela")).not.toBeInTheDocument();
    });

    it("selecionar todas (as da página visível) e marcar como lidas só as que estavam por ler", async () => {
        await renderInbox();
        fireEvent.click(screen.getByTestId("mail-select-all"));
        expect(screen.getByTestId("mail-selection-actions")).toHaveTextContent("3 selecionadas");
        fireEvent.click(screen.getByTestId("mail-mark-read"));
        await waitFor(() => expect(setMailboxSeenMany).toHaveBeenCalledWith(5, "INBOX", [3], true));
        expect(screen.queryByTestId("mail-selection-actions")).not.toBeInTheDocument(); // a seleção acaba

        fireEvent.click(within(rowOf("Segunda")).getByTestId("mail-select"));
        fireEvent.click(screen.getByTestId("mail-mark-unread"));
        await waitFor(() => expect(setMailboxSeenMany).toHaveBeenLastCalledWith(5, "INBOX", [2], false));
    });

    it("a estrela muda logo e volta atrás, com o motivo, se o servidor der erro", async () => {
        await renderInbox();
        const star = within(rowOf("Segunda")).getByTestId("mail-star");
        fireEvent.click(star);
        expect(star).toHaveAttribute("aria-pressed", "true");
        await waitFor(() => expect(setMailboxFlag).toHaveBeenCalledWith(5, "INBOX", [2], true));

        (setMailboxFlag as jest.Mock).mockRejectedValueOnce({ message: "O servidor de e-mail não respondeu." });
        fireEvent.click(within(rowOf("Terceira")).getByTestId("mail-star"));
        await waitFor(() => expect(toast.error).toHaveBeenCalledWith("Não foi possível pôr a estrela: O servidor de e-mail não respondeu."));
        expect(within(rowOf("Terceira")).getByTestId("mail-star")).toHaveAttribute("aria-pressed", "false");
    });

    it("o filtro Com estrela da barra lateral pede só as com estrela da Entrada", async () => {
        await renderInbox();
        fireEvent.click(screen.getByTestId("mail-folder-starred"));
        await waitFor(() => expect(getMailboxMessages).toHaveBeenLastCalledWith(5, "INBOX", 1, true));
        expect(screen.getByTestId("mail-folder-starred")).toHaveClass("active");
    });

    it("apagar tira logo da lista, e o Desfazer repõe as mensagens", async () => {
        await renderInbox();
        fireEvent.click(within(rowOf("Terceira")).getByTestId("mail-select"));
        fireEvent.click(screen.getByTestId("mail-delete-selected"));
        expect(screen.queryByText("Terceira")).not.toBeInTheDocument();
        await waitFor(() => expect(trashMailboxMessages).toHaveBeenCalledWith(5, "INBOX", [3], ["<m3@exemplo.invalid>"]));

        // O aviso com "Desfazer" (durante alguns segundos).
        await waitFor(() => expect(toast).toHaveBeenCalled());
        const [content, options] = (toast as unknown as jest.Mock).mock.calls[0];
        expect(options).toEqual({ autoClose: 8000 });
        render(content({ closeToast: jest.fn() }));
        expect(screen.getByTestId("mail-undo-toast")).toHaveTextContent("Mensagem movida para o Lixo.");
        await act(async () => { fireEvent.click(screen.getByTestId("mail-undo")); });
        expect(restoreMailboxMessages).toHaveBeenCalledWith(5, "INBOX", ["<m3@exemplo.invalid>"]);
        await waitFor(() => expect(getMailboxMessages).toHaveBeenCalledTimes(2)); // a lista volta a ler-se
    });

    it("se o servidor de e-mail der erro, as mensagens voltam à lista e aparece o motivo", async () => {
        (trashMailboxMessages as jest.Mock).mockRejectedValueOnce({ message: "A sua caixa não tem uma pasta de Lixo (marca \\Trash)." });
        await renderInbox();
        fireEvent.click(within(rowOf("Segunda")).getByTestId("mail-select"));
        fireEvent.click(screen.getByTestId("mail-delete-selected"));
        await waitFor(() => expect(toast.error).toHaveBeenCalledWith("Não foi possível apagar: A sua caixa não tem uma pasta de Lixo (marca \\Trash)."));
        expect(screen.getByText("Segunda")).toBeInTheDocument();
    });

    it("sem pasta do Lixo, o aviso leva às definições da caixa", async () => {
        (trashMailboxMessages as jest.Mock).mockRejectedValueOnce({ message: "A sua caixa não tem uma pasta de Lixo.", errors: { code: "no_trash_folder" } });
        await renderInbox();
        fireEvent.click(within(rowOf("Segunda")).getByTestId("mail-select"));
        fireEvent.click(screen.getByTestId("mail-delete-selected"));
        await waitFor(() => expect(toast.error).toHaveBeenCalled());
        render((toast.error as jest.Mock).mock.calls[0][0]);
        expect(screen.getByText("Escolher a pasta do Lixo")).toHaveAttribute("href", "/app/users/4#mailbox");
        expect(screen.getByText("Segunda")).toBeInTheDocument(); // nada foi apagado
    });

    it("apagar a conversa aberta volta à lista sem ela", async () => {
        (getMailboxThread as jest.Mock).mockResolvedValue({ data: { messages: [{ uid: 2, folder: "INBOX", subject: "Segunda", from, to: [], cc: [], date: "2026-10-27T16:00:00Z",
            text: "Corpo.", html: null, external_images: 0, seen: true, attachments: [], customer: null }] } });
        await renderInbox();
        fireEvent.click(rowOf("Segunda"));
        fireEvent.click(await screen.findByTestId("mail-delete-thread"));
        expect(await screen.findByTestId("mail-list")).toBeInTheDocument();
        expect(screen.queryByText("Segunda")).not.toBeInTheDocument();
        await waitFor(() => expect(trashMailboxMessages).toHaveBeenCalledWith(5, "INBOX", [2], ["<m2@exemplo.invalid>"]));
    });
});
