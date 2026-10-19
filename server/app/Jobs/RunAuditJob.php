<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\AuditEngine;
use App\Models\AuditRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * XPLENDOR — Auditoria: começa UMA corrida. Reserva-a (queued → running, uma só vez: uma entrega
 * repetida não faz nada) e põe na fila o primeiro passo; cada regra corre no seu próprio job
 * (RunAuditRuleJob), com o seu limite de tempo, e a corrida continua mesmo que uma regra falhe.
 * Só lê o espelho e as tabelas da XPLENDOR; nunca corrige nada sozinha. Uma tentativa só.
 */
class RunAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;
    public bool $failOnTimeout = true;

    public function __construct(public int $runId) {}

    public function handle(AuditEngine $engine): void
    {
        $run = AuditRun::find($this->runId);
        if (! $run || ! $engine->start($run)) {
            return;   // já começou (entrega repetida) ou já não existe
        }
        if (! ($run->fresh()->steps ?? [])) {
            $engine->finish($run->id);

            return;
        }
        RunAuditRuleJob::dispatch($run->id, 0);
    }

    public function failed(?\Throwable $e): void
    {
        AuditRun::where('id', $this->runId)->where('status', 'queued')
            ->update(['status' => 'failed', 'error' => 'A corrida não conseguiu começar: ' . mb_substr((string) $e?->getMessage(), 0, 200), 'finished_at' => now()]);
    }
}
