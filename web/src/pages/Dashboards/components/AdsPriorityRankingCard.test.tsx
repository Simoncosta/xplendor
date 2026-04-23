import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import AdsPriorityRankingCard from "./AdsPriorityRankingCard";
import { IAdsPriorityRankedCar } from "./marketingRoi.types";

jest.mock("react-router-dom", () => ({
    Link: ({ to, children, ...props }: any) => (
        <a href={to} {...props}>{children}</a>
    ),
}), { virtual: true });

const baseCar = (overrides: Partial<IAdsPriorityRankedCar>): IAdsPriorityRankedCar => ({
    position: 1,
    car_id: 1,
    car_name: "BMW 320d Pack M",
    price_gross: 24900,
    promotion_score: 82,
    promotion_state: "ready",
    promotion_label: "Pronto para anunciar",
    confidence: 76,
    reasons: ["Sinal forte de contacto recente."],
    recommended_action: {
        type: "promote_car",
        label: "Promover este carro",
    },
    priority_score: 82,
    confidence_score: 76,
    investment_label: "high_priority",
    reason: "Sinal forte de contacto recente.",
    why_now: null,
    risk_note: null,
    smartads_decision: "scale_ads",
    ...overrides,
});

const renderCard = (cars: IAdsPriorityRankedCar[]) => render(<AdsPriorityRankingCard cars={cars} />);

test("renders cars in the blocks provided by backend state", () => {
    renderCard([
        baseCar({ car_id: 1, car_name: "Ready Car", promotion_state: "ready", promotion_label: "Pronto para anunciar" }),
        baseCar({ car_id: 2, car_name: "Candidate Car", promotion_state: "candidate", promotion_label: "Bom candidato" }),
        baseCar({ car_id: 3, car_name: "Watch Car", promotion_state: "watch", promotion_label: "Em observação" }),
        baseCar({ car_id: 4, car_name: "Avoid Car", promotion_state: "avoid", promotion_label: "Evitar investimento" }),
    ]);

    expect(screen.getByText("Prontos para anunciar")).toBeInTheDocument();
    expect(screen.getByText("Bons candidatos")).toBeInTheDocument();
    expect(screen.getAllByText("Em observação").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Evitar investimento").length).toBeGreaterThan(0);
    expect(screen.getByText("Ready Car")).toBeInTheDocument();
    expect(screen.getByText("Candidate Car")).toBeInTheDocument();
    expect(screen.getByText("Watch Car")).toBeInTheDocument();
    expect(screen.getByText("Avoid Car")).toBeInTheDocument();
});

test("renders honest empty states", () => {
    renderCard([]);

    expect(screen.getByText("Nenhum carro está forte o suficiente para investimento imediato.")).toBeInTheDocument();
    expect(screen.getByText("Não existem candidatos intermédios neste momento.")).toBeInTheDocument();
    expect(screen.getByText("Sem carros em observação agora.")).toBeInTheDocument();
    expect(screen.getByText("Nenhum carro foi sinalizado para evitar investimento.")).toBeInTheDocument();
});

test("limit selector applies to the current ranking view", async () => {
    const cars = Array.from({ length: 6 }, (_, index) => baseCar({
        car_id: index + 1,
        car_name: `Car ${index + 1}`,
        promotion_state: index === 5 ? "avoid" : "ready",
        promotion_label: index === 5 ? "Evitar investimento" : "Pronto para anunciar",
    }));

    renderCard(cars);

    expect(screen.getByText("Car 1")).toBeInTheDocument();
    expect(screen.queryByText("Car 6")).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Todos" }));

    expect(screen.getByText("Car 6")).toBeInTheDocument();
});
