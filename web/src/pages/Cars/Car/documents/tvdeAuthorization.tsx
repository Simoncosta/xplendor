import React from "react";
import { SaleDocumentData } from "types/api";
import { SaleDocumentDef } from "./types";

// Data por defeito: data da venda se existir, senão hoje (editável no modal).
const defaultDate = (d: SaleDocumentData): string =>
    d.sale?.sold_at ?? new Date().toISOString().slice(0, 10);

const mesAno = (d: SaleDocumentData): string => {
    const m = d.car?.registration_month;
    const y = d.car?.registration_year;
    if (m && y) return `${m}/${y}`;
    if (y) return String(y);
    return "";
};

/**
 * DMS Fase 3 — 1.º documento: Autorização TVDE.
 *
 * ⚠️ TEXTO LEGAL — reproduzido EXATAMENTE como fornecido pelo Simon. NÃO
 * reescrever/corrigir (a correcção jurídica é da Matilde/jurista). Os
 * placeholders {v.xxx} são preenchidos a partir das entidades (ou na hora).
 */
export const tvdeAuthorization: SaleDocumentDef = {
    id: "tvde_authorization",
    title: "Autorização TVDE",
    description: "Autorização para inscrição de veículo em plataforma eletrónica TVDE.",
    fields: [
        // Entidades (pré-preenchidos).
        { key: "empresa_nome", label: "Empresa (Proprietário do veículo)", source: "entity", resolve: (d) => d.company?.fiscal_name ?? "" },
        { key: "viatura_marca", label: "Marca", source: "entity", resolve: (d) => d.car?.brand ?? "" },
        { key: "viatura_matricula", label: "Matrícula", source: "entity", resolve: (d) => d.car?.license_plate ?? "" },
        { key: "viatura_mes_ano", label: "Mês/Ano de matrícula", source: "entity", resolve: mesAno },
        { key: "cliente_nome", label: "Operador/Motorista (cliente)", source: "entity", resolve: (d) => d.customer?.name ?? "" },
        { key: "cliente_morada", label: "Morada do cliente", source: "entity", resolve: (d) => d.customer?.address ?? "" },
        { key: "cliente_cp", label: "Código postal", source: "entity", resolve: (d) => d.customer?.postal_code ?? "" },
        { key: "cliente_localidade", label: "Localidade", source: "entity", resolve: (d) => d.customer?.locality ?? "" },
        { key: "cliente_nif", label: "Contribuinte (NIF)", source: "entity", resolve: (d) => d.customer?.nif ?? "" },
        { key: "cliente_email", label: "Email", source: "entity", resolve: (d) => d.customer?.email ?? "" },
        { key: "empresa_localidade", label: "Localidade da empresa", source: "entity", resolve: (d) => d.company?.locality ?? d.company?.address ?? "" },
        { key: "data", label: "Data", source: "entity", resolve: defaultDate },
        // Preenchidos na hora (raros, vazios, não guardados).
        { key: "tvde_licenca", label: "Nº de licença TVDE do operador", source: "manual" },
        { key: "operador_domicilio", label: "Domicílio do operador (se diferente)", source: "manual" },
        { key: "operador_doc_id", label: "Nº do documento de identificação", source: "manual" },
        { key: "certificado_motorista", label: "Nº de certificado de motorista TVDE", source: "manual" },
        { key: "carta_conducao", label: "Nº da carta de condução", source: "manual" },
        { key: "carta_validade", label: "Validade da carta de condução", source: "manual" },
    ],
    renderBody: (v) => (
        <>
            <h2 className="doc-title">AUTORIZAÇÃO PARA INSCRIÇÃO DE VEÍCULO EM PLATAFORMA ELETRÓNICA TVDE</h2>

            <p className="doc-p">
                {v.empresa_nome}, na qualidade de único proprietário do veículo (o “Proprietário do Veículo”) com:
            </p>

            <div className="doc-vehicle">
                <span>Marca: {v.viatura_marca}</span>
                <span>Matrícula: {v.viatura_matricula}</span>
                <span>Mês/Ano: {v.viatura_mes_ano}</span>
            </div>

            <p className="doc-p">
                Pela presente declaração autorizamos a entidade: {v.cliente_nome}, da morada {v.cliente_morada}, {v.cliente_cp} {v.cliente_localidade}, Contribuinte nº {v.cliente_nif}, Operadora TVDE registada e devidamente licenciada junto do Instituto da Mobilidade e dos Transportes, I.P. com o n.º de licença {v.tvde_licenca} (“Operador TVDE”), a proceder à inscrição e registo do Veículo nas plataformas eletrónicas (“TVDE”), nos termos e para os efeitos do artigo 12.º n.º 1 da Lei n.º 45/2018, de 10 de agosto (“Lei TVDE”).
            </p>

            <p className="doc-p">
                , com domicílio em {v.operador_domicilio}, portador (a) do documento de identificação {v.operador_doc_id} com o endereço de e-mail {v.cliente_email}, motorista de transporte em veículo descaracterizado a partir de plataforma eletrónica (“TVDE”), registado e devidamente licenciado junto do Instituto da Mobilidade e dos Transportes, I.P. com o certificado de motorista n.º {v.certificado_motorista} e carta de condução nº {v.carta_conducao} (“Motorista”) com validade {v.carta_validade}, declara que tomou conhecimento da presente declaração. .
            </p>

            <p className="doc-p">
                Sem prejuízo da obrigação de o Operador TVDE de assegurar o pleno e permanente cumprimento dos requisitos de exercício da atividade previstos na Lei TVDE, incluindo os respeitantes a veículos e motoristas afetos à prestação de serviços TVDE, o Motorista, pela presente declaração, declara e garante que, juntamente com o Veículo, cumpre e cumprirá durante o período da presente declaração com todos os requisitos legais e regulamentares aplicáveis, especialmente os previstos na Lei TVDE, nomeadamente que (i) se trata de um Veículo automóvel ligeiro de passageiros com matrícula nacional, (ii) com lotação não superior a nove lugares, incluindo o do motorista, (iii) que o Veículo possui idade inferior a sete anos a contar da data da primeira matrícula, (iv) que o Veículo foi e/ou será apresentado à inspeção técnica um ano após a data da primeira matrícula e, em seguida, anualmente, (v) que o Veículo possui seguro de responsabilidade civil e acidentes pessoais, incluindo os passageiros transportados e respetivos prejuízos, em valor não inferior ao mínimo legalmente exigido para a atividade de transporte de aluguer em veículos automóveis ligeiros de passageiros, (vi) que o Veículo circula sem qualquer sinal exterior indicativo do tipo de serviço que presta, com exceção do dísco TVDE, visível do exterior e amovível, (vii) não tendo o Veículo qualquer publicidade, seja no seu exterior ou interior. Em caso de necessidade de substituição do Veículo ou verificada uma alteração às informações constantes da presente declaração, o Motorista desde já se obriga a regularizar a reserva situação e/ou, caso se afigure necessário, a submeter uma nova declaração em conformidade
            </p>

            <div className="doc-signatures">
                <span className="doc-sign-date">{v.empresa_localidade}, {v.data}</span>
                <span className="doc-sign-line">Pelo Proprietario: ___________________________________________</span>
                <span className="doc-sign-line">Pelo Motorista: ___________________________________________</span>
            </div>

            <div className="doc-footnotes">
                <div>*Custo chamada para a rede fixa nacional</div>
                <div>**Custo chamada para a rede móvel nacional</div>
            </div>
        </>
    ),
};
