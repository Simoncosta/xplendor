import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import SocialPublishingBlock from "./SocialPublishingBlock";
import { setSocialAutoPublishing } from "helpers/laravel_helper";

jest.mock("helpers/laravel_helper", () => ({ getSocialPublishAuthUrl: jest.fn(), disconnectSocialPublishing: jest.fn(), setSocialAutoPublishing: jest.fn() }));
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn(), info: jest.fn() } }));

const off = { connected: false, connected_at: null, platforms: [], auto_publishing_enabled: false };

describe("SocialPublishingBlock (F2.8)", () => {
    it("sem a publicação ligada, o administrador vê \"Ligar publicação\"", () => {
        render(<SocialPublishingBlock companyId={1} state={off} canManage readReady onChanged={() => undefined} />);
        expect(screen.getByText("Por ligar")).toBeInTheDocument();
        expect(screen.getByRole("button", { name: /Ligar publicação/ })).toBeInTheDocument();
        expect(screen.getByText(/A leitura dos seguidores continua igual/)).toBeInTheDocument();
    });

    it("ligada, mostra as redes e \"Desligar publicação\"", () => {
        render(<SocialPublishingBlock companyId={1} state={{ connected: true, connected_at: "2026-11-10T10:00:00Z", platforms: ["instagram", "facebook"], auto_publishing_enabled: true }} canManage readReady onChanged={() => undefined} />);
        expect(screen.getByText("Ligada")).toBeInTheDocument();
        expect(screen.getByText(/publicar no Instagram e Facebook/)).toBeInTheDocument();
        expect(screen.getByRole("button", { name: /Desligar publicação/ })).toBeInTheDocument();
        expect(screen.getByRole("switch")).toBeChecked();
    });

    it("quem não configura as integrações não vê os botões e o interruptor fica só de leitura", () => {
        render(<SocialPublishingBlock companyId={1} state={undefined} canManage={false} readReady onChanged={() => undefined} />);
        expect(screen.queryByRole("button")).not.toBeInTheDocument();
        expect(screen.getByRole("switch")).toBeDisabled();
        expect(screen.getByText(/Só quem configura as integrações/)).toBeInTheDocument();
    });

    it("o interruptor aparece sem a leitura ligada; \"Ligar publicação\" espera pela leitura", () => {
        render(<SocialPublishingBlock companyId={1} state={off} canManage readReady={false} onChanged={() => undefined} />);
        expect(screen.getByRole("switch")).not.toBeChecked();
        expect(screen.queryByRole("button", { name: /Ligar publicação/ })).not.toBeInTheDocument();
        expect(screen.getByText(/primeiro ligue as redes sociais e escolha as contas/)).toBeInTheDocument();
        expect(screen.getByText(/todas as publicações continuam manuais/)).toBeInTheDocument();
    });

    it("ligar o interruptor grava nas Integrações e avisa que falta \"Ligar publicação\"", async () => {
        (setSocialAutoPublishing as jest.Mock).mockResolvedValue({ data: { publishing: { ...off, auto_publishing_enabled: true } } });
        const onChanged = jest.fn();
        const { rerender } = render(<SocialPublishingBlock companyId={7} state={off} canManage readReady onChanged={onChanged} />);
        fireEvent.click(screen.getByRole("switch"));
        await waitFor(() => expect(onChanged).toHaveBeenCalledWith({ ...off, auto_publishing_enabled: true }));
        expect(setSocialAutoPublishing).toHaveBeenCalledWith(7, true);
        rerender(<SocialPublishingBlock companyId={7} state={{ ...off, auto_publishing_enabled: true }} canManage readReady onChanged={onChanged} />);
        expect(screen.getByText(/Falta "Ligar publicação"/)).toBeInTheDocument();
    });
});
