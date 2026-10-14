<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CompanyModuleService;
use Illuminate\Console\Command;

/**
 * Radar: liga ou desliga o módulo radar numa empresa (o mesmo que o interruptor do painel do
 * root). Sem RADAR_ENABLED=true, a empresa não recebe sinais novos mesmo com o módulo ligado.
 *
 *   php artisan radar:switch 1 on
 *   php artisan radar:switch 1 off
 */
class RadarSwitchCommand extends Command
{
    protected $signature = 'radar:switch {company : O id da empresa} {state : on ou off}';

    protected $description = 'Radar: liga ou desliga o módulo radar numa empresa.';

    public function handle(CompanyModuleService $modules): int
    {
        $company = Company::find((int) $this->argument('company'));
        $state = (string) $this->argument('state');
        if (! $company || ! in_array($state, ['on', 'off'], true)) {
            $this->error('Use: radar:switch {id da empresa} on|off (a empresa tem de existir).');

            return self::FAILURE;
        }
        $state === 'on' ? $modules->enable($company->id, 'radar') : $modules->disable($company->id, 'radar');
        $this->info(($state === 'on' ? 'Radar ligado' : 'Radar desligado') . " na empresa {$company->id}.");

        return self::SUCCESS;
    }
}
