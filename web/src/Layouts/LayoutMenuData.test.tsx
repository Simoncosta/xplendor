import { render } from "@testing-library/react";
import Navdata from "./LayoutMenuData";

jest.mock("react-router-dom", () => ({ useNavigate: () => jest.fn() }), { virtual: true });
jest.mock("helpers/roles", () => ({ sessionIsPlatformRoot: () => false }));
jest.mock("contexts/WorkingCompanyContext", () => ({ useWorkingCompany: () => ({ isWorkingAway: false, workingCompanyId: null, workingAway: false }) }));
let mockModules: string[] = [];
let mockPerms: string[] = [];
jest.mock("contexts/ModulesContext", () => ({ useModules: () => ({ has: (m?: string) => !m || mockModules.includes(m), can: (p?: string) => !p || mockPerms.includes(p) }) }));

/** O menu é uma lista de itens (o Navdata devolve-a como filhos de um Fragment). */
const menu = (): any[] => {
    let items: any[] = [];
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
