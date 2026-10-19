<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CompanyIntegration;
use App\Services\Restaurant\PosDocumentsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Auditoria v2-C · C3: documentos de caixa (SÓ LEITURA), à noite e fora do horário de
 * serviço: os últimos NIGHTLY_DAYS dias de cada empresa com o PingWin ligado (um lote por empresa).
 * Desliga-se por empresa em Configurações › Restauração e Auditoria.
 */
class SyncPingwinPosDocumentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900;

    public function handle(PosDocumentsService $docs): void
    {
        $to = now('Europe/Lisbon')->subDay()->toDateString();
        $from = now('Europe/Lisbon')->subDays(PosDocumentsService::NIGHTLY_DAYS)->toDateString();
        $companyIds = CompanyIntegration::where('platform', 'pingwin')->where('status', '!=', 'revoked')
            ->pluck('company_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        foreach ($companyIds as $companyId) {
            // A definição da empresa (Configurações › Restauração e Auditoria; antes PINGWIN_POS_DOCUMENTS_SYNC).
            if (! \App\Settings\Settings::company($companyId, 'pingwin.pos_documents_sync')) {
                continue;
            }
            try {
                $s = $docs->sync($companyId, $from, $to);
                Log::info('[PingWin Caixa] documentos lidos', ['company_id' => $companyId, 'from' => $from, 'to' => $to] + $s);
            } catch (\Throwable $e) {
                Log::warning('[PingWin Caixa] leitura falhou', ['company_id' => $companyId, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            }
        }
    }
}
