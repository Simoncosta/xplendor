<?php

/*
 * R2 (documents/NOITE-ACL-R2.md, parte 3): onde ficam os ficheiros PRIVADOS que crescem, além
 * da Linha Editorial (essa já escolhe o disco em config/media.php, MEDIA_DISK).
 *  · private_disk: os PDFs e comprovativos das cobranças e as faturas do OCR. Por omissão o
 *    disco local "local"; PRIVATE_FILES_DISK=r2 passa a usar o Cloudflare R2.
 *  · external_fetch_ttl: segundos de validade de um endereço assinado para um serviço externo
 *    ir buscar o ficheiro (a Meta, na F2 da publicação): 1 hora por omissão (o R2 aceita até 7 dias).
 * Mudar o disco não move ficheiros: a migração é o comando php artisan storage:migrate-to-r2.
 */
return [
    'private_disk' => env('PRIVATE_FILES_DISK', 'local'),
    'external_fetch_ttl' => (int) env('MEDIA_EXTERNAL_FETCH_TTL', 3600),
];
