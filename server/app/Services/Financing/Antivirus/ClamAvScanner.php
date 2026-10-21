<?php

declare(strict_types=1);

namespace App\Services\Financing\Antivirus;

use Illuminate\Support\Facades\Storage;

/**
 * ClamAV (o clamd de um contentor na rede da plataforma, porta 3310): o ficheiro vai em partes
 * pelo comando INSTREAM e a resposta é "stream: OK" ou "stream: <assinatura> FOUND". Lê do disco
 * onde o ficheiro está (local ou R2), sem o copiar para outro sítio.
 */
final class ClamAvScanner implements VirusScanner
{
    private const CHUNK = 8192;

    public function __construct(private readonly string $host, private readonly int $port, private readonly int $timeout) {}

    public function configured(): bool
    {
        return true;
    }

    public function scan(string $disk, string $path): string
    {
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, $this->timeout);
        if (! $socket) {
            throw new \RuntimeException("O antivírus não respondeu ({$errno}).");
        }
        stream_set_timeout($socket, $this->timeout);
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            fclose($socket);
            throw new \RuntimeException('Ficheiro não encontrado para análise.');
        }
        try {
            fwrite($socket, "zINSTREAM\0");
            while (! feof($stream)) {
                $chunk = (string) fread($stream, self::CHUNK);
                if ($chunk === '') {
                    continue;
                }
                fwrite($socket, pack('N', strlen($chunk)) . $chunk);
            }
            fwrite($socket, pack('N', 0));
            $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
        } finally {
            fclose($stream);
            fclose($socket);
        }

        return match (true) {
            str_ends_with($reply, 'OK') => self::CLEAN,
            str_ends_with($reply, 'FOUND') => self::INFECTED,
            default => throw new \RuntimeException('Resposta inesperada do antivírus.'),
        };
    }
}
