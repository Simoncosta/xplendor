/**
 * Todos os textos fixos do site do consultor, num só sítio, para rever. Português de Portugal
 * formal, na terceira pessoa ("a sua casa"), sem travessões, sem promessas de preço nem de prazo.
 * O que é pessoal (o nome, a promessa, a biografia, os textos da rede, as mensagens do WhatsApp)
 * vem do perfil do consultor na XPLENDOR; aqui ficam só os rascunhos por omissão.
 */

export const NAV = {
    vender: "Vender",
    imoveis: "Imóveis",
    empreendimentos: "Empreendimentos",
    comprar: "Comprar",
    blog: "Blog",
    sobre: "Sobre",
    contacto: "Contacto",
    cta: "Estudo da sua zona",
    menu: "Menu",
    fecharMenu: "Fechar o menu",
    inicio: "Página inicial",
    saltar: "Saltar para o conteúdo",
};

export const HERO = {
    promessaPorOmissao: "Vender a sua casa com um plano claro, do primeiro dia à escritura.",
    botaoPrincipal: "Conhecer o mercado da sua zona",
    botaoSecundario: "Ver imóveis",
    /** O segundo botão quando ainda não há imóveis publicados. */
    botaoComoVendo: "Como vendo a sua casa",
};

/* ── Página inicial: cada bloco com um título e, no máximo, uma frase ───────── */

export const VIDEOS_DESTAQUE = {
    titulo: "Os meus vídeos",
    frase: "Vídeos curtos, para ver no telemóvel.",
    ver: (n: number) => `Ver o vídeo ${n}`,
    iframeTitulo: (n: number) => `Vídeo ${n}`,
    anterior: "Vídeos anteriores",
    seguinte: "Vídeos seguintes",
};

export const MERCADO = {
    titulo: "O mercado num número",
    frase: "O valor mediano das vendas de casas por m², nos últimos 12 meses, segundo o INE.",
    unidade: "€/m²",
    botao: "Pedir o estudo da minha zona",
    freguesias: "Freguesias",
    pausar: "Pausar",
    continuar: "Continuar",
};

export const COMO_VENDO = {
    titulo: "Como vendo a sua casa",
    frase: "Oito passos, do primeiro contacto à escritura: toque em cada um para ver o pormenor.",
    saberMais: "Saber mais",
    passo: (n: number) => `Passo ${n}`,
    composicao: "O que a sua casa recebe: um vídeo vertical e um anúncio na zona certa.",
    legendas: { video: "Vídeo vertical", anuncio: "Anúncio na sua zona" },
    anuncioPatrocinado: "Patrocinado",
    anuncioTitulo: "A sua casa, aqui",
    anuncioBotao: "Saber mais",
};

export const QUEM_SOU = {
    titulo: "Quem sou",
    saberMais: "Saber mais",
};

export const CONTACTO_CURTO = {
    titulo: "Falamos sobre a sua casa?",
    frase: "Diga-me onde fica e respondo pessoalmente.",
    whatsapp: "Falar pelo WhatsApp",
    mensagem: "Enviar uma mensagem",
};

export const VIDEO_APRESENTACAO = {
    titulo: "Antes de falarmos",
    texto: "Dois minutos para conhecer a forma como trabalho.",
    botao: "Ver o vídeo",
    iframeTitulo: "Vídeo de apresentação",
};

export const PROCESSO = {
    passos: [
        { titulo: "Avaliação", texto: "Visito a casa, ouço o que procura e mostro o que o mercado da zona está a registar. O preço decide-o o proprietário, com informação." },
        { titulo: "Preparação", texto: "Ajustes simples que mudam a primeira impressão, e a documentação reunida antes de anunciar." },
        { titulo: "Marketing", texto: "Fotografia, vídeo, página própria e anúncios, conforme o plano combinado." },
        { titulo: "Visitas", texto: "Visitas marcadas com compradores com o perfil e a capacidade financeira confirmados." },
        { titulo: "Propostas", texto: "Cada proposta chega por escrito, com as condições e o enquadramento do comprador." },
        { titulo: "Negociação", texto: "Negociação feita por mim, sempre com a sua decisão final." },
        { titulo: "Contrato", texto: "Contrato-promessa preparado com as condições acordadas e os prazos por escrito." },
        { titulo: "Escritura", texto: "Acompanhamento até à escritura e à entrega das chaves." },
    ],
};

export const EXCLUSIVIDADE = {
    colunaSem: "Sem exclusividade",
    colunaCom: "Com exclusividade",
    linhas: [
        { tema: "Plano de marketing", sem: "Divulgação habitual, sem plano escrito.", com: "Plano de marketing por escrito, com o que é feito e quando." },
        { tema: "Investimento em anúncios", sem: "Investimento limitado, partilhado com outros imóveis.", com: "Investimento em anúncios definido para a sua casa." },
        { tema: "Informação", sem: "Notícias quando as há.", com: "Relatório semanal: visitas, contactos e o que os compradores dizem." },
        { tema: "Interlocutor", sem: "Vários contactos, de várias agências.", com: "Um único interlocutor do início ao fim." },
        { tema: "Preço anunciado", sem: "O mesmo imóvel pode surgir com preços diferentes.", com: "O mesmo preço em todo o lado." },
    ],
};

export const DESTAQUES = {
    titulo: "Imóveis em destaque",
    verTodos: "Ver todos os imóveis",
    verVideos: "Ver os vídeos",
    sobConsulta: "Preço sob consulta",
    reservado: "Reservado",
    vendido: "Vendido",
    arrendado: "Arrendado",
    porMes: "por mês",
};

export const EMPREENDIMENTOS = {
    titulo: "Empreendimentos em construção",
    desde: "desde",
    fracoes: (n: number) => (n === 1 ? "1 fração disponível" : `${n} frações disponíveis`),
    conclusao: "conclusão prevista",
};

export const ESTUDO = {
    titulo: "Conhecer o mercado da sua zona",
    botao: "Pedir o estudo da minha zona",
    mensagemPreenchida: "Gostava de receber o estudo do mercado da minha zona. A casa fica em: ",
};

export const REDE = {
    titulo: (rede: string) => `Porque a ${rede}`,
};

export const CREDITO = {
    titulo: "Crédito com o parceiro",
    registo: (nome: string, bp: string) => `${nome}, intermediário de crédito registado no Banco de Portugal com o n.º ${bp}.`,
    botao: "Falar sobre crédito",
    mensagemPreenchida: "Gostava de falar sobre o crédito para a compra de casa.",
};

export const BLOG = {
    titulo: "Blog e guias",
    ler: "Ler o artigo",
    minutos: (n: number) => `${n} min de leitura`,
};

export const FAQ = {
    titulo: "Perguntas de quem pensa vender",
    perguntas: [
        {
            p: "Tenho de pagar alguma coisa antes de vender?",
            r: "Pela lei, a remuneração da mediação só é devida quando o negócio se concretiza (Lei n.º 15/2013, artigo 19.º). As condições concretas ficam escritas no contrato de mediação, que explico antes de assinar.",
        },
        {
            p: "E se a casa não se vender?",
            r: "Se o negócio não se concretizar, a remuneração da mediação não é devida, salvo nos casos que a lei prevê para os contratos com exclusividade, que ficam escritos no contrato. No fim do prazo, revemos juntos o que aconteceu e decide se continua.",
        },
        {
            p: "Posso desistir?",
            r: "O contrato de mediação tem um prazo, e as regras para o terminar antes do fim estão escritas nele. Explico-as antes de assinar, para que decida sem surpresas.",
        },
        {
            p: "O que muda com a exclusividade?",
            r: "Com exclusividade, só eu promovo a casa durante o prazo do contrato e comprometo-me por escrito com o plano de marketing, o investimento em anúncios e um relatório semanal. Durante esse prazo, se a casa for vendida, a remuneração pode ser devida mesmo que o comprador chegue por outra via. Explico estas condições antes de assinar.",
        },
    ],
};

export const CONTACTO_FINAL = {
    titulo: "Falamos sobre a sua casa?",
    texto: "Deixe o seu contacto e uma linha sobre o que procura. Respondo pessoalmente.",
};

export const TESTEMUNHOS = {
    titulo: "O que dizem os proprietários",
};

export const FORMULARIO = {
    nome: "Nome",
    email: "Email",
    telefone: "Telefone",
    mensagem: "Mensagem",
    emailOuTelefone: "Indique o email ou o telefone.",
    privacidadeAntes: "Li e aceito a ",
    privacidadeLigacao: "política de privacidade",
    privacidadeDepois: ".",
    marketing: "Aceito receber informação sobre imóveis e sobre o mercado. Posso retirar este consentimento a qualquer momento.",
    enviar: "Enviar",
    aEnviar: "A enviar",
    sucessoTitulo: "Obrigado.",
    sucesso: "A mensagem chegou e vou responder-lhe pessoalmente.",
    sucessoVisita: "O pedido de visita chegou. Vou contactá-lo para combinar o dia e a hora.",
    disponibilidade: "Disponibilidade para a visita",
    disponibilidadeExemplo: "por exemplo, sábado de manhã ou dias úteis depois das 18h",
    erro: "Não foi possível enviar. Tente de novo dentro de momentos ou escreva-me pelo WhatsApp.",
    demasiados: "Recebemos vários pedidos seguidos. Tente de novo dentro de alguns minutos.",
    preview: "Pré-visualização: o formulário não envia mensagens.",
    obrigatorio: "obrigatório",
    nomeEmFalta: "Indique o seu nome.",
    privacidadeEmFalta: "Para enviar, é preciso aceitar a política de privacidade.",
};

export const WHATSAPP = {
    botao: "Falar pelo WhatsApp",
    abrir: "Abrir o WhatsApp",
    fechar: "Fechar",
};

export const COOKIES = {
    texto: "Este site usa cookies necessários para funcionar. Com a sua autorização, usa também cookies de estatística e de marketing.",
    saberMais: "Saber mais",
    aceitar: "Aceitar",
    recusar: "Recusar",
    personalizar: "Personalizar",
    preferencias: "Preferências de cookies",
    dialogoTitulo: "Preferências de cookies",
    dialogoTexto: "Escolha que cookies autoriza. Pode mudar a escolha a qualquer momento.",
    necessariosTitulo: "Necessários",
    necessariosTexto: "Guardam as suas escolhas e mantêm o site a funcionar. Estão sempre ativos.",
    estatisticaTitulo: "Estatística",
    estatisticaTexto: "Ajudam a perceber que páginas são vistas, com o Google Analytics. Só com a sua autorização.",
    marketingTitulo: "Marketing",
    marketingTexto: "Permitem medir os anúncios da Meta (Facebook e Instagram). Só com a sua autorização.",
    sempreAtivos: "Sempre ativos",
    guardar: "Guardar as escolhas",
    aceitarTodos: "Aceitar todos",
    recusarTodos: "Recusar todos",
};

export const RODAPE = {
    mediacao: "Empresa de mediação",
    ami: (nome: string, ami: string) => `${nome}, licença AMI n.º ${ami}`,
    livro: "Livro de reclamações",
    ral: "Resolução alternativa de litígios",
    privacidade: "Política de privacidade",
    cookies: "Política de cookies",
    legal: "Informação legal",
    redes: "Redes",
    feitoCom: "Feito com XPLENDOR",
    paginas: "Páginas",
};

export const REDES_NOMES: Record<string, string> = { instagram: "Instagram", facebook: "Facebook", linkedin: "LinkedIn", youtube: "YouTube", tiktok: "TikTok" };

export const PREVIEW = {
    faixa: "Pré-visualização",
    texto: "Este site está em pré-visualização: não é indexado, não conta visitas e os formulários não enviam.",
    sair: "Sair da pré-visualização",
    invalido: "O link de pré-visualização já não é válido. Peça um novo na XPLENDOR.",
};

export const VENDER = {
    titulo: "Vender a sua casa",
    lead: "Um plano escrito, uma só pessoa a acompanhar e informação real do mercado da sua zona.",
    botao: "Falar sobre a minha casa",
    contactoTitulo: "Vamos falar sobre a sua casa",
    contactoTexto: "Conte-me onde fica a casa e respondo pessoalmente.",
    mensagemPreenchida: "Estou a pensar vender a minha casa.",
    /* Página Vender: cada bloco com um título e uma frase. */
    oQueFaco: {
        titulo: "O que faço pela sua casa",
        frase: "Cada casa tem um plano de divulgação próprio, pensado para quem a pode comprar.",
        itens: ["Fotografia profissional", "Vídeo vertical", "Anúncios na zona certa", "Página própria", "Partilha na rede"],
    },
    passos: {
        titulo: "Do primeiro contacto à escritura",
        frase: "Oito passos, sempre a saber em que ponto está: toque em cada um para ver o pormenor.",
    },
    exclusividade: {
        titulo: "Com ou sem exclusividade",
        frase: "A diferença está no que fica escrito no contrato como compromisso meu, nunca num preço ou num prazo.",
        comparar: "Comparar",
    },
    compradores: {
        titulo: "Só levo a sua casa a quem a pode comprar",
        fraseComParceiro: "Antes de cada visita, conheço o comprador e confirmo como vai pagar, com o parceiro de crédito quando precisa de financiamento.",
        fraseSemParceiro: "Antes de cada visita, conheço o comprador e confirmo como vai pagar.",
    },
    estudo: {
        frase: "Diga-me a freguesia e respondo com o valor mediano das vendas por m² do INE e com o que vejo no terreno.",
    },
};

export const SOBRE = {
    titulo: "Sobre",
    trabalhoCom: "Trabalho com",
    processo: "Ver como trabalho, passo a passo",
};

export const CONTACTO = {
    titulo: "Contacto",
    lead: "Escreva-me pelo formulário ou pelo WhatsApp. Respondo pessoalmente.",
    whatsapp: "Prefere o WhatsApp?",
};

export const NAO_ENCONTRADO = {
    titulo: "Esta página não existe",
    texto: "A página que procura pode ter mudado de endereço ou já não estar disponível.",
    inicio: "Ir para a página inicial",
    contacto: "Contacto",
};

export const MARCADOR = (oQue: string) => `[por indicar: ${oQue}]`;

export const PRIVACIDADE = {
    titulo: "Política de privacidade",
    descricao: "Como são tratados os dados pessoais enviados pelos formulários deste site, e os seus direitos.",
    versao: "Versão",
    seccoes: {
        responsavel: "Quem é o responsável pelos seus dados",
        dados: "Que dados recolhemos",
        dadosTexto: "Os que indica nos formulários: o nome, o email, o telefone e a mensagem e, conforme o pedido, a zona e o tipo de imóvel, as características que procura ou a disponibilidade para uma visita. Guardamos também a origem da visita (por exemplo, a campanha de onde chegou), a data e a versão desta política que aceitou. Não guardamos o endereço IP.",
        finalidades: "Para que usamos os dados",
        finalidadesItens: [
            "Para responder ao seu pedido e acompanhar o processo de compra, venda ou arrendamento.",
            "Para lhe enviar informação sobre imóveis e sobre o mercado, só se o autorizar.",
            "Para partilhar os seus dados com o parceiro de crédito, só no pedido de crédito e com a sua autorização expressa.",
        ],
        fundamento: "Com que fundamento",
        fundamentoTexto: "As diligências pré-contratuais feitas a seu pedido (artigo 6.º, n.º 1, alínea b), do Regulamento Geral sobre a Proteção de Dados) e o seu consentimento (alínea a)), que pode retirar a qualquer momento, sem afetar o tratamento já feito.",
        acesso: "Quem tem acesso",
        acessoTexto: "O responsável e a XPLENDOR, que aloja a plataforma deste site como subcontratante, com um contrato que a obriga a proteger os dados. O parceiro de crédito, só no caso descrito acima. Os dados não são vendidos a ninguém.",
        prazo: "Durante quanto tempo",
        prazoTexto: (meses: number) => `Durante ${meses} meses a contar do pedido, salvo se um contrato ou a lei obrigar a guardá-los mais tempo.`,
        direitos: "Os seus direitos",
        cookies: "Cookies",
        cookiesTexto: "As escolhas sobre os cookies estão na política de cookies.",
    },
    marcadores: { nome: "o nome legal do responsável", nif: "o NIF do responsável", morada: "a morada do responsável", email: "o email para os pedidos sobre dados pessoais", versao: "a versão desta política" },
};

export const COOKIES_PAGINA = {
    titulo: "Política de cookies",
    intro: "Os cookies são pequenos ficheiros guardados no seu navegador. Este site usa os necessários para funcionar e, só com a sua autorização, os de estatística e de marketing.",
    colunas: { nome: "Nome", finalidade: "Para que serve", duracao: "Duração", categoria: "Categoria" },
    linhas: {
        consentimento: { nome: "xp_consent", finalidade: "Guarda as suas escolhas de cookies e a versão da política.", duracao: "6 meses", categoria: "Necessário" },
        previsualizacao: { nome: "xp_preview", finalidade: "Só na pré-visualização do site pela equipa do consultor.", duracao: "1 hora", categoria: "Necessário" },
        origem: { nome: "xp_origin (armazenamento da sessão)", finalidade: "Guarda a origem da visita (por exemplo, a campanha) para a juntar a um pedido que envie pelo site.", duracao: "Até fechar o separador", categoria: "Necessário" },
        ga: { nome: "_ga, _ga_*", finalidade: "Google Analytics: estatística das páginas vistas.", duracao: "Até 2 anos", categoria: "Estatística, só com autorização" },
        pixel: { nome: "_fbp", finalidade: "Meta Pixel: medição dos anúncios no Facebook e no Instagram.", duracao: "3 meses", categoria: "Marketing, só com autorização" },
    },
    mudar: "Pode mudar as suas escolhas a qualquer momento.",
};

export const LEGAL = {
    titulo: "Informação legal",
    mediacao: "Mediação imobiliária",
    livro: "Livro de reclamações",
    livroTexto: "Pode apresentar uma reclamação no Livro de Reclamações Eletrónico.",
    livroLigacao: "Abrir o Livro de Reclamações",
    ral: "Resolução alternativa de litígios",
    ralTexto: "Em caso de litígio de consumo, pode recorrer a uma das seguintes entidades de resolução alternativa de litígios:",
    ralPortal: "Mais informação no Portal do Consumidor (www.consumidor.gov.pt).",
    plataforma: "Plataforma",
    plataformaTexto: "Este site funciona na plataforma XPLENDOR.",
    marcadores: { mediacao: "o nome legal da empresa de mediação e a licença AMI", livro: "a ligação do livro de reclamações", ral: "as entidades de resolução alternativa de litígios" },
};

/* ── F3: imóveis, empreendimentos e vídeos ─────────────────────────────── */

export const FICHA = {
    rascunho: "Rascunho",
    reservado: "Reservado",
    vendido: "Vendido",
    arrendado: "Arrendado",
    sobConsulta: "Preço sob consulta",
    porMes: "por mês",
    energia: "Classe energética",
    classe: "Classe",
    energiaIsento: "Isento",
    preCertificado: "pré-certificado",
    galeria: "Fotografias",
    fotografia: "fotografia",
    anterior: "Fotografia anterior",
    seguinte: "Fotografia seguinte",
    video: "Vídeo",
    verVideo: "Ver o vídeo",
    caracteristicas: "Características",
    descricao: "Descrição",
    localizacao: "Localização",
    localizacaoAproximada: (lugar: string, raio: number) => `Localização aproximada: ${lugar}. A casa fica dentro de um círculo com cerca de ${raio} m de raio, nunca no centro marcado.`,
    localizacaoExata: (lugar: string) => `Localização do empreendimento: ${lugar}.`,
    quero: "Quero visitar",
    whatsapp: "Falar pelo WhatsApp",
    visitaTitulo: "Marcar uma visita",
    visitaTexto: "Diga-me quando lhe dá jeito. Combino consigo o dia e a hora.",
    pedirVisita: "Pedir a visita",
    verNaRede: (rede: string) => (rede ? `Ver na ${rede}` : "Ver no site da rede"),
    referencia: "Referência",
    mediacao: (nome: string, ami: string) => `Mediação: ${nome}, licença AMI n.º ${ami}.`,
    campos: {
        tipo: "Tipo", negocio: "Negócio", tipologia: "Tipologia", quartos: "Quartos", casasDeBanho: "Casas de banho", areaUtil: "Área útil",
        areaBruta: "Área bruta", estado: "Estado", freguesia: "Freguesia", concelho: "Concelho", empreendimento: "Empreendimento",
    },
    negocio: { venda: "Venda", arrendamento: "Arrendamento" } as Record<string, string>,
    estados: { usado: "Usado", novo: "Novo", em_construcao: "Em construção" } as Record<string, string>,
    voltar: "Ver todos os imóveis",
};

export const IMOVEIS_LISTA = {
    titulo: "Imóveis",
    lead: "Os imóveis que acompanho neste momento, com as fotografias, as características e a classe energética de cada um.",
    filtros: "Filtrar",
    tipo: "Tipo",
    tipologia: "Tipologia",
    zona: "Zona",
    estado: "Estado da construção",
    precoMin: "Preço mínimo",
    precoMax: "Preço máximo",
    todos: "Todos",
    todas: "Todas",
    aplicar: "Ver resultados",
    limpar: "Limpar os filtros",
    resultados: (n: number) => (n === 1 ? "1 imóvel" : `${n} imóveis`),
    vazio: "Não há imóveis com estes filtros.",
    vazioSemFiltros: "Neste momento não há imóveis publicados. Diga-me o que procura e aviso-o quando entrar algo assim.",
    falarComigo: "Dizer o que procuro",
    anterior: "Página anterior",
    seguinte: "Página seguinte",
    pagina: (p: number, de: number) => `Página ${p} de ${de}`,
};

export const EMPREENDIMENTO = {
    titulo: "Empreendimentos",
    lead: "Empreendimentos em construção: as frações disponíveis, a fase da obra, a data prevista e o plano de pagamentos.",
    vazio: "Neste momento não há empreendimentos publicados.",
    fase: "Fase da obra",
    conclusao: "Conclusão prevista",
    energiaPrevista: "Classe energética prevista",
    planoPagamentos: "Plano de pagamentos",
    fracoes: "Frações",
    fracoesDisponiveis: (n: number) => (n === 1 ? "1 fração disponível" : `${n} frações disponíveis`),
    semFracoes: "As frações deste empreendimento ainda não estão publicadas. Pergunte-me pelas disponíveis.",
    colunas: { fracao: "Fração", tipologia: "Tipologia", area: "Área útil", preco: "Preço", estado: "Estado" },
    disponivel: "Disponível",
    desde: "desde",
    guia: "Como funciona comprar na planta",
    guiaTexto: "Antes de assinar, convém perceber o contrato-promessa, o sinal e os riscos de comprar uma casa que ainda não existe.",
};

export const VIDEOS = {
    titulo: "Vídeos",
    lead: "Os imóveis e os empreendimentos em vídeo, um de cada vez.",
    verImovel: "Ver o imóvel",
    verEmpreendimento: "Ver o empreendimento",
    quero: "Quero visitar",
    tocar: "Tocar para ver o vídeo",
    ajuda: "Deslize para cima ou use as setas do teclado para mudar de vídeo.",
    vazio: "Ainda não há vídeos publicados.",
    anterior: "Vídeo anterior",
    seguinte: "Vídeo seguinte",
};

export const GUIA_PLANTA = {
    titulo: "Como funciona comprar na planta",
    lead: "Comprar uma casa em construção pode permitir escolher a fração e os acabamentos e pagar ao longo da obra. Também tem riscos que convém conhecer antes de assinar. Este guia é informação geral e não substitui o aconselhamento jurídico.",
    seccoes: [
        {
            titulo: "O contrato-promessa",
            texto: [
                "Na compra em planta, o primeiro passo é o contrato-promessa de compra e venda: o promotor promete vender e o comprador promete comprar, com o preço, a fração, os prazos e as condições de pagamento escritos.",
                "Quando o contrato-promessa é de um imóvel em construção, a lei exige o reconhecimento presencial das assinaturas e a certificação da existência da licença de construção (Código Civil, artigo 410.º, n.º 3).",
                "Antes de assinar, peça a descrição da fração, a planta, o mapa de acabamentos e o prazo previsto para a escritura.",
            ],
        },
        {
            titulo: "O sinal e os pagamentos",
            texto: [
                "O valor entregue no contrato-promessa é, em regra, sinal. Se o comprador desistir sem motivo, perde o sinal; se for o promotor a não cumprir, tem de devolver o sinal em dobro (Código Civil, artigo 442.º), salvo o que o contrato disser de outra forma dentro da lei.",
                "Muitos empreendimentos têm um plano de pagamentos ao longo da obra. Confirme o que paga em cada fase e o que fica para a escritura.",
            ],
        },
        {
            titulo: "Os riscos",
            texto: [
                "A obra pode atrasar, e a data da escritura pode mudar. Veja o que o contrato prevê para os atrasos.",
                "O projeto pode ter alterações. Confirme o que pode mudar sem o seu acordo.",
                "Se precisar de crédito, a aprovação definitiva só acontece perto da escritura, com as condições desse momento.",
                "Pergunte que garantias existem para os valores entregues antes da escritura.",
            ],
        },
        {
            titulo: "Os impostos e as despesas",
            texto: [
                "O IMT e o imposto do selo pagam-se antes da escritura, sobre o valor da compra, conforme as regras em vigor nessa data.",
            ],
        },
    ],
    falar: "Falar sobre um empreendimento",
    descricao: "O contrato-promessa, o sinal, os pagamentos e os riscos de comprar uma casa em construção, explicados de forma simples.",
};
