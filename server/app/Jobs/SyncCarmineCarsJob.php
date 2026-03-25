<?php

namespace App\Jobs;

use App\Models\CarmineConnection;
use App\Services\CarmineConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncCarmineCarsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600;

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('sync-carmine-cars-job'))->expireAfter(3600),
        ];
    }

    public function handle(CarmineConnectionService $carmineService): void
    {
        Log::info('[Carmine Sync] Job iniciado');

        $connections = CarmineConnection::query()
            ->whereNotNull('dealer_id')
            ->whereNotNull('token')
            ->with('company:id,trade_name,fiscal_name')
            ->get();

        if ($connections->isEmpty()) {
            Log::info('[Carmine Sync] Sem ligações Carmine ativas para sincronizar');
            return;
        }

        Log::info('[Carmine Sync] Ligações encontradas', [
            'total_connections' => $connections->count(),
        ]);

        foreach ($connections as $connection) {
            $companyName = $connection->company?->trade_name
                ?? $connection->company?->fiscal_name
                ?? 'Empresa desconhecida';
            $lockKey = "carmine-sync-company-{$connection->company_id}";
            $lock = Cache::lock($lockKey, 1800);

            if (!$lock->get()) {
                Log::warning('[Carmine Sync] Sincronização ignorada por lock ativo', [
                    'company_id' => $connection->company_id,
                    'company_name' => $companyName,
                ]);
                continue;
            }

            try {
                Log::info('[Carmine Sync] A sincronizar empresa', [
                    'company_id' => $connection->company_id,
                    'company_name' => $companyName,
                ]);

                $summary = $carmineService->syncCompanyCars((int) $connection->company_id);

                Log::info('[Carmine Sync] Empresa sincronizada com sucesso', [
                    'company_id' => $connection->company_id,
                    'company_name' => $companyName,
                    'total_received' => $summary['total_received'] ?? 0,
                    'imported' => $summary['imported'] ?? 0,
                    'updated' => $summary['updated'] ?? 0,
                    'errors_count' => count($summary['errors'] ?? []),
                ]);
            } catch (\Throwable $exception) {
                Log::error('[Carmine Sync] Erro ao sincronizar empresa', [
                    'company_id' => $connection->company_id,
                    'company_name' => $companyName,
                    'error' => $exception->getMessage(),
                ]);
            } finally {
                optional($lock)->release();
            }
        }

        Log::info('[Carmine Sync] Job concluído');
    }
}
