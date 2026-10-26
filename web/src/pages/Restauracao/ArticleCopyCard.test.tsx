import { render, screen } from "@testing-library/react";
import { ArticleChanges } from "./ArticleCopyCard";

describe("ArticleChanges (o que mudou face à cópia)", () => {
    it("assinala o que mudou quando a leitura ao vivo chega", () => {
        render(<ArticleChanges changes={[{ field: "purchase", label: "Unidade de compra", from: "Unidade", to: "Quilograma (KG)" }]} />);
        expect(screen.getByTestId("article-changes")).toHaveTextContent("Mudou no PingWin desde a última leitura: Unidade de compra: Unidade → Quilograma (KG).");
    });

    it("sem mudanças, não mostra nada", () => {
        const { container } = render(<ArticleChanges changes={[]} />);
        expect(container).toBeEmptyDOMElement();
    });
});
