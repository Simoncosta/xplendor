import { render, screen, within } from "@testing-library/react";
import CompassBudgetCard from "./CompassBudgetCard";

describe("CompassBudgetCard (limite mensal da bussola_jogadas)", () => {
    it("mostra o gasto do mês e cada empresa, com quem chegou ao limite", () => {
        render(<CompassBudgetCard compass={{
            function: "bussola_jogadas", monthly_limit: 60, month: "2026-10", requests_month: 72, cost_month_usd: 0.4321,
            companies: [
                { company_id: 7, company: "Restaurante Yuko", requests: 60, cost_usd: 0.36, reached: true },
                { company_id: 9, company: "Stand Quebom", requests: 12, cost_usd: 0.0721, reached: false },
            ],
        }} />);
        const card = screen.getByTestId("compass-budget");
        expect(within(card).getByTestId("compass-budget-total")).toHaveTextContent("72 pedidos");
        expect(card).toHaveTextContent("outubro de 2026");
        expect(card).toHaveTextContent("1 empresa no limite");
        const rows = within(card).getByTestId("compass-budget-companies");
        expect(rows).toHaveTextContent("Restaurante Yuko");
        expect(rows).toHaveTextContent("60 de 60");
        expect(within(rows).getAllByText("limite atingido")).toHaveLength(1);
    });

    it("sem pedidos este mês, diz-o", () => {
        render(<CompassBudgetCard compass={{ function: "bussola_jogadas", monthly_limit: 60, month: "2026-10", requests_month: 0, cost_month_usd: 0, companies: [] }} />);
        expect(screen.getByTestId("compass-budget")).toHaveTextContent("Nenhuma empresa pediu frases este mês.");
    });
});
