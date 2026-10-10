<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Storage\StorageMigration;
use Illuminate\Console\Command;

/**
 * R2: copia os ficheiros existentes para o R2. SIMULAÇÃO por omissão; com --execute copia,
 * confirma cada ficheiro (tamanho e SHA-256) e regista em storage_migration_items. Retomável e
 * idempotente. NÃO apaga o local (isso é o storage:purge-local).
 *   php artisan storage:migrate-to-r2                     → simulação
 *   php artisan storage:migrate-to-r2 --execute           → copia e confirma
 *   php artisan storage:migrate-to-r2 --execute --only=media --limit=500
 */
class StorageMigrateToR2Command extends Command
{
    protected $signature = 'storage:migrate-to-r2
        {--execute : Copia de facto (sem isto, só simula)}
        {--only= : Só estes tipos, separados por vírgulas: media, avatar, cobranca, ocr, fatura_ticket, foto_relatorio}
        {--limit= : No máximo este número de ficheiros copiados nesta execução}
        {--target=r2 : O disco de destino}';

    protected $description = 'Copia os ficheiros para o R2 (simulação por omissão), com confirmação de tamanho e SHA-256.';

    public function handle(): int
    {
        $kinds = $this->option('only') ? array_values(array_intersect(StorageMigration::KINDS, array_map('trim', explode(',', (string) $this->option('only'))))) : StorageMigration::KINDS;
        $execute = (bool) $this->option('execute');
        $migration = new StorageMigration((string) $this->option('target'));
        $this->line($execute ? 'A copiar e a confirmar…' : 'SIMULAÇÃO (nada é escrito). Use --execute para copiar.');

        $stats = $migration->run($execute, $kinds, $this->option('limit') ? (int) $this->option('limit') : null,
            fn (string $line) => $this->output->isVerbose() ? $this->line("  {$line}") : null);

        $this->table(['Ficheiros', 'MB', 'Copiados', 'Já estavam', 'Em falta', 'Falhas', 'Assets a ler do R2'], [[
            $stats['ficheiros'], round($stats['bytes'] / 1048576, 1), $stats['copiados'], $stats['ja_estavam'], $stats['em_falta'], $stats['falhas'], $stats['assets_no_r2'],
        ]]);
        foreach (array_slice($migration->errors, 0, 20) as $e) {
            $this->error($e);
        }

        return $stats['falhas'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
