<?php

declare(strict_types=1);

namespace App\Services\Financing\Antivirus;

use Illuminate\Support\Facades\Storage;

/**
 * ClamAV (o clamd do contentor xplendor-clamav, na rede interna, porta 3310): o ficheiro vai pelo
 * comando INSTREAM, lido do disco onde está (local ou R2), sem o copiar para outro sítio.
 */
final class ClamAvScanner implements VirusScanner
{
    private ?string $signature = null;

    public function __construct(private readonly ClamdClient $client) {}

    public function configured(): bool
    {
        return true;
    }

    public function ping(): bool
    {
        return $this->client->ping();
    }

    public function scan(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            throw new \RuntimeException('Ficheiro não encontrado para análise.');
        }
        try {
            [$result, $this->signature] = $this->client->scan($stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $result;
    }

    public function lastSignature(): ?string
    {
        return $this->signature;
    }
}
