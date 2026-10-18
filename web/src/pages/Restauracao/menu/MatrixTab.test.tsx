import { fireEvent, render, screen } from "@testing-library/react";
import MatrixTab from "./MatrixTab";
import { mi } from "./menuFormat.test";
import { FamilyStats } from "common/models/menuEngineering.model";

jest.mock("react-apexcharts", () => ({ __esModule: true, default: () => <div data-testid="apex" /> }));
jest.mock("Components/Common/Select", () => ({ __esModule: true, default: () => <div /> }));
jest.mock("Components/Common/InfoTip", () => ({ __esModule: true, default: () => null }));

const fam: FamilyStats = { family: "Comidas › Francesinhas", items: 3, costed: 2, qty: 300, revenue: 3900, popularity_threshold_pct: 23.3, margin_line: 7.5,
    price_cv_pct: 2, coverage_pct: 70, fragile: [{ code: "poucos_artigos", text: "A família tem só 2 artigos com custo (menos de 4)." }] };
const items = [
    mi(),
    mi({ product_id: "P2", description: "Meia Francesinha", class: "cavalo", confidence: "parcial", cost_eur: 6, margin_eur: 5, fragile: true }),
    mi({ product_id: "P3", description: "Francesinha Vegetariana", class: null, confidence: "sem_custo", cost_eur: null, margin_eur: null, share_pct: 10, popular: false }),
];
const mockMatch = (matches: boolean) => { window.matchMedia = jest.fn().mockImplementation(() => ({ matches, addEventListener: jest.fn(), removeEventListener: jest.fn() })); };

describe("MatrixTab", () => {
    it("no ecrã largo desenha a dispersão, avisa da classe frágil e põe os sem custo na faixa à parte", () => {
        mockMatch(false);
        const onOpen = jest.fn();
        render(<MatrixTab items={items} families={[fam]} hidden={false} onOpen={onOpen} />);
        expect(screen.getByTestId("apex")).toBeInTheDocument();
        expect(screen.getByTestId("menu-fragile")).toHaveTextContent("só 2 artigos com custo");
        expect(screen.queryByTestId("menu-matrix-list")).toBeNull();
        fireEvent.click(screen.getByText(/Francesinha Vegetariana/));
        expect(onOpen).toHaveBeenCalledWith(expect.objectContaining({ product_id: "P3" }));
    });

    it("no telemóvel a matriz passa a lista por classe", () => {
        mockMatch(true);
        render(<MatrixTab items={items} families={[fam]} hidden={false} onOpen={jest.fn()} />);
        expect(screen.queryByTestId("apex")).toBeNull();
        const list = screen.getByTestId("menu-matrix-list");
        expect(list).toHaveTextContent("Estrela (1)");
        expect(list).toHaveTextContent("Cavalo de trabalho (1)");
        expect(list).toHaveTextContent("Classe frágil");
        expect(screen.getAllByTestId("menu-item-line")).toHaveLength(2);
    });
});
