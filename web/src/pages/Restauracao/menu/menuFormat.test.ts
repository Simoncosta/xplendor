import { byClass, costLabel, familiesByRevenue, matrixPoints, missingLabel, niceAxis, topActions } from "./menuFormat";
import { MenuItem } from "common/models/menuEngineering.model";

export const mi = (over: Partial<MenuItem> = {}): MenuItem => ({
    product_id: "P1", code: "227", description: "Francesinha", family: "Comidas › Francesinhas", qty: 100, revenue_eur: 1300, vat_pct: 13, price_eur: 13, cost_eur: 4.54,
    margin_eur: 8.46, margin_total_eur: 846, food_cost_pct: 34.9, share_pct: 40, revenue_share_pct: 10, confidence: "confirmado", class: "estrela", popular: true,
    high_margin: true, fragile: false, excluded: null, excluded_reason: null, pareto: 1, play: "Manter a receita.", changed: false, missing: [], fragile_reasons: [], ...over,
});

describe("menuFormat", () => {
    it("o custo Parcial é um mínimo e o Sem custo não tem valor", () => {
        expect(costLabel(mi())).toBe("4,54 €");
        expect(costLabel(mi({ confidence: "parcial", cost_eur: 0 }))).toBe("pelo menos 0,00 €");
        expect(costLabel(mi({ confidence: "sem_custo", cost_eur: null }))).toBe("—");
        expect(missingLabel(mi({ missing: [{ component_id: "100366", code: "100366", description: "COCA-COLA ZERO GARRAFA", reason: "sem_compras" }] })))
            .toBe("COCA-COLA ZERO GARRAFA (sem compras)");
    });

    it("separa os pontos da matriz dos sem custo e ignora os excluídos", () => {
        const items = [mi(), mi({ product_id: "P2", class: null, margin_eur: null, confidence: "sem_custo" }), mi({ product_id: "P3", excluded: "preco_zero" }),
            mi({ product_id: "P4", family: "Bebidas › Sumos", revenue_eur: 5000 })];
        const { costed, noCost } = matrixPoints(items, "Comidas › Francesinhas");
        expect(costed.map((i) => i.product_id)).toEqual(["P1"]);
        expect(noCost.map((i) => i.product_id)).toEqual(["P2"]);
        expect(familiesByRevenue(items)).toEqual(["Bebidas › Sumos", "Comidas › Francesinhas"]);
        const g = byClass(items);
        expect(g.estrela.map((i) => i.product_id)).toEqual(["P4", "P1"]);
        expect(g.sem_custo.map((i) => i.product_id)).toEqual(["P2"]);
    });

    it("eixos com valores redondos", () => {
        expect(niceAxis(0, 54).ticks).toEqual([0, 10, 20, 30, 40, 50, 60]);
        expect(niceAxis(0, 9.3).ticks).toEqual([0, 2, 4, 6, 8, 10]);
        expect(niceAxis(-1.2, 8.7).min).toBe(-2);
    });

    it("as 2 ações com mais receita e até onde levam o custo confirmado", () => {
        const w = (id: string, share: number, actions: [string, string, string][]) => ({ product_id: id, description: id, family: "F", revenue_eur: share * 100, revenue_share_pct: share,
            actions: actions.map(([type, product_id, text]) => ({ type, product_id, label: "", text })) });
        const r = topActions([
            w("FRAN", 35, [["ver_ficha", "FRAN_SEMI", "Francesinha Tradicional: rendimento por confirmar."]]),
            w("MEIA", 10, [["ver_ficha", "MOLHO", "MOLHO FRANCESINHA 30LT: rendimento por confirmar."]]),
            w("FRAN2", 9, [["ver_ficha", "MOLHO", "MOLHO FRANCESINHA 30LT: rendimento por confirmar."], ["confirmar_fator", "BARRIL", "BARRIL 50L: compras em «Unidade» sem fator confirmado."]]),
            w("COCA", 2, [["ver_auditoria", "100366", "COCA-COLA ZERO GARRAFA: sem compras."]]),
        ], 15.4);
        expect(r?.phrases).toEqual(["o rendimento de Francesinha Tradicional", "o rendimento de MOLHO FRANCESINHA 30LT"]);
        expect(r?.targetPct).toBeCloseTo(15.4 + 35 + 10, 5);   // FRAN2 ainda precisa do barril
    });
});
