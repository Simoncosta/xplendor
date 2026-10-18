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
 * XPLENDOR — Auditoria: o motor. Corre as regras de uma corrida, uma por passo (estado, contagens e
 * tempo), e guarda os achados pela impressão digital (regra + entidade + chave da evidência):
 *  · novo → Aberto;
 *  · Resolvido que volta a aparecer → Aberto;
 *  · Marcado como corrigido que ainda aparece → Aberto, com a nota "Ainda aparece";
 *  · Ignorado cuja evidência mudou → Aberto, com a nota "Os dados mudaram";
 *  · o que a regra deixou de encontrar → Resolvido (automático).
 * A falha de uma regra não pára as outras, e uma regra que falha não resolve nada.
 */
final class AuditEngine
{
    public function execute(AuditRun $run): void
    {
        if ($run->status !== 'queued') {
            return;
        }
        $steps = array_map(fn ($code) => ['rule' => $code, 'title' => AuditRegistry::get($code)?->title() ?? $code, 'status' => 'pending'], $run->rules ?? []);
        $run->forceFill(['status' => 'running', 'started_at' => now(), 'steps' => $steps])->save();
        $scope = new AuditScope((int) $run->company_id, $run->area, $run->origin, $run->rules ?? []);
        $new = 0;
        $resolved = 0;
        $failed = 0;

        foreach ($steps as $i => $step) {
            $steps[$i]['status'] = 'running';
            $steps[$i]['started_at'] = now()->toIso8601String();
            $run->forceFill(['steps' => $steps])->save();
            $t0 = microtime(true);
            $rule = AuditRegistry::get($step['rule']);
            try {
                if (! $rule) {
                    throw new \RuntimeException('Regra desconhecida.');
                }
                if (! $rule->appliesTo((int) $run->company_id)) {
                    $steps[$i] = array_merge($steps[$i], ['status' => 'skipped', 'note' => 'Não se aplica a esta empresa.', 'found' => 0, 'new' => 0, 'resolved' => 0]);
                } else {
                    $counts = $this->persist($run, $rule, $rule->run((int) $run->company_id, $scope));
                    $steps[$i] = array_merge($steps[$i], ['status' => 'ok'], $counts);
                    $new += $counts['new'];
                    $resolved += $counts['resolved'];
                }
            } catch (Throwable $e) {
                $failed++;
                Log::warning('[Auditoria] regra falhou', ['company_id' => $run->company_id, 'rule' => $step['rule'], 'error' => $e->getMessage()]);
                $steps[$i] = array_merge($steps[$i], ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)]);
            }
            $steps[$i]['ms'] = (int) round((microtime(true) - $t0) * 1000);
            $run->forceFill(['steps' => $steps])->save();
        }

        $open = AuditFinding::where('company_id', $run->company_id)->whereIn('rule', $run->rules ?? [])
            ->whereIn('state', AuditFinding::OPEN)->count();
        $run->forceFill([
            'status' => $steps && $failed === count($steps) ? 'failed' : 'done',
            'error' => $failed ? ($failed === 1 ? 'Uma regra não conseguiu correr; as outras correram.' : sprintf('%d regras não conseguiram correr; as outras correram.', $failed)) : null,
            'new_count' => $new, 'resolved_count' => $resolved, 'open_count' => $open, 'finished_at' => now(),
        ])->save();
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
        $seen = [];
        $found = 0;
        $new = 0;
        $resolved = 0;

        // Materializa antes da transação: as regras leem o espelho (pode demorar).
        $list = [];
        foreach ($items as $d) {
            $list[] = $d;
        }

        DB::transaction(function () use ($list, $rule, $run, $companyId, $existing, &$seen, &$found, &$new, &$resolved) {
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
                $this->syncRefs($f, array_merge([[$d->entityType, $d->entityId]], $d->refs));
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

    /** @param list<array{0:string,1:string}> $refs */
    private function syncRefs(AuditFinding $f, array $refs): void
    {
        $want = [];
        foreach ($refs as [$type, $id]) {
            if ($type !== '' && (string) $id !== '') {
                $want["{$type}|{$id}"] = [$type, (string) $id];
            }
        }
        $have = AuditFindingRef::where('finding_id', $f->id)->get()->keyBy(fn ($r) => "{$r->entity_type}|{$r->entity_id}");
        foreach ($have as $k => $r) {
            if (! isset($want[$k])) {
                $r->delete();
            }
        }
        foreach ($want as $k => [$type, $id]) {
            if (! isset($have[$k])) {
                AuditFindingRef::create(['finding_id' => $f->id, 'company_id' => $f->company_id, 'entity_type' => $type, 'entity_id' => $id]);
            }
        }
    }
}
