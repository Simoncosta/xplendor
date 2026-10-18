<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Menu;

use App\Audit\AuditScope;
use App\Audit\Rules\Restaurant\RestaurantRule;
use App\Models\MenuEngineeringItem;
use App\Models\MenuEngineeringSnapshot;

/**
 * Fatia 3 · Base das regras da engenharia de menu (M1, M2): leem o snapshot mais recente da empresa
 * toda, no modo "média de 90 dias" (o que o ecrã mostra por omissão). Sem snapshot, não acham nada.
 */
abstract class MenuRule extends RestaurantRule
{
    public function screens(): array
    {
        return ['menu', 'fichas'];
    }

    /** @return array{0: ?MenuEngineeringSnapshot, 1: \Illuminate\Support\Collection<int, MenuEngineeringItem>} */
    protected function snapshot(int $companyId, AuditScope $scope): array
    {
        return $scope->memo('restauracao.menu', function () use ($companyId) {
            $snap = MenuEngineeringSnapshot::latestFor($companyId, null, 'media');

            return [$snap, $snap ? MenuEngineeringItem::where('snapshot_id', $snap->id)->orderBy('pareto')->get() : collect()];
        });
    }

    protected static function menuUrl(string $tab = 'tabela'): string
    {
        return '/restauracao/engenharia-menu?tab=' . $tab;
    }

    /** "de 20/07/2026 a 17/10/2026" */
    protected static function periodText(MenuEngineeringSnapshot $s): string
    {
        return sprintf('de %s a %s', \App\Audit\Fmt::date((string) $s->period_from), \App\Audit\Fmt::date((string) $s->period_to));
    }
}
