<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;

/**
 * R2: endereços assinados de curta duração para ficheiros de um disco S3 (o Cloudflare R2, ou o
 * MinIO em dev). O bucket é privado: um ficheiro só sai por aqui, e só depois de o backend ter
 * verificado a empresa e a permissão (ACL). A assinatura é feita com o endpoint PÚBLICO (o que o
 * browser ou a Meta usam), porque o host faz parte da assinatura; não há pedido nenhum ao R2.
 */
final class SignedFileUrl
{
    public static function supports(string $disk): bool
    {
        return config("filesystems.disks.{$disk}.driver") === 's3';
    }

    /** Endereço assinado válido por $seconds segundos (o R2 aceita até 7 dias). */
    public static function for(string $disk, string $path, int $seconds, ?string $downloadName = null): string
    {
        $cfg = config("filesystems.disks.{$disk}");
        $endpoint = $cfg['public_endpoint'] ?? null ?: $cfg['endpoint'] ?? null;
        $client = new S3Client([
            'version' => 'latest',
            'region' => $cfg['region'] ?? 'auto',
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => (bool) ($cfg['use_path_style_endpoint'] ?? true),
            'credentials' => ['key' => (string) $cfg['key'], 'secret' => (string) $cfg['secret']],
        ]);
        $args = ['Bucket' => $cfg['bucket'], 'Key' => ltrim(($cfg['root'] ?? '') . '/' . $path, '/')];
        if ($downloadName !== null) {
            $args['ResponseContentDisposition'] = 'inline; filename="' . addslashes($downloadName) . '"';
        }
        $seconds = max(1, min($seconds, 604800));

        return (string) $client->createPresignedRequest($client->getCommand('GetObject', $args), "+{$seconds} seconds")->getUri();
    }

    /** Para a Meta (F2 da publicação): o endereço que ela vai buscar, válido por config storage_targets.external_fetch_ttl. */
    public static function forExternalFetch(string $disk, string $path): string
    {
        return self::for($disk, $path, (int) config('storage_targets.external_fetch_ttl', 3600));
    }

    /** O disco local existe e a rota que o serve deve continuar a servir (sem R2). */
    public static function isLocal(string $disk): bool
    {
        return config("filesystems.disks.{$disk}.driver") === 'local' && Storage::disk($disk) !== null;
    }
}
