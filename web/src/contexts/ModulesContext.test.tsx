import { render, screen, waitFor } from "@testing-library/react";
import { ModulesProvider, seesAllModules, useModules } from "./ModulesContext";
import { getMyModules } from "helpers/laravel_helper";

jest.mock("helpers/laravel_helper", () => ({ getMyModules: jest.fn() }));
jest.mock("contexts/WorkingCompanyContext", () => ({ useWorkingCompanyId: () => Number(globalThis.sessionStorage.getItem("test-working") || 0) }));

const login = (role: string, companyId: number, workingId: number) => {
    sessionStorage.setItem("authUser", JSON.stringify({ id: 1, role, company_id: companyId }));
    sessionStorage.setItem("test-working", String(workingId));
};

function Probe() {
    const { has, isRoot, loading } = useModules();
    if (loading) return <span>a carregar</span>;
    return <span data-testid="probe">{JSON.stringify({ isRoot, stock: has("stock"), editorial: has("linha_editorial") })}</span>;
}

describe("módulos no ecrã", () => {
    beforeEach(() => { sessionStorage.clear(); (getMyModules as jest.Mock).mockReset(); });

    it("o root vê tudo só na própria empresa", () => {
        expect(seesAllModules("root", 1, 1)).toBe(true);
        expect(seesAllModules("root", 15, 1)).toBe(false);
        expect(seesAllModules("admin", 1, 1)).toBe(false);
    });

    it("root na própria empresa: tudo, sem pedir os módulos", async () => {
        login("root", 1, 1);
        render(<ModulesProvider><Probe /></ModulesProvider>);
        expect(await screen.findByTestId("probe")).toHaveTextContent('{"isRoot":true,"stock":true,"editorial":true}');
        expect(getMyModules).not.toHaveBeenCalled();
    });

    it("root no contexto de um cliente: segue os módulos desse cliente", async () => {
        (getMyModules as jest.Mock).mockResolvedValue({ data: { modules: ["linha_editorial", "marketing_analytics"] } });
        login("root", 1, 15);
        render(<ModulesProvider><Probe /></ModulesProvider>);
        await waitFor(() => expect(screen.getByTestId("probe")).toHaveTextContent('{"isRoot":false,"stock":false,"editorial":true}'));
        expect(getMyModules).toHaveBeenCalledWith(15);
    });

    it("utilizador normal: os módulos da empresa de trabalho", async () => {
        (getMyModules as jest.Mock).mockResolvedValue({ data: { modules: ["stock"] } });
        login("admin", 3, 3);
        render(<ModulesProvider><Probe /></ModulesProvider>);
        await waitFor(() => expect(screen.getByTestId("probe")).toHaveTextContent('{"isRoot":false,"stock":true,"editorial":false}'));
    });
});
