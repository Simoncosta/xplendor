import { render } from "@testing-library/react";
import { MovedTo } from "./MovedTo";

/** Os URLs antigos (Restauração › Lojas, Calendário e Artigos) redirecionam para os novos, com parâmetros, query e estado. */
const mockNavigate = jest.fn();
let mockParams: Record<string, string> = {};
let mockLocation: any = { search: "", hash: "", state: null };
jest.mock("react-router-dom", () => ({
    Navigate: (props: any) => { mockNavigate(props); return null; },
    useParams: () => mockParams,
    useLocation: () => mockLocation,
}), { virtual: true });

beforeEach(() => { mockNavigate.mockReset(); mockParams = {}; mockLocation = { search: "", hash: "", state: null }; });

test("Restauração › Artigos de um artigo vai para Cadastros › Artigos, com a query e o estado", () => {
    mockParams = { pingwinId: "584955579139803444" };
    mockLocation = { search: "?tab=stock", hash: "#unidades", state: { catalogItemId: 7 } };
    render(<MovedTo to="/cadastros/artigos/:pingwinId" />);
    expect(mockNavigate).toHaveBeenCalledWith({ to: "/cadastros/artigos/584955579139803444?tab=stock#unidades", state: { catalogItemId: 7 }, replace: true });
});

test("Lojas e Calendário de Faturação vão para Cadastros e Marketing", () => {
    render(<MovedTo to="/cadastros/lojas" />);
    render(<MovedTo to="/marketing/calendario-faturacao" />);
    expect(mockNavigate.mock.calls.map((c) => c[0].to)).toEqual(["/cadastros/lojas", "/marketing/calendario-faturacao"]);
});

test("na tabela de rotas, cada URL antigo aponta para o novo", () => {
    const src: string = jest.requireActual("fs").readFileSync(require("path").join(__dirname, "allRoutes.tsx"), "utf8");
    const moved = Object.fromEntries(Array.from(src.matchAll(/path: "([^"]+)", component: <MovedTo to="([^"]+)" \/>/g)).map((m) => [m[1], m[2]]));
    expect(moved).toEqual({
        "/restauracao/lojas": "/cadastros/lojas",
        "/restauracao/calendario": "/marketing/calendario-faturacao",
        "/restauracao/artigos": "/cadastros/artigos",
        "/restauracao/artigos/novo": "/cadastros/artigos/novo",
        "/restauracao/artigos/:pingwinId": "/cadastros/artigos/:pingwinId",
    });
});
