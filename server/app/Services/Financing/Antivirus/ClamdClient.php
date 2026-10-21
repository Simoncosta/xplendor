<?php

declare(strict_types=1);

namespace App\Services\Financing\Antivirus;

/**
 * Fala com o clamd (ClamAV) pela rede interna do Docker, porta 3310, com os comandos do
 * protocolo: PING, VERSION e INSTREAM (o ficheiro vai em partes, cada uma precedida do tamanho
 * em 4 bytes; um tamanho 0 fecha o envio). Sem ligação ou sem resposta: ClamdUnavailable.
 */
final class ClamdClient
{
    private const CHUNK = 65536;

    public function __construct(private readonly string $host, private readonly int $port, private readonly int $timeout) {}

    public static function fromConfig(?int $timeout = null): self
    {
        return new self((string) config('financing.antivirus.host'), (int) config('financing.antivirus.port'), $timeout ?? (int) config('financing.antivirus.timeout'));
    }

    public function ping(): bool
    {
        try {
            return $this->command('PING') === 'PONG';
        } catch (ClamdUnavailable) {
            return false;
        }
    }

    /** "ClamAV 1.5.4/27800/Tue Oct 20 08:21:03 2026": o motor, a versão e a data das assinaturas. */
    public function version(): string
    {
        return $this->command('VERSION');
    }

    /**
     * Analisa um conteúdo (recurso aberto para leitura).
     *
     * @param resource $stream
     * @return array{0: string, 1: ?string} [clean|infected, assinatura]
     */
    public function scan($stream): array
    {
        $socket = $this->connect();
        try {
            $this->write($socket, "zINSTREAM\0");
            while (! feof($stream)) {
                $chunk = (string) fread($stream, self::CHUNK);
                if ($chunk !== '') {
                    $this->write($socket, pack('N', strlen($chunk)) . $chunk);
                }
            }
            $this->write($socket, pack('N', 0));
            $reply = $this->read($socket);
        } finally {
            fclose($socket);
        }
        if (preg_match('/^stream: OK$/', $reply)) {
            return [VirusScanner::CLEAN, null];
        }
        if (preg_match('/^stream: (.+) FOUND$/', $reply, $m)) {
            return [VirusScanner::INFECTED, $m[1]];
        }
        // Ex.: "INSTREAM size limit exceeded. ERROR": nunca se dá como limpo.
        throw new \RuntimeException('Resposta inesperada do antivírus: ' . mb_substr($reply, 0, 120));
    }

    public function scanString(string $content): array
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $content);
        rewind($stream);
        try {
            return $this->scan($stream);
        } finally {
            fclose($stream);
        }
    }

    private function command(string $command): string
    {
        $socket = $this->connect();
        try {
            $this->write($socket, "z{$command}\0");

            return $this->read($socket);
        } finally {
            fclose($socket);
        }
    }

    /** @return resource */
    private function connect()
    {
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, $this->timeout);
        if (! $socket) {
            throw new ClamdUnavailable("O antivírus não responde em {$this->host}:{$this->port} ({$errno}).");
        }
        stream_set_timeout($socket, $this->timeout);

        return $socket;
    }

    /** @param resource $socket */
    private function write($socket, string $data): void
    {
        $len = strlen($data);
        for ($done = 0; $done < $len; $done += $n) {
            $n = @fwrite($socket, substr($data, $done));
            if ($n === false || $n === 0) {
                throw new ClamdUnavailable('A ligação ao antivírus caiu durante o envio.');
            }
        }
    }

    /** @param resource $socket */
    private function read($socket): string
    {
        $reply = (string) stream_get_contents($socket);
        if ($reply === '' && (stream_get_meta_data($socket)['timed_out'] ?? false)) {
            throw new ClamdUnavailable('O antivírus não respondeu a tempo.');
        }
        if ($reply === '') {
            throw new ClamdUnavailable('O antivírus fechou a ligação sem responder.');
        }

        return trim($reply, "\0\r\n ");
    }
}
