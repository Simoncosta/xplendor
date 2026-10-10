import { render, screen } from "@testing-library/react";
import RequireModule from "./RequireModule";
import { useModules } from "contexts/ModulesContext";

// O react-router-dom 7 não se resolve no Jest 27 do CRA: um Navigate que só mostra o destino.
jest.mock("react-router-dom", () => ({ Navigate: ({ to }: { to: string }) => <span data-testid="redirect">{to}</span> }), { virtual: true });
jest.mock("contexts/ModulesContext", () => ({ useModules: jest.fn() }));

const state = (over: Partial<ReturnType<typeof useModules>>) =>
    (useModules as jest.Mock).mockReturnValue({ loading: false, has: () => true, can: () => true, ...over });

describe("guarda das rotas (ACL, F4)", () => {
    it("falha fechada: enquanto carrega mostra 'a carregar' e nunca a página", () => {
        state({ loading: true });
        render(<RequireModule module="finance" permission="financas.ver"><span>página</span></RequireModule>);
        expect(screen.getByRole("status")).toHaveTextContent("A carregar");
        expect(screen.queryByText("página")).not.toBeInTheDocument();
    });

    it("sem a permissão, volta ao dashboard", () => {
        state({ can: (p?: string) => p !== "financas.ver" });
        render(<RequireModule module="finance" permission="financas.ver"><span>página</span></RequireModule>);
        expect(screen.getByTestId("redirect")).toHaveTextContent("/dashboard");
    });

    it("sem o módulo, volta ao dashboard", () => {
        state({ has: (m?: string) => m !== "finance" });
        render(<RequireModule module="finance"><span>página</span></RequireModule>);
        expect(screen.getByTestId("redirect")).toHaveTextContent("/dashboard");
    });

    it("com o módulo e a permissão, mostra a página", () => {
        state({});
        render(<RequireModule module="finance" permission="financas.ver"><span>página</span></RequireModule>);
        expect(screen.getByText("página")).toBeInTheDocument();
    });
});
