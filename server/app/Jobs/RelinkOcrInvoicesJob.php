<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CompanyIntegration;
use App\Services\OcrPingwinLinkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — F3: madrugada, DEPOIS da sync de documentos (07:00) e das linhas (07:15): volta a
 * ligar as faturas OCR dos últimos 90 dias (as não ligadas apanham os lançamentos novos; as
 * ligadas confirmam que o documento não foi anulado). Só espelhos + pesquisa de NIF (leitura).
 */
class RelinkOcrInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900;

    public function handle(OcrPingwinLinkService $links): void
    {
        $companyIds = CompanyIntegration::where('platform', 'pingwin')->where('status', '!=', 'revoked')
            ->pluck('company_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        foreach ($companyIds as $companyId) {
            $res = $links->relinkRecent($companyId, 90, true);
            Log::info('[OCR↔PingWin] verificação noturna', ['company_id' => $companyId] + $res);
        }
    }
}
