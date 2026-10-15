<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\AuditRunInProgress;
use App\Audit\AuditRunService;
use App\Models\Company;
use App\Services\CompanyModuleService;
use App\Services\Tracking\TrackingHealthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Auditoria (tracking dos sites): de manhã, guarda as contagens diárias dos últimos 28
 * dias (GA4 do dia anterior e XPLENDOR) e corre as regras do tracking. Chama o GA4: é uma tarefa
 * externa (SCHEDULE_EXTERNAL_JOBS).
 */
class TrackingHealthDailyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900;

    public function handle(TrackingHealthService $tracking, AuditRunService $runs, CompanyModuleService $modules): void
    {
        foreach (Company::whereNotNull('public_api_token')->pluck('id') as $companyId) {
            $companyId = (int) $companyId;
            if (! $modules->isEnabled($companyId, 'marketing_analytics')) {
                continue;
            }
            try {
                $tracking->sync($companyId);
                $runs->start($companyId, 'tracking', 'diaria');
            } catch (AuditRunInProgress) {
                Log::info('[Auditoria] tracking saltado (já há uma corrida em curso)', ['company_id' => $companyId]);
            } catch (\Throwable $e) {
                Log::error('[Auditoria] tracking falhou', ['company_id' => $companyId, 'error' => $e->getMessage()]);
            }
        }
    }
}
