<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Interruptor por empresa da sincronização nova do PingWin (vendas por artigo,
 * F1 do marketing). Desligado por omissão: um deploy não a liga sozinho. Sem o
 * argumento state, só mostra o estado.
 *   php artisan pingwin:item-sales-switch 5        → mostra
 *   php artisan pingwin:item-sales-switch 5 on     → liga
 *   php artisan pingwin:item-sales-switch 5 off    → desliga
 */
class PingwinItemSalesSwitchCommand extends Command
{
    protected $signature = 'pingwin:item-sales-switch
        {company : ID da empresa}
        {state? : on para ligar, off para desligar; vazio só mostra}';

    protected $description = 'Mostra, liga ou desliga a sincronização das vendas por artigo do PingWin de uma empresa.';

    public function handle(): int
    {
        $company = Company::find((int) $this->argument('company'));
        if (! $company) {
            $this->error('Empresa não existe.');

            return self::FAILURE;
        }

        $state = $this->argument('state');
        if ($state !== null) {
            if (! in_array($state, ['on', 'off'], true)) {
                $this->error('Estado inválido: use on ou off.');

                return self::FAILURE;
            }
            $company->forceFill(['pingwin_item_sales_enabled' => $state === 'on'])->save();
            Log::info('[PingWin Vendas por artigo] interruptor alterado', ['company_id' => $company->id, 'enabled' => $state === 'on']);
        }

        $this->line("{$company->fiscal_name} ({$company->id}): vendas por artigo "
            . ($company->fresh()->pingwin_item_sales_enabled ? 'LIGADAS' : 'desligadas') . '.');

        return self::SUCCESS;
    }
}
