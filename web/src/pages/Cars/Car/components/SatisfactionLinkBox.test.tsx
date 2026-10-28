import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import axios from "axios";
import { SatisfactionLinkBox } from "./CarDocumentsCard";
import { getMyMailbox } from "helpers/laravel_helper";
import { isImpersonating } from "helpers/impersonation";

// O react-router-dom 7 não se resolve no Jest 27 do CRA: um Link simples chega.
jest.mock("react-router-dom", () => ({ Link: ({ to, children }: any) => <a href={to}>{children}</a> }), { virtual: true });
jest.mock("axios", () => ({ post: jest.fn() }));
jest.mock("mammoth", () => ({}));
jest.mock("helpers/laravel_helper", () => ({ getMyMailbox: jest.fn(), getDocumentTemplates: jest.fn(), getDocumentTemplateVariables: jest.fn() }));
jest.mock("helpers/download_helper", () => ({ postDocx: jest.fn(), saveBlob: jest.fn() }));
jest.mock("helpers/impersonation", () => ({ isImpersonating: jest.fn() }));
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn(), info: jest.fn() } }));

const report = { data: { public_token: "abc", customer: { phone: null, email: "joana@cliente.invalid" }, sent_at: null, sent_channel: null } };

describe("Pós-venda: enviar o link pela minha caixa", () => {
    beforeEach(() => {
        (axios.post as jest.Mock).mockReset();
        (axios.post as jest.Mock).mockImplementation((url: string) => Promise.resolve(url.endsWith("send-email") ? { message: "Email enviado pela sua caixa (matilde@exemplo.pt).", data: {} } : report));
        (isImpersonating as jest.Mock).mockReturnValue(false);
    });

    it("com a caixa testada, envia pela caixa por omissão", async () => {
        (getMyMailbox as jest.Mock).mockResolvedValue({ data: { mailbox: { email: "matilde@exemplo.pt", status: "ativa" } } });
        render(<SatisfactionLinkBox companyId={3} carId={9} />);
        const toggle = await screen.findByLabelText(/Enviar pela minha caixa \(matilde@exemplo\.pt\)/);
        await waitFor(() => expect(toggle).toBeChecked());
        await act(async () => { fireEvent.click(screen.getByRole("button", { name: /Enviar por email/ })); });
        expect(axios.post).toHaveBeenLastCalledWith("/companies/3/cars/9/satisfaction-report/send-email", { via: "mailbox" }, { headers: { "Content-Type": "application/json" } });
    });

    it("desligando a opção, vai como hoje (pela XPLENDOR)", async () => {
        (getMyMailbox as jest.Mock).mockResolvedValue({ data: { mailbox: { email: "matilde@exemplo.pt", status: "ativa" } } });
        render(<SatisfactionLinkBox companyId={3} carId={9} />);
        const toggle = await screen.findByLabelText(/Enviar pela minha caixa/);
        await waitFor(() => expect(toggle).toBeChecked());
        fireEvent.click(toggle);
        await act(async () => { fireEvent.click(screen.getByRole("button", { name: /Enviar por email/ })); });
        expect(axios.post).toHaveBeenLastCalledWith("/companies/3/cars/9/satisfaction-report/send-email", {}, { headers: { "Content-Type": "application/json" } });
    });

    it("com a caixa por testar, a opção fica desligada e com o aviso", async () => {
        (getMyMailbox as jest.Mock).mockResolvedValue({ data: { mailbox: { email: "matilde@exemplo.pt", status: "por_testar" } } });
        render(<SatisfactionLinkBox companyId={3} carId={9} />);
        const toggle = await screen.findByLabelText(/Enviar pela minha caixa/);
        expect(toggle).toBeDisabled();
        expect(toggle).not.toBeChecked();
        expect(screen.getByTestId("satisfaction-via-mailbox")).toHaveTextContent("teste primeiro a caixa no Perfil");
    });

    it("sem caixa, ou durante a impersonation, não há opção", async () => {
        (getMyMailbox as jest.Mock).mockResolvedValue({ data: { mailbox: null } });
        const { unmount } = render(<SatisfactionLinkBox companyId={3} carId={9} />);
        await screen.findByRole("button", { name: /Enviar por email/ });
        expect(screen.queryByTestId("satisfaction-via-mailbox")).not.toBeInTheDocument();
        unmount();

        (isImpersonating as jest.Mock).mockReturnValue(true);
        (getMyMailbox as jest.Mock).mockClear();
        render(<SatisfactionLinkBox companyId={3} carId={9} />);
        await screen.findByRole("button", { name: /Enviar por email/ });
        expect(getMyMailbox).not.toHaveBeenCalled();
        expect(screen.queryByTestId("satisfaction-via-mailbox")).not.toBeInTheDocument();
    });
});
