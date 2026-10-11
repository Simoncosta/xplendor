<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RecomputeStandCompassJob;
use App\Models\Company;
use App\Services\CompanyModuleService;
use Illuminate\Console\Command;

/**
 * O interruptor da Bússola do stand, por empresa (nasce desligado; só o root o liga, como o da
 * restauração). Ao ligar, calcula os sinais.
 */
class BussolaStandSwitchCommand extends Command
{
    protected $signature = 'bussola:stand-switch {company : o número da empresa} {state : on ou off}';
    protected $description = 'Liga ou desliga a Bússola do stand numa empresa (só com o módulo de stock).';

    public function handle(CompanyModuleService $modules): int
    {
        $company = Company::find((int) $this->argument('company'));
        $state = (string) $this->argument('state');
        if (! $company || ! in_array($state, ['on', 'off'], true)) {
            $this->error('Uso: bussola:stand-switch {empresa} {on|off}');

            return self::FAILURE;
        }
        if ($state === 'on' && ! $modules->isEnabled($company->id, 'stock')) {
            $this->error('Esta empresa não tem o módulo de stock: a Bússola do stand não se liga.');

            return self::FAILURE;
        }
        $company->forceFill(['bussola_stand_enabled' => $state === 'on'])->save();
        if ($state === 'on') {
            RecomputeStandCompassJob::dispatch($company->id);
        }
        $this->info(sprintf('Bússola do stand %s em %s.', $state === 'on' ? 'ligada' : 'desligada', $company->trade_name ?: $company->fiscal_name));

        return self::SUCCESS;
    }
}
