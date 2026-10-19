<?php

declare(strict_types=1);

namespace App\Audit;

use App\Audit\Contracts\AuditRule;
use App\Models\AuditFinding;
use App\Models\AuditFindingEvent;
use App\Models\AuditFindingRef;
use App\Models\AuditRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * XPLENDOR — Auditoria: o motor. Corre as regras de uma corrida, uma por passo (estado, contagens,
 * tempo, consultas e memória), e guarda os achados pela impressão digital (regra + entidade + chave
 * da evidência):
 *  · novo → Aberto;
 *  · Resolvido que volta a aparecer → Aberto;
 *  · Marcado como corrigido que ainda aparece → Aberto, com a nota "Ainda aparece";
 *  · Ignorado cuja evidência mudou → Aberto, com a nota "Os dados mudaram";
 *  · o que a regra deixou de encontrar → Resolvido (automático).
 * A falha de uma regra não pára as outras, e uma regra que falha não resolve nada.
 *
 * Na fila, cada passo é um job (RunAuditRuleJob) com o seu limite de tempo: uma regra lenta fica
 * "Demorou demasiado" e a corrida continua. Durante uma regra, a IA e os serviços externos ficam
 * bloqueados (AuditGuard). execute() corre tudo de seguida (no mesmo processo), para os testes e
 * para quem chama sem fila.
 */
final class AuditEngine
{
    public const STEP_TIMEOUT = 'timeout';

    public function execute(AuditRun $run): void
    {
        if (! $this->start($run)) {
            return;
        }
        foreach (array_keys($run->fresh()->steps ?? []) as $i) {
            $this->runStep($run->id, $i);
        }
        $this->finish($run->id);
    }

    /** Reserva a corrida (queued → running) uma só vez: uma entrega repetida do job não faz nada. */
    public function start(AuditRun $run): bool
    {
        $steps = array_map(fn ($code) => ['rule' => $code, 'title' => AuditRegistry::get($code)?->title() ?? $code, 'status' => 'pending'], $run->rules ?? []);
        $claimed = AuditRun::whereKey($run->id)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => now(), 'steps' => json_encode($steps), 'updated_at' => now()]);

        return $claimed === 1;
    }

    /**
     * Corre um passo (uma regra). Idempotente: só corre se o passo ainda estiver "pending" e a
     * corrida "running". Devolve false se não correu.
     */
    public function runStep(int $runId, int $i): bool
    {
        $run = DB::transaction(function () use ($runId, $i) {
            $run = AuditRun::whereKey($runId)->lockForUpdate()->first();
            $steps = $run?->steps ?? [];
            if (! $run || $run->status !== 'running' || ($steps[$i]['status'] ?? null) !== 'pending') {
                return null;
            }
            $steps[$i]['status'] = 'running';
            $steps[$i]['started_at'] = now()->toIso8601String();
            $run->forceFill(['steps' => $steps])->save();

            return $run;
        });
        if (! $run) {
            return false;
        }
        $step = $run->steps[$i];
        $t0 = microtime(true);
        $q0 = self::queries();
        $rule = AuditRegistry::get($step['rule']);
        $result = [];
        try {
            if (! $rule) {
                throw new \RuntimeException('Regra desconhecida.');
            }
            if (! $rule->appliesTo((int) $run->company_id)) {
                $result = ['status' => 'skipped', 'note' => 'Não se aplica a esta empresa.', 'found' => 0, 'new' => 0, 'resolved' => 0];
            } else {
                // Uma regra por passo, com um âmbito próprio (a memória partilhada é só dentro do passo).
                $scope = new AuditScope((int) $run->company_id, $run->area, $run->origin, $run->rules ?? []);
                $counts = AuditGuard::run(fn () => $this->persist($run, $rule, $rule->run((int) $run->company_id, $scope)));
                $result = ['status' => 'ok'] + $counts;
            }
        } catch (Throwable $e) {
            Log::warning('[Auditoria] regra falhou', ['company_id' => $run->company_id, 'rule' => $step['rule'], 'error' => $e->getMessage()]);
            $result = ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)];
        }
        $result += ['ms' => (int) round((microtime(true) - $t0) * 1000), 'queries' => self::queries() - $q0, 'memory_mb' => (int) round(memory_get_peak_usage(true) / 1048576)];
        $this->setStep($runId, $i, $result);

        return true;
    }

    /** Um passo que demorou demasiado ou que parou o processo: fica marcado e a corrida segue. */
    public function failStep(int $runId, int $i, string $status, string $error): void
    {
        $this->setStep($runId, $i, ['status' => $status, 'error' => mb_substr($error, 0, 300)], onlyIfOpen: true);
    }

    /** Fecha a corrida: as contagens dos passos e o estado final. */
    public function finish(int $runId): void
    {
        $run = AuditRun::find($runId);
        if (! $run || $run->status !== 'running') {
            return;
        }
        $steps = $run->steps ?? [];
        // Um passo que ficou por correr (não devia acontecer) conta como falhado.
        foreach ($steps as $i => $s) {
            if (in_array($s['status'], ['pending', 'running'], true)) {
                $steps[$i] = array_merge($s, ['status' => 'failed', 'error' => 'O passo não chegou a terminar.']);
            }
        }
        $failed = count(array_filter($steps, fn ($s) => in_array($s['status'], ['failed', self::STEP_TIMEOUT], true)));
        $open = AuditFinding::where('company_id', $run->company_id)->whereIn('rule', $run->rules ?? [])->whereIn('state', AuditFinding::OPEN)->count();
        $run->forceFill([
            'steps' => $steps,
            'status' => $steps && $failed === count($steps) ? 'failed' : 'done',
            'error' => $failed ? ($failed === 1 ? 'Uma regra não conseguiu correr; as outras correram.' : sprintf('%d regras não conseguiram correr; as outras correram.', $failed)) : null,
            'new_count' => array_sum(array_map(fn ($s) => (int) ($s['new'] ?? 0), $steps)),
            'resolved_count' => array_sum(array_map(fn ($s) => (int) ($s['resolved'] ?? 0), $steps)),
            'open_count' => $open, 'finished_at' => now(),
        ])->save();
    }

    private static ?int $queryCount = null;

    /** As consultas à base de dados feitas por este processo (um só ouvinte), para o diagnóstico de cada passo. */
    private static function queries(): int
    {
        if (self::$queryCount === null) {
            self::$queryCount = 0;
            DB::listen(function () {
                self::$queryCount++;
            });
        }

        return self::$queryCount;
    }

    /** Grava o resultado de um passo (com o bloqueio da linha: os passos correm em processos diferentes). */
    private function setStep(int $runId, int $i, array $result, bool $onlyIfOpen = false): void
    {
        DB::transaction(function () use ($runId, $i, $result, $onlyIfOpen) {
            $run = AuditRun::whereKey($runId)->lockForUpdate()->first();
            if (! $run || ! isset(($run->steps ?? [])[$i])) {
                return;
            }
            $steps = $run->steps;
            if ($onlyIfOpen && ! in_array($steps[$i]['status'], ['pending', 'running'], true)) {
                return;
            }
            $steps[$i] = array_merge($steps[$i], $result);
            $run->forceFill(['steps' => $steps])->save();
        });
    }

    public static function fingerprint(string $rule, string $entityType, string $entityId, string $evidenceKey): string
    {
        return sha1("{$rule}|{$entityType}|{$entityId}|{$evidenceKey}");
    }

    /**
     * @param  iterable<AuditFindingData>  $items
     * @return array{found:int, new:int, resolved:int, open:int}
     */
    private function persist(AuditRun $run, AuditRule $rule, iterable $items): array
    {
        $companyId = (int) $run->company_id;
        $existing = AuditFinding::where('company_id', $companyId)->where('rule', $rule->code())->get()->keyBy('fingerprint');
        // As referências de todos os achados da regra numa só consulta (antes, uma por achado).
        $refs = AuditFindingRef::whereIn('finding_id', $existing->pluck('id')->all() ?: [0])->get()->groupBy('finding_id');
        $seen = [];
        $found = 0;
        $new = 0;
        $resolved = 0;

        // Materializa antes da transação: as regras leem o espelho (pode demorar).
        $list = [];
        foreach ($items as $d) {
            $list[] = $d;
        }

        DB::transaction(function () use ($list, $rule, $run, $companyId, $existing, $refs, &$seen, &$found, &$new, &$resolved) {
            $now = now();
            foreach ($list as $d) {
                $fp = self::fingerprint($rule->code(), $d->entityType, $d->entityId, $d->evidenceKey);
                if (isset($seen[$fp])) {
                    continue;
                }
                $seen[$fp] = true;
                $found++;
                $signature = $d->signature !== null ? sha1($d->signature) : sha1((string) json_encode($d->evidence));
                $attrs = [
                    'area' => $rule->area(), 'severity' => $d->severity ?? $rule->severity(),
                    'entity_type' => $d->entityType, 'entity_id' => $d->entityId, 'entity_label' => mb_substr($d->entityLabel, 0, 255),
                    'title' => mb_substr($d->title, 0, 255), 'seen' => $d->seen !== '' ? $d->seen : null, 'stake' => $d->stake !== '' ? $d->stake : null,
                    'evidence' => $d->evidence, 'why' => $d->why, 'suggestion' => $d->suggestion,
                    'impact_cents' => $d->impactCents, 'impact_pending_cents' => $d->impactPendingCents, 'weight_cents' => $d->weightCents,
                    'fix_url' => $d->fixUrl, 'fix_label' => $d->fixLabel, 'fix_action' => $d->fixAction,
                    'last_seen_at' => $now, 'last_run_id' => $run->id,
                ];
                $f = $existing[$fp] ?? null;
                if (! $f) {
                    $f = AuditFinding::create($attrs + [
                        'company_id' => $companyId, 'rule' => $rule->code(), 'fingerprint' => $fp, 'evidence_signature' => $signature,
                        'state' => 'aberto', 'first_seen_at' => $now,
                    ]);
                    $this->event($f, null, 'aberto', $run, 'Encontrado pela auditoria.');
                    $new++;
                } else {
                    $prev = $f->state;
                    [$to, $note, $reason] = match (true) {
                        $prev === 'resolvido' => ['aberto', null, 'Voltou a aparecer.'],
                        $prev === 'corrigido' => ['aberto', 'Ainda aparece', 'Estava marcado como corrigido, mas a auditoria ainda o encontra.'],
                        $prev === 'ignorado' && $f->evidence_signature !== $signature => ['aberto', 'Os dados mudaram', 'Estava ignorado, mas os dados mudaram.'],
                        default => [null, null, null],
                    };
                    $f->fill($attrs + ['evidence_signature' => $signature]);
                    if ($to) {
                        // Reaberto: os euros resolvidos deixam de contar.
                        $f->forceFill(['state' => $to, 'state_at' => $now, 'state_by' => null, 'resolved_at' => null, 'note' => $note,
                            'resolved_cents' => null, 'resolved_by' => null]);
                        $this->event($f, $prev, $to, $run, $reason);
                        if ($prev === 'resolvido') {
                            $new++;
                        }
                    }
                    $f->save();
                }
                $this->syncRefs($f, array_merge([[$d->entityType, $d->entityId]], $d->refs), $refs[$f->id] ?? collect());
            }

            foreach ($existing as $fp => $f) {
                if (isset($seen[$fp]) || $f->state === 'resolvido') {
                    continue;
                }
                $prev = $f->state;
                // Euros resolvidos: o € em jogo no momento (o guardado ao corrigir, senão o da última
                // corrida) e quem o corrigiu (quem o marcou como corrigido ou aceite; senão ninguém).
                $f->forceFill([
                    'state' => 'resolvido', 'resolved_at' => $now, 'state_at' => $now, 'state_by' => null, 'note' => null,
                    'resolved_cents' => $f->resolved_cents ?? ($f->impact_cents ?? $f->impact_pending_cents ?? 0),
                    'resolved_by' => $f->resolved_by ?? (in_array($prev, ['corrigido', 'aceite'], true) ? $f->state_by : null),
                ])->save();
                $this->event($f, $prev, 'resolvido', $run, 'Resolvido: a auditoria deixou de o encontrar.');
                $resolved++;
            }
        });

        $open = AuditFinding::where('company_id', $companyId)->where('rule', $rule->code())->whereIn('state', AuditFinding::OPEN)->count();

        return ['found' => $found, 'new' => $new, 'resolved' => $resolved, 'open' => $open];
    }

    private function event(AuditFinding $f, ?string $from, string $to, AuditRun $run, ?string $reason): void
    {
        AuditFindingEvent::create([
            'finding_id' => $f->id, 'company_id' => $f->company_id, 'from_state' => $from, 'to_state' => $to,
            'run_id' => $run->id, 'reason' => $reason, 'created_at' => now(),
        ]);
    }

    /**
     * @param  list<array{0:string,1:string}>  $refs
     * @param  \Illuminate\Support\Collection<int, AuditFindingRef>  $current  as referências já guardadas (pré-carregadas)
     */
    private function syncRefs(AuditFinding $f, array $refs, \Illuminate\Support\Collection $current): void
    {
        $want = [];
        foreach ($refs as [$type, $id]) {
            if ($type !== '' && (string) $id !== '') {
                $want["{$type}|{$id}"] = [$type, (string) $id];
            }
        }
        $have = $current->keyBy(fn ($r) => "{$r->entity_type}|{$r->entity_id}");
        $gone = $have->filter(fn ($r, $k) => ! isset($want[$k]))->pluck('id')->all();
        if ($gone) {
            AuditFindingRef::whereIn('id', $gone)->delete();
        }
        $rows = [];
        foreach ($want as $k => [$type, $id]) {
            if (! isset($have[$k])) {
                $rows[] = ['finding_id' => $f->id, 'company_id' => $f->company_id, 'entity_type' => $type, 'entity_id' => $id];
            }
        }
        if ($rows) {
            AuditFindingRef::insert($rows);
        }
    }
}
