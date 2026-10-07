import type { LegalContent } from "@/components/legal/LegalPage";
import { COMPANY } from "./company";

/** Termos e condições (PT e EN). Os campos entre parênteses retos são para preencher. */

export const termsPt: LegalContent = {
  eyebrow: "Termos",
  title: "Termos e Condições",
  intro: "Condições de utilização deste site e da Plataforma XPLENDOR.",
  updatedLabel: "Última atualização",
  updated: "7 de outubro de 2026",
  tocLabel: "Nesta página",
  alternate: { href: "/en/terms/", label: "Read in English" },
  sections: [
    {
      id: "identificacao",
      title: "1. Identificação",
      blocks: [
        `O site xplendor.tech e a Plataforma XPLENDOR (a \"Plataforma\") são disponibilizados por ${COMPANY.legalName}, NIF ${COMPANY.nif}, com sede em ${COMPANY.address}. Contacto: ${COMPANY.email}.`,
      ],
    },
    {
      id: "objeto",
      title: "2. Objeto",
      blocks: [
        "A Plataforma é um serviço de gestão e análise de marketing para empresas. Permite ao cliente reunir dados do seu negócio e, se assim o decidir, ligar contas de plataformas externas (por exemplo, Meta, Google Analytics ou programas de faturação) para consultar indicadores num só lugar.",
        "Estes termos aplicam-se a quem visita o site e a quem utiliza a Plataforma. As condições comerciais de cada cliente (plano, preço, duração) constam da respetiva proposta ou subscrição.",
      ],
    },
    {
      id: "conta",
      title: "3. Conta e acesso",
      blocks: [
        {
          list: [
            "O cliente é responsável pela veracidade dos dados que introduz e pela confidencialidade das credenciais dos seus utilizadores.",
            "Cada empresa cliente só tem acesso aos seus próprios dados.",
            "As faturas da XPLENDOR (emitidas num programa de faturação certificado) ficam disponíveis na Plataforma e num link pessoal enviado por email. O cliente pode ligar ou desligar os lembretes de cobrança no perfil da empresa (desligados por omissão) e indicar \"Já paguei\", com ou sem comprovativo; o pagamento só fica confirmado quando a XPLENDOR o confirmar.",
            `O cliente deve comunicar de imediato qualquer utilização não autorizada da sua conta para ${COMPANY.email}.`,
          ],
        },
      ],
    },
    {
      id: "integracoes",
      title: "4. Integrações com plataformas externas",
      blocks: [
        "Ao ligar uma conta externa, o cliente declara ter legitimidade para o fazer e autoriza a XPLENDOR a ler os dados descritos na Política de Privacidade, apenas para lhe prestar o serviço.",
        "No caso da Meta, a XPLENDOR apenas lê dados da conta de anúncios escolhida pelo cliente e não faz alterações às campanhas, anúncios ou públicos. O cliente pode desligar a integração a qualquer momento na Plataforma; ao fazê-lo, a autorização da aplicação é retirada na Meta e o cliente escolhe se mantém o histórico ou apaga todos os dados recebidos da Meta.",
        "A utilização das plataformas externas continua sujeita aos termos dessas plataformas. A XPLENDOR não controla a disponibilidade nem a exatidão dos dados que essas plataformas fornecem.",
      ],
    },
    {
      id: "utilizacao",
      title: "5. Utilização aceitável",
      blocks: [
        "O cliente compromete-se a não utilizar a Plataforma para fins ilícitos, a não tentar aceder a dados de outros clientes, a não interferir com o funcionamento do serviço e a não introduzir dados pessoais de terceiros sem fundamento legal para o fazer.",
      ],
    },
    {
      id: "dados",
      title: "6. Dados do cliente",
      blocks: [
        "Os dados introduzidos ou importados pelo cliente pertencem ao cliente. A XPLENDOR trata-os para prestar o serviço, nos termos da Política de Privacidade (/politica-de-privacidade/).",
        "Quando o cliente usa a Plataforma para tratar dados pessoais de terceiros (por exemplo, visitantes do seu site), o cliente é o responsável pelo tratamento e a XPLENDOR atua como subcontratante, por conta e segundo as instruções do cliente.",
        "O cliente pode pedir a exportação ou a eliminação dos seus dados. As instruções de eliminação estão em /eliminacao-de-dados/.",
      ],
    },
    {
      id: "agencias",
      title: "7. Termos para agências",
      blocks: [
        "Esta secção aplica-se às empresas marcadas na Plataforma como agência, quando gerem a conta de empresas clientes, e completa as restantes condições.",
        {
          list: [
            "Autorização do cliente: a agência só pede ou aceita a gestão de uma empresa com a autorização desse cliente, e declara-o na Plataforma em cada pedido. A empresa cliente confirma na Plataforma. A agência responde pela veracidade dessa declaração.",
            "A agência como subcontratante: a agência trata os dados do cliente por conta dele e segundo as suas instruções, como subcontratante nos termos do artigo 28.º do RGPD, e deve ter com o cliente um acordo de tratamento de dados (ou cláusulas equivalentes no contrato de prestação de serviços entre ambos).",
            "Confidencialidade da equipa: a agência garante que as pessoas da sua equipa com acesso às empresas clientes estão obrigadas a confidencialidade, e limita o acesso a quem precisa dele (a Plataforma permite atribuir cada cliente a pessoas escolhidas).",
            "Âmbito do acesso: a agência trabalha nas áreas ativas da empresa cliente, mas não gere os utilizadores nem os acessos do cliente, não aprova conteúdos nem aceita orçamentos em nome dele, não altera nem apaga os dados da empresa e não vê as faturas da XPLENDOR. Os módulos de cada empresa são ligados e desligados só pela XPLENDOR.",
            "O cliente pode terminar a qualquer momento: a empresa cliente pode terminar a relação com a agência na Plataforma, a qualquer momento e sem pré-aviso. O acesso da agência cessa de imediato. A agência também pode terminar a relação, indicando o motivo.",
            "Os dados ficam com o cliente: tudo o que a agência produz ou guarda na conta do cliente (publicações, ficheiros, Perfil da Marca, integrações e histórico) pertence ao cliente e fica na conta dele quando a relação termina. A agência não leva para fora da Plataforma dados do cliente sem a autorização dele.",
            "Faturação: paga quem dá o acesso. Uma empresa cliente com subscrição própria ativa continua a pagá-la. Uma empresa cliente cujo acesso depende da agência conta para a agência, segundo os valores em vigor indicados pela XPLENDOR (atualmente 15 € por empresa e por mês, a que acresce IVA à taxa legal), a partir do mês seguinte ao início da relação.",
            "Acordo de tratamento de dados com a XPLENDOR: no que respeita aos dados dos clientes geridos, a XPLENDOR trata-os apenas para prestar a Plataforma, com as medidas de segurança e os subcontratantes descritos na Política de Privacidade (/politica-de-privacidade/); ajuda a responder aos pedidos dos titulares; comunica sem demora qualquer violação de dados de que tenha conhecimento; e, no fim, mantém os dados na conta do cliente ou elimina-os nos termos da Política de Privacidade. Estas condições constituem o acordo de tratamento de dados entre a XPLENDOR e a agência e o cliente, nos termos do artigo 28.º do RGPD.",
          ],
        },
      ],
    },
    {
      id: "indicadores",
      title: "8. Indicadores e recomendações",
      blocks: [
        "Os indicadores, comparações e recomendações apresentados pela Plataforma são calculados a partir dos dados disponíveis e têm natureza informativa. Não constituem garantia de resultados. As decisões de negócio são da responsabilidade do cliente.",
      ],
    },
    {
      id: "propriedade",
      title: "9. Propriedade intelectual",
      blocks: [
        `O software, o design e os conteúdos da Plataforma e do site pertencem a ${COMPANY.legalName} ou aos respetivos titulares. A subscrição dá ao cliente um direito de utilização, não exclusivo e intransmissível, durante a sua vigência.`,
      ],
    },
    {
      id: "disponibilidade",
      title: "10. Disponibilidade e responsabilidade",
      blocks: [
        "A XPLENDOR procura manter a Plataforma disponível e os dados seguros, mas não garante funcionamento ininterrupto, nomeadamente durante manutenções ou falhas de serviços de terceiros.",
        "Na medida permitida por lei, a XPLENDOR não responde por danos indiretos ou lucros cessantes. Nada nestes termos limita direitos que a lei atribui aos consumidores ou responsabilidade que não possa ser legalmente excluída.",
      ],
    },
    {
      id: "cessacao",
      title: "11. Cessação",
      blocks: [
        "O cliente pode cancelar a subscrição nos termos do seu plano. Com o encerramento da conta, os dados da empresa são eliminados, salvo os que a lei obrigue a conservar. A XPLENDOR pode suspender o acesso em caso de incumprimento grave destes termos, após aviso ao cliente sempre que possível.",
      ],
    },
    {
      id: "alteracoes",
      title: "12. Alterações",
      blocks: [
        "Estes termos podem ser atualizados. A data da última atualização está no topo da página e as alterações relevantes são comunicadas aos clientes com antecedência razoável.",
      ],
    },
    {
      id: "lei",
      title: "13. Lei aplicável e litígios",
      blocks: [
        "Estes termos regem-se pela lei portuguesa. Para a resolução de litígios é competente o foro da comarca de [COMARCA], sem prejuízo das regras imperativas aplicáveis.",
        "Os consumidores podem recorrer a uma entidade de resolução alternativa de litígios de consumo. Mais informação em www.consumidor.gov.pt.",
      ],
    },
  ],
};

export const termsEn: LegalContent = {
  eyebrow: "Terms",
  title: "Terms and Conditions",
  intro: "Terms of use of this website and of the XPLENDOR Platform.",
  updatedLabel: "Last updated",
  updated: "7 October 2026",
  tocLabel: "On this page",
  alternate: { href: "/termos-e-condicoes/", label: "Ler em português" },
  sections: [
    {
      id: "identification",
      title: "1. Identification",
      blocks: [
        `The xplendor.tech website and the XPLENDOR Platform (the \"Platform\") are provided by ${COMPANY.legalName}, tax number (NIF) ${COMPANY.nif}, registered at ${COMPANY.address}. Contact: ${COMPANY.email}.`,
      ],
    },
    {
      id: "purpose",
      title: "2. Purpose",
      blocks: [
        "The Platform is a business management and marketing analytics service for companies. It lets the customer bring together data about their business and, if they choose, connect accounts on external platforms (for example Meta, Google Analytics or invoicing software) to view indicators in one place.",
        "These terms apply to visitors of the website and to users of the Platform. Each customer's commercial terms (plan, price, duration) are set out in the respective proposal or subscription.",
      ],
    },
    {
      id: "account",
      title: "3. Account and access",
      blocks: [
        {
          list: [
            "The customer is responsible for the accuracy of the data it enters and for keeping its users' credentials confidential.",
            "Each customer company can access only its own data.",
            "XPLENDOR invoices (issued with certified invoicing software) are available on the Platform and through a personal link sent by email. The customer can turn payment reminders on or off in the company profile (off by default) and indicate \"I have paid\", with or without proof of payment; the payment is only confirmed once XPLENDOR confirms it.",
            `The customer must report any unauthorised use of its account to ${COMPANY.email} without delay.`,
          ],
        },
      ],
    },
    {
      id: "integrations",
      title: "4. Integrations with external platforms",
      blocks: [
        "By connecting an external account, the customer confirms it is entitled to do so and authorises XPLENDOR to read the data described in the Privacy Policy, solely to provide the service.",
        "For Meta, XPLENDOR only reads data from the ad account chosen by the customer and makes no changes to campaigns, ads or audiences. The customer can disconnect the integration at any time in the Platform; when doing so, the app authorisation is removed on Meta and the customer chooses whether to keep the history or delete all data received from Meta.",
        "Use of external platforms remains subject to those platforms' own terms. XPLENDOR does not control the availability or accuracy of the data those platforms provide.",
      ],
    },
    {
      id: "acceptable-use",
      title: "5. Acceptable use",
      blocks: [
        "The customer agrees not to use the Platform for unlawful purposes, not to attempt to access other customers' data, not to interfere with the operation of the service and not to enter third party personal data without a legal basis to do so.",
      ],
    },
    {
      id: "customer-data",
      title: "6. Customer data",
      blocks: [
        "Data entered or imported by the customer belongs to the customer. XPLENDOR processes it to provide the service, as described in the Privacy Policy (/en/privacy-policy/).",
        "When the customer uses the Platform to process third party personal data (for example visitors of its website), the customer is the controller and XPLENDOR acts as a processor, on the customer's behalf and according to its instructions.",
        "The customer may request export or deletion of its data. Deletion instructions are at /en/data-deletion/.",
      ],
    },
    {
      id: "agencies",
      title: "7. Terms for agencies",
      blocks: [
        "This section applies to companies marked as an agency on the Platform when they manage the accounts of customer companies, and supplements the other conditions.",
        {
          list: [
            "Customer authorisation: the agency only requests or accepts the management of a company with that customer's authorisation, and declares it on the Platform with each request. The customer company confirms on the Platform. The agency is responsible for the accuracy of that declaration.",
            "The agency as a processor: the agency processes the customer's data on the customer's behalf and following its instructions, as a processor under Article 28 of the GDPR, and must have a data processing agreement with the customer (or equivalent clauses in the service agreement between them).",
            "Team confidentiality: the agency ensures that the members of its team with access to customer companies are bound by confidentiality, and limits access to those who need it (the Platform lets each customer be assigned to chosen people).",
            "Scope of access: the agency works in the customer company's active areas, but does not manage the customer's users or access, does not approve content or accept quotes on the customer's behalf, does not change or delete the company's data and does not see XPLENDOR invoices. Each company's modules are switched on and off only by XPLENDOR.",
            "The customer can end it at any time: the customer company can end the relationship with the agency on the Platform, at any time and without notice. The agency's access ends immediately. The agency can also end the relationship, stating the reason.",
            "The data stays with the customer: everything the agency produces or stores in the customer's account (posts, files, Brand Profile, integrations and history) belongs to the customer and stays in the customer's account when the relationship ends. The agency does not take customer data outside the Platform without the customer's authorisation.",
            "Billing: whoever provides the access pays. A customer company with its own active subscription keeps paying it. A customer company whose access depends on the agency counts towards the agency, at the rates in force set by XPLENDOR (currently €15 per company per month, plus VAT at the legal rate), from the month after the relationship starts.",
            "Data processing agreement with XPLENDOR: regarding the data of managed customers, XPLENDOR processes it only to provide the Platform, with the security measures and processors described in the Privacy Policy (/en/privacy-policy/); helps respond to data subject requests; reports without delay any personal data breach it becomes aware of; and, at the end, keeps the data in the customer's account or deletes it as described in the Privacy Policy. These conditions constitute the data processing agreement between XPLENDOR and the agency and the customer, under Article 28 of the GDPR.",
          ],
        },
      ],
    },
    {
      id: "indicators",
      title: "8. Indicators and recommendations",
      blocks: [
        "Indicators, comparisons and recommendations shown by the Platform are calculated from the available data and are for information only. They do not guarantee results. Business decisions remain the customer's responsibility.",
      ],
    },
    {
      id: "ip",
      title: "9. Intellectual property",
      blocks: [
        `The software, design and content of the Platform and website belong to ${COMPANY.legalName} or their respective owners. The subscription grants the customer a non-exclusive, non-transferable right of use for its duration.`,
      ],
    },
    {
      id: "availability",
      title: "10. Availability and liability",
      blocks: [
        "XPLENDOR seeks to keep the Platform available and data secure, but does not guarantee uninterrupted operation, in particular during maintenance or outages of third party services.",
        "To the extent permitted by law, XPLENDOR is not liable for indirect damages or loss of profits. Nothing in these terms limits rights granted to consumers by law or any liability that cannot be legally excluded.",
      ],
    },
    {
      id: "termination",
      title: "11. Termination",
      blocks: [
        "The customer may cancel the subscription under the terms of its plan. When the account is closed, the company's data is deleted, except data that the law requires to be kept. XPLENDOR may suspend access in case of serious breach of these terms, after notifying the customer whenever possible.",
      ],
    },
    {
      id: "changes",
      title: "12. Changes",
      blocks: [
        "These terms may be updated. The date of the last update is shown at the top of the page and relevant changes are communicated to customers with reasonable notice.",
      ],
    },
    {
      id: "law",
      title: "13. Governing law and disputes",
      blocks: [
        "These terms are governed by Portuguese law. The courts of [COMARCA] have jurisdiction over disputes, without prejudice to applicable mandatory rules.",
        "Consumers may use an alternative consumer dispute resolution entity. More information at www.consumidor.gov.pt.",
      ],
    },
  ],
};
