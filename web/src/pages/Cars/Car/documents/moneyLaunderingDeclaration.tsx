import React from "react";
import { SaleDocumentData } from "types/api";
import { SaleDocumentDef } from "./types";

const defaultDate = (d: SaleDocumentData): string =>
    d.sale?.sold_at ?? new Date().toISOString().slice(0, 10);

// Legenda repetida no topo do documento e no topo das definições legais.
const Legend = () => (
    <div className="doc-legend">
        <div className="fw-bold">Questionário — BCFT</div>
        <div>PEP - pessoas politicamente expostas</div>
        <div>PTRE - países terceiros de risco elevado</div>
        <div>TOCPP - Titulares de Outros Cargos Políticos ou Públicos</div>
    </div>
);

/**
 * DMS Fase 3 — 2.º documento: Declaração de Branqueamento de Capitais (BCFT).
 *
 * ⚠️ TEXTO LEGAL (Lei n.º 58/2020) — reproduzido EXATAMENTE como fornecido pelo
 * Simon. NÃO reescrever/corrigir (correcção jurídica é da Matilde/jurista). Os
 * {v.xxx} são preenchidos das entidades ou na hora. Cabeçalho (logo + empresa)
 * vem do molde partilhado.
 */
export const moneyLaunderingDeclaration: SaleDocumentDef = {
    id: "money_laundering_declaration",
    title: "Declaração de Branqueamento de Capitais",
    description: "Questionário BCFT (Lei n.º 58/2020) — PEP, residência e beneficiário efetivo.",
    fields: [
        // Entidades (pré-preenchidos).
        { key: "cliente_nome", label: "Nome", source: "entity", resolve: (d) => d.customer?.name ?? "" },
        { key: "cliente_morada", label: "Morada (residência permanente)", source: "entity", resolve: (d) => d.customer?.address ?? "" },
        { key: "cliente_cp", label: "Código postal", source: "entity", resolve: (d) => d.customer?.postal_code ?? "" },
        { key: "cliente_localidade", label: "Localidade", source: "entity", resolve: (d) => d.customer?.locality ?? "" },
        { key: "cc_numero", label: "Nº Cartão de Cidadão", source: "entity", resolve: (d) => d.customer?.citizen_card_number ?? "" },
        { key: "cc_validade", label: "Validade do CC", source: "entity", resolve: (d) => d.customer?.citizen_card_validity ?? "" },
        { key: "nacionalidade", label: "Nacionalidade", source: "entity", resolve: (d) => d.customer?.nationality ?? "" },
        { key: "nif", label: "NIF", source: "entity", resolve: (d) => d.customer?.nif ?? "" },
        { key: "profissao", label: "Profissão", source: "entity", resolve: (d) => d.customer?.profession ?? "" },
        { key: "data", label: "Data", source: "entity", resolve: defaultDate },

        // Preenchidos na hora.
        { key: "cliente_pais", label: "País (residência permanente)", source: "manual" },
        { key: "doc_tipo", label: "Tipo de documento", source: "manual", type: "select", options: [
            { value: "Cartão de cidadão", label: "Cartão de cidadão" },
            { value: "Passaporte", label: "Passaporte" },
        ] },

        // Residência fiscal — toggle "mesma morada" (default ligado); quando
        // desligado, os campos aparecem PRÉ-PREENCHIDOS com a morada do cliente.
        { key: "fiscal_same", label: "Residência fiscal é a mesma do cliente", source: "manual", type: "boolean", default: () => "1" },
        { key: "fiscal_morada", label: "Morada (residência fiscal)", source: "manual", default: (d) => d.customer?.address ?? "", visibleIf: (v) => v.fiscal_same !== "1" },
        { key: "fiscal_cp", label: "Código postal (fiscal)", source: "manual", default: (d) => d.customer?.postal_code ?? "", visibleIf: (v) => v.fiscal_same !== "1" },
        { key: "fiscal_localidade", label: "Localidade (fiscal)", source: "manual", default: (d) => d.customer?.locality ?? "", visibleIf: (v) => v.fiscal_same !== "1" },
        { key: "fiscal_pais", label: "País (fiscal)", source: "manual", visibleIf: (v) => v.fiscal_same !== "1" },

        // Questionário PEP (sensível — na hora, não guardado).
        { key: "pep", label: "É PEP?", source: "manual", type: "select", default: () => "Não", options: [
            { value: "Sim", label: "Sim" }, { value: "Não", label: "Não" },
        ] },
        { key: "pep_cargo", label: "Se sim, cargo (PEP)", source: "manual", visibleIf: (v) => v.pep === "Sim" },
        { key: "cargos_politicos", label: "Tem/teve cargos políticos (últimos 12 meses)?", source: "manual", type: "select", default: () => "Não", options: [
            { value: "Sim", label: "Sim" }, { value: "Não", label: "Não" },
        ] },
        { key: "cargos_cargo", label: "Se sim, cargo (políticos)", source: "manual", visibleIf: (v) => v.cargos_politicos === "Sim" },
        { key: "observacoes", label: "Observações / detalhes", source: "manual", type: "textarea" },
    ],
    renderBody: (v) => {
        const fiscalSame = v.fiscal_same === "1";
        return (
            <>
                <Legend />

                <div className="doc-p"><strong>Nome:</strong> {v.cliente_nome}</div>

                <h3 className="doc-section-title">Residência permanente</h3>
                <div className="doc-row">Morada: {v.cliente_morada}</div>
                <div className="doc-row">Código Postal: {v.cliente_cp}</div>
                <div className="doc-row">Localidade: {v.cliente_localidade}</div>
                <div className="doc-row">País: {v.cliente_pais}</div>

                <h3 className="doc-section-title">Residência fiscal</h3>
                {fiscalSame ? (
                    <div className="doc-row">Morada: (MESMA)</div>
                ) : (
                    <>
                        <div className="doc-row">Morada: {v.fiscal_morada}</div>
                        <div className="doc-row">Código Postal: {v.fiscal_cp}</div>
                        <div className="doc-row">Localidade: {v.fiscal_localidade}</div>
                        <div className="doc-row">País: {v.fiscal_pais}</div>
                    </>
                )}

                <h3 className="doc-section-title">Identificação</h3>
                <div className="doc-row">Tipo de documento: {v.doc_tipo}</div>
                <div className="doc-row">Data de validade: {v.cc_validade}</div>
                <div className="doc-row">Nacionalidade: {v.nacionalidade}</div>
                <div className="doc-row">Nº: {v.cc_numero}</div>
                <div className="doc-row">NIF: {v.nif}</div>
                <div className="doc-row">Profissão: {v.profissao}</div>

                <h3 className="doc-section-title">PEP - Pessoa Politicamente Exposta</h3>
                <div className="doc-row">É PEP? {v.pep}</div>
                <div className="doc-row">Se sim, cargo: {v.pep_cargo}</div>

                <h3 className="doc-section-title">Cargos políticos (últimos 12 meses)</h3>
                <div className="doc-row">Tem/teve cargos políticos? {v.cargos_politicos}</div>
                <div className="doc-row">Se sim, cargo: {v.cargos_cargo}</div>

                <p className="doc-p" style={{ marginTop: 14 }}>
                    Eu, {v.cliente_nome}, portador do Cartão de Cidadão n.º {v.cc_numero} e NIF {v.nif}, residente em {v.cliente_morada}, {v.cliente_cp} {v.cliente_localidade}, declaro que:
                </p>
                <p className="doc-p">1) Sou pessoa politicamente exposta (PEP)? {v.pep}.</p>
                <p className="doc-p">2) Exerço ou exerci cargos políticos ativos? {v.cargos_politicos}.</p>
                <p className="doc-p">3) Observações / detalhes (por exemplo, cargo, entidade, relação): {v.observacoes}.</p>
                <p className="doc-p">Declaro que as informações acima são verdadeiras para efeitos de prevenção e combate ao branqueamento de capitais.</p>

                <div className="doc-signatures">
                    <span className="doc-sign-date">Data: {v.data}</span>
                    <span className="doc-sign-line">(Assinatura do cliente)</span>
                </div>

                <h3 className="doc-section-title">Documentação Particular:</h3>
                <ul className="doc-list">
                    <li>Cópia Cartão de Cidadão ou passaporte</li>
                    <li>Comprovativo de morada com menos 3 meses</li>
                </ul>

                <h3 className="doc-section-title">Documentação Empresa:</h3>
                <ul className="doc-list">
                    <li>Código Certidão Permanente Atualizada</li>
                    <li>Código RCBE</li>
                    <li>Cópia Cartão Cidadão Beneficiários efetivos</li>
                </ul>

                {/* Definições legais — nova página. */}
                <div className="doc-pagebreak" />
                <Legend />
                <p className="doc-p">
                    Nos termos da Lei n.º 58/2020 de 31 de agosto, devem considerar-se, para o preenchimento desta declaração, o Artigo 2º, n.º 1 e o Artigo 30º.
                </p>
                <p className="doc-p">
                    <strong>Beneficiário Efetivo:</strong> as pessoas singulares que detêm a titularidade ou o controlo, direto ou indireto de uma percentagem suficiente de unidades de participação ou de titularização (tratando-se de organismos de investimento coletivo), ou detêm a propriedade ou controlo, direto ou indireto, de uma percentagem de ações ou de direitos de voto ou de participação no capital, (tratando-se de sociedade comercial). Consideram-se beneficiários efetivos, as pessoas singulares que detêm direta ou indireta, o controlo das mesmas, dos quais apenas se dispõem dos meios possíveis de identificação dos beneficiários efetivos ou que, apesar de não existir qualquer propriedade, controle ou ainda de detenção de direitos de natureza política ou pública.
                </p>
                <p className="doc-p">
                    <strong>Pessoas politicamente expostas:</strong> as pessoas singulares que desempenham, ou desempenharam nos últimos 12 meses, cargos políticos de nível superior, designadamente Chefes de Estado ou de Governo, Ministros, Secretários de Estado, Deputados, Membros de Tribunais Superiores, Membros do órgão do Governo.
                </p>
                <p className="doc-p">
                    <strong>Pessoas reconhecidas como estreitamente associadas:</strong> que detenham conjuntamente com uma pessoa politicamente exposta, capital social numa pessoa coletiva ou qualquer pessoa singular conhecida por ter relações societárias com pessoa politicamente exposta.
                </p>
                <p className="doc-p">
                    <strong>Membros próximos da família:</strong> são os parentes e afins de pessoa politicamente exposta, designadamente cônjuge ou unido de facto, descendentes ou ascendentes e respetivos cônjuges ou unidos de facto.
                </p>
                <p className="doc-p">
                    <strong>Titulares de outros cargos políticos ou públicos:</strong> as pessoas singulares que, não sendo qualificadas como pessoas politicamente expostas, desempenham ou tenham desempenhado, nos últimos 12 meses e em território nacional cargos de natureza política ou pública.
                </p>
            </>
        );
    },
};
