import { entriesToSave, parseQty, sumEntries } from "./countEntries";

const units = [{ unit: "Unidade", factor: 1, source: "stock" }, { unit: "Grade 24", factor: 24, source: "confirmado" }];

describe("contagem: soma de várias unidades", () => {
    it("soma 2 Grades 24 + 5 Unidades na unidade de stock", () => {
        expect(sumEntries([{ qty: "2", unit: "Grade 24" }, { qty: "5", unit: "Unidade" }], units)).toBe(53);
    });
    it("aceita vírgula decimal", () => {
        expect(parseQty("1,5")).toBe(1.5);
        expect(sumEntries([{ qty: "1,5", unit: "Unidade" }], units)).toBe(1.5);
    });
    it("vazio é não contado (null) e 0 é zero contado", () => {
        expect(sumEntries([{ qty: "", unit: "Unidade" }], units)).toBeNull();
        expect(sumEntries([], units)).toBeNull();
        expect(sumEntries([{ qty: "0", unit: "Unidade" }], units)).toBe(0);
    });
    it("uma unidade sem fator confirmado ou um número inválido não somam", () => {
        expect(sumEntries([{ qty: "2", unit: "Caixa 6" }], units)).toBeNaN();
        expect(sumEntries([{ qty: "-1", unit: "Unidade" }], units)).toBeNaN();
    });
    it("grava só as entradas preenchidas", () => {
        expect(entriesToSave([{ qty: "2", unit: "Grade 24" }, { qty: "", unit: "Unidade" }])).toEqual([{ qty: "2", unit: "Grade 24" }]);
    });
});
