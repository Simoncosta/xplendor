import { compassProviders } from "./BussolaPage";

jest.mock("contexts/ModulesContext", () => ({ useAccess: () => ({ modules: [], isRoot: false }) }));
jest.mock("./RestaurantBussola", () => () => null);
jest.mock("./stand/StandBussola", () => () => null);
jest.mock("./radar/RadarCard", () => () => null);
jest.mock("./radar/CompassCalendarCard", () => () => null);
jest.mock("./imoveis/ImoveisBussola", () => () => null);
jest.mock("Components/Common/PageHeader", () => () => null);
jest.mock("react-toastify", () => ({ ToastContainer: () => null }));
jest.mock("helpers/workingCompany", () => ({ getWorkingCompanyId: () => 7 }));

describe("Bússola comum: o fornecedor pelos módulos", () => {
    it("o PingWin dá a restauração e o stock dá o stand", () => {
        expect(compassProviders(["pingwin", "linha_editorial"], false)).toEqual(["restauracao"]);
        expect(compassProviders(["stock"], false)).toEqual(["stand"]);
    });
    it("com os dois, a restauração primeiro; o root na própria empresa vê a restauração", () => {
        expect(compassProviders(["pingwin", "stock"], false)).toEqual(["restauracao", "stand"]);
        expect(compassProviders([], true)).toEqual(["restauracao"]);
        expect(compassProviders(["stock"], true)).toEqual(["stand"]);
    });
    it("só com o Radar (sem PingWin nem stock), nenhum fornecedor: a página mostra só o Radar", () => {
        expect(compassProviders(["radar", "linha_editorial"], false)).toEqual([]);
        expect(compassProviders(["stock", "radar"], false)).toEqual(["stand"]);
        expect(compassProviders([], false)).toEqual(["restauracao"]);
    });
    it("os imóveis dão o imobiliário (também com o Radar)", () => {
        expect(compassProviders(["imoveis", "radar"], false)).toEqual(["imobiliario"]);
        expect(compassProviders(["imoveis"], true)).toEqual(["imobiliario"]);
        expect(compassProviders(["stock", "imoveis"], false)).toEqual(["stand", "imobiliario"]);
    });
});
