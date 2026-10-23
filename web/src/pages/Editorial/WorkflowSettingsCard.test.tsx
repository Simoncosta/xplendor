import { render, screen } from "@testing-library/react";
import WorkflowSettingsCard from "./WorkflowSettingsCard";
import { getWorkflowSettings } from "helpers/laravel_helper";

// O react-router-dom 7 não se resolve no Jest 27 do CRA: um Link simples chega.
jest.mock("react-router-dom", () => ({ Link: ({ to, children }: any) => <a href={to}>{children}</a> }), { virtual: true });
jest.mock("helpers/laravel_helper", () => ({ getWorkflowSettings: jest.fn(), setContentApprover: jest.fn(), updateWorkflowSettings: jest.fn() }));
jest.mock("Components/Common/Select", () => () => null);
jest.mock("./useProducerLabel", () => ({ __esModule: true, default: () => "a equipa XPLENDOR", byProducer: (p: string) => `pel${p}`, capitalize: (s: string) => s }));

const settings = (enabled: boolean) => ({
    content_approval_required: true, internal_review_required: false, production_mode: "self", auto_publishing_enabled: enabled,
    can_change_mode: true, can_edit: true, can_manage_approvers: true, users: [],
});

describe("WorkflowSettingsCard: a publicação automática só como estado", () => {
    it("mostra ligada ou desligada, com a ligação para as Integrações, sem interruptor", async () => {
        (getWorkflowSettings as jest.Mock).mockResolvedValue({ data: settings(true) });
        render(<WorkflowSettingsCard companyId={5} />);
        const line = await screen.findByTestId("wf-auto-status");
        expect(line).toHaveTextContent("Publicação automática: ligada.");
        expect(screen.getByRole("link", { name: "Mudar em Integrações › Redes sociais" })).toHaveAttribute("href", "/companies/5?tab=integrations");
        expect(screen.queryByLabelText(/Publicação automática no Instagram/)).not.toBeInTheDocument();
    });

    it("desligada", async () => {
        (getWorkflowSettings as jest.Mock).mockResolvedValue({ data: settings(false) });
        render(<WorkflowSettingsCard companyId={5} />);
        expect(await screen.findByTestId("wf-auto-status")).toHaveTextContent("Publicação automática: desligada.");
    });
});
