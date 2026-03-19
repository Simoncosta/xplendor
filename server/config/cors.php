<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://xplendor.tech',
        'https://www.xplendor.tech',
        'https://paautomoveis.pt',
        'https://www.paautomoveis.pt',
        'https://spacedrive.pt',
        'https://www.spacedrive.pt',
        'https://standspacedrive.pt',
        'https:/www.standspacedrive.pt',

        // Localhost
        'https://277d-2001-818-c523-5900-5084-d9fa-97d0-679.ngrok-free.app',

        'http://localhost:3000',
        'http://localhost:8001',
        'http://localhost:5173',
        'http://127.0.0.1:5500',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
