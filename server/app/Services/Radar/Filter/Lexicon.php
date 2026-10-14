<?php

declare(strict_types=1);

namespace App\Services\Radar\Filter;

/**
 * Radar: as listas do filtro de segurança (DESENHO.md, secção 5), por motivo. Escrevem-se com
 * acentos, para se lerem; o filtro compara sem acentos e em minúsculas, por isso "vítima" e
 * "vitima" contam igual. Cada entrada é uma palavra inteira ou uma expressão; "*" no fim é um
 * radical ("namor*" apanha namoro, namorada, namorados).
 *
 * O filtro é deliberadamente conservador: na dúvida, bloqueia. Se um motivo tirar demasiado,
 * ajusta-se a lista com os exemplos do painel do root (Administração › Radar), não o princípio.
 * Algumas palavras inglesas existem porque o Google Trends traz notícias relacionadas em inglês.
 */
final class Lexicon
{
    /** A ordem dos motivos no painel e nas respostas. */
    public const REASONS = ['tragedia', 'morte', 'acidente', 'crime', 'doenca', 'politica', 'religiao', 'conflito', 'vida_pessoal'];

    public const FLAGS = ['nome_pessoa', 'marca_protegida', 'desporto'];

    public const LABELS = [
        'tragedia' => 'Tragédia',
        'morte' => 'Morte',
        'acidente' => 'Acidente',
        'crime' => 'Crime',
        'doenca' => 'Doença',
        'politica' => 'Política',
        'religiao' => 'Religião',
        'conflito' => 'Conflito',
        'vida_pessoal' => 'Nome de pessoa (vida pessoal)',
        'nome_pessoa' => 'Nome de pessoa',
        'marca_protegida' => 'Marca protegida',
        'desporto' => 'Desporto',
    ];

    /** Bloqueiam (no título, nos títulos das notícias relacionadas e nas categorias do feed). */
    public const BLOCKING = [
        'tragedia' => [
            'tragédia', 'tragédias', 'trágico', 'trágica', 'incêndio', 'incêndios', 'explosão', 'explosões', 'naufrágio',
            'desabamento', 'derrocada', 'catástrofe', 'catástrofes', 'sismo', 'terramoto', 'tsunami', 'cheias', 'inundação',
            'inundações', 'furacão', 'evacuação', 'evacuados', 'desaparecido', 'desaparecida', 'desaparecidos', 'sobreviventes',
            'queda de avião', 'blast', 'explosion', 'disaster',
        ],
        'morte' => [
            'morte', 'mortes', 'morto', 'mortos', 'morta', 'mortas', 'morreu', 'morrem', 'morreram', 'morrer', 'matou', 'mataram',
            'faleceu', 'faleceram', 'falecimento', 'funeral', 'funerais', 'óbito', 'óbitos', 'velório', 'luto', 'vítima',
            'vítimas', 'cadáver', 'suicídio', 'perdeu a vida', 'perde a vida', 'sem vida', 'killed', 'kills', 'dead', 'death',
            'dies', 'died',
        ],
        'acidente' => [
            'acidente', 'acidentes', 'colisão', 'colisões', 'despiste', 'despistou', 'atropelamento', 'atropelado',
            'atropelada', 'choque frontal', 'capotamento', 'capotou', 'descarrilamento', 'ferido', 'ferida', 'feridos',
            'feridas', 'crash', 'injured', 'injures', 'wounded',
        ],
        'crime' => [
            'crime', 'crimes', 'criminoso', 'criminosa', 'homicídio', 'homicídios', 'assassinato', 'assassinado',
            'assassinada', 'assassino', 'violação', 'violada', 'abuso sexual', 'agressão', 'agredido', 'agredida', 'assalto',
            'assaltado', 'assaltada', 'roubo', 'roubos', 'furto', 'furtos', 'rapto', 'raptado', 'raptada', 'sequestro',
            'tráfico', 'droga', 'cocaína', 'haxixe', 'detido', 'detida', 'detidos', 'detidas', 'detenção', 'preso', 'presos',
            'prisão', 'condenado', 'condenada', 'condenados', 'tribunal', 'julgamento', 'acusado', 'acusada', 'arguido',
            'arguida', 'arguidos', 'burla', 'fraude', 'corrupção', 'branqueamento', 'pj', 'gnr', 'psp', 'polícia',
            'polícia judiciária', 'tiroteio', 'esfaqueado', 'esfaqueamento', 'ladrão', 'ladrões', 'apreensão', 'apreendeu',
            'apreendidos', 'suspeito', 'suspeitos', 'murder', 'shooting', 'arrested',
        ],
        'doenca' => [
            'doença', 'doenças', 'cancro', 'tumor', 'surto', 'epidemia', 'pandemia', 'vírus', 'gripe', 'covid', 'internado',
            'internada', 'internados', 'hospital', 'hospitais', 'hospitalizado', 'hospitalizada', 'urgências', 'ébola',
            'sarampo', 'infeção', 'infetados', 'contágio', 'dengue', 'varíola', 'tuberculose', 'legionella', 'meningite',
            'sida', 'intoxicação', 'intoxicados', 'estado de saúde',
        ],
        'politica' => [
            'governo', 'governos', 'governante', 'governantes', 'ministro', 'ministra', 'ministros', 'ministério',
            'primeiro-ministro', 'presidente da república', 'parlamento', 'assembleia da república', 'deputado', 'deputada',
            'deputados', 'eleições', 'eleição', 'eleitoral', 'eleitorais', 'partido', 'partidos', 'moção', 'orçamento do estado',
            'oe20*', 'autárquicas', 'legislativas', 'presidenciais', 'campanha eleitoral', 'voto', 'votos', 'votação',
            'referendo', 'autarca', 'secretário-geral', 'secretário de estado', 'congressista', 'senador', 'senadora',
            'casa branca', 'kremlin', 'psd', 'pcp', 'cds', 'cds-pp', 'bloco de esquerda', 'iniciativa liberal',
            'partido socialista', 'aliança democrática',
        ],
        'religiao' => [
            'papa', 'igreja', 'igrejas', 'missa', 'missas', 'fátima', 'peregrinação', 'peregrinos', 'bispo', 'bispos',
            'cardeal', 'padre', 'padres', 'freira', 'santuário', 'vaticano', 'muçulman*', 'islâmic*', 'islão', 'judaic*',
            'judeu', 'judeus', 'evangélic*', 'religião', 'religioso', 'religiosa', 'cristão', 'cristã', 'cristãos',
            'católico', 'católica', 'católicos', 'mesquita', 'sinagoga', 'ramadão', 'jeová',
        ],
        'conflito' => [
            'guerra', 'guerras', 'ataque', 'ataques', 'atacado', 'atacada', 'bombardeamento', 'bombardeamentos', 'míssil',
            'mísseis', 'drone militar', 'drones militares', 'exército', 'militar', 'militares', 'tropas', 'invasão',
            'cessar-fogo', 'trégua', 'refugiados', 'terrorismo', 'terrorista', 'terroristas', 'atentado', 'atentados',
            'combatentes', 'gaza', 'ucrânia', 'ucraniano', 'ucranianos', 'rússia', 'russo', 'russos', 'russa', 'russas',
            'israel', 'israelita', 'israelitas', 'israeli', 'irão', 'iraniano', 'hamas', 'hezbollah', 'palestina',
            'palestiniano', 'palestinianos', 'nato', 'otan', 'war', 'attack', 'missile',
        ],
    ];

    /**
     * Siglas e nomes próprios que só contam com maiúsculas (em minúsculas são palavras comuns:
     * "ps", "be", "chega", "livre"). Não se aplicam a títulos todos em maiúsculas.
     */
    public const BLOCKING_CASE_SENSITIVE = [
        'politica' => ['PS', 'IL', 'BE', 'PAN', 'PM', 'AD', 'JSD', 'JS', 'Chega', 'Livre'],
    ];

    /** Categorias do feed que contam como o motivo (ou a marca) respetivo. */
    public const CATEGORY_REASONS = ['política' => 'politica', 'justiça' => 'crime', 'religião' => 'religiao'];

    public const CATEGORY_FLAGS = ['desporto' => 'desporto'];

    /** Marcas protegidas: passa, e o cartão avisa para não usar os nomes nem os logótipos oficiais. */
    public const PROTECTED_BRANDS = [
        'champions', 'champions league', 'liga dos campeões', 'mundial 20*', 'campeonato do mundo', 'campeonato da europa',
        'euro 20*', 'jogos olímpicos', 'olimpíadas', 'paralímpicos', 'uefa', 'fifa', 'super bowl', 'nba', 'nfl',
        'fórmula 1', 'fórmula um', 'óscares', 'grammy', 'grammys', 'eurovisão', 'tour de france', 'volta a frança',
        'wimbledon', 'roland garros', 'globos de ouro', 'emmy', 'emmys', 'rock in rio', 'web summit',
    ];

    /** "Mundial" só com maiúscula (em minúsculas é "líder mundial", "economia mundial"). */
    public const PROTECTED_BRANDS_CASE_SENSITIVE = ['Mundial'];

    /**
     * Desporto: passa com a marca; só chega às empresas que o pedirem (R2). "Porto", "Braga" e
     * "Guimarães" não estão na lista de propósito: são cidades, e o clube só conta com outra
     * palavra de desporto ("FC Porto", "jogo", "treinador").
     */
    public const SPORTS = [
        'futebol', 'jogo', 'jogos', 'golo', 'golos', 'benfica', 'sporting', 'fc porto', 'sc braga', 'vitória de guimarães',
        'vitória sc', 'seleção', 'seleção nacional', 'liga portugal', 'primeira liga', 'segunda liga', 'i liga', 'liga betclic',
        'liga europa', 'liga conferência', 'liga das nações', 'taça', 'taça de portugal', 'supertaça', 'treinador',
        'árbitro', 'ténis', 'ciclismo', 'surf', 'atletismo', 'maratona', 'f1', 'motogp', 'basquetebol', 'andebol', 'hóquei',
        'futsal', 'voleibol', 'râguebi', 'rugby', 'golfe', 'natação', 'judo', 'triatlo', 'boxe', 'ufc', 'plantel', 'avançado',
        'guarda-redes', 'penálti', 'dérbi', 'derby', 'jornada', 'campeonato', 'serie a', 'la liga', 'premier league',
        'bundesliga', 'ligue 1', 'champions',
    ];

    /** Evento público: um nome de pessoa só passa com uma destas palavras (e nenhuma de vida pessoal). */
    public const PUBLIC_EVENT = [
        'jogo', 'jogos', 'partida', 'concerto', 'concertos', 'estreia', 'final', 'finais', 'meia-final', 'espetáculo',
        'espetáculos', 'festival', 'festivais', 'prémio', 'prémios', 'gala', 'cerimónia', 'estádio', 'digressão', 'torneio',
        'campeonato', 'jornada', 'exposição', 'mostra', 'atuação', 'ao vivo', 'álbum', 'filme', 'golo', 'golos', 'empate',
        'vitória', 'derrota', 'convocatória', 'convocados',
    ];

    /** Vida pessoal: um nome de pessoa com uma destas palavras é sempre bloqueado. */
    public const PERSONAL_LIFE = [
        'casou', 'casaram', 'casamento', 'casar', 'noivado', 'noiva', 'noivo', 'namor*', 'divórcio', 'divorciou',
        'divorciados', 'grávida', 'gravidez', 'filho', 'filha', 'filhos', 'filhas', 'bebé', 'separação', 'separou',
        'separaram', 'traição', 'traiu', 'relação', 'fotografias', 'fotos', 'biquíni', 'polémic*', 'intimidade',
        'vida privada', 'onlyfans', 'nora', 'genro',
    ];

    /** Ligam palavras de um nome ("Rui Calvário da Mota"). */
    public const NAME_CONNECTORS = ['da', 'de', 'do', 'das', 'dos', 'del', 'di', 'van', 'von', 'd'];

    /**
     * Não são pessoas: lugares, instituições, marcas, clubes e substantivos comuns que os títulos
     * escrevem com maiúscula. Uma sequência de maiúsculas com uma destas palavras não é um nome.
     * Não se põem aqui apelidos comuns ("Costa", "Mota", "Silva"), para os nomes não escaparem.
     */
    public const NOT_PERSON = [
        // Lugares
        'portugal', 'lisboa', 'porto', 'braga', 'coimbra', 'aveiro', 'faro', 'setúbal', 'évora', 'beja', 'leiria',
        'santarém', 'viseu', 'guarda', 'bragança', 'vila real', 'viana do castelo', 'castelo branco', 'portalegre',
        'madeira', 'açores', 'funchal', 'ponta delgada', 'porto santo', 'algarve', 'alentejo', 'minho', 'douro',
        'trás-os-montes', 'ribatejo', 'beira', 'sintra', 'cascais', 'oeiras', 'amadora', 'almada', 'seixal', 'barreiro',
        'gaia', 'matosinhos', 'guimarães', 'famalicão', 'barcelos', 'chiado', 'belém', 'alfama', 'marquês de pombal',
        'rossio', 'alcântara', 'parque das nações', 'sines', 'nazaré', 'peniche', 'óbidos', 'tomar', 'porto de mós',
        'régua', 'canaveses', 'caparica', 'costa da caparica', 'ericeira', 'comporta', 'lagos', 'albufeira', 'portimão',
        'tavira', 'olhão', 'loulé', 'vilamoura', 'serra da estrela', 'gales', 'espanha', 'frança', 'itália', 'alemanha',
        'inglaterra', 'reino unido', 'irlanda', 'holanda', 'bélgica', 'suíça', 'áustria', 'grécia', 'noruega', 'suécia',
        'dinamarca', 'finlândia', 'polónia', 'rússia', 'ucrânia', 'china', 'japão', 'índia', 'brasil', 'angola',
        'moçambique', 'cabo verde', 'guiné-bissau', 'são tomé', 'timor', 'macau', 'eua', 'estados unidos', 'américa',
        'nova iorque', 'new york', 'washington', 'londres', 'paris', 'madrid', 'barcelona', 'roma', 'milão', 'berlim',
        'bruxelas', 'ceuta', 'marrocos', 'médio oriente', 'oriente', 'europa', 'áfrica', 'ásia', 'mediterrâneo',
        'atlântico', 'ilha do fogo', 'maranhão', 'são paulo', 'rio de janeiro', 'veneza', 'venezia', 'líbano', 'israel',
        'gaza', 'irão', 'xangai', 'fairford',
        // Instituições e cargos
        'governo', 'ministério', 'ministro', 'ministra', 'câmara', 'municipal', 'comissão', 'europeia', 'europeu',
        'europeus', 'união', 'assembleia', 'república', 'parlamento', 'conselho', 'tribunal', 'supremo', 'polícia',
        'judiciária', 'segurança', 'social', 'banco', 'central', 'instituto', 'universidade', 'faculdade', 'escola',
        'hospital', 'centro', 'serviço', 'serviços', 'nacional', 'nações', 'unidas', 'autoridade', 'agência', 'direção',
        'geral', 'estado', 'estados', 'unidos', 'presidente', 'presidência', 'secretário', 'secretária', 'partido', 'bloco',
        'esquerda', 'direita', 'iniciativa', 'liberal', 'socialista', 'democrata', 'aliança', 'livre', 'chega', 'igreja',
        'santa', 'santo', 'são', 'nossa senhora', 'metro', 'linha', 'comboios', 'aeroporto', 'estádio', 'arena', 'coliseu',
        'teatro', 'museu', 'fundação', 'gulbenkian', 'onu', 'nato', 'ue', 'fmi', 'ocde', 'bce', 'ecb', 'mai', 'siresp',
        'igac', 'anecra', 'casa branca', 'kremlin', 'governing council', 'monetary policy',
        // Eventos e datas
        'festival', 'prémio', 'prémios', 'nobel', 'gala', 'óscares', 'globos de ouro', 'jogos', 'olímpicos', 'mundial',
        'mundiais', 'liga', 'taça', 'campeões', 'champions', 'league', 'natal', 'páscoa', 'carnaval', 'ano novo', 'verão',
        'inverno', 'outono', 'primavera', 'black friday', 'dia da mãe', 'dia do pai', 'dia dos namorados', 'segunda-feira',
        'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira',
        // Clubes e desporto
        'futebol', 'clube', 'seleção', 'sporting', 'benfica', 'fc', 'sc', 'académica', 'vitória de guimarães', 'marítimo',
        'boavista', 'belenenses', 'estoril', 'arouca', 'rio ave', 'moreirense', 'gil vicente', 'casa pia', 'farense',
        'tondela', 'inter', 'milan', 'juventus', 'napoli', 'lazio', 'real madrid', 'atlético', 'bayern', 'manchester',
        'liverpool', 'chelsea', 'arsenal', 'tottenham', 'psg', 'ajax', 'felgueiras', 'atalanta', 'serie a', 'enilive',
        'worldtour', 'pinarello', 'trek', 'motogp',
        // Marcas, empresas e meios de comunicação
        'big brother', 'all stars', 'netflix', 'disney', 'hbo', 'amazon', 'google', 'apple', 'microsoft', 'facebook',
        'instagram', 'whatsapp', 'tiktok', 'youtube', 'spotify', 'twitter', 'openai', 'chatgpt', 'tesla', 'galp', 'edp',
        'meo', 'vodafone', 'altice', 'sonae', 'continente', 'pingo doce', 'lidl', 'aldi', 'auchan', 'mercadona', 'ikea',
        'zara', 'worten', 'fnac', 'ctt', 'tap', 'ryanair', 'easyjet', 'taag', 'mota-engil', 'hotels', 'hotel', 'resort',
        'national geographic', 'condé nast', 'traveller', 'truth social', 'onlyfans', 'cnn', 'bbc', 'rtp', 'sic', 'tvi',
        'sapo', 'público', 'expresso', 'observador', 'eco', 'jornal', 'notícias', 'diário', 'correio da manhã', 'rádio',
        'canal', 'revista', 'times', 'record', 'a bola', 'renascença', 'tsf', 'lusa', 'reuters', 'bloomberg', 'thyssenkrupp',
        'citroën', 'admiral', 'verspieren',
        // Substantivos comuns com maiúscula nos títulos e nas categorias dos feeds
        'alta velocidade', 'fact check', 'capital verde', 'local online', 'corporate', 'opinião', 'atualidade', 'defesa',
        'energia', 'economia', 'mercados', 'negócios', 'empresas', 'turismo', 'regiões', 'infraestruturas', 'trabalho',
        'combustíveis', 'seguradoras', 'mediação', 'media', 'jornalismo', 'televisão', 'cibersegurança', 'automóveis',
        'mobilidade', 'consumo', 'sustentável', 'conferências', 'biodiversidade', 'reciclagem', 'viagens', 'artes', 'cultura',
        'ciência', 'ciências', 'cinema', 'música', 'crítica', 'história', 'arqueologia', 'tecnologia', 'internet', 'comércio',
        'exportações', 'elétricos', 'petróleo', 'agricultura', 'educação', 'ensino', 'transporte', 'transportes',
        'ferroviário', 'legislação', 'pensões', 'pobreza', 'sociedade', 'país', 'meteorologia', 'céu', 'terra', 'mau tempo',
        'tempo quente', 'tempo frio', 'agitação', 'marítima', 'nevoeiro', 'neve', 'precipitação', 'trovoada', 'vento',
        'aviso', 'avisos', 'distrito', 'debate', 'quinzenal', 'deputados', 'macroeconomia', 'psicologia', 'família',
        'saúde', 'psiquiatria', 'programa', 'operação', 'guerra', 'conflito', 'eleições', 'orçamento',
    ];
}
