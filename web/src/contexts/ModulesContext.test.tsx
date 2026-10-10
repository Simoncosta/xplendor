import { render, screen, waitFor } from "@testing-library/react";
import { ModulesProvider, seesAllModules, useModules, useCan } from "./ModulesContext";
import { getMyAccess } from "helpers/laravel_helper";

jest.mock("helpers/laravel_helper", () => ({ getMyAccess: jest.fn() }));
jest.mock("contexts/WorkingCompanyContext", () => ({ useWorkingCompanyId: () => Number(globalThis.sessionStorage.getItem("test-working") || 0) }));

const login = (role: string, companyId: number, workingId: number) => {
    sessionStorage.setItem("authUser", JSON.stringify({ id: 1, role, company_id: companyId }));
    sessionStorage.setItem("test-working", String(workingId));
};

const access = (modules: string[], permissions: Record<string, boolean>, reasons: Record<string, string> = {}) =>
    ({ data: { modules, permissions, reasons, agency_admin: false, profile: { name: "Utilizador (como hoje)" } } });

function Probe() {
    const { has, can, loading, isRoot, profileName } = useModules();
    const [canApprove, why] = useCan("editorial.aprovar");
    if (loading) return <span data-testid="probe">a carregar {String(has("stock"))} {String(can("blog.ver"))}</span>;
    return (
        <span data-testid="probe">
            {JSON.stringify({ isRoot, stock: has("stock"), editorial: has("linha_editorial"), blog: can("blog.ver"), canApprove, why, profileName })}
        </span>
    );
}

describe("acessos no ecrã (ACL, F4)", () => {
    beforeEach(() => { sessionStorage.clear(); (getMyAccess as jest.Mock).mockReset(); });

    it("o root vê todos os módulos só na própria empresa", () => {
        expect(seesAllModules("root", 1, 1)).toBe(true);
        expect(seesAllModules("root", 15, 1)).toBe(false);
        expect(seesAllModules("admin", 1, 1)).toBe(false);
    });

    it("falha fechada: enquanto carrega, nenhum módulo nem permissão conta como dado", () => {
        (getMyAccess as jest.Mock).mockReturnValue(new Promise(() => {}));
        login("user", 3, 3);
        render(<ModulesProvider><Probe /></ModulesProvider>);
        expect(screen.getByTestId("probe")).toHaveTextContent("a carregar false false");
    });

    it("falha fechada: se o pedido falhar, fica tudo fechado e o motivo explica", async () => {
        (getMyAccess as jest.Mock).mockRejectedValue(new Error("rede"));
        login("user", 3, 3);
        render(<ModulesProvider><Probe /></ModulesProvider>);
        await waitFor(() => expect(screen.getByTestId("probe")).toHaveTextContent('"stock":false'));
        expect(screen.getByTestId("probe")).toHaveTextContent('"blog":false');
        expect(screen.getByTestId("probe")).toHaveTextContent("Não foi possível carregar as permissões");
    });

    it("utilizador normal: os módulos e as permissões da empresa de trabalho, com o motivo do backend", async () => {
        (getMyAccess as jest.Mock).mockResolvedValue(access(["linha_editorial"], { "blog.ver": true, "editorial.aprovar": false },
            { "editorial.aprovar": "O seu perfil não permite aprovar em Linha Editorial." }));
        login("user", 3, 3);
        render(<ModulesProvider><Probe /></ModulesProvider>);
        await waitFor(() => expect(screen.getByTestId("probe")).toHaveTextContent('"isRoot":false,"stock":false,"editorial":true,"blog":true,"canApprove":false'));
        expect(screen.getByTestId("probe")).toHaveTextContent("O seu perfil não permite aprovar em Linha Editorial.");
        expect(screen.getByTestId("probe")).toHaveTextContent("Utilizador (como hoje)");
        expect(getMyAccess).toHaveBeenCalledWith(3);
    });

    it("root na própria empresa: todos os módulos; as permissões continuam a vir do backend", async () => {
        (getMyAccess as jest.Mock).mockResolvedValue(access([], { "blog.ver": true, "editorial.aprovar": false }));
        login("root", 1, 1);
        render(<ModulesProvider><Probe /></ModulesProvider>);
        await waitFor(() => expect(screen.getByTestId("probe")).toHaveTextContent('"isRoot":true,"stock":true'));
        expect(screen.getByTestId("probe")).toHaveTextContent('"canApprove":false');
    });

    it("root no contexto de um cliente: segue os módulos desse cliente", async () => {
        (getMyAccess as jest.Mock).mockResolvedValue(access(["linha_editorial"], { "blog.ver": true }));
        login("root", 1, 15);
        render(<ModulesProvider><Probe /></ModulesProvider>);
        await waitFor(() => expect(screen.getByTestId("probe")).toHaveTextContent('"isRoot":false,"stock":false,"editorial":true'));
        expect(getMyAccess).toHaveBeenCalledWith(15);
    });
});
