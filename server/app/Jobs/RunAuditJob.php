<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\AuditEngine;
use App\Models\AuditRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * XPLENDOR — Auditoria: corre UMA corrida (uma de cada vez por empresa e âmbito). Só lê o espelho e
 * as tabelas da XPLENDOR; nunca corrige nada sozinha. Uma tentativa só.
 */
class RunAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1200;

    public function __construct(public int $runId) {}

    public function middleware(): array
    {
        $run = AuditRun::find($this->runId);

        return $run ? [(new WithoutOverlapping("audit:{$run->company_id}:{$run->scope_key}"))->dontRelease()->expireAfter(1500)] : [];
    }

    public function handle(AuditEngine $engine): void
    {
        $run = AuditRun::find($this->runId);
        if ($run) {
            $engine->execute($run);
        }
    }

    public function failed(?\Throwable $e): void
    {
        AuditRun::where('id', $this->runId)->whereIn('status', AuditRun::ACTIVE)
            ->update(['status' => 'failed', 'error' => 'A corrida falhou: ' . mb_substr((string) $e?->getMessage(), 0, 200), 'finished_at' => now()]);
    }
}
