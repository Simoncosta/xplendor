// DMS Fase 2A — rótulo HONESTO da margem, condicional a companies.uses_vat.
//
// Fazemos margem SIMPLES (venda − compra − despesas), SEM cálculo de IVA:
//  - Empresa SEM IVA → a margem simples É a margem real → "Lucro" (sem ressalvas).
//  - Empresa COM IVA → é lucro BRUTO, falta o IVA → "Margem bruta (sem IVA)" +
//    aviso "Cálculo com IVA por incluir". NUNCA chamar "lucro real" a este número.
//
// Mesmo princípio do "Faturação vs Lucro". O mesmo ecrã diz coisas diferentes
// conforme uses_vat.
export interface MarginLabels {
    title: string;
    /** Aviso discreto quando o IVA ainda não está no cálculo (null se não aplicável). */
    warning: string | null;
}

export function marginLabels(usesVat: boolean): MarginLabels {
    return usesVat
        ? { title: "Margem bruta (sem IVA)", warning: "Cálculo com IVA por incluir." }
        : { title: "Lucro", warning: null };
}

/** Motivo de margem não-calculável → texto pt-PT discreto (honestidade). */
export function marginNotCalculableText(reason: string | null): string {
    switch (reason) {
        case "no_purchase_price":
            return "Custo de compra não registado — margem não calculável.";
        case "no_sale_price":
            return "Preço de venda não registado — margem não calculável.";
        case "not_sold":
            return "Viatura ainda não vendida.";
        default:
            return "Margem não calculável.";
    }
}
