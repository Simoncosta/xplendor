import type { LegalContent } from "@/components/legal/LegalPage";
import { COMPANY } from "./company";

/** Termos e condições (PT e EN). Os campos entre parênteses retos são para preencher. */

export const termsPt: LegalContent = {
  eyebrow: "Termos",
  title: "Termos e Condições",
  intro: "Condições de utilização deste site e da Plataforma XPLENDOR.",
  updatedLabel: "Última atualização",
  updated: "5 de outubro de 2026",
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
      id: "indicadores",
      title: "7. Indicadores e recomendações",
      blocks: [
        "Os indicadores, comparações e recomendações apresentados pela Plataforma são calculados a partir dos dados disponíveis e têm natureza informativa. Não constituem garantia de resultados. As decisões de negócio são da responsabilidade do cliente.",
      ],
    },
    {
      id: "propriedade",
      title: "8. Propriedade intelectual",
      blocks: [
        `O software, o design e os conteúdos da Plataforma e do site pertencem a ${COMPANY.legalName} ou aos respetivos titulares. A subscrição dá ao cliente um direito de utilização, não exclusivo e intransmissível, durante a sua vigência.`,
      ],
    },
    {
      id: "disponibilidade",
      title: "9. Disponibilidade e responsabilidade",
      blocks: [
        "A XPLENDOR procura manter a Plataforma disponível e os dados seguros, mas não garante funcionamento ininterrupto, nomeadamente durante manutenções ou falhas de serviços de terceiros.",
        "Na medida permitida por lei, a XPLENDOR não responde por danos indiretos ou lucros cessantes. Nada nestes termos limita direitos que a lei atribui aos consumidores ou responsabilidade que não possa ser legalmente excluída.",
      ],
    },
    {
      id: "cessacao",
      title: "10. Cessação",
      blocks: [
        "O cliente pode cancelar a subscrição nos termos do seu plano. Com o encerramento da conta, os dados da empresa são eliminados, salvo os que a lei obrigue a conservar. A XPLENDOR pode suspender o acesso em caso de incumprimento grave destes termos, após aviso ao cliente sempre que possível.",
      ],
    },
    {
      id: "alteracoes",
      title: "11. Alterações",
      blocks: [
        "Estes termos podem ser atualizados. A data da última atualização está no topo da página e as alterações relevantes são comunicadas aos clientes com antecedência razoável.",
      ],
    },
    {
      id: "lei",
      title: "12. Lei aplicável e litígios",
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
  updated: "5 October 2026",
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
      id: "indicators",
      title: "7. Indicators and recommendations",
      blocks: [
        "Indicators, comparisons and recommendations shown by the Platform are calculated from the available data and are for information only. They do not guarantee results. Business decisions remain the customer's responsibility.",
      ],
    },
    {
      id: "ip",
      title: "8. Intellectual property",
      blocks: [
        `The software, design and content of the Platform and website belong to ${COMPANY.legalName} or their respective owners. The subscription grants the customer a non-exclusive, non-transferable right of use for its duration.`,
      ],
    },
    {
      id: "availability",
      title: "9. Availability and liability",
      blocks: [
        "XPLENDOR seeks to keep the Platform available and data secure, but does not guarantee uninterrupted operation, in particular during maintenance or outages of third party services.",
        "To the extent permitted by law, XPLENDOR is not liable for indirect damages or loss of profits. Nothing in these terms limits rights granted to consumers by law or any liability that cannot be legally excluded.",
      ],
    },
    {
      id: "termination",
      title: "10. Termination",
      blocks: [
        "The customer may cancel the subscription under the terms of its plan. When the account is closed, the company's data is deleted, except data that the law requires to be kept. XPLENDOR may suspend access in case of serious breach of these terms, after notifying the customer whenever possible.",
      ],
    },
    {
      id: "changes",
      title: "11. Changes",
      blocks: [
        "These terms may be updated. The date of the last update is shown at the top of the page and relevant changes are communicated to customers with reasonable notice.",
      ],
    },
    {
      id: "law",
      title: "12. Governing law and disputes",
      blocks: [
        "These terms are governed by Portuguese law. The courts of [COMARCA] have jurisdiction over disputes, without prejudice to applicable mandatory rules.",
        "Consumers may use an alternative consumer dispute resolution entity. More information at www.consumidor.gov.pt.",
      ],
    },
  ],
};
