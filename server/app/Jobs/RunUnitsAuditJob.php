<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\AuditRunInProgress;
use App\Audit\AuditRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Depois de as unidades de um artigo mudarem: corre as regras da auditoria de unidades da empresa (o
 * âmbito "unidades" da restauração; o motor não corre uma regra só para um artigo). Uma de cada vez por
 * empresa: se já houver uma corrida, não se pede outra.
 */
class RunUnitsAuditJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $uniqueFor = 600;

    public function __construct(public int $companyId) {}

    public function uniqueId(): string
    {
        return (string) $this->companyId;
    }

    public function handle(AuditRunService $runs): void
    {
        try {
            $runs->start($this->companyId, 'restauracao', 'unidades');
        } catch (AuditRunInProgress) {
            // Já há uma corrida das unidades: apanha a mudança.
        } catch (\Throwable $e) {
            Log::info('[Auditoria] unidades depois de mudar um artigo: não correu', ['company_id' => $this->companyId, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }
}
