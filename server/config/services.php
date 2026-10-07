<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'meta' => [
        'app_id'     => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'redirect_uri' => env('META_REDIRECT_URI'),
        // Redes sociais (Instagram e Facebook). Vazio: deriva do redirect_uri dos anúncios
        // (.../api/oauth/meta/callback → .../api/oauth/meta/social/callback).
        'social_redirect_uri' => env('META_SOCIAL_REDIRECT_URI'),
        // A Meta já aprovou a app (App Review)? Enquanto não, o ecrã de gerar o link avisa que o
        // passo do Facebook só funciona para contas de teste.
        'app_approved' => (bool) env('META_APP_APPROVED', false),
    ],

    'openai' => [
        'key' => env('OPENAI_KEY'),
        // OCR de faturas de fornecedor (Fase A).
        'ocr_disk' => env('OCR_INVOICE_DISK', 'local'),        // disco onde a imagem original é guardada
        'ocr_monthly_cap' => (int) env('OCR_MONTHLY_CAP', 200), // teto de faturas OCR por empresa/mês (controlo de custo)
        // Blog: "Ajudar a escrever" / "a partir de uma publicação".
        'blog_ai_model' => env('BLOG_AI_MODEL', 'gpt-4o'),
        // Limite mensal de pedidos à IA por empresa, POR MODO (um modo não gasta o de outro).
        'ai_monthly_caps' => [
            'blog'          => (int) env('BLOG_AI_MONTHLY_CAP', 6),            // rascunhos de artigos
            'brand_profile' => (int) env('BRAND_PROFILE_AI_MONTHLY_CAP', 3),   // "Sugerir perfil"
            'creative'      => (int) env('CREATIVE_AI_MONTHLY_CAP', 40),       // "Sugerir criativo"
            'ideas'         => (int) env('IDEAS_AI_MONTHLY_CAP', 3),           // "Gerar ideias do mês"
            'caption'       => (int) env('CAPTION_AI_MONTHLY_CAP', 60),        // "Gerar legenda"
        ],
    ],

    // XPLENDOR — GA4 (Data API). Credencial única do SERVIDOR: uma Service
    // Account para toda a XPLENDOR. A chave (JSON) NUNCA vai no repo — vive num
    // ficheiro fora do versionamento, apontado por GA4_SA_CREDENTIALS (caminho
    // absoluto), OU o JSON inteiro em GA4_SA_CREDENTIALS_JSON (para ambientes
    // que injetam segredos por env). `sa_email` é só o email da SA a mostrar ao
    // cliente nas instruções ("adiciona este email como Visualizador").
    'ga4' => [
        'credentials'      => env('GA4_SA_CREDENTIALS'),       // caminho para o keyfile JSON
        'credentials_json' => env('GA4_SA_CREDENTIALS_JSON'),  // ou o JSON inline (opcional)
        'sa_email'         => env('GA4_SA_EMAIL'),             // mostrado na UI
        'cache_minutes'    => (int) env('GA4_CACHE_MINUTES', 720), // 12h por defeito
    ],

    'scraper' => [
        'token' => env('SCRAPER_API_TOKEN'),
    ],

    // XPLENDOR — PingWin (POS GrupoPIE cloud). Valores GLOBAIS, iguais a TODOS os
    // restaurantes na cloud partilhada GrupoPIE — vivem no .env, NÃO por empresa.
    // Por empresa (company_integrations) guardam-se só 3: username, database e a
    // senha (cifrada). O PingwinService compõe estes globais + os 3 da empresa.
    'pingwin' => [
        'auth_url'          => env('PINGWIN_AUTH_URL'),        // SOA partilhado GrupoPIE
        'api_url'           => env('PINGWIN_API_URL'),         // mesmo host partilhado
        // frontend_url VARIA por restaurante (deriva de https://{database}.mycloudpie.com
        // no PingwinService). Aqui fica só como OVERRIDE opcional (raro; vazio = derivar).
        'frontend_url'      => env('PINGWIN_FRONTEND_URL'),
        'app_version'       => env('PINGWIN_APP_VERSION'),     // segue a versão do servidor GrupoPIE
        'application'       => env('PINGWIN_APPLICATION', 'pbo_soa_2026.0'),  // constante do protocolo
        'app_grupopie'      => env('PINGWIN_APP_GRUPOPIE', 'PBOWEB'),          // constante do protocolo
        'report_id'         => env('PINGWIN_REPORT_ID_SALES'),  // relatório "Resumo de Vendas" (ver aviso no .env)
        // Relatório "Vendas por artigo" (loja × dia × artigo, F1 do marketing). Global da cloud
        // GrupoPIE (documents/PINGWIN-RELATORIOS-F0.md); só confirmado na Yuko.
        'item_sales_report_id' => env('PINGWIN_REPORT_ID_ITEM_SALES') ?: '919525121217',
        // ⚠️ As 3 chaves seguintes são DIFERENTES (não confundir — ver .env.example):
        'stores'            => env('PINGWIN_STORES', ''),       // CSV de winrest_store_id (filtro do relatório); o sync sobrepõe-no com as lojas cadastradas. NÃO é um dataset.
        'stores_dataset_id' => env('PINGWIN_STORES_DATASET_ID', ''), // dataset (browserdataset) de descoberta de lojas — fetch_stores (Yuko: 1099511639262)
        'catalog_dataset_id' => env('PINGWIN_CATALOG_DATASET_ID', ''), // dataset (browserdataset) do catálogo de artigos — fetch_catalog (Yuko: 1099511639254)
        'suppliers_dataset_id' => env('PINGWIN_SUPPLIERS_DATASET_ID', ''), // dataset (browserdataset) de fornecedores — fetch_suppliers (Yuko: 1099511639252)
        'paycond_dataset_id' => env('PINGWIN_PAYCOND_DATASET_ID', ''), // dataset (browserdataset) de condições de pagamento — fetch_payment_conditions (Yuko: 1099511639195)
        // ⚠️ As UNIDADES vivem numa PORTA DIFERENTE (8138, não a 8136). Override
        // explícito opcional; vazio → o cliente deriva do api_url trocando a porta.
        'units_url' => env('PINGWIN_UNITS_URL', ''),
        'allowed_hosts'     => env('PINGWIN_ALLOWED_HOSTS', ''), // allowlist de hosts (segurança SSL fraco)
    ],

    // XPLENDOR — CoverManager (reservas). HTTP normal. As credenciais (slug+token)
    // são POR LOJA; aqui só o base_url global (com default).
    'covermanager' => [
        'base_url' => env('COVERMANAGER_BASE_URL', 'https://www.covermanager.com'),
    ],
];
