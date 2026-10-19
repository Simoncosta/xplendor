<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\FinanceRule;
use Illuminate\Support\Collection;

/**
 * XPLENDOR — Finanças: a regra de classificação que se aplica a uma linha (ou a um documento sem
 * linhas). Ganha a mais específica: artigo, depois entidade, depois tipo de documento, depois família;
 * no mesmo tipo, a de menor prioridade (e a mais antiga). Uma regra pode ficar restrita a uma entidade.
 */
final class FinanceRuleMatcher
{
    private const ORDER = ['artigo' => 0, 'entidade' => 1, 'tipo_documento' => 2, 'familia' => 3];

    /** @var Collection<int, FinanceRule> */
    private Collection $rules;

    public function __construct(int $companyId, ?Collection $rules = null)
    {
        $this->rules = ($rules ?? FinanceRule::where('company_id', $companyId)->where('active', true)->get())
            ->sortBy(fn (FinanceRule $r) => sprintf('%d-%05d-%010d', self::ORDER[$r->match_type] ?? 9, $r->priority, $r->id))->values();
    }

    /**
     * @param  array{entity: ?string, docconfig: ?string, codes: list<string>, path: string}  $ctx
     */
    public function match(array $ctx): ?FinanceRule
    {
        foreach ($this->rules as $r) {
            if ($r->entity_pingwin_id && (string) $r->entity_pingwin_id !== (string) $ctx['entity']) {
                continue;
            }
            $values = array_map(fn ($v) => trim((string) $v), (array) $r->match_values);
            $hit = match ($r->match_type) {
                'artigo' => array_intersect($values, array_filter(array_map('strval', $ctx['codes']))) !== [],
                'entidade' => in_array((string) $ctx['entity'], $values, true),
                'tipo_documento' => in_array((string) $ctx['docconfig'], $values, true),
                'familia' => $ctx['path'] !== '' && collect($values)->contains(fn ($v) => $v !== '' && str_starts_with(mb_strtolower($ctx['path']), mb_strtolower($v))),
                default => false,
            };
            if ($hit) {
                return $r;
            }
        }

        return null;
    }

    public static function label(FinanceRule $r, array $groups): string
    {
        $what = $r->match_label ?: match ($r->match_type) {
            'artigo' => 'Artigos ' . implode(', ', (array) $r->match_values),
            'entidade' => 'Entidade ' . ($r->entity_name ?? implode(', ', (array) $r->match_values)),
            'tipo_documento' => 'Tipo de documento ' . implode(', ', (array) $r->match_values),
            default => 'Família ' . implode(', ', (array) $r->match_values),
        };
        $where = $r->entity_pingwin_id && $r->match_type !== 'entidade' && $r->entity_name ? " de {$r->entity_name}" : '';

        return "{$what}{$where} → " . ($groups[$r->group]['label'] ?? $r->group);
    }
}
