<?php

/*
 * Radar de oportunidade (Bússola Master, R1): as fontes públicas lidas de 15 em 15 minutos
 * (tarefa externa radar-collect-feeds). Só entram fontes cujos termos de uso foram lidos e
 * permitem este uso (documents/bussola-master/DESENHO.md, secção 2); as que ficaram de fora
 * (RTP, JN, DN, Jornal de Negócios, TSF, Expresso, SIC Notícias, Renascença, EMMI, BPstat)
 * estão só no desenho.
 *
 * De cada item guarda-se apenas o título, a ligação, a fonte, a hora e o volume aproximado,
 * nunca o corpo do artigo; tudo é apagado ao fim de retention_days (radar-purge-items).
 * Desligar uma fonte é mudar o enabled dela aqui.
 */
return [
    // O interruptor geral do Radar (P1 do fecho): desligado por omissão em todos os ambientes.
    // Desligado, a recolha não corre (nem pela tarefa, nem pelo comando sem --force), a relevância
    // e o encaixe não correm, e o painel do root diz "Radar desligado". O módulo radar de cada
    // empresa é outra coisa: continua desligado até o root o ligar.
    'enabled' => (bool) env('RADAR_ENABLED', false),

    // Identifica a XPLENDOR junto de cada fonte (sem cookies nem autenticação).
    'user_agent' => 'XPLENDOR-Radar/1.0 (leitura de feeds; contacto no site da XPLENDOR)',

    // O teto da noite do pedido: no máximo 4 pedidos por fonte e por hora (conta também os
    // falhados, os 304 e a leitura diária do robots.txt). Só o --force do comando passa por cima.
    'max_requests_per_hour' => 4,

    // Itens, tendências e recolhas com mais de 30 dias são apagados (às 03:20 de Lisboa).
    'retention_days' => 30,

    // Pedidos HTTP: sem repetições automáticas; uma resposta acima de max_bytes é um erro.
    'timeout' => 15,
    'connect_timeout' => 5,
    'max_bytes' => 2 * 1024 * 1024,

    'sources' => [
        'google_trends' => [
            'label' => 'Google Trends',
            'url' => 'https://trends.google.com/trending/rss?geo=PT',
            'type' => 'trends',
            'interval_minutes' => 15,
            'enabled' => true,
            'source_name' => 'Google Trends',
            'attribution' => 'Fonte: Google Trends',
            'terms_url' => 'https://policies.google.com/terms?hl=pt-PT',
            'terms_checked_at' => '2026-10-12',
            'terms_note' => 'Leitura automática permitida enquanto o robots.txt não proibir /trending; citar sempre o Google Trends.',
            // Lido uma vez por dia (cache de 24 horas); se /trending passar a Disallow, a fonte não é lida.
            'robots_url' => 'https://trends.google.com/robots.txt',
            'robots_path' => '/trending',
        ],
        'jornal_eco' => [
            'label' => 'ECO',
            'url' => 'https://eco.sapo.pt/feed/',
            'type' => 'news',
            'interval_minutes' => 15,
            'enabled' => true,
            'source_name' => 'ECO',
            'attribution' => 'Fonte: ECO',
            'terms_url' => 'https://eco.sapo.pt/termos-e-condicoes/',
            'terms_checked_at' => '2026-10-12',
            'terms_note' => 'Termos omissos sobre feeds; o feed traz o artigo inteiro, que é descartado à entrada.',
        ],
        'jornal_publico' => [
            'label' => 'Público',
            'url' => 'https://feeds.feedburner.com/PublicoRSS',
            'type' => 'news',
            'interval_minutes' => 15,
            'enabled' => true,
            'source_name' => 'Público',
            'attribution' => 'Fonte: Público',
            'terms_url' => 'https://www.publico.pt/nos/termos-e-condicoes',
            'terms_checked_at' => '2026-10-12',
            'terms_note' => 'Termos omissos sobre feeds; feed público anunciado pelo próprio jornal.',
        ],
        'jornal_observador' => [
            'label' => 'Observador',
            'url' => 'https://observador.pt/feed/',
            'type' => 'news',
            'interval_minutes' => 15,
            'enabled' => true,
            'source_name' => 'Observador',
            'attribution' => 'Fonte: Observador',
            'terms_url' => 'https://observador.pt/termos-e-condicoes/',
            'terms_checked_at' => '2026-10-12',
            'terms_note' => 'Termos omissos sobre feeds; feed público anunciado pelo próprio jornal.',
        ],
        'bce_press' => [
            'label' => 'Banco Central Europeu',
            'url' => 'https://www.ecb.europa.eu/rss/press.html',
            'type' => 'bce',
            'interval_minutes' => 15,
            'enabled' => true,
            'source_name' => 'Banco Central Europeu',
            'attribution' => 'Fonte: Banco Central Europeu (informação disponível gratuitamente em ecb.europa.eu)',
            'terms_url' => 'https://www.ecb.europa.eu/services/using-our-site/disclaimer/html/index.en.html',
            'terms_checked_at' => '2026-10-12',
            'terms_note' => 'Reutilização livre com citação da fonte; só entram as decisões de política monetária.',
        ],
        'ipma_avisos' => [
            'label' => 'IPMA: avisos meteorológicos',
            'url' => 'https://api.ipma.pt/open-data/forecast/warnings/warnings_www.json',
            'type' => 'ipma',
            'interval_minutes' => 15,
            // Os termos de 2017 restringem a fins não lucrativos: desligada até à confirmação escrita do IPMA.
            'enabled' => false,
            'disabled_reason' => 'Os termos de 2017 restringem a fins não lucrativos; à espera da confirmação escrita do IPMA.',
            'source_name' => 'IPMA',
            'attribution' => 'Fonte: IPMA, Instituto Português do Mar e da Atmosfera (www.ipma.pt)',
            'terms_url' => 'https://api.ipma.pt/',
            'terms_checked_at' => '2026-10-12',
            'terms_note' => 'Atribuição, logótipo e ligação obrigatórios; uso gratuito só sem fins lucrativos (a confirmar).',
            'page_url' => 'https://www.ipma.pt/pt/otempo/prev-sam/',
        ],
    ],
];
