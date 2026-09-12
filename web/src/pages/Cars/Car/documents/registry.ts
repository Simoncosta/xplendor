import { SaleDocumentDef } from "./types";
import { tvdeAuthorization } from "./tvdeAuthorization";
import { moneyLaunderingDeclaration } from "./moneyLaunderingDeclaration";

/**
 * DMS Fase 3 — registo dos documentos de venda disponíveis.
 * Adicionar um documento novo = importar aqui a sua definição. Nada mais.
 * Próximos: contrato de compra e venda, DUA, garantias, RGPD…
 */
export const SALE_DOCUMENTS: SaleDocumentDef[] = [
    tvdeAuthorization,
    moneyLaunderingDeclaration,
];

export function getSaleDocument(id: string): SaleDocumentDef | null {
    return SALE_DOCUMENTS.find((d) => d.id === id) ?? null;
}
