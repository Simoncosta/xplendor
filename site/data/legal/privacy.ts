import type { LegalContent } from "@/components/legal/LegalPage";
import { COMPANY } from "./company";

/**
 * Política de privacidade (PT e EN). Descreve o que o código do server/ faz hoje
 * com os dados da Meta. Se o comportamento mudar (por exemplo, o que acontece ao
 * desligar a integração: MetaDataPurger e CompanyIntegrationController::disconnectMeta; nas
 * redes sociais, SocialConnectionService::disconnect),
 * este texto tem de mudar no mesmo momento.
 * Os campos entre parênteses retos são para preencher.
 */

export const privacyPt: LegalContent = {
  eyebrow: "Privacidade",
  title: "Política de Privacidade",
  intro:
    "Como a XPLENDOR trata os dados pessoais e os dados de publicidade dos seus clientes, incluindo os dados recebidos da Meta (Facebook e Instagram).",
  updatedLabel: "Última atualização",
  updated: "5 de outubro de 2026",
  tocLabel: "Nesta página",
  alternate: { href: "/en/privacy-policy/", label: "Read in English" },
  sections: [
    {
      id: "responsavel",
      title: "1. Responsável pelo tratamento",
      blocks: [
        "O responsável pelo tratamento dos dados descritos nesta política é:",
        {
          list: [
            `Entidade: ${COMPANY.legalName}, que opera a marca ${COMPANY.brand}`,
            `NIF: ${COMPANY.nif}`,
            `Morada: ${COMPANY.address}`,
            `Contacto para questões de privacidade: ${COMPANY.email}`,
          ],
        },
        "XPLENDOR é a plataforma de gestão e análise de marketing disponível em xplendor.tech e na aplicação web associada (a \"Plataforma\").",
      ],
    },
    {
      id: "ambito",
      title: "2. A quem se aplica",
      blocks: [
        "Esta política aplica-se a:",
        {
          list: [
            "visitantes deste site;",
            "empresas clientes da Plataforma e aos utilizadores que estas autorizam;",
            "visitantes dos sites dos clientes que instalaram o script de medição da XPLENDOR (secção 6).",
          ],
        },
      ],
    },
    {
      id: "dados-plataforma",
      title: "3. Dados da conta e da utilização da Plataforma",
      blocks: [
        {
          list: [
            "Dados de conta: nome, endereço de email e palavra-passe (guardada apenas em forma cifrada irreversível, hash).",
            "Dados da empresa: denominação, NIPC, contactos e outros dados que o cliente introduz.",
            "Dados de negócio que o cliente introduz ou importa (por exemplo, viaturas, vendas, despesas, reservas agregadas).",
            "Registos técnicos do servidor (por exemplo, pedidos, erros e datas de sincronização).",
          ],
        },
        "Finalidade: prestar o serviço contratado. Fundamento: execução do contrato com o cliente (artigo 6.º, n.º 1, alínea b) do RGPD).",
        "Formulário de contacto deste site: nome, email e mensagem, enviados através do serviço Formspree para resposta ao pedido. Fundamento: diligências pré-contratuais a pedido do titular.",
      ],
    },
    {
      id: "dados-meta",
      title: "4. Dados recebidos da Meta (Facebook e Instagram)",
      blocks: [
        "Esta secção descreve, de forma específica, os dados que a XPLENDOR recebe da Meta através do Facebook Login. Há duas ligações separadas, cada uma com as suas permissões: a dos anúncios (a conta de anúncios da Meta) e a das redes sociais (as Páginas de Facebook e as contas de Instagram profissionais da empresa). Cada ligação é sempre iniciada pelo administrador da empresa cliente e pode ser desligada a qualquer momento, sem afetar a outra.",
        { h3: "4.1 Permissões pedidas" },
        "Ligação dos anúncios: a XPLENDOR pede apenas a permissão ads_read, para ler os dados de desempenho da conta de anúncios indicada pelo cliente.",
        "Ligação das redes sociais: a XPLENDOR pede apenas três permissões:",
        {
          list: [
            "pages_show_list, para listar as Páginas de Facebook que o cliente gere, de modo a escolher quais ficam ligadas à empresa;",
            "pages_read_engagement, para ler o número de seguidores das Páginas escolhidas;",
            "instagram_basic, para identificar a conta de Instagram profissional ligada a cada Página e ler o respetivo número de seguidores.",
          ],
        },
        "A XPLENDOR apenas lê dados. Não publica, não cria, não edita, não pausa nem apaga campanhas, anúncios, públicos, publicações ou qualquer outro conteúdo na Meta.",
        { h3: "4.2 O que recolhemos e para quê" },
        {
          list: [
            "Token de acesso: o token de longa duração emitido pela Meta e a respetiva data de expiração. Serve apenas para a Plataforma pedir os dados abaixo em nome do cliente. É guardado cifrado (AES-256-CBC) na base de dados e nunca é mostrado no navegador.",
            "Identificador da conta de anúncios escolhida pelo cliente.",
            "Estrutura das campanhas: identificadores e nomes de campanhas, conjuntos de anúncios e anúncios, e o estado de cada anúncio (ativo, em pausa, etc.).",
            "Métricas diárias de desempenho: investimento, impressões, cliques, alcance, frequência, CPM, CTR e CPC, por conta, campanha e anúncio.",
            "Métricas agregadas por faixa etária e género (impressões, cliques, investimento e alcance). São totais estatísticos fornecidos pela Meta; não identificam pessoas.",
            "Configuração de segmentação dos conjuntos de anúncios (por exemplo, idades, localizações e interesses escolhidos pelo anunciante), e pesquisas de interesses no catálogo de segmentação da Meta.",
            "Metadados dos públicos personalizados: identificador, nome, tipo, dimensão aproximada, estado de entrega e data de atualização. A XPLENDOR não recebe nem guarda a lista de pessoas que compõem esses públicos.",
          ],
        },
        "Finalidade (anúncios): mostrar ao cliente quanto investiu em publicidade, que resultados obteve, que anúncios estão associados a cada produto (por exemplo, a cada viatura), e relacionar o investimento com as vendas que o próprio cliente regista na Plataforma. A partir destes dados a Plataforma calcula indicadores como custo por contacto e regista o investimento da Meta como despesa de marketing do cliente.",
        "Na ligação das redes sociais:",
        {
          list: [
            "Tokens de acesso: o token de longa duração emitido pela Meta para esta ligação, a respetiva data de expiração e o identificador numérico que a Meta atribui à conta Facebook que autorizou; e o token de cada Página escolhida. São guardados cifrados (AES-256-CBC) na base de dados e nunca são mostrados no navegador.",
            "Páginas de Facebook: identificador e nome. Contas de Instagram profissionais: identificador, nome e nome de utilizador. Guardamos apenas as que o cliente escolhe e qual é a principal de cada rede.",
            "Número de seguidores, lido uma vez por dia, de cada Página e conta escolhida; na conta de Instagram, também o número de contas seguidas e de publicações. Fica um histórico diário por empresa.",
          ],
        },
        "Finalidade (redes sociais): mostrar ao cliente, no Perfil da Marca, os seguidores atuais e o crescimento ao longo do tempo, e adequar as sugestões de formato das publicações à dimensão da audiência.",
        "Fundamento: execução do contrato com o cliente, que autoriza expressamente a ligação.",
        { h3: "4.3 O que não recolhemos" },
        {
          list: [
            "Dados de utilizadores individuais do Facebook ou do Instagram (perfis, contactos, mensagens, comentários ou listas de membros de públicos).",
            "Leads de formulários da Meta, dados do Pixel ou da API de Conversões.",
            "O conteúdo das Páginas e das contas de Instagram (publicações, comentários, mensagens, estatísticas das publicações) nem a lista de seguidores: apenas o número total de seguidores.",
            "Dados de faturação da conta de anúncios.",
          ],
        },
        { h3: "4.4 Uso limitado" },
        "Os dados recebidos da Meta são usados apenas para prestar a Plataforma ao cliente que os autorizou. Não são vendidos, não são usados para publicidade a terceiros, não são partilhados com outros clientes e não são usados para treinar modelos de inteligência artificial.",
      ],
    },
    {
      id: "conservacao-meta",
      title: "5. Onde ficam, quanto tempo e como se eliminam os dados da Meta",
      blocks: [
        "Onde ficam: na base de dados da Plataforma, em servidores alojados por [FORNECEDOR DE ALOJAMENTO E PAÍS], separados por empresa cliente. Cada empresa só tem acesso aos seus próprios dados.",
        "Quanto tempo: os dados de desempenho e de estrutura das campanhas são conservados enquanto a conta do cliente na Plataforma estiver ativa, para permitir comparações históricas. Não existe, de momento, eliminação automática por antiguidade.",
        "Quando o cliente desliga a integração na Plataforma:",
        {
          list: [
            "a XPLENDOR pede à Meta que retire apenas a permissão ads_read na conta do cliente e, em seguida, apaga o token de acesso; a integração deixa de pedir dados dos anúncios à Meta; a ligação das redes sociais, se existir, mantém-se;",
            "se a Meta não aceitar o pedido (por exemplo, porque o token já expirou), o desligar conclui-se na mesma e o cliente pode remover a XPLENDOR nas definições do Facebook (Definições e privacidade, Integrações empresariais);",
            "o cliente escolhe o que acontece aos dados já recebidos: por omissão, o histórico (métricas, estrutura das campanhas, metadados de públicos e despesas calculadas) mantém-se na conta para consulta; em alternativa, pode apagar de imediato todos os dados recebidos da Meta, mediante confirmação explícita;",
            "ao apagar, as vendas registadas pelo cliente mantêm-se, sem ligação a campanhas ou anúncios.",
          ],
        },
        "Quando o cliente desliga as redes sociais na Plataforma:",
        {
          list: [
            "a XPLENDOR pede à Meta que retire, uma a uma, apenas as três permissões das redes sociais (pages_show_list, pages_read_engagement e instagram_basic); a ligação dos anúncios não é afetada;",
            "os tokens de acesso e as Páginas e contas escolhidas são apagados e a XPLENDOR deixa de ler os seguidores;",
            "o cliente escolhe o que acontece ao histórico de seguidores: por omissão mantém-se para consulta; em alternativa, pode apagar de imediato os números lidos automaticamente da Meta, mediante confirmação explícita. Os valores que o próprio cliente registou à mão mantêm-se.",
          ],
        },
                "O histórico de seguidores é conservado enquanto a conta do cliente na Plataforma estiver ativa, salvo se o cliente o apagar antes.",
        "Quando o cliente muda de conta de anúncios, as métricas e os anúncios da conta anterior são eliminados.",
        "Quando a conta do cliente na Plataforma é encerrada, todos os dados da empresa, incluindo os dados recebidos da Meta, são eliminados da base de dados.",
        "Eliminação posterior: se tiver mantido o histórico, o cliente pode apagá-lo mais tarde na Plataforma (Integrações) ou pedir a eliminação por email, sem encerrar a conta. As instruções e o prazo estão na página Eliminação de Dados (/eliminacao-de-dados/).",
        "Registos técnicos: os registos do servidor podem conter identificadores de contas e campanhas e mensagens de erro devolvidas pela Meta; não contêm tokens de acesso. São conservados durante 14 dias e depois apagados automaticamente.",
        "Cópias de segurança: [DESCREVER CÓPIAS DE SEGURANÇA E PRAZO].",
      ],
    },
    {
      id: "medicao-sites",
      title: "6. Medição nos sites dos clientes",
      blocks: [
        "Os clientes podem instalar no seu próprio site um script da XPLENDOR que regista visitas vindas de anúncios. Esse script guarda um identificador aleatório de visitante e de sessão, a página visitada, os parâmetros de campanha do endereço (UTM), o identificador do anúncio e o identificador de clique da plataforma de publicidade (por exemplo, fbclid ou gclid), e interações como cliques em telefone, WhatsApp ou formulário.",
        "Nesse caso, o cliente é o responsável pelo tratamento perante os visitantes do seu site, e a XPLENDOR atua como subcontratante, por conta do cliente. Cabe ao cliente informar os seus visitantes e obter o consentimento necessário.",
      ],
    },
    {
      id: "partilha",
      title: "7. Partilha com terceiros",
      blocks: [
        "A XPLENDOR não vende dados pessoais. Os dados podem ser tratados pelos seguintes prestadores, apenas na medida do necessário para prestar o serviço:",
        {
          list: [
            "alojamento da Plataforma: [FORNECEDOR DE ALOJAMENTO E PAÍS];",
            "envio do formulário de contacto deste site: Formspree;",
            "integrações que o próprio cliente ativa (por exemplo, Meta, Google Analytics, programas de faturação), que recebem apenas os pedidos necessários ao funcionamento dessas ligações.",
          ],
        },
        "Quando algum prestador tratar dados fora do Espaço Económico Europeu, a transferência é feita com as garantias previstas no RGPD, como as cláusulas contratuais-tipo da Comissão Europeia.",
      ],
    },
    {
      id: "seguranca",
      title: "8. Segurança",
      blocks: [
        "Os tokens de acesso a plataformas externas são guardados cifrados. A chave secreta da aplicação Meta existe apenas no servidor. O acesso à Plataforma é feito com autenticação e cada pedido é verificado para garantir que um utilizador só acede aos dados da sua própria empresa. As comunicações são feitas por ligação cifrada (HTTPS).",
      ],
    },
    {
      id: "direitos",
      title: "9. Os seus direitos (RGPD)",
      blocks: [
        "Nos termos do Regulamento Geral sobre a Proteção de Dados, pode exercer, a qualquer momento:",
        {
          list: [
            "direito de acesso aos dados que lhe dizem respeito;",
            "direito de retificação de dados inexatos ou incompletos;",
            "direito ao apagamento (eliminação) dos dados;",
            "direito à limitação do tratamento e direito de oposição;",
            "direito à portabilidade dos dados que forneceu;",
            "direito de retirar a autorização dada, por exemplo desligando a integração com a Meta, sem afetar o tratamento feito até esse momento.",
          ],
        },
        `Para exercer estes direitos, escreva para ${COMPANY.email}. Respondemos no prazo máximo de um mês a contar da receção do pedido, prorrogável nos casos previstos no RGPD, e podemos pedir a confirmação da identidade de quem faz o pedido.`,
        "Tem ainda o direito de apresentar reclamação à Comissão Nacional de Proteção de Dados (CNPD), em www.cnpd.pt.",
      ],
    },
    {
      id: "alteracoes",
      title: "10. Alterações a esta política",
      blocks: [
        "Esta política pode ser atualizada para refletir alterações na Plataforma ou na lei. A data da última atualização está no topo da página. Alterações relevantes são comunicadas aos clientes por email ou na Plataforma.",
      ],
    },
  ],
};

export const privacyEn: LegalContent = {
  eyebrow: "Privacy",
  title: "Privacy Policy",
  intro:
    "How XPLENDOR handles personal data and advertising data of its customers, including the data received from Meta (Facebook and Instagram).",
  updatedLabel: "Last updated",
  updated: "5 October 2026",
  tocLabel: "On this page",
  alternate: { href: "/politica-de-privacidade/", label: "Ler em português" },
  sections: [
    {
      id: "controller",
      title: "1. Data controller",
      blocks: [
        "The controller of the data described in this policy is:",
        {
          list: [
            `Entity: ${COMPANY.legalName}, which operates the ${COMPANY.brand} brand`,
            `Tax number (NIF): ${COMPANY.nif}`,
            `Address: ${COMPANY.address}`,
            `Privacy contact: ${COMPANY.email}`,
          ],
        },
        "XPLENDOR is the business management and marketing analytics platform available at xplendor.tech and in the related web application (the \"Platform\").",
      ],
    },
    {
      id: "scope",
      title: "2. Who this policy applies to",
      blocks: [
        "This policy applies to:",
        {
          list: [
            "visitors of this website;",
            "companies that are customers of the Platform and the users they authorise;",
            "visitors of customer websites that installed the XPLENDOR measurement script (section 6).",
          ],
        },
      ],
    },
    {
      id: "platform-data",
      title: "3. Account and Platform usage data",
      blocks: [
        {
          list: [
            "Account data: name, email address and password (stored only as an irreversible hash).",
            "Company data: legal name, company tax number, contacts and other data the customer enters.",
            "Business data the customer enters or imports (for example vehicles, sales, expenses, aggregated bookings).",
            "Server technical logs (for example requests, errors and synchronisation dates).",
          ],
        },
        "Purpose: to provide the contracted service. Legal basis: performance of the contract with the customer (Article 6(1)(b) GDPR).",
        "Contact form on this website: name, email and message, sent through the Formspree service so that we can reply. Legal basis: steps taken at the request of the data subject prior to entering into a contract.",
      ],
    },
    {
      id: "meta-data",
      title: "4. Data received from Meta (Facebook and Instagram)",
      blocks: [
        "This section specifically describes the data XPLENDOR receives from Meta through Facebook Login. There are two separate connections, each with its own permissions: the advertising connection (the Meta ad account) and the social media connection (the company's Facebook Pages and Instagram professional accounts). Each connection is always started by an administrator of the customer company and can be disconnected at any time without affecting the other.",
        { h3: "4.1 Permissions requested" },
        "Advertising connection: XPLENDOR requests only the ads_read permission, to read the performance data of the ad account selected by the customer.",
        "Social media connection: XPLENDOR requests only three permissions:",
        {
          list: [
            "pages_show_list, to list the Facebook Pages the customer manages, so that the customer can choose which ones are linked to the company;",
            "pages_read_engagement, to read the follower count of the chosen Pages;",
            "instagram_basic, to identify the Instagram professional account linked to each Page and read its follower count.",
          ],
        },
        "XPLENDOR only reads data. It does not publish, create, edit, pause or delete campaigns, ads, audiences, posts or any other content on Meta.",
        { h3: "4.2 What we collect and why" },
        {
          list: [
            "Access token: the long-lived token issued by Meta and its expiry date. It is used only so that the Platform can request the data below on the customer's behalf. It is stored encrypted (AES-256-CBC) in the database and is never shown in the browser.",
            "The ID of the ad account chosen by the customer.",
            "Campaign structure: IDs and names of campaigns, ad sets and ads, and the status of each ad (active, paused, etc.).",
            "Daily performance metrics: spend, impressions, clicks, reach, frequency, CPM, CTR and CPC, per account, campaign and ad.",
            "Metrics aggregated by age range and gender (impressions, clicks, spend and reach). These are statistical totals provided by Meta and do not identify individuals.",
            "Ad set targeting settings (for example the ages, locations and interests chosen by the advertiser), and interest searches in Meta's targeting catalogue.",
            "Custom audience metadata: ID, name, type, approximate size, delivery status and last update date. XPLENDOR does not receive or store the list of people in those audiences.",
          ],
        },
        "Purpose (advertising): to show the customer how much they spent on advertising, what results they obtained, which ads relate to each product (for example each vehicle), and to relate that spend to the sales the customer records in the Platform. From this data the Platform calculates indicators such as cost per contact and records Meta spend as a marketing expense of the customer.",
        "In the social media connection:",
        {
          list: [
            "Access tokens: the long-lived token issued by Meta for this connection, its expiry date and the numeric ID Meta assigns to the Facebook account that authorised it; and the token of each chosen Page. They are stored encrypted (AES-256-CBC) in the database and are never shown in the browser.",
            "Facebook Pages: ID and name. Instagram professional accounts: ID, name and username. We store only the ones the customer chooses, and which one is the main account of each network.",
            "Follower count, read once a day, of each chosen Page and account; for the Instagram account, also the number of accounts followed and of posts. A daily history is kept per company.",
          ],
        },
        "Purpose (social media): to show the customer, in the Brand Profile, the current followers and their growth over time, and to adapt the post format suggestions to the size of the audience.",
        "Legal basis: performance of the contract with the customer, who expressly authorises the connection.",
        { h3: "4.3 What we do not collect" },
        {
          list: [
            "Data about individual Facebook or Instagram users (profiles, contacts, messages, comments or audience member lists).",
            "Meta lead form data, Pixel data or Conversions API data.",
            "The content of Pages and Instagram accounts (posts, comments, messages, post statistics) or the list of followers: only the total follower count.",
            "Ad account billing data.",
          ],
        },
        { h3: "4.4 Limited use" },
        "Data received from Meta is used only to provide the Platform to the customer who authorised it. It is not sold, not used for third party advertising, not shared with other customers and not used to train artificial intelligence models.",
      ],
    },
    {
      id: "meta-retention",
      title: "5. Where Meta data is stored, for how long and how it is deleted",
      blocks: [
        "Where: in the Platform database, on servers hosted by [FORNECEDOR DE ALOJAMENTO E PAÍS], separated by customer company. Each company can access only its own data.",
        "How long: campaign performance and structure data is kept while the customer's Platform account is active, to allow historical comparisons. There is currently no automatic deletion based on age.",
        "When the customer disconnects the integration in the Platform:",
        {
          list: [
            "XPLENDOR asks Meta to remove only the ads_read permission from the customer's account and then deletes the access token; the integration stops requesting advertising data from Meta; the social media connection, if any, is kept;",
            "if Meta does not accept the request (for example because the token has already expired), the disconnection still completes and the customer can remove XPLENDOR in their Facebook settings (Settings and privacy, Business integrations);",
            "the customer chooses what happens to the data already received: by default, the history (metrics, campaign structure, audience metadata and calculated expenses) is kept in the account for reference; alternatively, the customer can immediately delete all data received from Meta, after explicit confirmation;",
            "when deleting, the sales recorded by the customer are kept, without any link to campaigns or ads.",
          ],
        },
        "When the customer disconnects the social media connection in the Platform:",
        {
          list: [
            "XPLENDOR asks Meta to remove, one by one, only the three social media permissions (pages_show_list, pages_read_engagement and instagram_basic); the advertising connection is not affected;",
            "the access tokens and the chosen Pages and accounts are deleted and XPLENDOR stops reading followers;",
            "the customer chooses what happens to the follower history: by default it is kept for reference; alternatively, the customer can immediately delete the counts read automatically from Meta, after explicit confirmation. Values the customer entered manually are kept.",
          ],
        },
                "The follower history is kept while the customer's Platform account is active, unless the customer deletes it earlier.",
        "When the customer switches to a different ad account, the metrics and ads of the previous account are deleted.",
        "When the customer's Platform account is closed, all company data, including the data received from Meta, is deleted from the database.",
        "Later deletion: if the history was kept, the customer can delete it later in the Platform (Integrations) or request deletion by email, without closing the account. Instructions and timeframes are on the Data Deletion page (/en/data-deletion/).",
        "Technical logs: server logs may contain account and campaign IDs and error messages returned by Meta; they do not contain access tokens. They are kept for 14 days and then deleted automatically.",
        "Backups: [DESCREVER CÓPIAS DE SEGURANÇA E PRAZO].",
      ],
    },
    {
      id: "site-measurement",
      title: "6. Measurement on customer websites",
      blocks: [
        "Customers may install an XPLENDOR script on their own website to record visits coming from ads. The script stores a random visitor and session ID, the page visited, the campaign parameters in the URL (UTM), the ad ID and the advertising platform click ID (for example fbclid or gclid), and interactions such as clicks on phone, WhatsApp or form.",
        "In that case the customer is the controller towards the visitors of their website, and XPLENDOR acts as a processor on the customer's behalf. The customer is responsible for informing its visitors and obtaining any required consent.",
      ],
    },
    {
      id: "sharing",
      title: "7. Sharing with third parties",
      blocks: [
        "XPLENDOR does not sell personal data. Data may be processed by the following providers, only as needed to provide the service:",
        {
          list: [
            "Platform hosting: [FORNECEDOR DE ALOJAMENTO E PAÍS];",
            "delivery of this website's contact form: Formspree;",
            "integrations the customer chooses to enable (for example Meta, Google Analytics, invoicing software), which receive only the requests needed for those connections to work.",
          ],
        },
        "Where a provider processes data outside the European Economic Area, the transfer relies on the safeguards provided by the GDPR, such as the European Commission's standard contractual clauses.",
      ],
    },
    {
      id: "security",
      title: "8. Security",
      blocks: [
        "Access tokens for external platforms are stored encrypted. The Meta app secret exists only on the server. Access to the Platform requires authentication and every request is checked so that a user can only access the data of their own company. Communications use an encrypted connection (HTTPS).",
      ],
    },
    {
      id: "rights",
      title: "9. Your rights (GDPR)",
      blocks: [
        "Under the General Data Protection Regulation you may, at any time, exercise:",
        {
          list: [
            "the right of access to your data;",
            "the right to rectification of inaccurate or incomplete data;",
            "the right to erasure (deletion) of your data;",
            "the right to restriction of processing and the right to object;",
            "the right to data portability for the data you provided;",
            "the right to withdraw an authorisation you gave, for example by disconnecting the Meta integration, without affecting processing carried out before that.",
          ],
        },
        `To exercise these rights, write to ${COMPANY.email}. We reply within one month of receiving the request, extendable in the cases provided for in the GDPR, and we may ask the requester to confirm their identity.`,
        "You also have the right to lodge a complaint with the Portuguese data protection authority, Comissão Nacional de Proteção de Dados (CNPD), at www.cnpd.pt.",
      ],
    },
    {
      id: "changes",
      title: "10. Changes to this policy",
      blocks: [
        "This policy may be updated to reflect changes to the Platform or to the law. The date of the last update is shown at the top of the page. Relevant changes are communicated to customers by email or in the Platform.",
      ],
    },
  ],
};
