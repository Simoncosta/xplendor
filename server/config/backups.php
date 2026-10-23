<?php

/*
 * Cópias diárias da base de dados, cifradas e fora da VPS (um bucket próprio no R2, com uma chave
 * só sua; no dev, o MinIO). Tudo desligado por omissão: sem BACKUP_ENABLED=true nada corre, e sem
 * o bucket e a chave de cifra o comando recusa-se a começar.
 *
 * O dump usa o cliente da MariaDB (mariadb-dump), que tem de estar na imagem do PHP (ver o
 * relatório da noite 7). A cópia é comprimida (gzip) e cifrada por blocos com o libsodium
 * (XChaCha20-Poly1305, secretstream) antes de sair da máquina.
 */
return [
    'enabled' => (bool) env('BACKUP_ENABLED', false),

    // O disco de destino (filesystems.disks.backups) e a pasta dentro do bucket.
    'disk' => 'backups',
    'prefix' => trim((string) env('BACKUP_PREFIX', 'db'), '/'),

    // 32 bytes em base64 (por exemplo: php -r "echo base64_encode(random_bytes(32));"). Sem ela, não há cópia.
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),

    // Dias de retenção: as cópias mais antigas apagam-se depois de cada cópia nova.
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    // O programa do dump (no PATH do contentor) e o tempo máximo, em segundos.
    'dump_binary' => env('BACKUP_DUMP_BINARY', 'mariadb-dump'),
    'timeout' => (int) env('BACKUP_TIMEOUT', 3600),
];
