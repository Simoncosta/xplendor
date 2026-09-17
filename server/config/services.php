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
    ],

    'openai' => [
        'key' => env('OPENAI_KEY'),
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
];
