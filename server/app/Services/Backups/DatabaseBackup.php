<?php

declare(strict_types=1);

namespace App\Services\Backups;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Cópias diárias da base de dados, cifradas e fora da VPS (config/backups.php).
 *  1. dump com o cliente da MariaDB (mariadb-dump, numa só transação, com rotinas e gatilhos);
 *  2. gzip e cifra por blocos (libsodium secretstream, XChaCha20-Poly1305): o ficheiro só sai da
 *     máquina cifrado e um ficheiro cortado ou alterado não se restaura;
 *  3. envio para o disco "backups" (um bucket próprio) e retenção: as cópias com mais de N dias saem;
 *  4. restauro: descarrega, decifra e descomprime para um ficheiro .sql (importa-se à mão, numa base
 *     à escolha, nunca por cima da base em uso sem decisão de uma pessoa).
 * O ficheiro: "XPBK1\n", o cabeçalho do secretstream e blocos [4 bytes de tamanho + bloco cifrado].
 */
final class DatabaseBackup
{
    public const MAGIC = "XPBK1\n";
    private const READ_BYTES = 1048576;
    private const NAME_PATTERN = '/xplendor-(\d{8})-(\d{6})\.sql\.gz\.enc$/';

    /** O que falta para se poder fazer uma cópia (vazio = pronto). @return list<string> */
    public function missing(): array
    {
        $out = [];
        if ($this->key() === null) {
            $out[] = 'BACKUP_ENCRYPTION_KEY (32 bytes em base64)';
        }
        if ((string) config('filesystems.disks.' . config('backups.disk') . '.bucket') === '') {
            $out[] = 'BACKUP_S3_BUCKET (e as chaves BACKUP_S3_KEY e BACKUP_S3_SECRET)';
        }

        return $out;
    }

    /**
     * Faz uma cópia: o dump (ou o ficheiro SQL indicado), cifra, envia e aplica a retenção.
     *
     * @return array{path: string, bytes: int, deleted: list<string>}
     */
    public function run(?string $fromFile = null, ?CarbonImmutable $now = null): array
    {
        if ($missing = $this->missing()) {
            throw new RuntimeException('Cópia não configurada: falta ' . implode('; ', $missing) . '.');
        }
        $now ??= CarbonImmutable::now('UTC');
        $dir = $this->tmpDir();
        $sql = $fromFile ?? "{$dir}/dump-" . bin2hex(random_bytes(6)) . '.sql';
        $enc = "{$dir}/" . self::name($now);
        try {
            if ($fromFile === null) {
                $this->dump($sql);
            } elseif (! is_file($fromFile)) {
                throw new RuntimeException("O ficheiro {$fromFile} não existe.");
            }
            self::encryptFile($sql, $enc, (string) $this->key());
            $path = $this->prefix() . self::name($now);
            $stream = fopen($enc, 'rb');
            try {
                Storage::disk(config('backups.disk'))->writeStream($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $bytes = (int) filesize($enc);
            $deleted = $this->prune($now);
            Log::info('[Cópias] Cópia da base de dados enviada', ['ficheiro' => $path, 'bytes' => $bytes, 'apagadas' => count($deleted)]);

            return ['path' => $path, 'bytes' => $bytes, 'deleted' => $deleted];
        } finally {
            if ($fromFile === null) {
                File::delete($sql);
            }
            File::delete($enc);
        }
    }

    /** As cópias no bucket, da mais recente para a mais antiga. @return list<array{path: string, taken_at: string}> */
    public function list(): array
    {
        $out = [];
        foreach (Storage::disk(config('backups.disk'))->files(rtrim($this->prefix(), '/')) as $file) {
            if ($at = self::takenAt($file)) {
                $out[] = ['path' => $file, 'taken_at' => $at->toIso8601String()];
            }
        }
        usort($out, fn ($a, $b) => strcmp($b['taken_at'], $a['taken_at']));

        return $out;
    }

    /** Apaga as cópias com mais dias do que a retenção. @return list<string> as apagadas */
    public function prune(?CarbonImmutable $now = null): array
    {
        $limit = ($now ?? CarbonImmutable::now('UTC'))->subDays(max(1, (int) config('backups.retention_days')));
        $old = array_values(array_filter(array_column($this->list(), 'path'), fn ($p) => self::takenAt($p)?->lt($limit) ?? false));
        if ($old !== []) {
            Storage::disk(config('backups.disk'))->delete($old);
        }

        return $old;
    }

    /** Restaura uma cópia do bucket para um ficheiro .sql local. Devolve o caminho do ficheiro. */
    public function restore(string $path, string $toSql): string
    {
        if ($this->key() === null) {
            throw new RuntimeException('Falta BACKUP_ENCRYPTION_KEY para decifrar a cópia.');
        }
        $enc = $this->tmpDir() . '/restore-' . bin2hex(random_bytes(6)) . '.enc';
        try {
            $in = Storage::disk(config('backups.disk'))->readStream($path);
            if (! $in) {
                throw new RuntimeException("A cópia {$path} não existe.");
            }
            $out = fopen($enc, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            File::ensureDirectoryExists(dirname($toSql));
            self::decryptFile($enc, $toSql, (string) $this->key());

            return $toSql;
        } finally {
            File::delete($enc);
        }
    }

    // ── o dump ──────────────────────────────────────────────────────────────────────────────

    private function dump(string $sqlPath): void
    {
        $db = (array) config('database.connections.' . config('database.default'));
        $command = [
            (string) config('backups.dump_binary'), '--host=' . ($db['host'] ?? '127.0.0.1'), '--port=' . ($db['port'] ?? 3306),
            '--user=' . ($db['username'] ?? ''), '--single-transaction', '--quick', '--routines', '--triggers', '--events', '--hex-blob',
            '--default-character-set=utf8mb4', '--result-file=' . $sqlPath, (string) ($db['database'] ?? ''),
        ];
        // A palavra-passe vai pelo ambiente (MYSQL_PWD), nunca na linha de comando.
        $result = Process::timeout((int) config('backups.timeout'))->env(['MYSQL_PWD' => (string) ($db['password'] ?? '')])->run($command);
        if (! $result->successful() || ! is_file($sqlPath) || filesize($sqlPath) === 0) {
            $err = trim($result->errorOutput()) ?: 'sem ficheiro do dump';
            throw new RuntimeException('O dump falhou: ' . mb_substr($err, 0, 300)
                . (str_contains($err, 'not found') || $result->exitCode() === 127 ? ' (o mariadb-dump não está no contentor; ver o relatório da noite 7)' : ''));
        }
    }

    // ── a cifra ─────────────────────────────────────────────────────────────────────────────

    public static function encryptFile(string $src, string $dst, string $key): void
    {
        $in = fopen($src, 'rb');
        $out = fopen($dst, 'wb');
        if (! $in || ! $out) {
            throw new RuntimeException('Não foi possível abrir os ficheiros da cópia.');
        }
        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            fwrite($out, self::MAGIC . $header);
            $gz = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
            while (! feof($in)) {
                $chunk = (string) fread($in, self::READ_BYTES);
                $compressed = deflate_add($gz, $chunk, ZLIB_NO_FLUSH);
                if ($compressed !== '') {
                    self::writeBlock($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, $compressed));
                }
            }
            $last = deflate_add($gz, '', ZLIB_FINISH);
            self::writeBlock($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, $last, '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL));
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public static function decryptFile(string $src, string $dst, string $key): void
    {
        $in = fopen($src, 'rb');
        $out = fopen($dst, 'wb');
        if (! $in || ! $out) {
            throw new RuntimeException('Não foi possível abrir os ficheiros do restauro.');
        }
        try {
            if (fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('Este ficheiro não é uma cópia da XPLENDOR.');
            }
            $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $gz = inflate_init(ZLIB_ENCODING_GZIP);
            $final = false;
            while (! $final) {
                $len = fread($in, 4);
                if ($len === false || strlen($len) < 4) {
                    throw new RuntimeException('A cópia está cortada (falta o fim).');
                }
                $block = (string) fread($in, unpack('N', $len)[1]);
                $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $block);
                if ($res === false) {
                    throw new RuntimeException('Não foi possível decifrar a cópia (chave errada ou ficheiro alterado).');
                }
                [$plain, $tag] = $res;
                fwrite($out, (string) inflate_add($gz, $plain, $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL ? ZLIB_FINISH : ZLIB_SYNC_FLUSH));
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private static function writeBlock($out, string $cipher): void
    {
        fwrite($out, pack('N', strlen($cipher)) . $cipher);
    }

    // ── nomes e configuração ────────────────────────────────────────────────────────────────

    public static function name(CarbonImmutable $at): string
    {
        return 'xplendor-' . $at->utc()->format('Ymd-His') . '.sql.gz.enc';
    }

    public static function takenAt(string $path): ?CarbonImmutable
    {
        return preg_match(self::NAME_PATTERN, $path, $m) ? CarbonImmutable::createFromFormat('YmdHis', $m[1] . $m[2], 'UTC') : null;
    }

    private function key(): ?string
    {
        $raw = base64_decode((string) config('backups.encryption_key'), true);

        return $raw !== false && strlen($raw) === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES ? $raw : null;
    }

    private function prefix(): string
    {
        $p = trim((string) config('backups.prefix'), '/');

        return $p === '' ? '' : "{$p}/";
    }

    private function tmpDir(): string
    {
        $dir = storage_path('app/backups-tmp');
        File::ensureDirectoryExists($dir, 0700);

        return $dir;
    }
}
