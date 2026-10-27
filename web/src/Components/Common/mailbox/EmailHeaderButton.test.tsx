import { act, render, screen, waitFor } from "@testing-library/react";
import EmailHeaderButton from "./EmailHeaderButton";
import { getMyMailboxUnread } from "helpers/laravel_helper";
import { isImpersonating } from "helpers/impersonation";
import { adjustMailboxUnread, unreadLabel } from "./useMailboxUnread";

jest.mock("react-router-dom", () => ({ Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a> }), { virtual: true });
jest.mock("helpers/laravel_helper", () => ({ getMyMailboxUnread: jest.fn() }));
jest.mock("helpers/impersonation", () => ({ isImpersonating: jest.fn() }));
jest.mock("helpers/roles", () => ({ sessionUser: () => ({ id: 4, company_id: 9, role: "user" }) }));

describe("O envelope do cabeçalho (E-mail 3)", () => {
    beforeEach(() => {
        (getMyMailboxUnread as jest.Mock).mockReset();
        (isImpersonating as jest.Mock).mockReturnValue(false);
    });

    it("unreadLabel: nada em 0, o número até 99, 99+ acima", () => {
        expect([unreadLabel(null), unreadLabel(0), unreadLabel(1), unreadLabel(99), unreadLabel(100)]).toEqual([null, null, "1", "99", "99+"]);
    });

    it("aparece sempre e leva a /email; sem caixa, sem número", async () => {
        (getMyMailboxUnread as jest.Mock).mockResolvedValue({ data: { has_mailbox: false, unread_count: null } });
        const { unmount } = render(<EmailHeaderButton />);
        expect(screen.getByTestId("header-email")).toHaveAttribute("href", "/email");
        await waitFor(() => expect(getMyMailboxUnread).toHaveBeenCalledWith(9)); // a empresa da própria pessoa
        expect(screen.queryByTestId("header-email-badge")).not.toBeInTheDocument();
        unmount();
    });

    it("com caixa: o número acima de 0, 99+ acima de 99", async () => {
        (getMyMailboxUnread as jest.Mock).mockResolvedValue({ data: { has_mailbox: true, unread_count: 150 } });
        const { unmount } = render(<EmailHeaderButton />);
        expect(await screen.findByTestId("header-email-badge")).toHaveTextContent("99+");
        unmount();
    });

    it("durante a impersonation não pede nada e não mostra número", async () => {
        (isImpersonating as jest.Mock).mockReturnValue(true);
        const { unmount } = render(<EmailHeaderButton />);
        expect(screen.getByTestId("header-email")).toBeInTheDocument();
        await new Promise((r) => setTimeout(r, 0));
        expect(getMyMailboxUnread).not.toHaveBeenCalled();
        expect(screen.queryByTestId("header-email-badge")).not.toBeInTheDocument();
        unmount();
    });

    it("o número muda no mesmo instante: -1 ao abrir, +1 ao marcar como por ler (nunca abaixo de 0)", async () => {
        (getMyMailboxUnread as jest.Mock).mockResolvedValue({ data: { has_mailbox: true, unread_count: 4 } });
        const { unmount } = render(<EmailHeaderButton />);
        expect(await screen.findByTestId("header-email-badge")).toHaveTextContent("4");
        act(() => adjustMailboxUnread(-1));
        expect(screen.getByTestId("header-email-badge")).toHaveTextContent("3");
        act(() => adjustMailboxUnread(+1));
        expect(screen.getByTestId("header-email-badge")).toHaveTextContent("4");
        act(() => adjustMailboxUnread(-10));
        expect(screen.queryByTestId("header-email-badge")).not.toBeInTheDocument();
        unmount();
    });
});
