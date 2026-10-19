<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\FinanceRule;
use App\Models\FinanceRuleEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * XPLENDOR — Finanças: criar, mudar, ativar, desativar e apagar regras de classificação, cada mudança
 * no histórico (quem, quando, antes e depois). Só na XPLENDOR: nada muda no PingWin.
 */
class FinanceRuleService
{
    private const FIELDS = ['match_type', 'match_values', 'match_label', 'entity_pingwin_id', 'entity_name', 'group', 'active', 'priority'];

    public function create(int $companyId, array $data, ?int $userId): FinanceRule
    {
        $data = $this->clean($data);

        return DB::transaction(function () use ($companyId, $data, $userId) {
            $r = FinanceRule::create($data + ['company_id' => $companyId, 'created_by' => $userId, 'updated_by' => $userId]);
            $this->event($r, 'criar', null, $this->snapshot($r), $userId);

            return $r;
        });
    }

    public function update(FinanceRule $r, array $data, ?int $userId): FinanceRule
    {
        $before = $this->snapshot($r);
        $data = $this->clean(array_merge($before, $data));

        return DB::transaction(function () use ($r, $data, $before, $userId) {
            $r->update($data + ['updated_by' => $userId]);
            $after = $this->snapshot($r->fresh());
            $action = $before['active'] !== $after['active'] && array_diff_key($before, ['active' => 1]) == array_diff_key($after, ['active' => 1])
                ? ($after['active'] ? 'ativar' : 'desativar') : 'editar';
            $this->event($r, $action, $before, $after, $userId);

            return $r->fresh();
        });
    }

    public function delete(FinanceRule $r, ?int $userId): void
    {
        DB::transaction(function () use ($r, $userId) {
            $this->event($r, 'apagar', $this->snapshot($r), null, $userId);
            $r->delete();
        });
    }

    private function clean(array $d): array
    {
        $fail = fn (string $k, string $m) => throw ValidationException::withMessages([$k => [$m]]);
        if (! in_array($d['match_type'] ?? null, FinanceRule::TYPES, true)) {
            $fail('match_type', 'Escolha quando se aplica a regra: artigo, entidade, tipo de documento ou família.');
        }
        $values = array_values(array_unique(array_filter(array_map(fn ($v) => mb_substr(trim((string) $v), 0, 200), (array) ($d['match_values'] ?? [])), fn ($v) => $v !== '')));
        if ($values === []) {
            $fail('match_values', 'Indique pelo menos um valor.');
        }
        if (! array_key_exists($d['group'] ?? '', (array) config('finance_month.groups'))) {
            $fail('group', 'Grupo desconhecido.');
        }

        return [
            'match_type' => $d['match_type'], 'match_values' => $values,
            'match_label' => isset($d['match_label']) && trim((string) $d['match_label']) !== '' ? mb_substr(trim((string) $d['match_label']), 0, 255) : null,
            'entity_pingwin_id' => ($d['entity_pingwin_id'] ?? null) ?: null, 'entity_name' => ($d['entity_name'] ?? null) ?: null,
            'group' => $d['group'], 'active' => (bool) ($d['active'] ?? true), 'priority' => max(0, min(1000, (int) ($d['priority'] ?? 100))),
        ];
    }

    private function snapshot(FinanceRule $r): array
    {
        return array_intersect_key($r->only(self::FIELDS), array_flip(self::FIELDS));
    }

    private function event(FinanceRule $r, string $action, ?array $before, ?array $after, ?int $userId): void
    {
        FinanceRuleEvent::create(['company_id' => $r->company_id, 'rule_id' => $r->id, 'action' => $action, 'before' => $before, 'after' => $after,
            'user_id' => $userId, 'created_at' => now()]);
    }
}
