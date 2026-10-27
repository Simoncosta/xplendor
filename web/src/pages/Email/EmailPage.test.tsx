import { render, screen } from "@testing-library/react";
import EmailPage from "./index";
import { getMyMailbox } from "helpers/laravel_helper";
import { isImpersonating } from "helpers/impersonation";

jest.mock("react-router-dom", () => ({ Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a> }), { virtual: true });
jest.mock("Components/Common/PageHeader", () => ({ title }: any) => <h4>{title}</h4>);
jest.mock("helpers/laravel_helper", () => ({ getMyMailbox: jest.fn() }));
jest.mock("./inbox/Inbox", () => ({ email, profileLink, companyId }: any) => <div data-testid="mail-inbox">{email} {profileLink} {companyId}</div>);
jest.mock("helpers/impersonation", () => ({ isImpersonating: jest.fn() }));
let mockMe: any = { id: 4, company_id: 9, role: "user" };
let mockAway = false;
jest.mock("helpers/roles", () => ({ sessionUser: () => mockMe, isRootRole: (r?: string) => r === "root" }));
jest.mock("contexts/WorkingCompanyContext", () => ({ useWorkingCompany: () => ({ away: mockAway }) }));

describe("A página /email (E-mail 3)", () => {
    beforeEach(() => {
        (getMyMailbox as jest.Mock).mockReset();
        (isImpersonating as jest.Mock).mockReturnValue(false);
        mockMe = { id: 4, company_id: 9, role: "user" };
        mockAway = false;
    });

    it("sem caixa: explica o que é e \"Ligar a minha caixa\" leva ao quadro no Perfil", async () => {
        (getMyMailbox as jest.Mock).mockResolvedValue({ data: { mailbox: null } });
        render(<EmailPage />);
        expect(await screen.findByTestId("email-empty")).toHaveTextContent("Ligue a sua caixa de correio");
        expect(screen.getByRole("link", { name: /Ligar a minha caixa/ })).toHaveAttribute("href", "/users/4#mailbox");
        expect(getMyMailbox).toHaveBeenCalledWith(9);
        expect(document.body).not.toHaveTextContent(/em breve/i);
    });

    it("com caixa: a caixa de entrada da própria pessoa, na sua empresa", async () => {
        (getMyMailbox as jest.Mock).mockResolvedValue({ data: { mailbox: { email: "matilde@exemplo.pt", status: "ativa" } } });
        render(<EmailPage />);
        expect(await screen.findByTestId("mail-inbox")).toHaveTextContent("matilde@exemplo.pt /users/4#mailbox 9");
    });

    it("durante a impersonation: não disponível, sem pedir nada", () => {
        (isImpersonating as jest.Mock).mockReturnValue(true);
        render(<EmailPage />);
        expect(screen.getByTestId("email-unavailable")).toHaveTextContent("A caixa de correio não está disponível aqui");
        expect(getMyMailbox).not.toHaveBeenCalled();
    });

    it("o root a trabalhar noutra empresa: não disponível; na própria, a sua caixa", async () => {
        mockMe = { id: 1, company_id: 2, role: "root" };
        mockAway = true;
        const { unmount } = render(<EmailPage />);
        expect(screen.getByTestId("email-unavailable")).toBeInTheDocument();
        expect(getMyMailbox).not.toHaveBeenCalled();
        unmount();

        mockAway = false;
        (getMyMailbox as jest.Mock).mockResolvedValue({ data: { mailbox: null } });
        render(<EmailPage />);
        expect(await screen.findByTestId("email-empty")).toBeInTheDocument();
        expect(getMyMailbox).toHaveBeenCalledWith(2);
    });
});
