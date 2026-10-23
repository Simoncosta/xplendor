<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Agency\Meta\AgencyAssetLifecycle;
use Illuminate\Console\Command;

/**
 * Parceiro 5: a verificação diária dos ativos da Meta que as agências ligaram aos clientes. Lê os
 * portfólios (o que saiu passa a "sem acesso") e renova o token nas ligações dos clientes. Lê a Meta:
 * tarefa externa (só com SCHEDULE_EXTERNAL_JOBS).
 */
class AgencyMetaAssetsCheckCommand extends Command
{
    protected $signature = 'agency:meta-assets-check';

    protected $description = 'Agência: verifica os ativos da Meta ligados aos clientes (portfólios e token).';

    public function handle(AgencyAssetLifecycle $lifecycle): int
    {
        $out = $lifecycle->daily();
        $this->info($out === [] ? 'Nenhuma agência com ativos ligados.' : 'Agências verificadas: ' . count($out) . '.');

        return self::SUCCESS;
    }
}
