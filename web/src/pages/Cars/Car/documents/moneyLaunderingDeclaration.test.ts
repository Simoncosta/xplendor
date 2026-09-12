import { SaleDocumentData } from "types/api";
import { buildInitialValues } from "./types";
import { moneyLaunderingDeclaration } from "./moneyLaunderingDeclaration";

/**
 * DMS Fase 3 — 2.º documento (BCFT). Cobre a resolução dos campos das entidades
 * e o comportamento "usar a mesma morada" (fiscal_same + defaults + visibleIf).
 */
const data = {
    company: null,
    car: null,
    sale: { sold_at: "2026-08-01" },
    customer: {
        name: "Carlos de Faveri Quintão",
        nif: "304879681",
        phone: null,
        email: null,
        address: "Rua Alberto Sampaio",
        postal_code: "4760-292",
        locality: "Vila Nova de Famalicão",
        citizen_card_number: "12345678",
        citizen_card_validity: "2030-01-01",
        birth_date: null,
        nationality: "Portuguesa",
        profession: "Motorista",
        marital_status: null,
    },
} as unknown as SaleDocumentData;

const initial = () => buildInitialValues(moneyLaunderingDeclaration, data);

describe("moneyLaunderingDeclaration — resolução de campos das entidades", () => {
    it("pré-preenche os campos do cliente a partir das entidades", () => {
        const v = initial();
        expect(v.cliente_nome).toBe("Carlos de Faveri Quintão");
        expect(v.cliente_morada).toBe("Rua Alberto Sampaio");
        expect(v.cliente_cp).toBe("4760-292");
        expect(v.cliente_localidade).toBe("Vila Nova de Famalicão");
        expect(v.cc_numero).toBe("12345678");
        expect(v.nacionalidade).toBe("Portuguesa");
        expect(v.nif).toBe("304879681");
        expect(v.profissao).toBe("Motorista");
    });

    it("usa a data da venda quando existe", () => {
        expect(initial().data).toBe("2026-08-01");
    });

    it("campos manuais sem default arrancam vazios", () => {
        const v = initial();
        expect(v.cliente_pais).toBe("");
        expect(v.doc_tipo).toBe("");
    });
});

describe("moneyLaunderingDeclaration — 'usar a mesma morada' (residência fiscal)", () => {
    it("fiscal_same arranca ligado (default '1')", () => {
        expect(initial().fiscal_same).toBe("1");
    });

    it("os campos da residência fiscal pré-preenchem com a morada do cliente", () => {
        const v = initial();
        expect(v.fiscal_morada).toBe("Rua Alberto Sampaio");
        expect(v.fiscal_cp).toBe("4760-292");
        expect(v.fiscal_localidade).toBe("Vila Nova de Famalicão");
    });

    const field = (key: string) => moneyLaunderingDeclaration.fields.find((f) => f.key === key)!;

    it("esconde os campos fiscais quando fiscal_same = '1'", () => {
        const v = { fiscal_same: "1" };
        expect(field("fiscal_morada").visibleIf!(v)).toBe(false);
        expect(field("fiscal_cp").visibleIf!(v)).toBe(false);
        expect(field("fiscal_localidade").visibleIf!(v)).toBe(false);
        expect(field("fiscal_pais").visibleIf!(v)).toBe(false);
    });

    it("mostra os campos fiscais quando fiscal_same != '1'", () => {
        const v = { fiscal_same: "0" };
        expect(field("fiscal_morada").visibleIf!(v)).toBe(true);
        expect(field("fiscal_pais").visibleIf!(v)).toBe(true);
    });

    it("o corpo imprime '(MESMA)' quando fiscal_same está ligado", () => {
        const html = JSON.stringify(moneyLaunderingDeclaration.renderBody({ fiscal_same: "1" }));
        expect(html).toContain("(MESMA)");
    });
});

describe("moneyLaunderingDeclaration — campos condicionais PEP", () => {
    const field = (key: string) => moneyLaunderingDeclaration.fields.find((f) => f.key === key)!;

    it("pep e cargos_politicos arrancam em 'Não'", () => {
        const v = initial();
        expect(v.pep).toBe("Não");
        expect(v.cargos_politicos).toBe("Não");
    });

    it("cargo do PEP só aparece quando pep = 'Sim'", () => {
        expect(field("pep_cargo").visibleIf!({ pep: "Não" })).toBe(false);
        expect(field("pep_cargo").visibleIf!({ pep: "Sim" })).toBe(true);
    });

    it("cargo político só aparece quando cargos_politicos = 'Sim'", () => {
        expect(field("cargos_cargo").visibleIf!({ cargos_politicos: "Não" })).toBe(false);
        expect(field("cargos_cargo").visibleIf!({ cargos_politicos: "Sim" })).toBe(true);
    });
});
