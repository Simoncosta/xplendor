import { companyDisplayName, companyWith, companyWithArticle, contractCompanyName } from "./companyName";

describe("companyName", () => {
    it("usa o nome comercial e, sem ele, o fiscal", () => {
        expect(companyDisplayName({ trade_name: "Pinheiros", fiscal_name: "Pinheiros, Lda." })).toBe("Pinheiros");
        expect(companyDisplayName({ trade_name: null, fiscal_name: "Pinheiros, Lda." })).toBe("Pinheiros, Lda.");
    });

    it("faz as contrações com cada artigo", () => {
        expect(contractCompanyName("em", "Pinheiros", "os")).toBe("nos Pinheiros");
        expect(contractCompanyName("em", "Adega", "a")).toBe("na Adega");
        expect(contractCompanyName("de", "Pinheiro", "o")).toBe("do Pinheiro");
        expect(contractCompanyName("de", "Tias", "as")).toBe("das Tias");
        expect(contractCompanyName("a", "Adega", "a")).toBe("à Adega");
        expect(contractCompanyName("a", "Tias", "as")).toBe("às Tias");
        expect(contractCompanyName("a", "Pinheiros", "os")).toBe("aos Pinheiros");
    });

    it("sem artigo (ou com um inválido) não usa artigo nem contração", () => {
        expect(contractCompanyName("em", "Pinheiro", null)).toBe("em Pinheiro");
        expect(contractCompanyName("de", "Pinheiro", "")).toBe("de Pinheiro");
        expect(contractCompanyName("a", "Pinheiro", "xx")).toBe("a Pinheiro");
        expect(companyWithArticle({ trade_name: "Pinheiro" })).toBe("Pinheiro");
    });

    it("lê o artigo da empresa", () => {
        const company = { trade_name: "Pinheiros", fiscal_name: "Pinheiros, Lda.", name_article: "os" };
        expect(companyWith("em", company)).toBe("nos Pinheiros");
        expect(companyWithArticle(company)).toBe("os Pinheiros");
    });
});
