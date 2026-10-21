import { act, fireEvent, render, screen } from "@testing-library/react";
import FinancingUploadPage from "./index";

const TOKEN = "A".repeat(64);

const payload = (accepted: boolean, sent: any[] = []) => ({
    company: { name: "Exemplo", logo_url: null, fiscal_name: "Autocaravanas Exemplo Lda", nipc: "500019321" },
    customer_first_name: "Joana", expires_at: "2026-10-27T23:59:59Z",
    items: [{ key: "cc", label: "Cartão de Cidadão", hint: "Frente e verso.", sent }],
    limits: { max_file_bytes: 10485760, max_files: 20, remaining_files: 20, types: ["PDF", "JPG", "PNG", "HEIC"] },
    notice: { version: "2026-10", privacy: { title: "Proteção dos seus dados", paragraphs: ["Quem trata os seus dados: Autocaravanas Exemplo Lda."] },
        cc_copy: "Autorizo Autocaravanas Exemplo Lda a guardar uma cópia do meu Cartão de Cidadão.", accepted },
});

const reply = (status: number, json: any) => Promise.resolve({ status, json: () => Promise.resolve(json) } as any);

describe("Página de envio de documentos (sem conta)", () => {
    const fetchMock = jest.fn();
    beforeEach(() => {
        fetchMock.mockReset();
        (global as any).fetch = fetchMock;
        window.location.hash = `#${TOKEN}`;
    });

    it("o código vai no cabeçalho; aviso e consentimento do CC antes de enviar; depois a lista do que foi enviado, sem endereços", async () => {
        fetchMock
            .mockImplementationOnce(() => reply(200, { data: payload(false) }))
            .mockImplementationOnce(() => reply(200, { data: payload(true) }))
            .mockImplementationOnce(() => reply(201, { data: payload(true, [{ name: "Cartão de Cidadão 1.jpg", sent_at: "2026-10-20T11:05:00Z", refused: false }]) }));
        render(<FinancingUploadPage />);
        expect(await screen.findByText("Olá, Joana.")).toBeInTheDocument();
        expect(fetchMock.mock.calls[0][1].headers["X-Financing-Token"]).toBe(TOKEN);
        expect(fetchMock.mock.calls[0][0]).not.toContain(TOKEN);

        const go = screen.getByRole("button", { name: "Continuar" });
        expect(go).toBeDisabled();
        fireEvent.click(screen.getByLabelText("Li e aceito o aviso de proteção de dados."));
        expect(go).toBeDisabled();
        fireEvent.click(screen.getByLabelText(/guardar uma cópia do meu Cartão de Cidadão/));
        fireEvent.click(go);
        expect(await screen.findByTestId("fu-items")).toBeInTheDocument();
        expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({ privacy: true, cc_copy: true });

        const file = new File(["%PDF-1.4"], "cc.jpg", { type: "image/jpeg" });
        fireEvent.change(screen.getByLabelText("Enviar Cartão de Cidadão"), { target: { files: [file] } });
        expect(await screen.findByText(/Cartão de Cidadão 1\.jpg · enviado a/)).toBeInTheDocument();
        const form = fetchMock.mock.calls[2][1].body as FormData;
        expect(form.get("kind")).toBe("cc");
        expect(screen.queryByRole("link", { name: /Cartão de Cidadão 1/ })).not.toBeInTheDocument();
    });

    it("enquanto o antivírus verifica, mostra a verificar; se recusar, o cliente vê a mensagem sem recarregar", async () => {
        jest.useFakeTimers();
        const sent = (state: string) => [{ name: "Cartão de Cidadão 1.pdf", sent_at: "2026-10-21T10:00:00Z", refused: state === "recusado", state }];
        fetchMock
            .mockImplementationOnce(() => reply(200, { data: payload(true, sent("em_analise")) }))
            .mockImplementationOnce(() => reply(200, { data: payload(true, sent("recusado")) }));
        render(<FinancingUploadPage />);
        expect(await screen.findByText("A verificar Cartão de Cidadão 1.pdf…")).toBeInTheDocument();
        await act(async () => { jest.advanceTimersByTime(3100); });
        expect(await screen.findByRole("alert")).toHaveTextContent("a verificação de segurança recusou-o");
        expect(fetchMock).toHaveBeenCalledTimes(2);
        jest.useRealTimers();
    });

    it("link expirado, revogado ou desconhecido: a mesma mensagem", async () => {
        fetchMock.mockImplementationOnce(() => reply(404, { message: "Este link já não está disponível." }));
        render(<FinancingUploadPage />);
        expect(await screen.findByText("Este link já não está disponível.")).toBeInTheDocument();
    });

    it("um código mal formado nem chega a pedir", async () => {
        window.location.hash = "#curto";
        render(<FinancingUploadPage />);
        expect(await screen.findByTestId("fu-gone")).toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
