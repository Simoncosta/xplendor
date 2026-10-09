<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\SupplierDocumentsSyncInProgress;
use App\Models\CompanyIntegration;
use App\Models\PingwinDocumentSyncRun;
use App\Services\SupplierDocumentsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Documentos de fornecedor (F1): despacho NOTURNO. Um run "nightly" por empresa
 * com PingWin ligado, com os últimos NIGHTLY_DAYS dias (apanha lançamentos atrasados e
 * anulações recentes). Se a empresa já tiver um run em curso (ex.: manual), salta-a.
 */
class DispatchSupplierDocumentsSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60; // só cria runs e despacha; não fala com o PingWin

    public function handle(SupplierDocumentsService $docs): void
    {
        $today = now('Europe/Lisbon')->toDateString();
        $from = now('Europe/Lisbon')->subDays(SupplierDocumentsService::NIGHTLY_DAYS)->toDateString();
        $companyIds = CompanyIntegration::where('platform', 'pingwin')->where('status', '!=', 'revoked')
            ->pluck('company_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        foreach ($companyIds as $companyId) {
            try {
                $run = $docs->startRun($companyId, $from, $today, PingwinDocumentSyncRun::TRIGGER_NIGHTLY);
                Log::info('[PingWin Documentos] run noturno criado', ['company_id' => $companyId, 'run_id' => $run->id, 'from' => $from, 'to' => $today]);
            } catch (SupplierDocumentsSyncInProgress $e) {
                Log::info('[PingWin Documentos] noite saltada: já há run em curso', ['company_id' => $companyId, 'run_id' => $e->run->id]);
            }
        }
    }
}
