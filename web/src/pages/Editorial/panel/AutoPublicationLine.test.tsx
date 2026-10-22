import { render, screen } from "@testing-library/react";
import { AutoPublicationBadge, AutoPublicationLine } from "./PublishSection";
import type { SocialPublicationInfo } from "common/models/editorialWorkflow.model";

jest.mock("helpers/laravel_helper", () => ({ savePostResults: jest.fn(), skipPostNetwork: jest.fn(), markPostPublished: jest.fn() }));
jest.mock("../MarkPublishedModal", () => () => null);

const info = (over: Partial<SocialPublicationInfo>): SocialPublicationInfo => ({
    status: "scheduled", label: "Agendado", scheduled_for: "2026-11-12T12:00:00Z", published_at: null, permalink: null, error_message: null, attempts: 0, ...over,
});

describe("AutoPublicationLine (F2.2)", () => {
    it("mostra a hora agendada", () => {
        render(<AutoPublicationLine info={info({})} published={false} />);
        expect(screen.getByText(/Publicação automática agendada para/)).toBeInTheDocument();
    });

    it("mostra a falha com o motivo e o que fazer", () => {
        render(<AutoPublicationLine info={info({ status: "failed", error_message: "A Página não aceitou a publicação." })} published={false} />);
        expect(screen.getByText(/A publicação automática falhou: A Página não aceitou a publicação\./)).toBeInTheDocument();
        expect(screen.getByText(/Pode publicar à mão/)).toBeInTheDocument();
    });

    it("não mostra nada quando foi cancelada ou não há", () => {
        const { container } = render(<><AutoPublicationLine info={info({ status: "cancelled" })} published={false} /><AutoPublicationLine info={null} published={false} /></>);
        expect(container).toBeEmptyDOMElement();
    });

    it("mostra a nova tentativa e a quota adiada (F2.7)", () => {
        const { rerender } = render(<AutoPublicationLine info={info({ next_attempt_at: "2026-11-12T12:02:00Z", error_code: "2", error_message: "A Meta está ocupada. Nova tentativa às 12:02." })} published={false} />);
        expect(screen.getByText(/A publicação automática vai tentar de novo: A Meta está ocupada\. Nova tentativa às 12:02\./)).toBeInTheDocument();
        rerender(<AutoPublicationLine info={info({ next_attempt_at: "2026-11-12T13:00:00Z", error_code: "quota", error_message: "A quota está esgotada." })} published={false} />);
        expect(screen.getByText(/Publicação automática adiada: A quota está esgotada\./)).toBeInTheDocument();
        rerender(<AutoPublicationLine info={info({ next_attempt_at: "2026-11-12T12:01:00Z" })} published={false} />);
        expect(screen.getByText(/À espera de que o Instagram acabe de processar o vídeo/)).toBeInTheDocument();
    });

    it("mostra o estado por rede ao lado da rede (F2.8)", () => {
        const { rerender, container } = render(<AutoPublicationBadge info={info({ status: "failed", label: "Falhou" })} />);
        expect(screen.getByText("Falhou")).toBeInTheDocument();
        rerender(<AutoPublicationBadge info={info({ status: "published", label: "Publicado" })} />);
        expect(screen.getByText("Publicado")).toBeInTheDocument();
        rerender(<AutoPublicationBadge info={info({ status: "cancelled", label: "Cancelado" })} />);
        expect(container).toBeEmptyDOMElement();
    });

    it("diz que saiu sozinha", () => {
        render(<AutoPublicationLine info={info({ status: "published" })} published />);
        expect(screen.getByText("Publicada automaticamente pela XPLENDOR.")).toBeInTheDocument();
    });
});
