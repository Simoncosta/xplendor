<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\AuditRunInProgress;
use App\Audit\AuditRunService;
use App\Models\CompanyIntegration;
use App\Services\CompanyModuleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Auditoria: a corrida noturna da restauração (todas as regras da área), para as
 * empresas com a restauração ligada (o módulo do PingWin e a integração ativa). Corre depois dos
 * jobs do PingWin da madrugada: só lê o espelho. Uma empresa com uma corrida em curso é saltada.
 */
class DispatchNightlyAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(AuditRunService $runs, CompanyModuleService $modules): void
    {
        $companyIds = CompanyIntegration::where('platform', 'pingwin')->where('status', '!=', 'revoked')
            ->pluck('company_id')->unique()->values();
        foreach ($companyIds as $companyId) {
            if (! $modules->isEnabled((int) $companyId, 'pingwin')) {
                continue;
            }
            try {
                $runs->start((int) $companyId, 'restauracao', 'noturna');
            } catch (AuditRunInProgress) {
                Log::info('[Auditoria] corrida noturna saltada (já há uma em curso)', ['company_id' => $companyId]);
            } catch (\Throwable $e) {
                Log::error('[Auditoria] corrida noturna falhou ao pôr na fila', ['company_id' => $companyId, 'error' => $e->getMessage()]);
            }
        }
    }
}
