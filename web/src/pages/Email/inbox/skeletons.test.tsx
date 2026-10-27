import { render, screen, waitFor } from "@testing-library/react";
import Inbox from "./Inbox";
import ThreadView from "./ThreadView";
import MailConversation from "Components/Common/mailbox/MailConversation";
import { getCustomerMailMessages, getMailboxFolders, getMailboxMessages } from "helpers/laravel_helper";

jest.mock("react-router-dom", () => ({ Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a> }), { virtual: true });
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() } }));
jest.mock("Components/Common/mailbox/useMailboxUnread", () => ({ refreshMailboxUnread: jest.fn(), adjustMailboxUnread: jest.fn() }));
jest.mock("helpers/download_helper", () => ({ fetchBlob: jest.fn(), downloadGet: jest.fn() }));
jest.mock("helpers/laravel_helper", () => ({
    getMailboxFolders: jest.fn(), getMailboxMessages: jest.fn(), getMailboxThread: jest.fn(), setMailboxSeenMany: jest.fn(), linkMailboxMessage: jest.fn(),
    getCustomers: jest.fn(), getCustomerMailMessages: jest.fn(), getCarMailMessages: jest.fn(), mailboxAttachmentPath: () => "",
}));

const never = () => new Promise(() => undefined);

describe("Esqueletos da caixa de correio (E-mail 4b)", () => {
    beforeEach(() => jest.clearAllMocks());

    it("enquanto as pastas chegam: o mesmo desenho, com as pastas e a lista em esqueleto (sem spinner)", () => {
        (getMailboxFolders as jest.Mock).mockImplementation(never);
        const { container } = render(<Inbox companyId={5} email="matilde@exemplo.pt" profileLink="/users/4#mailbox" />);
        expect(screen.getByTestId("mail-skeleton-folders")).toHaveClass("placeholder-glow");
        expect(screen.getByTestId("mail-skeleton-list")).toHaveClass("message-list");
        expect(screen.getByText("matilde@exemplo.pt")).toBeInTheDocument();
        expect(screen.getByText("Entrada")).toBeInTheDocument();
        expect(container.querySelectorAll(".placeholder").length).toBeGreaterThan(10);
        expect(container.querySelector(".spinner-border")).toBeNull();
    });

    it("enquanto a lista chega: as pastas já à vista e a lista em esqueleto", async () => {
        (getMailboxFolders as jest.Mock).mockResolvedValue({ data: { folders: [{ path: "INBOX", name: "Entrada", special: "inbox" }] } });
        (getMailboxMessages as jest.Mock).mockImplementation(never);
        render(<Inbox companyId={5} email="matilde@exemplo.pt" profileLink="/users/4#mailbox" />);
        expect(await screen.findByTestId("mail-folders")).toBeInTheDocument();
        expect(screen.queryByTestId("mail-skeleton-folders")).not.toBeInTheDocument();
        expect(screen.getByTestId("mail-skeleton-list")).toBeInTheDocument();
    });

    it("uma conversa a abrir: o esqueleto do assunto, das mensagens e do texto", () => {
        const { container } = render(<ThreadView companyId={5} myEmail="matilde@exemplo.pt" messages={[]} loading error={null} onBack={() => undefined} onUnread={() => undefined} onLinked={() => undefined} />);
        expect(screen.getByTestId("mail-skeleton-thread")).toHaveAttribute("aria-busy", "true");
        expect(screen.getByRole("button", { name: "Voltar" })).toBeInTheDocument(); // a barra de cima já lá está
        expect(container.querySelector(".spinner-border")).toBeNull();
    });

    it("a Conversa da ficha a carregar mostra o esqueleto e depois as mensagens", async () => {
        let resolve: (v: any) => void = () => undefined;
        (getCustomerMailMessages as jest.Mock).mockImplementation(() => new Promise((r) => { resolve = r; }));
        render(<MailConversation companyId={5} customerId={12} />);
        expect(screen.getByTestId("mail-skeleton-conversation")).toBeInTheDocument();
        resolve({ data: { messages: [] } });
        await waitFor(() => expect(screen.queryByTestId("mail-skeleton-conversation")).not.toBeInTheDocument());
        expect(screen.getByText(/Ainda não há e-mails ligados/)).toBeInTheDocument();
    });
});
