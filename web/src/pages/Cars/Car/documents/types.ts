import React from "react";
import { SaleDocumentData } from "types/api";

/**
 * DMS Fase 3 — MOLDE dos documentos de venda.
 *
 * Cada documento é UM ficheiro que define: título + lista de campos + corpo.
 * NÃO reconstrói o mecanismo de impressão (esse é partilhado pelo
 * SaleDocumentPrint). Adicionar um 2.º documento = criar um SaleDocumentDef e
 * registá-lo em registry.ts. Nada mais.
 */

// De onde vem o valor de um campo:
//  - "entity": vem das entidades (cliente/viatura/empresa/venda) → pré-preenchido.
//  - "manual": preenchido na hora (raro) → vazio no modal, NÃO guardado.
export type DocFieldSource = "entity" | "manual";

// Tipos de input no modal (aditivo — default "text", TVDE não precisa de mexer).
export type DocFieldType = "text" | "textarea" | "select" | "boolean";
export interface DocFieldOption { value: string; label: string; }

export interface DocFieldDef {
    key: string;                                   // chave do placeholder (ex.: "cliente_nome")
    label: string;                                 // rótulo no modal
    source: DocFieldSource;
    resolve?: (d: SaleDocumentData) => string;     // valor inicial para source="entity"
    /** Valor inicial para campos MANUAIS que devem pré-preencher de uma entidade
     *  (ex.: residência fiscal a partir da morada do cliente) mas ficam editáveis. */
    default?: (d: SaleDocumentData) => string;
    multiline?: boolean;                           // (retro-compat) textarea no modal
    type?: DocFieldType;                            // default: multiline ? "textarea" : "text"
    options?: DocFieldOption[];                     // para type="select"
    /** Visibilidade condicional no modal (ex.: "cargo" só se PEP="Sim"). */
    visibleIf?: (v: Record<string, string>) => boolean;
}

export interface SaleDocumentDef {
    id: string;                                    // ex.: "tvde_authorization"
    title: string;                                 // nome no menu (ex.: "Autorização TVDE")
    description?: string;
    fields: DocFieldDef[];
    /** Corpo do documento — recebe o mapa de valores (chave→texto) já resolvido/editado. */
    renderBody: (v: Record<string, string>) => React.ReactNode;
}

/** Valores iniciais: entity → resolve(data); manual → default(data) se existir, senão "". */
export function buildInitialValues(def: SaleDocumentDef, data: SaleDocumentData): Record<string, string> {
    const values: Record<string, string> = {};
    for (const f of def.fields) {
        if (f.source === "entity" && f.resolve) {
            values[f.key] = f.resolve(data) ?? "";
        } else if (f.default) {
            values[f.key] = f.default(data) ?? "";
        } else {
            values[f.key] = "";
        }
    }
    return values;
}
