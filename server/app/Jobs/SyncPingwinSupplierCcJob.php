<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\SupplierCcService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Conta corrente de fornecedor (S1, SÓ LEITURA): sincroniza UM LOTE de
 * fornecedores (≤ SupplierCcService::BATCH_SIZE) num único docker exec. Corre no worker
 * (o php-fpm não tem o socket Docker).
 *
 * ⚠️ Um job por lote (e não um job para todos): o retry_after da queue é 90 s; um lote de
 * 40 demora ~12 s, longe desse limite. O despacho noturno parte os fornecedores em lotes.
 * Lotes da MESMA empresa nunca correm em paralelo (lock por empresa).
 */
class SyncPingwinSupplierCcJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 200;      // docker exec (180 s) + margem
    public int $maxExceptions = 1;  // exceção real → falha; esperar pelo lock não conta

    /** @param list<int> $supplierIds ids locais (suppliers.id) */
    public function __construct(public int $companyId, public array $supplierIds) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("supplier-cc:{$this->companyId}"))->releaseAfter(30)->expireAfter(300)];
    }

    /** Pode esperar pelo lock da empresa até 3 h. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(3);
    }

    public function handle(SupplierCcService $cc): void
    {
        $summary = $cc->sync($this->companyId, $this->supplierIds);
        Log::info('[PingWin CC] lote concluído', [
            'company_id' => $this->companyId,
            'ok'         => $summary['ok'],
            'failed'     => $summary['failed'],
            'documents'  => $summary['documents'],
            'reconciled' => $summary['reconciled'],
            'seconds'    => $summary['seconds'],
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[PingWin CC] lote falhou', [
            'company_id' => $this->companyId, 'suppliers' => count($this->supplierIds), 'error' => $e->getMessage(),
        ]);
    }
}
