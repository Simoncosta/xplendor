<?php

/**
 * IA da XPLENDOR: fornecedores, modelos (com os PREÇOS, a única fonte para o custo de cada
 * pedido) e funções. O modelo de cada função é escolhido pelo root em Administração › Modelos
 * de IA (tabela ai_function_settings); aqui ficam os valores iniciais. O OCR das faturas também
 * aparece ali (ocr_text e ocr_image); sem escolha gravada, vale a reserva do .env
 * (OCR_MODEL_TEXT e OCR_MODEL_IMAGE).
 *
 * Fontes (consultadas a 2026-10-07):
 *  · Anthropic: platform.claude.com/docs/en/about-claude/pricing, .../models/opus-5-5/whats-new-opus-5-5
 *  · OpenAI: developers.openai.com/api/docs/models/gpt-6.1-sol, .../api/docs/pricing
 */
return [
    'providers' => [
        'anthropic' => [
            'label' => 'Anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1/messages'),
            'version' => '2023-06-01',
        ],
        'openai' => [
            'label' => 'OpenAI',
            'key' => env('OPENAI_KEY'),
            'url' => env('OPENAI_RESPONSES_URL', 'https://api.openai.com/v1/responses'),
        ],
    ],

    // Preços em USD por milhão de tokens. O raciocínio é cobrado como saída nos dois fornecedores.
    'models' => [
        // Claude Fable 5.1: preços POR CONFIRMAR (null: o custo fica desconhecido até os pôr aqui).
        // "default" = não enviar o esforço (vale o do modelo).
        'claude-fable-5-1' => [
            'label' => 'Claude Fable 5.1', 'provider' => 'anthropic', 'efforts' => ['default', 'low', 'medium', 'high'],
            'prices' => null,
        ],
        'claude-opus-5-5' => [
            'label' => 'Claude Opus 5.5', 'provider' => 'anthropic', 'efforts' => ['low', 'medium', 'high'],
            'prices' => ['input' => 4.00, 'cached_input' => 0.20, 'output' => 20.00],
        ],
        'claude-sonnet-5-5' => [
            'label' => 'Claude Sonnet 5.5', 'provider' => 'anthropic', 'efforts' => ['low', 'medium', 'high'],
            'prices' => ['input' => 2.00, 'cached_input' => 0.10, 'output' => 10.00],
        ],
        'gpt-6.1-sol' => [
            'label' => 'GPT-6.1 Sol', 'provider' => 'openai', 'efforts' => ['low', 'medium', 'high'],
            'prices' => ['input' => 2.00, 'cached_input' => 0.10, 'output' => 10.00],
        ],
    ],

    'default_model' => 'claude-opus-5-5',

    // Espaço extra para o raciocínio no limite de tokens de saída (o raciocínio conta para ele).
    'reasoning_headroom' => ['low' => 4000, 'medium' => 12000, 'high' => 32000, 'default' => 32000],

    // As funções. max_tokens: o texto da resposta, sem o raciocínio. As do OCR (group "ocr") têm
    // a reserva do .env (reserve_model) e não entram no "Aplicar a todas as funções".
    'functions' => [
        'caption' => ['label' => 'Legenda', 'hint' => 'Gerar legenda no painel da publicação', 'default_effort' => 'low', 'max_tokens' => 3000],
        'creative' => ['label' => 'Criativo', 'hint' => 'Sugerir criativo', 'default_effort' => 'low', 'max_tokens' => 2500],
        'ideas' => ['label' => 'Ideias', 'hint' => 'Gerar ideias do mês', 'default_effort' => 'medium', 'max_tokens' => 6000],
        'blog' => ['label' => 'Blog', 'hint' => 'Ajudar a escrever um artigo', 'default_effort' => 'medium', 'max_tokens' => 6000],
        'brand_profile' => ['label' => 'Perfil da Marca', 'hint' => 'Sugerir perfil', 'default_effort' => 'low', 'max_tokens' => 3000],
        'car_description' => ['label' => 'Descrição de viaturas', 'hint' => 'Gerar descrição da viatura', 'default_effort' => 'low', 'max_tokens' => 800],
        'car_analysis' => ['label' => 'Análise de viaturas', 'hint' => 'Análise de mercado e público da viatura', 'default_effort' => 'medium', 'max_tokens' => 2500],
        'family_categories' => ['label' => 'Categorias das famílias', 'hint' => 'Sugerir a categoria das famílias da restauração (as que as regras não reconhecem)', 'default_effort' => 'low', 'max_tokens' => 1500],
        'bussola_jogadas' => ['label' => 'Jogadas da Bússola', 'hint' => 'O que fazer em cada jogada da semana (restauração)', 'default_effort' => 'low', 'max_tokens' => 1200],
        'ocr_text' => ['label' => 'OCR das faturas: PDF com texto', 'hint' => 'Ler as faturas de fornecedor em PDF com texto (o QR da AT é lido antes, sem IA)',
            'default_effort' => 'low', 'max_tokens' => 16000, 'group' => 'ocr', 'reserve_model' => 'services.openai.ocr.model_text'],
        'ocr_image' => ['label' => 'OCR das faturas: digitalizadas e fotografias', 'hint' => 'Ler as faturas em PDF sem texto e as fotografias; também a 2.ª tentativa quando as linhas não conferem com o QR',
            'default_effort' => 'low', 'max_tokens' => 16000, 'group' => 'ocr', 'reserve_model' => 'services.openai.ocr.model_image'],
    ],

    // Instrução de sistema comum a TODA a IA (antes da instrução de cada função).
    'pt_pt_instruction' => 'Escreva sempre em português de Portugal, num registo formal. Trate o leitor por "a sua marca" ou de forma impessoal, nunca por "você". '
        . 'Use o vocabulário de Portugal (por exemplo, "telemóvel", "equipa", "contacto", "registo", "ecrã", "ficheiro", "autocarro") e a construção "estar a + infinitivo" '
        . '(por exemplo, "estamos a preparar"), nunca o gerúndio do Brasil. Não use travessões: para separar ideias use vírgula, dois pontos ou ponto final.',
];
