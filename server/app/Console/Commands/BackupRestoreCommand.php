<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backups\DatabaseBackup;
use Illuminate\Console\Command;

/**
 * Restaura uma cópia do bucket para um ficheiro .sql local (decifra e descomprime). Não importa nada:
 * a importação faz-se à mão, numa base à escolha (por exemplo uma base de ensaio), para nunca se
 * escrever por cima da base em uso sem uma decisão de uma pessoa. Sem caminho, lista as cópias.
 */
class BackupRestoreCommand extends Command
{
    protected $signature = 'backup:restore {path? : o caminho da cópia no bucket} {--latest : a cópia mais recente} {--to= : o ficheiro .sql de destino}';

    protected $description = 'Descarrega, decifra e descomprime uma cópia da base de dados para um ficheiro .sql (sem importar).';

    public function handle(DatabaseBackup $backup): int
    {
        try {
            $copies = $backup->list();
            $path = $this->argument('path') ?: ($this->option('latest') ? ($copies[0]['path'] ?? null) : null);
            if (! $path) {
                $this->line(count($copies) . ' cópia(s) no bucket:');
                foreach ($copies as $c) {
                    $this->line("  {$c['path']}  ({$c['taken_at']})");
                }
                $this->line('Indique o caminho ou use --latest.');

                return self::SUCCESS;
            }
            $to = $this->option('to') ?: storage_path('app/backups-tmp/restauro-' . basename($path, '.gz.enc'));
            $file = $backup->restore($path, $to);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Restaurado para {$file} (" . number_format(filesize($file) / 1048576, 2, ',', ' ') . ' MB).');
        $this->line('Para importar numa base de ensaio: mariadb -u <utilizador> -p <base_de_ensaio> < ' . $file);

        return self::SUCCESS;
    }
}
