import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import MyMailboxCard from "./MyMailboxCard";
import { getMyMailbox, saveMyMailbox } from "helpers/laravel_helper";

jest.mock("helpers/laravel_helper", () => ({ getMyMailbox: jest.fn(), saveMyMailbox: jest.fn(), deleteMyMailbox: jest.fn(), testMyMailbox: jest.fn() }));
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() } }));

const openTypeMenu = () => {
    const input = document.getElementById("mailbox-type") as HTMLInputElement;
    fireEvent.focus(input);
    fireEvent.keyDown(input, { key: "ArrowDown", keyCode: 40 });
};

describe("A minha caixa de correio", () => {
    beforeEach(() => {
        (getMyMailbox as jest.Mock).mockResolvedValue({ data: { mailbox: null } });
        (saveMyMailbox as jest.Mock).mockReset();
    });

    it("o primeiro campo é o tipo de conta; um endereço do Gmail escolhe o Gmail e preenche os servidores", async () => {
        render(<MyMailboxCard companyId={7} />);
        const email = await screen.findByLabelText("Endereço de e-mail");
        const labels = Array.from(document.querySelectorAll("label")).map((l) => l.textContent);
        expect(labels[0]).toBe("Tipo de conta");
        expect(screen.getByText("Domínio próprio (cPanel)")).toBeInTheDocument();
        expect(screen.getByTestId("mailbox-cpanel-help")).toHaveTextContent("Use o endereço e a palavra-passe com que entra no webmail.");

        fireEvent.change(email, { target: { value: "matilde.exemplo@gmail.com" } });
        expect(screen.getByText("Gmail")).toBeInTheDocument();
        const help = screen.getByTestId("mailbox-gmail-help");
        expect(help).toHaveTextContent("Crie uma palavra-passe de app com o nome XPLENDOR");
        expect(help).toHaveTextContent("Copie os 16 carateres, sem espaços, para o campo Palavra-passe.");
        expect(help).toHaveTextContent("esta conta ainda não pode ser ligada");
        const links = Array.from(help.querySelectorAll("a"));
        expect(links.map((a) => a.getAttribute("href"))).toEqual([
            "https://myaccount.google.com/security", "https://myaccount.google.com/apppasswords", "https://support.google.com/accounts/answer/185833"]);
        links.forEach((a) => { expect(a).toHaveAttribute("target", "_blank"); expect(a).toHaveAttribute("rel", "noopener noreferrer"); });
        expect(screen.queryByTestId("mailbox-cpanel-help")).not.toBeInTheDocument();
        expect(screen.getByLabelText("Servidor SMTP (envio)")).toHaveValue("smtp.gmail.com");
        expect(screen.getByLabelText("Servidor IMAP (receção)")).toHaveValue("imap.gmail.com");
        expect(screen.getByLabelText("Utilizador")).toHaveValue("matilde.exemplo@gmail.com");
        expect(screen.getByLabelText("Guardar cópia em Enviados")).not.toBeChecked();
        // Nos modelos, as definições avançadas começam fechadas.
        expect(screen.getByRole("button", { name: /Definições avançadas/ })).toHaveAttribute("aria-expanded", "false");
    });

    it("a lista dos tipos tem só Gmail, Domínio próprio (cPanel) e Outro (manual)", async () => {
        render(<MyMailboxCard companyId={7} />);
        await screen.findByLabelText("Endereço de e-mail");
        openTypeMenu();
        expect((await screen.findAllByRole("option")).map((o) => o.textContent)).toEqual(["Gmail", "Domínio próprio (cPanel)", "Outro (manual)"]);
        expect(screen.queryByText(/Outlook|em breve/)).not.toBeInTheDocument();
    });

    it("Outro (manual) abre as definições para preencher à mão", async () => {
        render(<MyMailboxCard companyId={7} />);
        await screen.findByLabelText("Endereço de e-mail");
        openTypeMenu();
        fireEvent.click(await screen.findByText("Outro (manual)"));
        expect(screen.getByRole("button", { name: /Definições avançadas/ })).toHaveAttribute("aria-expanded", "true");
    });

    it("o modelo não sobrepõe a porta mudada nas definições avançadas, e guarda o que se vê", async () => {
        (saveMyMailbox as jest.Mock).mockResolvedValue({ data: { mailbox: null } });
        render(<MyMailboxCard companyId={7} />);
        const email = await screen.findByLabelText("Endereço de e-mail");
        fireEvent.change(email, { target: { value: "matilde@quebom.pt" } });
        fireEvent.click(screen.getByRole("button", { name: /Definições avançadas/ }));
        fireEvent.change(screen.getByLabelText("Porta SMTP"), { target: { value: "587" } });
        fireEvent.change(email, { target: { value: "matilde@exemplo.pt" } });
        expect(screen.getByLabelText("Porta SMTP")).toHaveValue(587);
        expect(screen.getByLabelText("Servidor SMTP (envio)")).toHaveValue("mail.exemplo.pt");

        fireEvent.change(screen.getByLabelText("Palavra-passe"), { target: { value: "segredo-de-teste" } });
        await act(async () => { fireEvent.click(screen.getByRole("button", { name: /Guardar/ })); });
        await waitFor(() => expect(saveMyMailbox).toHaveBeenCalled());
        expect((saveMyMailbox as jest.Mock).mock.calls[0]).toEqual([7, expect.objectContaining({
            account_type: "cpanel", email: "matilde@exemplo.pt", smtp_host: "mail.exemplo.pt", smtp_port: 587, imap_port: 993, password: "segredo-de-teste", save_to_sent: true,
        })]);
    });

    it("numa caixa gravada, a palavra-passe fica em branco e não é enviada se não mudar", async () => {
        (getMyMailbox as jest.Mock).mockResolvedValue({ data: { mailbox: { account_type: "cpanel", email: "matilde@quebom.pt", smtp_host: "mail.quebom.pt", smtp_port: 465,
            smtp_encryption: "ssl", imap_host: "mail.quebom.pt", imap_port: 993, imap_encryption: "ssl", username: "matilde@quebom.pt", save_to_sent: true,
            has_password: true, status: "erro", last_error: "O servidor recusou o utilizador ou a palavra-passe.", last_tested_at: null } } });
        (saveMyMailbox as jest.Mock).mockResolvedValue({ data: { mailbox: null } });
        render(<MyMailboxCard companyId={7} />);
        expect(await screen.findByTestId("mailbox-error")).toHaveTextContent("recusou o utilizador");
        expect(screen.getByTestId("mailbox-status")).toHaveTextContent("Com erro");
        expect(screen.getByLabelText("Palavra-passe")).toHaveValue("");
        expect(screen.getByLabelText("Palavra-passe")).toHaveAttribute("placeholder", "Gravada. Deixe em branco para a manter.");
        fireEvent.change(screen.getByLabelText("Endereço de e-mail"), { target: { value: "geral@quebom.pt" } });
        await act(async () => { fireEvent.click(screen.getByRole("button", { name: /Guardar/ })); });
        expect((saveMyMailbox as jest.Mock).mock.calls[0][1]).not.toHaveProperty("password");
    });

    it("só no Gmail: \"marcar também como lida no Gmail\", ligada por omissão, e vai no que se guarda", async () => {
        (saveMyMailbox as jest.Mock).mockResolvedValue({ data: { mailbox: null } });
        render(<MyMailboxCard companyId={7} />);
        const email = await screen.findByLabelText("Endereço de e-mail");
        expect(screen.queryByTestId("mailbox-gmail-seen")).not.toBeInTheDocument(); // cPanel: sem opção
        fireEvent.change(email, { target: { value: "matilde.exemplo@gmail.com" } });
        const toggle = screen.getByLabelText("Ao abrir uma mensagem na XPLENDOR, marcá-la também como lida no Gmail");
        expect(toggle).toBeChecked();
        fireEvent.click(toggle);
        expect(screen.getByTestId("mailbox-gmail-seen")).toHaveTextContent("No Gmail continua por ler");
        fireEvent.change(screen.getByLabelText("Palavra-passe de app"), { target: { value: "abcdabcdabcdabcd" } });
        await act(async () => { fireEvent.click(screen.getByRole("button", { name: /Guardar/ })); });
        expect((saveMyMailbox as jest.Mock).mock.calls[0][1]).toMatchObject({ account_type: "gmail", mark_seen_on_server: false });
    });
});
