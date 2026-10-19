import { branchAllows } from "./branch";

/** O menu por ramo: só se esconde a quem tem outro ramo explícito. */
test("item da restauração por ramo da empresa", () => {
    expect(branchAllows("restauracao", "restauracao", false)).toBe(true);
    expect(branchAllows("restauracao", "automovel", false)).toBe(false);
    expect(branchAllows("restauracao", null, false)).toBe(true); // sem setor ou setor fora dos ramos: decide o módulo
    expect(branchAllows("restauracao", "automovel", true)).toBe(true); // o root vê tudo
    expect(branchAllows(undefined, "automovel", false)).toBe(true); // item sem ramo
});
