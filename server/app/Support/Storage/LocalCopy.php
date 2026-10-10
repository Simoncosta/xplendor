<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * R2: o ffmpeg, as miniaturas, o OCR e os PDFs precisam de um ficheiro no disco. Num disco
 * local usa-se o caminho real; num disco S3 (R2) o worker trabalha numa CÓPIA TEMPORÁRIA,
 * apagada no fim (mesmo se o trabalho falhar).
 */
final class LocalCopy
{
    public static function isLocal(Filesystem $disk): bool
    {
        return $disk instanceof FilesystemAdapter && $disk->getAdapter() instanceof LocalFilesystemAdapter;
    }

    /**
     * @template T
     * @param callable(string): T $fn recebe um caminho local com o conteúdo do ficheiro
     * @return T
     */
    public static function with(Filesystem $disk, string $path, callable $fn): mixed
    {
        if (self::isLocal($disk)) {
            return $fn($disk->path($path));
        }
        $tmp = self::tempPath(pathinfo($path, PATHINFO_EXTENSION));
        $in = $disk->readStream($path);
        if (! $in) {
            throw new \RuntimeException("Não foi possível ler o ficheiro {$path}.");
        }
        $out = fopen($tmp, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($out);
        if (is_resource($in)) {
            fclose($in);
        }
        try {
            return $fn($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** Um caminho temporário local (com a extensão, para o ffmpeg e o Intervention reconhecerem o tipo). */
    public static function tempPath(string $extension = ''): string
    {
        $dir = storage_path('app/tmp-copias');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . '/' . bin2hex(random_bytes(12)) . ($extension !== '' ? '.' . $extension : '');
    }

    /** Envia um ficheiro local para o disco (em stream, sem o carregar todo para memória). */
    public static function put(Filesystem $disk, string $path, string $localFile): void
    {
        if (self::isLocal($disk)) {
            $target = $disk->path($path);
            if (! is_dir(dirname($target))) {
                @mkdir(dirname($target), 0775, true);
            }
            copy($localFile, $target);

            return;
        }
        $in = fopen($localFile, 'rb');
        try {
            $disk->writeStream($path, $in);
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
        }
    }
}
