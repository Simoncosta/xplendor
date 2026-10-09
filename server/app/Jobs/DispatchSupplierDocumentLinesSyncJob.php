<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CompanyIntegration;
use App\Services\SupplierDocumentLinesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * XPLENDOR — Linhas dos documentos de fornecedor (F4): despacho NOTURNO. Um ciclo por
 * empresa com PingWin ligado, com teto de NIGHTLY_MAX_DOCS documentos (o incremental do dia
 * são poucas dezenas; o backfill grande corre à mão, fora do horário de trabalho).
 */
class DispatchSupplierDocumentLinesSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60; // só despacha; não fala com o PingWin

    public function handle(): void
    {
        $companyIds = CompanyIntegration::where('platform', 'pingwin')->where('status', '!=', 'revoked')
            ->pluck('company_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        foreach ($companyIds as $companyId) {
            SyncPingwinSupplierDocumentLinesJob::dispatch($companyId, SupplierDocumentLinesService::NIGHTLY_MAX_DOCS, now()->toIso8601String());
        }
    }
}
