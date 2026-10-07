<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ExpenseChargeOpen;
use App\Services\Billing\ChargeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Às 09:00 (Lisboa): lembretes das cobranças da XPLENDOR em aberto, no dia do vencimento e
 * depois às segundas-feiras, só nas empresas com os lembretes ligados. Retenção: as
 * aberturas do link com mais de 12 meses são apagadas (fica o contador na cobrança).
 */
class ChargeRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public const OPENS_MONTHS = 12;

    public function handle(ChargeService $charges): array
    {
        $pruned = ExpenseChargeOpen::where('opened_at', '<', now()->subMonths(self::OPENS_MONTHS))->delete();

        return ['sent' => $charges->runReminders(), 'pruned_opens' => $pruned];
    }
}
