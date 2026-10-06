<?php

/*
 * Media da Linha Editorial (F3b): imagens, carrosséis e vídeos por versão de publicação.
 */
return [
    'disk' => env('MEDIA_DISK', 'media'),

    // Envio em partes (retomável). Cada parte cabe no upload_max_filesize do PHP (10 MB).
    'chunk_bytes' => 8 * 1024 * 1024,
    'max_image_bytes' => 30 * 1024 * 1024,
    'max_video_bytes' => 300 * 1024 * 1024,

    'image_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    'video_mimes' => ['video/mp4', 'video/quicktime'],
    'image_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
    'video_extensions' => ['mp4', 'mov'],

    // Quota por empresa (originais).
    'company_quota_mb' => (int) env('MEDIA_COMPANY_QUOTA_MB', 5120),

    // URLs assinados de curta duração.
    'signed_url_minutes' => 30,

    'retention' => [
        'superseded_days' => 30,            // media das versões substituídas
        'published_original_months' => 12, // originais das publicações publicadas (biblioteca da marca)
        'orphan_days' => 7,                 // enviados e nunca usados
        'upload_session_hours' => 24,       // envios em partes por concluir
    ],

    // Aviso à equipa quando o disco do servidor passar desta percentagem.
    'disk_alert_percent' => 70,

    'ffmpeg' => env('FFMPEG_BIN', 'ffmpeg'),
    'ffprobe' => env('FFPROBE_BIN', 'ffprobe'),
];
