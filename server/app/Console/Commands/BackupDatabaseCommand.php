<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backups\DatabaseBackup;
use Illuminate\Console\Command;

/**
 * Uma cópia cifrada da base de dados no bucket das cópias (config/backups.php), com a retenção.
 * O agendamento diário só corre com BACKUP_ENABLED=true; à mão corre sempre que a cópia estiver
 * configurada. --from-file envia um ficheiro SQL já feito (por exemplo, para testar o envio e o
 * restauro sem o mariadb-dump no contentor).
 */
class BackupDatabaseCommand extends Command
{
    protected $signature = 'backup:database {--from-file= : um ficheiro SQL já feito, em vez do dump}';

    protected $description = 'Faz uma cópia cifrada da base de dados para o bucket das cópias e apaga as que passaram a retenção.';

    public function handle(DatabaseBackup $backup): int
    {
        if ($missing = $backup->missing()) {
            $this->error('A cópia não está configurada. Falta: ' . implode('; ', $missing) . '.');

            return self::FAILURE;
        }
        try {
            $out = $backup->run($this->option('from-file') ?: null);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Cópia enviada: {$out['path']} (" . number_format($out['bytes'] / 1048576, 2, ',', ' ') . ' MB).');
        $this->line(count($out['deleted']) . ' cópia(s) antiga(s) apagada(s) pela retenção de ' . (int) config('backups.retention_days') . ' dias.');

        return self::SUCCESS;
    }
}
