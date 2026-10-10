<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Storage\StorageMigration;
use Illuminate\Console\Command;

/**
 * R2: apaga a cópia LOCAL dos ficheiros já copiados e confirmados no R2. SIMULAÇÃO por omissão.
 * Só apaga quando o ficheiro continua no R2 com o mesmo tamanho E já é lido de lá (os media pelo
 * media_assets.disk; as fotos depois de MEDIA_DISK=r2; as cobranças e o OCR depois de
 * PRIVATE_FILES_DISK=r2). Correr só depois de uns dias com tudo a funcionar no R2.
 *   php artisan storage:purge-local             → simulação
 *   php artisan storage:purge-local --execute   → apaga
 */
class StoragePurgeLocalCommand extends Command
{
    protected $signature = 'storage:purge-local {--execute : Apaga de facto (sem isto, só simula)} {--target=r2 : O disco de destino}';

    protected $description = 'Apaga a cópia local dos ficheiros já confirmados no R2 (simulação por omissão).';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $this->line($execute ? 'A apagar as cópias locais confirmadas…' : 'SIMULAÇÃO (nada é apagado). Use --execute para apagar.');
        $stats = (new StorageMigration((string) $this->option('target')))->purgeLocal($execute,
            fn (string $line) => $this->output->isVerbose() ? $this->line("  {$line}") : null);
        $this->table(['Ficheiros verificados', 'MB a libertar', 'Apagados', 'Mantidos'], [[
            $stats['ficheiros'], round($stats['bytes'] / 1048576, 1), $stats['apagados'], $stats['mantidos'],
        ]]);

        return self::SUCCESS;
    }
}
