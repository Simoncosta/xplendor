import { render, screen } from "@testing-library/react";
import PriceSimulator, { simulatePrice } from "./PriceSimulator";

/** Auditoria v2-C (C2): o simulador do preço do prato (+0,25, +0,50, +1,00 € com IVA). */
const action = { price: 10, cost: 3.2, cost_avg: 2.8, monthly_qty: 300, vat_pct: 13, steps: [0.25, 0.5, 1] };

test("o preço sobe sem o IVA, a margem por mês e o food cost novo", () => {
    const r = simulatePrice(action, 0.5);
    expect(r.price).toBeCloseTo(10 + 0.5 / 1.13, 6);
    expect(r.monthly).toBeCloseTo((0.5 / 1.13) * 300, 6);
    expect(r.food_cost).toBeCloseTo((3.2 / (10 + 0.5 / 1.13)) * 100, 6);
    expect(r.covers).toBe(true);                       // 0,44 € a mais cobre os 0,40 € de subida do custo
    expect(simulatePrice(action, 0.25).covers).toBe(false);
});

test("mostra as três subidas", () => {
    render(<PriceSimulator action={action} />);
    expect(screen.getByTestId("simulator-0.25")).toHaveTextContent("+0,25 €");
    expect(screen.getByTestId("simulator-1")).toHaveTextContent("265,49 €");   // 1 / 1,13 × 300
    expect(screen.getByText(/Hoje: 10,00 € sem IVA, food cost de 32%/)).toBeInTheDocument();
});
