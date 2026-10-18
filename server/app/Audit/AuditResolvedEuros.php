<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\AuditFinding;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * XPLENDOR — Auditoria v2: os euros resolvidos. Ao corrigir (ou quando a auditoria deixa de encontrar
 * o achado), guarda-se o € em jogo nesse momento, quem resolveu e quando (resolved_cents,
 * resolved_by, resolved_at). Conta só o que a corrida seguinte confirmou (estado "resolvido").
 * "Em jogo agora": o € dos achados abertos (o do Pareto).
 */
final class AuditResolvedEuros
{
    /** @param list<string> $areas */
    public static function summary(int $companyId, array $areas, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('Europe/Lisbon');
        $monthStart = $now->startOfMonth()->utc();
        $base = AuditFinding::where('company_id', $companyId)->whereIn('area', $areas ?: ['__nenhuma__']);
        $openCents = (int) (clone $base)->whereIn('state', AuditFinding::OPEN)->sum('impact_cents');
        $resolved = (clone $base)->where('state', 'resolvido')->whereNotNull('resolved_cents')->get(['id', 'rule', 'resolved_cents', 'resolved_by', 'resolved_at']);
        $month = $resolved->filter(fn ($f) => $f->resolved_at && $f->resolved_at->gte($monthStart));
        $names = User::whereIn('id', $resolved->pluck('resolved_by')->filter()->unique())->pluck('name', 'id');
        $titles = collect(AuditRegistry::all())->mapWithKeys(fn ($r) => [$r->code() => $r->title()]);

        $by = fn ($rows, string $key) => $rows->groupBy(fn ($f) => (string) ($f->{$key} ?? ''))->map(fn ($g, $k) => [
            'key' => $k === '' ? null : $k,
            'label' => $key === 'rule' ? ($titles[$k] ?? $k) : ($k === '' ? 'A auditoria (sem ninguém a marcar)' : ($names[(int) $k] ?? 'Utilizador')),
            'cents' => (int) $g->sum('resolved_cents'), 'count' => $g->count(),
        ])->sortByDesc('cents')->values()->all();

        return [
            'open_cents' => $openCents,
            'month' => ['cents' => (int) $month->sum('resolved_cents'), 'count' => $month->count(), 'from' => $now->startOfMonth()->toDateString(),
                'by_person' => $by($month, 'resolved_by'), 'by_rule' => $by($month, 'rule')],
            'total' => ['cents' => (int) $resolved->sum('resolved_cents'), 'count' => $resolved->count(),
                'by_person' => $by($resolved, 'resolved_by'), 'by_rule' => $by($resolved, 'rule')],
        ];
    }
}
