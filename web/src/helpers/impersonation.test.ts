import "./api_helper";
import { startImpersonationFlow, stopImpersonationFlow, reconcileImpersonation, takeReturnPath } from "./impersonation";

// O axios do CRA não carrega no Jest (ESM): guarda-se o interceptor de pedidos que o api_helper regista.
jest.mock("axios", () => {
    const requestInterceptors: Array<(c: any) => any> = [];
    return {
        __esModule: true,
        default: {
            requestInterceptors,
            defaults: { headers: { post: {}, common: {} } },
            interceptors: { request: { use: (ok: any) => requestInterceptors.push(ok) }, response: { use: jest.fn() } },
        },
    };
});

const mockStart = jest.fn();
const mockStop = jest.fn();
const mockCurrent = jest.fn();
jest.mock("./laravel_helper", () => ({
    startImpersonation: (...a: unknown[]) => mockStart(...a),
    stopImpersonation: (...a: unknown[]) => mockStop(...a),
    getCurrentImpersonation: (...a: unknown[]) => mockCurrent(...a),
}));

const ROOT = { token: "token-root", id: 1, name: "Equipa", role: "root" };
const assign = jest.fn();
const realLocation = window.location;

/** O cabeçalho que o axios põe no próximo pedido (o interceptor lê o sessionStorage). */
const nextAuthHeader = async () => {
    const config = await jest.requireMock("axios").default.requestInterceptors[0]({ headers: {} });
    return config.headers.Authorization;
};

beforeEach(() => {
    sessionStorage.clear();
    assign.mockReset();
    mockStart.mockReset(); mockStop.mockReset(); mockCurrent.mockReset();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    delete (window as any).location;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (window as any).location = { pathname: "/admin/tickets/7", search: "?aba=2", assign };
    sessionStorage.setItem("authUser", JSON.stringify(ROOT));
});

afterAll(() => {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (window as any).location = realLocation;
});

describe("sessão como cliente: entrar e sair recomeçam limpos", () => {
    it("entrar guarda o root e o sítio de onde entrou, troca o token e recarrega no painel da cliente", async () => {
        mockStart.mockResolvedValue({ data: { token: "token-cliente", user: { id: 2, name: "Cliente", role: "admin", company_id: 5 }, company: { id: 5, name: "Stand" } } });
        expect(await nextAuthHeader()).toBe("Bearer token-root");

        await startImpersonationFlow(2);

        const auth = JSON.parse(sessionStorage.getItem("authUser")!);
        expect(auth).toMatchObject({ token: "token-cliente", id: 2, role: "admin", impersonating: true });
        expect(JSON.parse(sessionStorage.getItem("rootAuthUser")!)).toEqual(ROOT);
        expect(sessionStorage.getItem("impersonationReturnTo")).toBe("/admin/tickets/7?aba=2");
        expect(assign).toHaveBeenCalledWith("/dashboard");
        expect(await nextAuthHeader()).toBe("Bearer token-cliente");
    });

    it("sair restaura o root, apaga o que era da cliente e volta ao sítio de onde entrou", async () => {
        mockStart.mockResolvedValue({ data: { token: "token-cliente", user: { id: 2, role: "admin", company_id: 5 } } });
        mockStop.mockResolvedValue({});
        await startImpersonationFlow(2);
        assign.mockReset();

        await stopImpersonationFlow();

        expect(JSON.parse(sessionStorage.getItem("authUser")!)).toEqual(ROOT);
        expect(sessionStorage.getItem("rootAuthUser")).toBeNull();
        expect(sessionStorage.getItem("impersonationReturnTo")).toBeNull();
        expect(assign).toHaveBeenCalledWith("/admin/tickets/7?aba=2");
        expect(await nextAuthHeader()).toBe("Bearer token-root");
    });

    it("sair mesmo que o servidor falhe, e sem sítio guardado vai para o /admin", async () => {
        mockStart.mockResolvedValue({ data: { token: "token-cliente", user: { id: 2, role: "admin", company_id: 5 } } });
        await startImpersonationFlow(2);
        sessionStorage.removeItem("impersonationReturnTo");
        mockStop.mockRejectedValue(new Error("rede"));
        assign.mockReset();

        await stopImpersonationFlow();

        expect(JSON.parse(sessionStorage.getItem("authUser")!)).toEqual(ROOT);
        expect(assign).toHaveBeenCalledWith("/admin");
    });

    it("a sessão terminada no servidor (token expirado) também volta ao root e ao sítio guardado", async () => {
        mockStart.mockResolvedValue({ data: { token: "token-cliente", user: { id: 2, role: "admin", company_id: 5 } } });
        await startImpersonationFlow(2);
        mockCurrent.mockRejectedValue({ __status: 401 });
        assign.mockReset();

        expect(await reconcileImpersonation()).toBe(true);
        expect(JSON.parse(sessionStorage.getItem("authUser")!)).toEqual(ROOT);
        expect(assign).toHaveBeenCalledWith("/admin/tickets/7?aba=2");
    });

    it("nunca volta para fora da app", () => {
        sessionStorage.setItem("impersonationReturnTo", "//outro.exemplo/roubo");
        expect(takeReturnPath()).toBe("/admin");
        sessionStorage.setItem("impersonationReturnTo", "https://outro.exemplo/");
        expect(takeReturnPath()).toBe("/admin");
    });
});
