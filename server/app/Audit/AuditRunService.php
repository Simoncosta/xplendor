<?php

declare(strict_types=1);

namespace App\Audit;

use App\Jobs\RunAuditJob;
use App\Models\AuditRun;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * XPLENDOR — Auditoria: pôr uma corrida na fila (uma de cada vez por empresa e âmbito) e dar como
 * falhadas as que ficaram paradas (o worker morreu a meio).
 */
final class AuditRunService
{
    public const STALE_MINUTES = 30;

    /** @throws AuditRunInProgress */
    public function start(int $companyId, string $area, string $origin, ?int $userId = null): AuditRun
    {
        if (! AuditAreas::exists($area) || ! in_array($origin, AuditRegistry::origins(), true)) {
            throw new InvalidArgumentException('Este ecrã não tem auditoria.');
        }
        $rules = AuditRegistry::forScope($area, $origin);
        if (! $rules) {
            throw new InvalidArgumentException('Este ecrã não tem regras de auditoria.');
        }
        $this->expireStale($companyId);
        $scopeKey = "{$area}:{$origin}";

        $run = DB::transaction(function () use ($companyId, $area, $origin, $userId, $rules, $scopeKey) {
            $active = AuditRun::where('company_id', $companyId)->where('scope_key', $scopeKey)
                ->whereIn('status', AuditRun::ACTIVE)->lockForUpdate()->first();
            if ($active) {
                throw new AuditRunInProgress($active);
            }

            return AuditRun::create([
                'company_id' => $companyId, 'area' => $area, 'origin' => $origin, 'scope_key' => $scopeKey,
                'rules' => array_map(fn ($r) => $r->code(), $rules), 'requested_by' => $userId, 'status' => 'queued',
                'steps' => array_map(fn ($r) => ['rule' => $r->code(), 'title' => $r->title(), 'status' => 'pending'], $rules),
            ]);
        });
        RunAuditJob::dispatch($run->id)->afterCommit();

        return $run->fresh() ?? $run;
    }

    public function expireStale(int $companyId): void
    {
        AuditRun::where('company_id', $companyId)->whereIn('status', AuditRun::ACTIVE)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update(['status' => 'failed', 'error' => 'A corrida ficou parada e foi dada como falhada.', 'finished_at' => now()]);
    }
}
