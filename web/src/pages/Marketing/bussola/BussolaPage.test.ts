import { compassProviders } from "./BussolaPage";

jest.mock("contexts/ModulesContext", () => ({ useAccess: () => ({ modules: [], isRoot: false }) }));
jest.mock("./RestaurantBussola", () => () => null);

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
});
