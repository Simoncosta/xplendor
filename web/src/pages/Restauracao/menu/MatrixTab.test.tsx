import { fireEvent, render, screen } from "@testing-library/react";
import MatrixTab from "./MatrixTab";
import { mi } from "./menuFormat.test";
import { FamilyStats, MenuSnapshot } from "common/models/menuEngineering.model";

jest.mock("Components/Common/Select", () => ({ __esModule: true, default: () => <div /> }));
jest.mock("Components/Common/InfoTip", () => ({ __esModule: true, default: () => null }));

const fam: FamilyStats = { family: "Comidas › Francesinhas", items: 3, costed: 2, qty: 300, revenue: 3900, popularity_threshold_pct: 23.3, margin_line: 7.5,
    price_cv_pct: 2, coverage_pct: 70, fragile: [{ code: "poucos_artigos", text: "A família tem só 2 artigos com custo (menos de 4)." }] };
const items = [
    mi(),
    mi({ product_id: "P2", description: "Meia Francesinha Tradicional com ovo", class: "cavalo", confidence: "parcial", cost_eur: 6, margin_eur: 5, fragile: true, qty: 600 }),
    mi({ product_id: "P3", description: "Francesinha Vegetariana", class: null, confidence: "sem_custo", cost_eur: null, margin_eur: null, share_pct: 10, popular: false }),
];
const snapshot = { period: { from: "2026-07-20", to: "2026-10-17", days: 90 },
    worklist: [{ product_id: "P2", description: "Meia Francesinha Tradicional com ovo", family: "Comidas › Francesinhas", confidence: "parcial", revenue_eur: 100, revenue_share_pct: 5, cost_eur: 6,
        actions: [{ type: "ver_ficha", product_id: "MOLHO", label: "Ver o semi-produto", text: "MOLHO FRANCESINHA 30LT: rendimento por confirmar." }] }] } as unknown as MenuSnapshot;
const plays = { estrela: "Manter a receita e a porção.", cavalo: "Rever a porção ou subir o preço." };
const mockMatch = (matches: boolean) => { window.matchMedia = jest.fn().mockImplementation(() => ({ matches, addEventListener: jest.fn(), removeEventListener: jest.fn() })); };

describe("MatrixTab", () => {
    it("no ecrã largo desenha a matriz com quadrantes, bolhas preenchidas, nomes e o que isto quer dizer", () => {
        mockMatch(false);
        const onOpen = jest.fn();
        render(<MatrixTab items={items} snapshot={snapshot} families={[fam]} hidden={false} plays={plays} onOpen={onOpen} />);
        const chart = screen.getByTestId("menu-matrix-chart");
        expect(chart).toHaveTextContent("Quebra-cabeças");
        expect(chart).toHaveTextContent("Popularidade 23%");
        expect(chart).toHaveTextContent("Margem média 7,50 €");
        expect(chart).toHaveTextContent("Meia Francesinha…");                // encurtado a 18 caracteres
        const bubbles = screen.getAllByTestId("menu-bubble");
        expect(bubbles).toHaveLength(2);
        const partial = bubbles.find((b) => b.getAttribute("aria-label")?.includes("Parcial"))!;
        expect(partial.getAttribute("style")).toContain("fill-opacity: 0.35");
        expect(partial.getAttribute("style")).toContain("stroke-dasharray: 4 3");
        fireEvent.mouseEnter(partial);
        expect(screen.getByTestId("menu-tooltip")).toHaveTextContent("custo pelo menos 6,00 €");
        fireEvent.click(partial);
        expect(onOpen).toHaveBeenCalledWith(expect.objectContaining({ product_id: "P2" }));
        const ins = screen.getByTestId("menu-insights");
        expect(ins).toHaveTextContent("Francesinha é a estrela: 40% das vendas da família e 8,46 € de margem por unidade.");
        expect(ins).toHaveTextContent("Subir 0,50 € no preço de Meia Francesinha Tradicional com ovo vale ≈ 100 € por mês");
        expect(ins).toHaveTextContent("Os custos ainda são parciais: confirmar o rendimento de MOLHO FRANCESINHA 30LT fecha os números.");
        expect(ins).toHaveTextContent("Rever a porção ou subir o preço.");
        expect(screen.getByTestId("menu-fragile")).toHaveTextContent("só 2 artigos com custo");
        expect(screen.queryByTestId("menu-matrix-list")).toBeNull();
    });

    it("no telemóvel a matriz passa a lista por classe e o cartão das frases vem primeiro", () => {
        mockMatch(true);
        render(<MatrixTab items={items} snapshot={snapshot} families={[fam]} hidden={false} plays={plays} onOpen={jest.fn()} />);
        expect(screen.queryByTestId("menu-matrix-chart")).toBeNull();
        const insights = screen.getByTestId("menu-insights");
        const list = screen.getByTestId("menu-matrix-list");
        expect(insights.compareDocumentPosition(list) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(screen.getAllByTestId("menu-item-line")).toHaveLength(2);
        expect(list).toHaveTextContent("Classe frágil");
    });
});
