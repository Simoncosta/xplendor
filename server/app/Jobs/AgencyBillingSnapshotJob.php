<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Company;
use App\Services\Agency\AgencyBilling;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** No dia 1 de cada mês: o registo de quantas empresas contam para cada agência nesse mês (para a Cobrança 2). */
class AgencyBillingSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(): void
    {
        $month = now('Europe/Lisbon')->format('Y-m');
        $out = [];
        foreach (Company::whereNotNull('agency_enabled_at')->get() as $agency) {
            $out[$agency->id] = AgencyBilling::snapshot($agency, $month);
        }
        Log::info('[Gestão por agências] Contagem mensal das agências', ['month' => $month, 'counts' => $out]);
    }
}
