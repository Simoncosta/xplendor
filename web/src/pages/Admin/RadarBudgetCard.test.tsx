import { render, screen } from "@testing-library/react";
import RadarBudgetCard from "./RadarBudgetCard";

const budget = (over = {}) => ({
    global_cap_usd: 2, company_cap_usd: 0.3, spent_today_usd: 0.47, global_reached: false,
    by_function: [{ function: "radar_relevancia", requests: 3, cost_usd: 0.12 }, { function: "radar_porque", requests: 5, cost_usd: 0.35 }],
    companies: [{ company_id: 2, company: "Tasca Exemplo", requests: 4, cost_usd: 0.3, reached: true }, { company_id: 3, company: "Outra Tasca", requests: 1, cost_usd: 0.05, reached: false }],
    ...over,
});

describe("RadarBudgetCard (Bússola 1)", () => {
    it("mostra o gasto do dia, por função e por empresa, com o teto atingido de uma empresa", () => {
        render(<RadarBudgetCard budget={budget()} />);
        expect(screen.getByTestId("radar-budget-spent")).toHaveTextContent("0,47 USD de 2,00 USD");
        expect(screen.getByText("Relevância por ramo")).toBeInTheDocument();
        expect(screen.getByText("Porque lhe interessa")).toBeInTheDocument();
        expect(screen.getByText("teto atingido")).toBeInTheDocument();
        expect(screen.getByRole("progressbar")).toHaveAttribute("aria-valuenow", "24");
        expect(screen.queryByText("Teto atingido")).not.toBeInTheDocument();
    });

    it("com o teto global atingido, avisa no cabeçalho", () => {
        render(<RadarBudgetCard budget={budget({ spent_today_usd: 2.01, global_reached: true, companies: [] })} />);
        expect(screen.getByText("Teto atingido")).toBeInTheDocument();
        expect(screen.getByText("Nenhuma empresa gastou hoje.")).toBeInTheDocument();
    });

    it("sem dados, não mostra nada", () => {
        const { container } = render(<RadarBudgetCard budget={undefined} />);
        expect(container).toBeEmptyDOMElement();
    });
});
