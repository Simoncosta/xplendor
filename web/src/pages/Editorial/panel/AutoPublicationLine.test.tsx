import { render, screen } from "@testing-library/react";
import { AutoPublicationLine } from "./PublishSection";
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

    it("diz que saiu sozinha", () => {
        render(<AutoPublicationLine info={info({ status: "published" })} published />);
        expect(screen.getByText("Publicada automaticamente pela XPLENDOR.")).toBeInTheDocument();
    });
});
