import { fireEvent, render, screen } from "@testing-library/react";
import ArticleCopyCard, { ArticleChanges, ArticleCopy } from "./ArticleCopyCard";

const copy: ArticleCopy = {
    catalog_item_id: 3, pingwin_id: "101208", code: "101208", description: "COMIDA STAFF 23%", family: "Staff", product_status: "Ativo", is_active: true,
    saleprice_cents: null, purchaseprice_cents: 1590, read_at: "2026-10-19T04:00:00Z", live_read_at: null,
    units: {
        base: { label: "Unidade base", id: null, name: null },
        purchase: { label: "Unidade de compra", id: null, name: "Unidade" },
        sale: { label: "Unidade de venda", id: null, name: "Unidade" },
        stock: { label: "Unidade de stock", id: null, name: null },
    },
};

describe("ArticleCopyCard (cópia local do artigo)", () => {
    it("mostra logo a cópia com a hora dos dados, a atualizar", () => {
        render(<ArticleCopyCard copy={copy} error={null} />);
        expect(screen.getByTestId("article-copy")).toHaveTextContent("Dados de 19/10 às 05:00, a atualizar…");
        expect(screen.getByTestId("copy-unit-purchase")).toHaveTextContent("Unidade");
        expect(screen.getByTestId("copy-unit-base")).toHaveTextContent("—");
    });

    it("sem resposta do PingWin, fica a cópia com o aviso e Tentar de novo", () => {
        const retry = jest.fn();
        render(<ArticleCopyCard copy={copy} error="Não foi possível ligar ao PingWin neste momento." onRetry={retry} />);
        expect(screen.getByTestId("article-copy-error")).toHaveTextContent("Não foi possível ligar ao PingWin neste momento. Estes são os dados guardados de 19/10 às 05:00.");
        fireEvent.click(screen.getByText("Tentar de novo"));
        expect(retry).toHaveBeenCalled();
    });

    it("assinala o que mudou quando a leitura ao vivo chega", () => {
        render(<ArticleChanges changes={[{ field: "purchase", label: "Unidade de compra", from: "Unidade", to: "Quilograma (KG)" }]} />);
        expect(screen.getByTestId("article-changes")).toHaveTextContent("Mudou no PingWin desde a última leitura: Unidade de compra: Unidade → Quilograma (KG).");
    });

    it("avisa quando a unidade de um artigo com histórico mudou (as linhas antigas ficam na anterior)", () => {
        const warning = "A unidade de compra mudou de Unidade (UN) para Quilograma (KG) a 26/10/2026: as 19 linhas de compra anteriores estão em Unidade (UN).";
        render(<ArticleCopyCard copy={{ ...copy, unit_changes: [{ field: "purchase", from: "Unidade (UN)", to: "Quilograma (KG)", changed_at: "2026-10-26T01:40:00Z", history_lines: 19, warning }] }} error={null} />);
        expect(screen.getByTestId("article-unit-changes")).toHaveTextContent(warning);
    });

    it("sem mudanças, não mostra nada", () => {
        const { container } = render(<ArticleChanges changes={[]} />);
        expect(container).toBeEmptyDOMElement();
    });
});
