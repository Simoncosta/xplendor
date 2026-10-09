<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CompanyIntegration;
use App\Models\PingwinSupplierCcBalance;
use App\Services\PingwinService;
use App\Services\SupplierCcService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Conta corrente de fornecedor (S1): despacho NOTURNO. Para cada empresa
 * com PingWin ligado: (S2) sincroniza PRIMEIRO os fornecedores — a CC só percorre os
 * fornecedores do espelho, e um espelho desatualizado deixava fornecedores (e as suas
 * faturas) de fora; se essa sync falhar, a CC corre na mesma com o espelho que houver e
 * a falha fica no log. Depois parte os fornecedores em lotes e põe um
 * SyncPingwinSupplierCcJob por lote na queue (estado "queued").
 */
class DispatchSupplierCcSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300; // sync de fornecedores (um docker exec, como SyncPingwinSuppliersJob) + despacho

    public function __construct(public ?int $companyId = null) {}

    public function handle(SupplierCcService $cc, PingwinService $pingwin): void
    {
        $companyIds = $this->companyId !== null
            ? [$this->companyId]
            : CompanyIntegration::where('platform', 'pingwin')->where('status', '!=', 'revoked')
                ->pluck('company_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        foreach ($companyIds as $companyId) {
            try {
                $count = $pingwin->syncSuppliers($companyId);
                Log::info('[PingWin CC] fornecedores sincronizados antes da CC', ['company_id' => $companyId, 'count' => $count]);
            } catch (\Throwable $e) {
                Log::warning('[PingWin CC] sync de fornecedores falhou — a CC segue com o espelho atual', [
                    'company_id' => $companyId, 'error' => $e->getMessage(),
                ]);
            }

            $suppliers = $cc->suppliersFor($companyId);
            foreach ($suppliers as $s) {
                PingwinSupplierCcBalance::updateOrCreate(
                    ['company_id' => $companyId, 'supplier_id' => $s->id],
                    ['entity_pingwin_id' => (string) $s->pingwin_id, 'sync_status' => PingwinSupplierCcBalance::STATUS_QUEUED]
                );
            }
            $batches = $suppliers->pluck('id')->chunk(SupplierCcService::BATCH_SIZE);
            foreach ($batches as $ids) {
                SyncPingwinSupplierCcJob::dispatch($companyId, $ids->values()->all());
            }
            Log::info('[PingWin CC] despacho noturno', [
                'company_id' => $companyId, 'suppliers' => $suppliers->count(), 'batches' => $batches->count(),
            ]);
        }
    }
}
