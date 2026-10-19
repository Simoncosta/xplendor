<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Audit\AuditEngine;
use App\Models\AuditRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;

/**
 * XPLENDOR — Auditoria: UM passo de uma corrida (uma regra), com o seu limite de tempo
 * (config auditoria.rule_timeout, 120 s por defeito). No fim, põe na fila o passo seguinte (ou fecha
 * a corrida). Se a regra passar o limite, o passo fica "Demorou demasiado"; se falhar ou parar o
 * processo, "Falhou" com o erro; em todos os casos a corrida continua com as seguintes.
 * Uma tentativa só; o timeout fica muito abaixo do retry_after da fila.
 */
class RunAuditRuleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout;
    public bool $failOnTimeout = true;

    public function __construct(public int $runId, public int $index)
    {
        $this->timeout = max(10, (int) config('auditoria.rule_timeout', 120));
    }

    public function handle(AuditEngine $engine): void
    {
        // Uma regra grande não deita o worker abaixo por falta de memória (o worker corre a 128 MB).
        @ini_set('memory_limit', (string) config('auditoria.rule_memory', '512M'));
        // Um erro fatal (memória, por exemplo) não passa pelos catch: o passo fica "Falhou" e a corrida segue.
        register_shutdown_function(function () use ($engine) {
            $e = error_get_last();
            if ($e && in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true) && $this->stillRunning()) {
                $engine->failStep($this->runId, $this->index, 'failed', 'A regra parou o processo: ' . $e['message']);
                $this->next($engine);
            }
        });
        $engine->runStep($this->runId, $this->index);
        $this->next($engine);
    }

    public function failed(?\Throwable $e): void
    {
        $engine = app(AuditEngine::class);
        [$status, $error] = match (true) {
            $e instanceof TimeoutExceededException => [AuditEngine::STEP_TIMEOUT, sprintf('Demorou demasiado: passou o limite de %d s.', $this->timeout)],
            $e instanceof MaxAttemptsExceededException => ['failed', 'O processo parou a meio desta regra (por exemplo, por falta de memória).'],
            default => ['failed', mb_substr((string) $e?->getMessage(), 0, 250) ?: 'Falhou.'],
        };
        $engine->failStep($this->runId, $this->index, $status, $error);
        $this->next($engine);
    }

    /** O passo seguinte, ou fechar a corrida. */
    private function next(AuditEngine $engine): void
    {
        $run = AuditRun::find($this->runId);
        if (! $run || $run->status !== 'running') {
            return;
        }
        $next = $this->index + 1;
        if (isset(($run->steps ?? [])[$next])) {
            // Só se o passo seguinte ainda estiver por correr (uma entrega repetida não o duplica).
            if (($run->steps[$next]['status'] ?? null) === 'pending') {
                self::dispatch($this->runId, $next);
            }

            return;
        }
        $engine->finish($this->runId);
    }

    private function stillRunning(): bool
    {
        $run = AuditRun::find($this->runId);

        return $run && in_array(($run->steps[$this->index]['status'] ?? null), ['pending', 'running'], true);
    }
}
