<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PingwinSupplierWrite;
use App\Services\PingwinSupplierWriteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — ⚠️ ESCRITA no PingWin: criar / editar / anular UM fornecedor (FN). Corre no
 * worker (docker socket). Escritas da mesma empresa em série (lock). Esperar pelo lock não
 * conta como falha; uma exceção real falha à primeira (uma escrita NUNCA se repete às cegas
 * — o serviço também só executa escritas "pendente").
 */
class WritePingwinSupplierJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 200;      // docker exec (180 s) + margem
    public int $maxExceptions = 1;

    public function __construct(public int $companyId, public int $writeId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pingwin-supplier-write:{$this->companyId}"))->releaseAfter(10)->expireAfter(300)];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(PingwinSupplierWriteService $writes): void
    {
        $writes->execute($this->writeId);
    }

    public function failed(\Throwable $e): void
    {
        PingwinSupplierWrite::where('id', $this->writeId)->where('status', PingwinSupplierWrite::PENDING)
            ->update(['status' => PingwinSupplierWrite::ERROR, 'error_message' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()]);
        Log::error('[PingWin Fornecedor] job falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);
    }
}
