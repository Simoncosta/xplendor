<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Media da Linha Editorial (F3b): privado, nunca servido diretamente pelo nginx.
        // Os ficheiros saem só por URLs assinados de curta duração (MediaFileController).
        'media' => [
            'driver' => 'local',
            'root' => storage_path('app/media'),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        // Cloudflare R2 (compatível com S3): bucket PRIVADO. Os ficheiros saem só por endereços
        // assinados de curta duração, gerados pelo backend depois da verificação de empresa e do
        // ACL. Em dev aponta para o MinIO do docker-compose (R2_ENDPOINT=http://minio:9000).
        // Fica em uso só quando MEDIA_DISK=r2 e/ou PRIVATE_FILES_DISK=r2 (config/storage_targets.php).
        'r2' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => env('R2_REGION', 'auto'),
            'bucket' => env('R2_BUCKET'),
            'endpoint' => env('R2_ENDPOINT'),
            // Os endereços assinados são assinados com este endpoint (o que o browser e a Meta
            // usam). Em produção é o mesmo do R2_ENDPOINT; em dev, o MinIO visto de fora do Docker.
            'public_endpoint' => env('R2_PUBLIC_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('R2_PATH_STYLE', true),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
