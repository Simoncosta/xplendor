import { render } from "@testing-library/react";
import Navdata from "./LayoutMenuData";

jest.mock("react-router-dom", () => ({ useNavigate: () => jest.fn() }), { virtual: true });
jest.mock("helpers/roles", () => ({ sessionIsPlatformRoot: () => false }));
jest.mock("contexts/WorkingCompanyContext", () => ({ useWorkingCompany: () => ({ isWorkingAway: false, workingCompanyId: null, workingAway: false }) }));
let mockModules: string[] = [];
let mockPerms: string[] = [];
let mockBranch: string | null = null;
jest.mock("contexts/ModulesContext", () => ({ useModules: () => ({ has: (m?: string) => !m || mockModules.includes(m), can: (p?: string) => !p || mockPerms.includes(p),
    inBranch: (b?: string) => jest.requireActual("contexts/branch").branchAllows(b, mockBranch, false) }) }));

/** O menu é uma lista de itens (o Navdata devolve-a como filhos de um Fragment). */
const menu = (): any[] => {
    let items: any[] = [];
    // eslint-disable-next-line testing-library/no-node-access -- é o elemento React que o Navdata devolve, não um nó do DOM
    const Probe = () => { const el: any = Navdata(); items = el.props.children; return null; };
    render(<Probe />);
    return items;
};

describe("Menu lateral: o ramo imobiliário", () => {
    it("agrupa Imóveis, Empreendimentos, Contactos recebidos e Site do consultor em Imobiliário, e a Bússola no Marketing", () => {
        mockModules = ["imoveis", "site_consultor"];
        mockPerms = ["imoveis.ver", "contactos.ver", "site_consultor.ver", "bussola.ver"];
        const items = menu();
        const group = items.find((i) => i.id === "imobiliario");
        expect(group).toMatchObject({ label: "Imobiliário", icon: "ri-home-4-line" });
        expect(group.subItems.map((s: any) => s.label)).toEqual(["Imóveis", "Empreendimentos", "Contactos recebidos", "Site do consultor"]);
        expect(items.some((i) => ["imoveis", "empreendimentos", "contactos", "site-consultor"].includes(i.id))).toBe(false); // já não soltos
        expect(items.find((i) => i.id === "analytics").subItems.map((s: any) => s.id)).toContain("bussola");
    });

    it("só com o módulo dos imóveis: o grupo sem o site; sem o módulo: sem grupo", () => {
        mockModules = ["imoveis"];
        mockPerms = ["imoveis.ver", "contactos.ver", "site_consultor.ver"];
        expect(menu().find((i) => i.id === "imobiliario").subItems.map((s: any) => s.id)).toEqual(["imoveis", "empreendimentos"]);
        mockModules = ["stock"];
        expect(menu().find((i) => i.id === "imobiliario")).toBeUndefined();
    });
});

describe("Menu lateral: Restauração e Cadastros", () => {
    const RESTAURANT = ["pingwin", "restauracao_lojas", "restauracao_calendario", "restauracao_artigos", "restauracao_faturas", "restauracao_documentos",
        "restauracao_familias", "restauracao_fornecedores", "restauracao_unidades", "restauracao_condicoes_pagamento", "restauracao_conta_corrente", "marketing_analytics"];
    const sub = (items: any[], id: string) => (items.find((i) => i.id === id)?.subItems ?? []).map((s: any) => s.label);

    it("no ramo da restauração: Lojas e Artigos em Cadastros e o Calendário de Faturação no Marketing", () => {
        mockModules = RESTAURANT;
        mockPerms = ["restauracao.ver", "resultados.ver"];
        mockBranch = "restauracao";
        const items = menu();
        expect(sub(items, "cadastros")).toEqual(["Lojas", "Artigos", "Documentos", "Famílias", "Fornecedores", "Unidades", "Armazéns", "Condições de Pagamento"]);
        expect(sub(items, "restauracao")).not.toContain("Lojas");
        expect(sub(items, "restauracao")).not.toContain("Artigos");
        expect(sub(items, "restauracao")).not.toContain("Calendário de faturação");
        expect(sub(items, "analytics")).toContain("Calendário de Faturação");
        const cad = items.find((i) => i.id === "cadastros").subItems;
        expect(cad.find((s: any) => s.label === "Lojas").link).toBe("/cadastros/lojas");
        expect(cad.find((s: any) => s.label === "Artigos").link).toBe("/cadastros/artigos");
        expect(items.find((i) => i.id === "analytics").subItems.find((s: any) => s.id === "pingwin-calendario").link).toBe("/marketing/calendario-faturacao");
    });

    it("noutro ramo (Carros), mesmo com os módulos ligados, não vê os cadastros nem o calendário da restauração", () => {
        mockModules = RESTAURANT;
        mockPerms = ["restauracao.ver", "resultados.ver"];
        mockBranch = "automovel";
        const items = menu();
        expect(items.find((i) => i.id === "cadastros")).toBeUndefined();
        expect(sub(items, "analytics")).not.toContain("Calendário de Faturação");
        expect(sub(items, "analytics")).toContain("Tráfego do site");
    });

    it("sem setor (ou com um setor fora dos ramos), decide o módulo: com a restauração ligada, vê tudo", () => {
        mockModules = RESTAURANT;
        mockPerms = ["restauracao.ver", "resultados.ver"];
        mockBranch = null;
        const items = menu();
        expect(sub(items, "cadastros")).toEqual(["Lojas", "Artigos", "Documentos", "Famílias", "Fornecedores", "Unidades", "Armazéns", "Condições de Pagamento"]);
        expect(sub(items, "analytics")).toContain("Calendário de Faturação");
        // Sem os módulos da restauração, sem ramo, não aparece nada da restauração.
        mockModules = ["marketing_analytics"];
        const none = menu();
        expect(none.find((i) => i.id === "cadastros")).toBeUndefined();
        expect(sub(none, "analytics")).not.toContain("Calendário de Faturação");
    });
});

describe("Menu lateral: os fornecedores das Finanças", () => {
    it("chamam-se Fornecedores de despesas, para não se confundirem com Cadastros › Fornecedores", () => {
        mockModules = ["finance"];
        mockPerms = ["financas.ver"];
        mockBranch = null;
        const fin = menu().find((i) => i.id === "finances").subItems.find((s: any) => s.id === "suppliers");
        expect(fin).toMatchObject({ label: "Fornecedores de despesas", link: "/suppliers" });
    });
});
