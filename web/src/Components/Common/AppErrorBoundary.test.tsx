import { fireEvent, render, screen } from "@testing-library/react";
import AppErrorBoundary from "./AppErrorBoundary";

function Broken(): null {
    throw new Error("ecrã rebentou");
}

describe("proteção na raiz da app", () => {
    const realLocation = window.location;
    const reload = jest.fn();
    beforeEach(() => {
        jest.spyOn(console, "error").mockImplementation(() => undefined); // o React e a proteção registam o erro
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        delete (window as any).location;
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        (window as any).location = { reload };
    });
    afterEach(() => {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        (window as any).location = realLocation;
    });

    it("um ecrã que rebenta mostra a mensagem com Recarregar, nunca um ecrã branco", () => {
        render(<AppErrorBoundary><Broken /></AppErrorBoundary>);
        expect(screen.getByTestId("app-error")).toHaveTextContent("Não foi possível mostrar esta página.");
        fireEvent.click(screen.getByRole("button", { name: /Recarregar/ }));
        expect(reload).toHaveBeenCalled();
    });

    it("sem erro, mostra a app", () => {
        render(<AppErrorBoundary><p>Painel</p></AppErrorBoundary>);
        expect(screen.getByText("Painel")).toBeInTheDocument();
        expect(screen.queryByTestId("app-error")).not.toBeInTheDocument();
    });
});
