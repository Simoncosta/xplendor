import { render, screen } from "@testing-library/react";
import SocialPublishingBlock from "./SocialPublishingBlock";

jest.mock("helpers/laravel_helper", () => ({ getSocialPublishAuthUrl: jest.fn(), disconnectSocialPublishing: jest.fn() }));

describe("SocialPublishingBlock (F2.8)", () => {
    it("sem a publicação ligada, o administrador vê \"Ligar publicação\"", () => {
        render(<SocialPublishingBlock companyId={1} state={{ connected: false, connected_at: null, platforms: [] }} canManage onChanged={() => undefined} />);
        expect(screen.getByText("Por ligar")).toBeInTheDocument();
        expect(screen.getByRole("button", { name: /Ligar publicação/ })).toBeInTheDocument();
        expect(screen.getByText(/A leitura dos seguidores continua igual/)).toBeInTheDocument();
    });

    it("ligada, mostra as redes e \"Desligar publicação\"", () => {
        render(<SocialPublishingBlock companyId={1} state={{ connected: true, connected_at: "2026-11-10T10:00:00Z", platforms: ["instagram", "facebook"] }} canManage onChanged={() => undefined} />);
        expect(screen.getByText("Ligada")).toBeInTheDocument();
        expect(screen.getByText(/publicar no Instagram e Facebook/)).toBeInTheDocument();
        expect(screen.getByRole("button", { name: /Desligar publicação/ })).toBeInTheDocument();
    });

    it("quem não é administrador não vê os botões", () => {
        render(<SocialPublishingBlock companyId={1} state={undefined} canManage={false} onChanged={() => undefined} />);
        expect(screen.queryByRole("button")).not.toBeInTheDocument();
        expect(screen.getByText(/Só o administrador da empresa/)).toBeInTheDocument();
    });
});
