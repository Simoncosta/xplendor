import { fireEvent, render, screen } from "@testing-library/react";
import AuditRunPanel from "./AuditRunPanel";
import { AuditRun } from "common/models/audit.model";

/** Auditoria: o pipeline mostra o passo que demorou demasiado e o que falhou; uma corrida falhada tem "Tentar de novo". */
jest.mock("react-router-dom", () => ({ useNavigate: () => jest.fn() }), { virtual: true });
jest.mock("helpers/laravel_helper", () => ({ getAuditRun: jest.fn() }));

const run = (status: AuditRun["status"]): AuditRun => ({
    id: 22, area: "restauracao", origin: "central", rules: ["a", "b", "c"], status, new_count: 0, resolved_count: 0, open_count: 3,
    error: status === "failed" ? "A corrida parou no passo «Embalagem na descrição, fator 1 no PingWin»." : null,
    requested_by: null, created_at: null, started_at: null, finished_at: null,
    steps: [
        { rule: "a", title: "Regra A", status: "ok", found: 1, new: 0, resolved: 0, ms: 300 },
        { rule: "b", title: "Embalagem na descrição, fator 1 no PingWin", status: "timeout", error: "Demorou demasiado: passou o limite de 120 s." },
        { rule: "c", title: "Regra C", status: "failed", error: "Coluna em falta." },
    ] as any,
});

test("passo que demorou demasiado e passo que falhou, com o erro", () => {
    render(<AuditRunPanel companyId={5} run={run("done")} onClose={() => {}} />);
    expect(screen.getByTestId("audit-step-timeout")).toHaveTextContent("Demorou demasiado (passou o limite de 120 s.). As outras continuam.");
    expect(screen.getByTestId("audit-step-failed")).toHaveTextContent("Falhou: Coluna em falta. As outras continuam.");
    expect(screen.queryByTestId("audit-run-retry")).toBeNull();
});

test("corrida falhada: o passo e Tentar de novo", () => {
    const retry = jest.fn();
    render(<AuditRunPanel companyId={5} run={run("failed")} onClose={() => {}} onRetry={retry} />);
    expect(screen.getByTestId("audit-run-state")).toHaveTextContent("Falhou");
    expect(screen.getByTestId("audit-run-error")).toHaveTextContent("parou no passo «Embalagem na descrição, fator 1 no PingWin»");
    fireEvent.click(screen.getByTestId("audit-run-retry"));
    expect(retry).toHaveBeenCalled();
});
