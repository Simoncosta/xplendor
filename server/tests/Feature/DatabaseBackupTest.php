<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Backups\DatabaseBackup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cópias diárias cifradas fora da VPS: o dump (simulado), o gzip e a cifra por blocos, o envio para o
 * disco das cópias (simulado), a retenção de 30 dias, o restauro (que devolve o SQL igual) e as
 * recusas (sem chave, chave errada, ficheiro cortado). Nada sai da máquina nos testes.
 */
class DatabaseBackupTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
        $this->key = base64_encode(random_bytes(32));
        config(['backups.encryption_key' => $this->key, 'filesystems.disks.backups.bucket' => 'xplendor-backups', 'backups.prefix' => 'db']);
    }

    private function sql(int $lines = 5000): string
    {
        $out = "-- dump de teste\nCREATE TABLE t (id int, nome varchar(50));\n";
        for ($i = 0; $i < $lines; $i++) {
            $out .= "INSERT INTO t VALUES ({$i}, 'linha " . str_repeat('ção', $i % 7) . "');\n";
        }

        return $out;
    }

    private function fakeDump(string $sql, ?array &$seen = null): void
    {
        Process::fake(function (PendingProcess $p) use ($sql, &$seen) {
            $seen = ['command' => $p->command, 'env' => $p->environment];
            foreach ((array) $p->command as $part) {
                if (str_starts_with((string) $part, '--result-file=')) {
                    file_put_contents(substr((string) $part, strlen('--result-file=')), $sql);
                }
            }

            return Process::result('');
        });
    }

    public function test_a_backup_is_dumped_compressed_encrypted_and_sent_and_the_restore_gives_the_same_sql(): void
    {
        $sql = $this->sql();
        $this->fakeDump($sql, $seen);
        config(['database.connections.mysql.password' => 'segredo-de-teste', 'database.default' => 'mysql']);

        $this->artisan('backup:database')->assertSuccessful();
        $files = Storage::disk('backups')->files('db');
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^db\/xplendor-\d{8}-\d{6}\.sql\.gz\.enc$/', $files[0]);
        $stored = Storage::disk('backups')->get($files[0]);
        $this->assertStringStartsWith(DatabaseBackup::MAGIC, $stored);
        $this->assertStringNotContainsString('INSERT INTO', $stored, 'nunca sai em claro');
        $this->assertLessThan(strlen($sql), strlen($stored), 'comprimido');
        // A palavra-passe vai pelo ambiente, nunca na linha de comando; o dump numa só transação.
        $this->assertSame('segredo-de-teste', $seen['env']['MYSQL_PWD']);
        $this->assertStringNotContainsString('segredo-de-teste', implode(' ', (array) $seen['command']));
        $this->assertContains('--single-transaction', (array) $seen['command']);

        $to = storage_path('app/backups-tmp/teste-restauro.sql');
        $this->artisan('backup:restore', ['--latest' => true, '--to' => $to])->assertSuccessful();
        $this->assertSame($sql, file_get_contents($to));
        @unlink($to);
    }

    public function test_from_file_sends_a_ready_sql_without_the_dump(): void
    {
        Process::fake();
        $file = storage_path('app/backups-tmp/pronto.sql');
        @mkdir(dirname($file), 0700, true);
        file_put_contents($file, $this->sql(10));
        $this->artisan('backup:database', ['--from-file' => $file])->assertSuccessful();
        Process::assertNothingRan();
        $this->assertCount(1, Storage::disk('backups')->files('db'));
        $this->assertFileExists($file, 'o ficheiro indicado fica');
        @unlink($file);
    }

    public function test_retention_deletes_copies_older_than_30_days(): void
    {
        $now = CarbonImmutable::parse('2026-10-24 02:20:00', 'UTC');
        foreach (['2026-09-20 02:20:00', '2026-09-24 02:19:00', '2026-09-25 02:20:00', '2026-10-23 02:20:00'] as $at) {
            Storage::disk('backups')->put('db/' . DatabaseBackup::name(CarbonImmutable::parse($at, 'UTC')), 'x');
        }
        Storage::disk('backups')->put('db/outro-ficheiro.txt', 'fica');
        $deleted = app(DatabaseBackup::class)->prune($now);
        $this->assertSame(['db/xplendor-20260924-021900.sql.gz.enc', 'db/xplendor-20260920-022000.sql.gz.enc'], $deleted);
        $this->assertCount(3, Storage::disk('backups')->files('db'));
    }

    public function test_without_key_or_bucket_nothing_runs(): void
    {
        Process::fake();
        config(['backups.encryption_key' => null, 'filesystems.disks.backups.bucket' => null]);
        $this->artisan('backup:database')->assertFailed();
        Process::assertNothingRan();
        $this->assertSame([], Storage::disk('backups')->allFiles());
        config(['backups.encryption_key' => base64_encode('curta')]);
        $this->assertContains('BACKUP_ENCRYPTION_KEY (32 bytes em base64)', app(DatabaseBackup::class)->missing());
    }

    public function test_a_wrong_key_or_a_cut_file_never_restores(): void
    {
        $dir = storage_path('app/backups-tmp');
        @mkdir($dir, 0700, true);
        file_put_contents("{$dir}/a.sql", $this->sql(2000));
        DatabaseBackup::encryptFile("{$dir}/a.sql", "{$dir}/a.enc", base64_decode($this->key));

        try {
            DatabaseBackup::decryptFile("{$dir}/a.enc", "{$dir}/b.sql", random_bytes(32));
            $this->fail('Decifrou com a chave errada.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('chave errada', $e->getMessage());
        }
        $bytes = file_get_contents("{$dir}/a.enc");
        file_put_contents("{$dir}/cortado.enc", substr($bytes, 0, (int) (strlen($bytes) * 0.6)));
        $this->expectException(\RuntimeException::class);
        try {
            DatabaseBackup::decryptFile("{$dir}/cortado.enc", "{$dir}/c.sql", base64_decode($this->key));
        } finally {
            foreach (['a.sql', 'a.enc', 'b.sql', 'cortado.enc', 'c.sql'] as $f) {
                @unlink("{$dir}/{$f}");
            }
        }
    }

    public function test_a_failed_dump_says_what_is_missing_and_sends_nothing(): void
    {
        Process::fake(fn () => Process::result('', 'sh: mariadb-dump: not found', 127));
        $this->artisan('backup:database')->expectsOutputToContain('o mariadb-dump não está no contentor')->assertFailed();
        $this->assertSame([], Storage::disk('backups')->allFiles());
    }

    public function test_the_daily_schedule_only_runs_with_backup_enabled(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'database-backup-daily');
        $this->assertNotNull($event);
        $this->assertSame('20 3 * * *', $event->expression);
        $this->assertSame('Europe/Lisbon', (string) $event->timezone);
        $this->assertFalse((bool) config('backups.enabled'), 'desligado por omissão');
        $this->assertFalse($event->filtersPass($this->app));
        config(['backups.enabled' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }
}
