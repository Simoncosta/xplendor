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

    public function expireStale(?int $companyId = null): int
    {
        $minutes = (int) config('auditoria.stale_minutes', self::STALE_MINUTES);
        $n = 0;
        $stale = AuditRun::whereIn('status', AuditRun::ACTIVE)->where('updated_at', '<', now()->subMinutes($minutes))
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))->get();
        foreach ($stale as $run) {
            $steps = $run->steps ?? [];
            $where = null;
            foreach ($steps as $i => $st) {
                if ($st['status'] === 'running') {
                    $steps[$i] = array_merge($st, ['status' => 'failed', 'error' => sprintf('Parou aqui: sem progresso há mais de %d minutos.', $minutes)]);
                    $where = $st['title'] ?? $st['rule'];
                }
            }
            $error = $where
                ? sprintf('A corrida parou no passo «%s» (sem progresso há mais de %d minutos) e foi dada como falhada.', $where, $minutes)
                : sprintf('A corrida ficou parada (sem progresso há mais de %d minutos) e foi dada como falhada.', $minutes);
            $n += AuditRun::whereKey($run->id)->whereIn('status', AuditRun::ACTIVE)
                ->update(['status' => 'failed', 'steps' => json_encode($steps), 'error' => $error, 'finished_at' => now(), 'updated_at' => now()]);
        }

        return $n;
    }
}
