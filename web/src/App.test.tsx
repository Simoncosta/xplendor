import { render, screen } from "@testing-library/react";
import { Provider } from "react-redux";
import { configureStore } from "@reduxjs/toolkit";
import { MemoryRouter } from "react-router-dom";
import rootReducer from "./slices";
import App from "./App";

/**
 * Arranque da app: carrega todas as rotas e ecrãs (o que apanha um import partido ou um pacote que o
 * Jest não consegue ler) e, sem sessão, mostra o ecrã de entrada.
 */
test("a app arranca e, sem sessão, mostra o ecrã de entrada", async () => {
    sessionStorage.clear();
    const store = configureStore({ reducer: rootReducer });
    render(
        <Provider store={store}>
            <MemoryRouter initialEntries={["/login"]}>
                <App />
            </MemoryRouter>
        </Provider>
    );
    expect(await screen.findByRole("heading", { name: "Entrar na XPLENDOR" })).toBeInTheDocument();
    expect(screen.getByPlaceholderText("O seu email")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Entrar" })).toBeInTheDocument();
});
