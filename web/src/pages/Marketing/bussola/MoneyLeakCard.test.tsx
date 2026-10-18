import { render, screen } from "@testing-library/react";
import { MoneyLeakCard } from "./BussolaBlocks";

// O Jest deste projeto não resolve o react-router-dom: o Link fica um <a>.
jest.mock("react-router-dom", () => ({ Link: ({ children, to, className }: any) => <a href={to} className={className}>{children}</a> }), { virtual: true });
jest.mock("pages/Restauracao/HeatmapCard", () => ({ __esModule: true, default: () => null }));

/** Auditoria v2-C: o bloco "Onde está a fugir dinheiro" da Bússola (o top 3 pelo € em jogo). */
const block = {
    headline: "Onde está a fugir dinheiro: 5 292 € em jogo nos achados de dinheiro por resolver.",
    open_cents: 529225,
    items: [
        { finding_id: 1, title: "BOVFT/382: possível duplicado de BOVFT/347", rule_title: "Faturas de fornecedor em duplicado", impact_cents: 69857 },
        { finding_id: 2, title: "Queijo barra: 11% mais barato na Pinheiro", rule_title: "Mais barato noutro fornecedor", impact_cents: null },
    ],
};

test("mostra os achados, o € em jogo e a ligação ao grupo Dinheiro", () => {
    render(<MoneyLeakCard block={block} />);
    expect(screen.getByTestId("bussola-money")).toHaveTextContent("Onde está a fugir dinheiro");
    expect(screen.getByText("BOVFT/382: possível duplicado de BOVFT/347")).toBeInTheDocument();
    expect(screen.getByTestId("bussola-money")).toHaveTextContent("699 €");
    expect(screen.getByRole("link", { name: "Ver todos" })).toHaveAttribute("href", "/auditoria?familia=dinheiro");
});

test("sem achados não mostra nada", () => {
    const { container } = render(<MoneyLeakCard block={{ ...block, items: [] }} />);
    expect(container).toBeEmptyDOMElement();
});
