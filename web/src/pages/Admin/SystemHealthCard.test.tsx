import { render, screen } from "@testing-library/react";
import SystemHealthCard, { checkStatus, HealthCheck } from "./SystemHealthCard";
import { getAdminSystemHealth } from "helpers/laravel_helper";

jest.mock("helpers/laravel_helper", () => ({ getAdminSystemHealth: jest.fn() }));

const check = (over: Partial<HealthCheck> = {}): HealthCheck => ({
    name: "database", label: "Base de dados", ok: true, skipped: false, detail: null,
    last_ok_at: "2026-10-22T21:00:00Z", failing_since: null, alerted_at: null, ...over,
});

describe("SystemHealthCard", () => {
    it("classifica cada verificação", () => {
        expect(checkStatus(check())).toEqual({ label: "Bem", tone: "success" });
        expect(checkStatus(check({ ok: false }))).toEqual({ label: "Falha", tone: "danger" });
        expect(checkStatus(check({ skipped: true }))).toEqual({ label: "Não usado", tone: "secondary" });
    });

    it("mostra a falha com o motivo e se o root foi avisado", async () => {
        (getAdminSystemHealth as jest.Mock).mockResolvedValue({ data: { healthy: false, checks: [
            check(),
            check({ name: "redis", label: "Redis (fila e cache)", ok: false, detail: "O Redis não responde (fila e cache).",
                failing_since: "2026-10-22T20:40:00Z", alerted_at: "2026-10-22T20:50:00Z" }),
            check({ name: "antivirus", label: "Antivírus (ClamAV)", skipped: true }),
        ] } });
        render(<SystemHealthCard />);

        expect(await screen.findByText("Há falhas")).toBeInTheDocument();
        expect(screen.getByText("O Redis não responde (fila e cache).")).toBeInTheDocument();
        expect(screen.getByText(/o root foi avisado/)).toBeInTheDocument();
        expect(screen.getByText("Não usado")).toBeInTheDocument();
        expect(screen.getAllByText("Bem")).toHaveLength(1);
    });

    it("diz quando não consegue ler", async () => {
        (getAdminSystemHealth as jest.Mock).mockRejectedValue(new Error("x"));
        render(<SystemHealthCard />);
        expect(await screen.findByText(/Não foi possível ler a saúde do sistema/)).toBeInTheDocument();
    });
});
