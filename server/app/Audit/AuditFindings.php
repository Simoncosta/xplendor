<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\AuditFinding;
use App\Support\Text\MoneyText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * XPLENDOR — Auditoria: a lista de achados (filtros, Pareto com a % acumulada, "os primeiros N
 * achados cobrem 80%") e a forma de cada achado na API. Os euros (impacto, peso, os da evidência e
 * os dos textos) só saem do servidor para quem tem financas.ver, como na Bússola.
 */
final class AuditFindings
{
    public const SORTS = ['pareto', 'severity', 'title', 'entity', 'area', 'state', 'last_seen', 'first_seen', 'impact'];
    private const MONEY_FORMATS = ['eur', 'eur4', 'cents'];

    /**
     * @param  list<string>  $areas  as áreas que a pessoa pode ver
     */
    public function list(int $companyId, array $areas, array $filters, string $sort, string $dir, int $page, int $perPage, bool $finance): array
    {
        $q = $this->query($companyId, $areas, $filters);
        $total = (clone $q)->count();
        $bySeverity = (clone $q)->selectRaw('severity, COUNT(*) as n')->groupBy('severity')->pluck('n', 'severity')->all();

        // Pareto honesto: primeiro o dinheiro em jogo (impacto €), do maior para o menor; depois o
        // impacto a confirmar; só depois o que só tem peso (vendas ou compras ligadas). A % acumulada
        // e "os primeiros N cobrem 80%" contam só o impacto €.
        $light = (clone $q)->get(['id', 'impact_cents', 'impact_pending_cents', 'weight_cents', 'severity']);
        $keys = [];
        foreach ($light as $f) {
            $keys[$f->id] = [...AuditFinding::paretoKey($f->impact_cents, $f->impact_pending_cents, $f->weight_cents), AuditFinding::SEVERITY_RANK[$f->severity] ?? 0];
        }
        $paretoOrder = array_keys($keys);
        usort($paretoOrder, fn ($a, $b) => [$keys[$a][0], $keys[$b][1], $keys[$b][2], $a] <=> [$keys[$b][0], $keys[$a][1], $keys[$a][2], $b]);
        $sum = 0;
        $pending = 0;
        $withImpact = 0;
        foreach ($keys as $k) {
            if ($k[0] === 0) {
                $sum += $k[1];
                $withImpact++;
            } elseif ($k[0] === 1) {
                $pending += $k[1];
            }
        }
        $cum = [];
        $running = 0;
        $n80 = null;
        foreach ($paretoOrder as $i => $id) {
            if ($keys[$id][0] !== 0) {
                continue; // fora do dinheiro em jogo: sem % acumulada
            }
            $running += $keys[$id][1];
            $cum[$id] = $sum > 0 ? round($running / $sum * 100, 1) : null;
            if ($n80 === null && $sum > 0 && $running >= $sum * 0.8) {
                $n80 = $i + 1;
            }
        }

        if ($sort === 'pareto') {
            $ids = array_slice($paretoOrder, ($page - 1) * $perPage, $perPage);
            $models = AuditFinding::with('stateUser:id,name')->whereIn('id', $ids)->get()->keyBy('id');
            $rows = array_values(array_filter(array_map(fn ($id) => $models[$id] ?? null, $ids)));
        } else {
            $col = match ($sort) {
                'severity' => null,
                'title' => 'title', 'entity' => 'entity_label', 'area' => 'area', 'state' => 'state',
                'first_seen' => 'first_seen_at', 'impact' => 'impact_cents', default => 'last_seen_at',
            };
            $ordered = (clone $q)->with('stateUser:id,name');
            if ($col === null) {
                $ordered->orderByRaw("CASE severity WHEN 'alta' THEN 3 WHEN 'media' THEN 2 WHEN 'baixa' THEN 1 ELSE 0 END " . ($dir === 'asc' ? 'asc' : 'desc'));
            } else {
                $ordered->orderBy($col, $dir === 'asc' ? 'asc' : 'desc');
            }
            $rows = $ordered->orderBy('id')->forPage($page, $perPage)->get()->all();
        }

        $paginator = new LengthAwarePaginator(
            array_map(fn ($f) => $this->shape($f, $finance, $cum[$f->id] ?? null), $rows), $total, $perPage, $page,
        );

        return [
            'findings' => $paginator->toArray(),
            'summary' => [
                'total' => $total,
                'by_severity' => array_map('intval', $bySeverity),
                'with_impact' => $withImpact,
                'pareto_n80' => $n80,
                'pareto_total_cents' => $finance ? $sum : null,
                'pending_total_cents' => $finance ? $pending : null,
            ],
        ];
    }

    /**
     * "Agrupar por regra": por regra, quantos achados, o dinheiro em jogo, o a confirmar, o peso e a
     * gravidade mais alta; primeiro as regras com mais dinheiro em jogo.
     *
     * @return list<array<string, mixed>>
     */
    public function groups(int $companyId, array $areas, array $filters, bool $finance): array
    {
        $rows = $this->query($companyId, $areas, $filters)
            ->groupBy('rule', 'area')
            ->get(['rule', 'area', DB::raw('COUNT(*) as n'), DB::raw('SUM(COALESCE(impact_cents, 0)) as impact'),
                DB::raw('SUM(COALESCE(impact_pending_cents, 0)) as pending'), DB::raw('SUM(COALESCE(weight_cents, 0)) as weight'),
                DB::raw("MAX(CASE severity WHEN 'alta' THEN 3 WHEN 'media' THEN 2 WHEN 'baixa' THEN 1 ELSE 0 END) as rank")]);
        $names = array_flip(AuditFinding::SEVERITY_RANK);
        $out = [];
        foreach ($rows as $r) {
            $rule = AuditRegistry::get((string) $r->rule);
            $out[] = [
                'rule' => (string) $r->rule, 'rule_title' => $rule?->title() ?? (string) $r->rule, 'rule_description' => $rule?->description(),
                'area' => (string) $r->area, 'area_label' => AuditAreas::label((string) $r->area), 'count' => (int) $r->n,
                'impact_cents' => $finance ? (int) $r->impact : null, 'pending_cents' => $finance ? (int) $r->pending : null,
                'weight_cents' => $finance ? (int) $r->weight : null, 'severity' => $names[(int) $r->rank] ?? 'info',
                '_order' => [(int) $r->impact, (int) $r->pending, (int) $r->rank, (int) $r->n],
            ];
        }
        usort($out, fn ($a, $b) => $b['_order'] <=> $a['_order']);

        return array_map(fn ($g) => array_diff_key($g, ['_order' => 1]), $out);
    }

    /** As linhas que a pessoa pode ver, com os filtros. */
    public function query(int $companyId, array $areas, array $filters): Builder
    {
        $states = match ($filters['state'] ?? 'abertos') {
            'abertos', '' => AuditFinding::OPEN,
            'todos' => AuditFinding::STATES,
            default => array_values(array_intersect(explode(',', (string) $filters['state']), AuditFinding::STATES)),
        };
        $csv = fn (?string $v) => array_values(array_filter(array_map('trim', explode(',', (string) $v))));

        return AuditFinding::query()->where('company_id', $companyId)->whereIn('area', $areas ?: ['__nenhuma__'])
            ->whereIn('state', $states ?: ['__nenhum__'])
            ->when(! empty($filters['area']), fn ($q) => $q->where('area', $filters['area']))
            ->when(! empty($filters['rule']), fn ($q) => $q->whereIn('rule', $csv($filters['rule'])))
            ->when(! empty($filters['severity']), fn ($q) => $q->whereIn('severity', $csv($filters['severity'])))
            ->when(! empty($filters['entity_type']) && ! empty($filters['entity_id']), fn ($q) => $q->whereExists(fn ($e) => $e->selectRaw('1')
                ->from('audit_finding_refs as r')->whereColumn('r.finding_id', 'audit_findings.id')
                ->where('r.entity_type', $filters['entity_type'])->where('r.entity_id', (string) $filters['entity_id'])))
            ->when(! empty($filters['search']), function ($q) use ($filters) {
                $s = '%' . str_replace(['%', '_'], ['\%', '\_'], trim((string) $filters['search'])) . '%';
                $q->where(fn ($w) => $w->where('title', 'like', $s)->orWhere('entity_label', 'like', $s));
            });
    }

    /** Um achado para a API (na lista ou no detalhe). */
    public function shape(AuditFinding $f, bool $finance, ?float $cumPct = null, bool $full = false): array
    {
        $rule = AuditRegistry::get($f->rule);
        $out = [
            'id' => $f->id,
            'rule' => $f->rule,
            'rule_title' => $rule?->title() ?? $f->rule,
            'area' => $f->area,
            'area_label' => AuditAreas::label($f->area),
            'severity' => $f->severity,
            'entity_type' => $f->entity_type,
            'entity_id' => $f->entity_id,
            'entity_label' => $f->entity_label,
            'title' => $f->title,
            'impact_cents' => $f->impact_cents,
            'impact_pending_cents' => $f->impact_pending_cents,
            'weight_cents' => $f->weight_cents,
            'pareto_pct' => $cumPct,
            'state' => $f->state,
            'state_by' => $f->stateUser?->name,
            'state_at' => $f->state_at?->toIso8601String(),
            'state_reason' => $f->state_reason,
            'note' => $f->note,
            'first_seen_at' => $f->first_seen_at?->toIso8601String(),
            'last_seen_at' => $f->last_seen_at?->toIso8601String(),
            'resolved_at' => $f->resolved_at?->toIso8601String(),
            'fix' => ['url' => $f->fix_url, 'label' => $f->fix_label, 'action' => $f->fix_action],
        ];
        if ($full) {
            $out += [
                'seen' => $f->seen,
                'why' => $f->why,
                'suggestion' => $f->suggestion,
                'stake' => $f->stake,
                'evidence' => $f->evidence ?? [],
                'rule_description' => $rule?->description(),
                'refs' => $f->refs()->get(['entity_type', 'entity_id'])->map(fn ($r) => ['type' => $r->entity_type, 'id' => $r->entity_id])->all(),
                'events' => $f->events()->with('user:id,name')->orderBy('id')->get()->map(fn ($e) => [
                    'from' => $e->from_state, 'to' => $e->to_state, 'user' => $e->user?->name, 'run_id' => $e->run_id,
                    'reason' => $e->reason, 'at' => $e->created_at?->toIso8601String(),
                ])->all(),
            ];
        }

        return $finance ? $out : self::redact($out);
    }

    /** Sem financas.ver: os euros do achado ficam no servidor (impacto, peso, evidência, textos). */
    public static function redact(array $f): array
    {
        $f['impact_cents'] = null;
        $f['impact_pending_cents'] = null;
        $f['weight_cents'] = null;
        $f['pareto_pct'] = null;
        foreach (['title', 'entity_label', 'seen', 'why', 'suggestion', 'stake', 'state_reason'] as $k) {
            if (isset($f[$k]) && is_string($f[$k])) {
                $f[$k] = MoneyText::redact($f[$k]);
            }
        }
        if (isset($f['evidence']) && is_array($f['evidence'])) {
            $f['evidence'] = self::redactEvidence($f['evidence']);
        }

        return $f;
    }

    public static function redactEvidence(array $e): array
    {
        foreach ($e['facts'] ?? [] as $i => $fact) {
            if (in_array($fact['format'] ?? null, self::MONEY_FORMATS, true)) {
                $e['facts'][$i]['value'] = null;
                $e['facts'][$i]['hidden'] = true;
            } elseif (is_string($fact['value'] ?? null)) {
                $e['facts'][$i]['value'] = MoneyText::redact($fact['value']);
            }
        }
        if (isset($e['table']['rows']) && is_array($e['table']['rows'])) {
            $money = [];
            foreach ($e['table']['columns'] ?? [] as $c) {
                if (in_array($c['format'] ?? null, self::MONEY_FORMATS, true)) {
                    $money[] = $c['key'];
                }
            }
            foreach ($e['table']['rows'] as $i => $row) {
                foreach ($row as $k => $v) {
                    if (in_array($k, $money, true) || str_ends_with((string) $k, '_cents')) {
                        $e['table']['rows'][$i][$k] = null;
                    } elseif (is_string($v)) {
                        $e['table']['rows'][$i][$k] = MoneyText::redact($v);
                    }
                }
            }
            $e['table']['hidden_columns'] = $money;
        }
        if (isset($e['note']) && is_string($e['note'])) {
            $e['note'] = MoneyText::redact($e['note']);
        }

        return $e;
    }
}
