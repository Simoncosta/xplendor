<?php

/**
 * Envio seguro de documentos para financiamento: os limites de cada link, a retenção e o
 * antivírus. O antivírus (ClamAV num contentor, ANTIVIRUS_DRIVER=clamav) é obrigatório em
 * produção antes de clientes reais enviarem documentos: sem ele, require_antivirus impede criar
 * links e receber ficheiros.
 */
return [
    // Validade do link (dias): por omissão e o máximo que o vendedor pode escolher.
    'link_days_default' => 7,
    'link_days_max' => 30,

    // Limites por link: ficheiros, tamanho de cada ficheiro e o total.
    'max_files' => 20,
    'max_files_per_item' => 6,
    'max_file_bytes' => 10 * 1024 * 1024,
    'max_link_bytes' => 100 * 1024 * 1024,

    // Retoma (pedido de proposta): o código de envio vale 24 horas; até 25 ficheiros por retoma.
    'trade_in_hours' => 24,
    'trade_in_max_files' => 25,

    // Retenção: dias depois do último envio (por empresa, entre o mínimo e o máximo), o aviso
    // aos administradores antes do apagamento e os links nunca usados (dias depois de expirarem).
    'retention_days_default' => 90,
    'retention_days_min' => 30,
    'retention_days_max' => 365,
    'warn_days_before' => 7,
    'unused_link_grace_days' => 30,

    // A versão dos textos do aviso de proteção de dados e do consentimento da cópia do documento de identificação.
    'notice_version' => '2026-11',

    'require_antivirus' => (bool) env('FINANCING_REQUIRE_ANTIVIRUS', env('APP_ENV', 'production') === 'production'),
    // O ClamAV corre no contentor xplendor-clamav (docker-compose*.yml), só na rede interna.
    'antivirus' => [
        'driver' => env('ANTIVIRUS_DRIVER', 'none'), // none | clamav
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
    ],
];
