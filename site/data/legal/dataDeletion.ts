import type { LegalContent } from "@/components/legal/LegalPage";
import { COMPANY } from "./company";

/**
 * Eliminação de dados (PT e EN). URL para indicar à Meta em "Data Deletion
 * Instructions URL". Descreve o comportamento atual: ao desligar os anúncios, só a
 * permissão ads_read é retirada na Meta e o token apagado; o histórico mantém-se por omissão e pode ser
 * apagado por opção (ao desligar ou depois, na Plataforma) ou a pedido por email.
 * Nas redes sociais (Instagram e Facebook) retiram-se só as três permissões delas.
 */

export const dataDeletionPt: LegalContent = {
  eyebrow: "Os seus dados",
  title: "Eliminação de Dados",
  intro:
    "Como pedir a eliminação dos dados guardados pela XPLENDOR, incluindo os dados recebidos da Meta (Facebook e Instagram), e em que prazo.",
  updatedLabel: "Última atualização",
  updated: "6 de outubro de 2026",
  tocLabel: "Nesta página",
  alternate: { href: "/en/data-deletion/", label: "Read in English" },
  sections: [
    {
      id: "pedido",
      title: "1. Como pedir a eliminação",
      blocks: [
        "Os clientes podem apagar os dados recebidos da Meta diretamente na Plataforma, em Integrações, ao desligar os anúncios ou as redes sociais, ou mais tarde (secção 4). A eliminação é imediata.",
        "Também pode pedir a eliminação por email, por exemplo se não for cliente ou já não tiver acesso à Plataforma.",
        `Envie um email para ${COMPANY.email} com o assunto \"Eliminação de dados\", indicando:`,
        {
          list: [
            "o nome da empresa e o NIPC usados na conta XPLENDOR (ou, se não for cliente, o seu nome e email);",
            "o que pretende eliminar: só os dados recebidos da Meta, ou todos os dados da conta;",
            "se possível, o identificador da conta de anúncios da Meta ou o nome da Página de Facebook ou da conta de Instagram ligadas à XPLENDOR.",
          ],
        },
        "O pedido deve ser enviado a partir do email de um utilizador da conta. Se não for possível, pediremos outra forma de confirmar a identidade, para evitar eliminações indevidas.",
      ],
    },
    {
      id: "prazo",
      title: "2. Prazo",
      blocks: [
        "Confirmamos a receção do pedido no prazo de 3 dias úteis.",
        "A eliminação é concluída no prazo máximo de 30 dias a contar da receção do pedido, e enviamos uma confirmação por email quando estiver feita.",
        "Os registos técnicos do servidor, que podem conter identificadores de contas e campanhas, são apagados automaticamente ao fim de 14 dias. Cópias de segurança: [DESCREVER CÓPIAS DE SEGURANÇA E PRAZO].",
      ],
    },
    {
      id: "o-que-e-eliminado",
      title: "3. O que é eliminado",
      blocks: [
        "Num pedido de eliminação dos dados da Meta, são eliminados:",
        {
          list: [
            "o token de acesso e a configuração da integração;",
            "as métricas diárias de desempenho (por conta, campanha e anúncio) e as métricas agregadas por idade e género;",
            "os nomes e identificadores de campanhas, conjuntos de anúncios e anúncios, e as associações entre anúncios e produtos;",
            "os metadados de públicos personalizados e as configurações de segmentação;",
            "as despesas de marketing calculadas a partir do investimento na Meta;",
            "na ligação das redes sociais: os tokens de acesso, as Páginas e contas de Instagram escolhidas (identificador, nome, nome de utilizador e fotografia de perfil) e o histórico de seguidores lido automaticamente.",
          ],
        },
        "Os números de seguidores que o próprio cliente registou à mão não vêm da Meta; mantêm-se, salvo num pedido de eliminação de todos os dados.",
        "As vendas e restantes dados de negócio que o cliente introduziu mantêm-se, mas deixam de estar associadas a campanhas ou anúncios da Meta.",
        "Num pedido de eliminação de todos os dados, a conta da empresa é encerrada e todos os dados associados são eliminados, salvo os que a lei obrigue a conservar (por exemplo, documentos de faturação).",
        "Quem recebeu um orçamento da XPLENDOR pode pedir, pelo mesmo email, a eliminação dos registos de abertura do link e dos seus dados de contacto. Os registos de aceitação, recusa ou pedido de alterações são conservados enquanto a lei o exigir (até 10 anos) e eliminados depois; as aberturas são apagadas automaticamente ao fim de 12 meses.",
        "Quem recebeu um link de aprovação de conteúdos pode pedir à empresa que o enviou, ou à XPLENDOR pelo mesmo email, a eliminação do seu nome e email e dos registos de abertura. As aberturas são apagadas automaticamente ao fim de 12 meses; as decisões e os comentários fazem parte do histórico das publicações do cliente e são eliminados com a conta ou a pedido dele.",
      ],
    },
    {
      id: "desligar",
      title: "4. O que acontece ao desligar as integrações com a Meta",
      blocks: [
        "Na Plataforma, em Integrações, o cliente pode desligar a Meta a qualquer momento. Ao fazê-lo:",
        {
          list: [
            "a XPLENDOR pede à Meta que retire apenas a permissão dos anúncios (ads_read) na conta do cliente; a ligação das redes sociais, se existir, mantém-se;",
            "o token de acesso é apagado e a XPLENDOR deixa de pedir dados à Meta;",
            "o cliente escolhe entre \"Manter o histórico\" (opção por omissão), para continuar a consultar os resultados passados, e \"Apagar todos os dados da Meta\", que elimina de imediato os dados indicados na secção 3, depois de o cliente escrever APAGAR para confirmar.",
          ],
        },
        "Se tiver mantido o histórico, pode apagá-lo mais tarde no mesmo ecrã (\"Apagar os dados da Meta guardados\") ou pedir a eliminação por email, como descrito na secção 1.",
        "Se a Meta não aceitar o pedido de retirada da permissão (por exemplo, porque o token já expirou), o desligar conclui-se na mesma; nesse caso, remova a XPLENDOR nas definições do Facebook, como descrito na secção 5.",
        { h3: "Redes sociais (Instagram e Facebook)" },
        "No cartão \"Redes sociais (Instagram e Facebook)\", em Integrações, o administrador pode desligar a ligação das redes sociais a qualquer momento. Ao fazê-lo:",
        {
          list: [
            "a XPLENDOR pede à Meta que retire, uma a uma, apenas as permissões das redes sociais (pages_show_list, pages_read_engagement e instagram_basic); a ligação dos anúncios mantém-se;",
            "os tokens de acesso e as Páginas e contas escolhidas são apagados e a XPLENDOR deixa de ler os seguidores;",
            "o cliente escolhe entre \"Manter o histórico de seguidores\" (opção por omissão) e \"Apagar o histórico lido automaticamente\", que elimina de imediato os números de seguidores lidos da Meta, depois de escrever APAGAR para confirmar. Os valores registados à mão mantêm-se.",
          ],
        },
        "Se tiver mantido o histórico, pode apagá-lo mais tarde no mesmo cartão (\"Apagar o histórico de seguidores lido automaticamente\") ou pedir a eliminação por email.",
      ],
    },
    {
      id: "facebook",
      title: "5. Remover a XPLENDOR nas definições do Facebook",
      blocks: [
        "Pode também retirar a autorização diretamente na Meta:",
        {
          list: [
            "no Facebook, abra Definições e privacidade e depois Definições;",
            "entre em Integrações empresariais (Business Integrations);",
            "selecione XPLENDOR e escolha Remover.",
          ],
        },
        "Depois disso, a XPLENDOR deixa de conseguir aceder a quaisquer dados da sua conta de anúncios, das suas Páginas e das suas contas de Instagram, porque esta remoção retira todas as permissões dadas à aplicação. Os dados já recebidos podem ser apagados na Plataforma ou mediante pedido, nos termos das secções 1 e 4.",
      ],
    },
    {
      id: "contacto",
      title: "6. Contacto",
      blocks: [
        `Responsável: ${COMPANY.legalName}, NIF ${COMPANY.nif}, ${COMPANY.address}. Email: ${COMPANY.email}.`,
        "Mais informação sobre o tratamento dos dados e os seus direitos na Política de Privacidade (/politica-de-privacidade/). Pode ainda apresentar reclamação à CNPD, em www.cnpd.pt.",
      ],
    },
  ],
};

export const dataDeletionEn: LegalContent = {
  eyebrow: "Your data",
  title: "Data Deletion",
  intro:
    "How to request deletion of the data stored by XPLENDOR, including the data received from Meta (Facebook and Instagram), and within what timeframe.",
  updatedLabel: "Last updated",
  updated: "6 October 2026",
  tocLabel: "On this page",
  alternate: { href: "/eliminacao-de-dados/", label: "Ler em português" },
  sections: [
    {
      id: "request",
      title: "1. How to request deletion",
      blocks: [
        "Customers can delete the data received from Meta directly in the Platform, under Integrations, when disconnecting the advertising or the social media connection, or later (section 4). Deletion is immediate.",
        "You can also request deletion by email, for example if you are not a customer or no longer have access to the Platform.",
        `Send an email to ${COMPANY.email} with the subject \"Data deletion\", stating:`,
        {
          list: [
            "the company name and company tax number used in the XPLENDOR account (or, if you are not a customer, your name and email);",
            "what you want deleted: only the data received from Meta, or all account data;",
            "if possible, the ID of the Meta ad account, or the name of the Facebook Page or Instagram account, connected to XPLENDOR.",
          ],
        },
        "The request should be sent from the email address of a user of the account. If that is not possible, we will ask for another way to confirm your identity, to prevent improper deletions.",
      ],
    },
    {
      id: "timeframe",
      title: "2. Timeframe",
      blocks: [
        "We acknowledge receipt of the request within 3 business days.",
        "Deletion is completed within 30 days of receiving the request at the latest, and we send a confirmation by email once it is done.",
        "Server technical logs, which may contain account and campaign IDs, are deleted automatically after 14 days. Backups: [DESCREVER CÓPIAS DE SEGURANÇA E PRAZO].",
      ],
    },
    {
      id: "what-is-deleted",
      title: "3. What is deleted",
      blocks: [
        "For a request to delete Meta data, the following is deleted:",
        {
          list: [
            "the access token and the integration settings;",
            "daily performance metrics (per account, campaign and ad) and metrics aggregated by age and gender;",
            "names and IDs of campaigns, ad sets and ads, and the links between ads and products;",
            "custom audience metadata and targeting settings;",
            "marketing expenses calculated from Meta spend;",
            "for the social media connection: the access tokens, the chosen Pages and Instagram accounts (ID, name, username and profile picture) and the follower history read automatically.",
          ],
        },
        "Follower counts entered manually by the customer do not come from Meta; they are kept, except in a request to delete all data.",
        "Sales and other business data entered by the customer are kept, but are no longer linked to Meta campaigns or ads.",
        "For a request to delete all data, the company account is closed and all associated data is deleted, except data that the law requires to be kept (for example invoicing documents).",
        "Anyone who received a quote from XPLENDOR can request, by the same email, deletion of the link open records and of their contact details. Records of acceptance, refusal or change requests are kept for as long as the law requires (up to 10 years) and deleted afterwards; opens are deleted automatically after 12 months.",
        "Anyone who received a content approval link can ask the company that sent it, or XPLENDOR by the same email, to delete their name and email and the open records. Opens are deleted automatically after 12 months; decisions and comments are part of the customer's post history and are deleted with the account or at the customer's request.",
      ],
    },
    {
      id: "disconnect",
      title: "4. What happens when you disconnect the Meta integrations",
      blocks: [
        "In the Platform, under Integrations, the customer can disconnect Meta at any time. When doing so:",
        {
          list: [
            "XPLENDOR asks Meta to remove only the advertising permission (ads_read) from the customer's account; the social media connection, if any, is kept;",
            "the access token is deleted and XPLENDOR stops requesting data from Meta;",
            "the customer chooses between \"Manter o histórico\" (keep the history, the default), to keep viewing past results, and \"Apagar todos os dados da Meta\" (delete all Meta data), which immediately deletes the data listed in section 3, after the customer types APAGAR to confirm.",
          ],
        },
        "If the history was kept, it can be deleted later on the same screen (\"Apagar os dados da Meta guardados\", delete stored Meta data) or by email request, as described in section 1.",
        "If Meta does not accept the permission removal request (for example because the token has already expired), the disconnection still completes; in that case, remove XPLENDOR in your Facebook settings, as described in section 5.",
        { h3: "Social media (Instagram and Facebook)" },
        "In the \"Redes sociais (Instagram e Facebook)\" card (social media), under Integrations, the administrator can disconnect the social media connection at any time. When doing so:",
        {
          list: [
            "XPLENDOR asks Meta to remove, one by one, only the social media permissions (pages_show_list, pages_read_engagement and instagram_basic); the advertising connection is kept;",
            "the access tokens and the chosen Pages and accounts are deleted and XPLENDOR stops reading followers;",
            "the customer chooses between \"Manter o histórico de seguidores\" (keep the follower history, the default) and \"Apagar o histórico lido automaticamente\" (delete the history read automatically), which immediately deletes the follower counts read from Meta, after typing APAGAR to confirm. Values entered manually are kept.",
          ],
        },
        "If the history was kept, it can be deleted later on the same card (\"Apagar o histórico de seguidores lido automaticamente\") or by email request.",
      ],
    },
    {
      id: "facebook",
      title: "5. Remove XPLENDOR in your Facebook settings",
      blocks: [
        "You can also withdraw the authorisation directly on Meta:",
        {
          list: [
            "on Facebook, open Settings and privacy, then Settings;",
            "go to Business Integrations;",
            "select XPLENDOR and choose Remove.",
          ],
        },
        "After that, XPLENDOR can no longer access any data from your ad account, your Pages or your Instagram accounts, because this removal withdraws all permissions given to the app. Data already received can be deleted in the Platform or on request, as described in sections 1 and 4.",
      ],
    },
    {
      id: "contact",
      title: "6. Contact",
      blocks: [
        `Controller: ${COMPANY.legalName}, tax number (NIF) ${COMPANY.nif}, ${COMPANY.address}. Email: ${COMPANY.email}.`,
        "More information about how data is processed and your rights is in the Privacy Policy (/en/privacy-policy/). You may also lodge a complaint with the Portuguese data protection authority (CNPD) at www.cnpd.pt.",
      ],
    },
  ],
};
