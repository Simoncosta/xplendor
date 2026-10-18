<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Menu;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;

/**
 * Fatia 3 · M1 · Prato com food cost acima do alvo (35 % por omissão; configurável por família em
 * config/menu_engineering.php). Só os artigos com custo (Confirmado ou Parcial: no Parcial o custo é um
 * mínimo, o food cost real é ainda maior) e incluídos na matriz. € = (custo − alvo × preço) × quantidade.
 */
final class FoodCostAcimaAlvoRule extends MenuRule
{
    public function code(): string
    {
        return 'restauracao.menu_food_cost_alvo';
    }

    public function entityType(): string
    {
        return 'artigo';
    }

    public function title(): string
    {
        return 'Pratos com o food cost acima do alvo';
    }

    public function description(): string
    {
        return 'Pratos em que o custo dos ingredientes passa a percentagem alvo do preço sem IVA.';
    }

    public function severity(): string
    {
        return 'media';
    }

    public static function targetFor(string $family): float
    {
        $targets = (array) config('menu_engineering.family_targets', []);
        foreach ($targets as $f => $t) {
            if (mb_strtolower((string) $f) === mb_strtolower($family) || str_starts_with(mb_strtolower($family), mb_strtolower((string) $f) . ' ›')) {
                return (float) $t;
            }
        }

        return (float) config('menu_engineering.food_cost_target_pct', 35);
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        [$snap, $items] = $this->snapshot($companyId, $scope);
        if (! $snap) {
            return;
        }
        foreach ($items as $i) {
            if ($i->excluded !== null || $i->cost === null || $i->price === null || $i->price <= 0 || $i->confidence === 'sem_custo') {
                continue;
            }
            $target = self::targetFor((string) $i->family);
            $fc = $i->cost / $i->price * 100;
            if ($fc <= $target) {
                continue;
            }
            $eur = ($i->cost - $target / 100 * $i->price) * $i->qty;
            $partial = $i->confidence === 'parcial';

            yield new AuditFindingData(
                entityType: 'artigo', entityId: (string) $i->product_id, entityLabel: trim(($i->code ? $i->code . ' · ' : '') . $i->description),
                title: Fmt::title((string) $i->description, sprintf('food cost de %s (alvo %s)', Fmt::pct($fc), Fmt::pct($target))),
                why: sprintf('Num prato a %s sem IVA, os ingredientes custam %s: sobra menos para pagar a equipa, a renda e o lucro do que a casa definiu como alvo.',
                    Fmt::eur($i->price), Fmt::eur($i->cost)),
                suggestion: 'Reveja a porção e os ingredientes da ficha, negocie o preço com o fornecedor, ou suba o preço de venda. Se o alvo desta família for outro, ajuste-o na configuração.',
                evidence: ['facts' => [
                    ['label' => 'Família', 'value' => (string) $i->family],
                    ['label' => 'Preço médio sem IVA', 'value' => $i->price, 'format' => 'eur'],
                    ['label' => $partial ? 'Custo (pelo menos)' : 'Custo', 'value' => $i->cost, 'format' => 'eur'],
                    ['label' => 'Food cost', 'value' => Fmt::pct($fc, 1)],
                    ['label' => 'Alvo', 'value' => Fmt::pct($target)],
                    ['label' => 'Quantidade vendida', 'value' => Fmt::qty($i->qty)],
                ]],
                signature: sprintf('%.0f', $fc),
                impactCents: (int) round($eur * 100),
                fixUrl: self::menuUrl(), fixLabel: 'Abrir a engenharia de menu',
                refs: [['artigo', (string) $i->product_id]],
                seen: sprintf('%s, %s: vendeu %s a %s sem IVA cada; os ingredientes custam %s%s (food cost de %s; alvo de %s).',
                    self::periodText($snap), $i->description, Fmt::units($i->qty, 'unidade'), Fmt::eur($i->price), $partial ? 'pelo menos ' : '', Fmt::eur($i->cost),
                    Fmt::pct($fc, 1), Fmt::pct($target)),
                stake: sprintf('%s: o custo acima do alvo (custo − %s do preço) × as %s unidades vendidas no período.', Fmt::eur($eur), Fmt::pct($target), Fmt::qty($i->qty)),
            );
        }
    }
}
