// O nome da empresa nos textos, com o artigo dela (name_article: o, a, os, as) e as contrações do
// português: em → no/na/nos/nas, de → do/da/dos/das, a → ao/à/aos/às. Sem artigo, o texto não usa
// artigo nem contração ("em Pinheiro", "de Pinheiro"). Espelha App\Support\CompanyName do servidor.

export type CompanyNameArticle = "o" | "a" | "os" | "as";
export type CompanyNamePreposition = "em" | "de" | "a";

export const COMPANY_NAME_ARTICLES: CompanyNameArticle[] = ["o", "a", "os", "as"];

const CONTRACTIONS: Record<CompanyNamePreposition, Record<CompanyNameArticle, string>> = {
    em: { o: "no", a: "na", os: "nos", as: "nas" },
    de: { o: "do", a: "da", os: "dos", as: "das" },
    a: { o: "ao", a: "à", os: "aos", as: "às" },
};

interface CompanyLike {
    trade_name?: string | null;
    fiscal_name?: string | null;
    name_article?: string | null;
}

const validArticle = (article?: string | null): CompanyNameArticle | null =>
    COMPANY_NAME_ARTICLES.includes(article as CompanyNameArticle) ? (article as CompanyNameArticle) : null;

/** O nome que se mostra: o comercial, senão o fiscal. */
export const companyDisplayName = (company?: CompanyLike | null): string =>
    String(company?.trade_name || company?.fiscal_name || "").trim();

/** "os Pinheiros"; sem artigo, só o nome. */
export const companyWithArticle = (company?: CompanyLike | null): string => {
    const name = companyDisplayName(company);
    const article = validArticle(company?.name_article);
    return article ? `${article} ${name}` : name;
};

/** "nos Pinheiros", "dos Pinheiros", "aos Pinheiros"; sem artigo, "em Pinheiros", "de Pinheiros", "a Pinheiros". */
export const contractCompanyName = (preposition: CompanyNamePreposition, name: string, article?: string | null): string => {
    const a = validArticle(article);
    return a ? `${CONTRACTIONS[preposition][a]} ${name}` : `${preposition} ${name}`.trim();
};

export const companyWith = (preposition: CompanyNamePreposition, company?: CompanyLike | null): string =>
    contractCompanyName(preposition, companyDisplayName(company), company?.name_article);
