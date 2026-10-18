<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Money;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Models\MenuEngineeringItem;
use App\Models\MenuEngineeringSnapshot;
use App\Services\Restaurant\RecipeCostService;

/**
 * C2 · A margem do prato caiu: o custo do prato ao último preço de compra contra o custo pela média de
 * 90 dias (a função de custo da Fatia 3: os dois snapshots da engenharia de menu, "ultimo" e "media",
 * do mesmo período). Achado se o food cost subir mais de 3 pp ou o custo mais de 10 %. Só os pratos
 * Confirmados ou Parciais. Diz o ingrediente que mais pesou ("o bife subiu 18 % na …").
 * € em jogo = a subida do custo × a quantidade vendida por mês. Ações: abrir o detalhe na engenharia
 * de menu e simular o preço (+0,25, +0,50, +1,00 €) com o efeito em €/mês e no food cost.
 */
final class MargemPratoRule extends MoneyRule
{
    public function code(): string
    {
        return 'restauracao.dinheiro_margem_prato';
    }

    public function entityType(): string
    {
        return 'artigo';
    }

    public function title(): string
    {
        return 'A margem do prato caiu';
    }

    public function description(): string
    {
        return 'O custo do prato aos últimos preços de compra está acima do custo médio de 90 dias: o food cost subiu.';
    }

    public function screens(): array
    {
        return ['menu', 'fichas'];
    }

    /** @return array{0:?MenuEngineeringSnapshot,1:?MenuEngineeringSnapshot} os snapshots "media" e "ultimo" do mesmo período */
    public static function snapshots(int $companyId): array
    {
        $last = MenuEngineeringSnapshot::latestFor($companyId, null, 'ultimo');
        if (! $last) {
            return [null, null];
        }
        $avg = MenuEngineeringSnapshot::latestFor($companyId, null, 'media', substr((string) $last->period_from, 0, 10), substr((string) $last->period_to, 0, 10));

        return [$avg, $avg ? $last : null];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        [$avgSnap, $lastSnap] = self::snapshots($companyId);
        if (! $avgSnap) {
            return;
        }
        $avg = MenuEngineeringItem::where('snapshot_id', $avgSnap->id)->get()->keyBy(fn ($i) => (string) $i->product_id);
        $ppLimit = (float) self::cfg('margin_food_cost_pp', 3);
        $pctLimit = (float) self::cfg('margin_cost_pct', 10);
        $days = max(1, (int) round((strtotime(substr((string) $avgSnap->period_to, 0, 10)) - strtotime(substr((string) $avgSnap->period_from, 0, 10))) / 86400) + 1);
        $candidates = [];
        foreach (MenuEngineeringItem::where('snapshot_id', $lastSnap->id)->get() as $i) {
            $a = $avg[(string) $i->product_id] ?? null;
            if (! $a || $i->excluded !== null || ! in_array($i->confidence, ['confirmado', 'parcial'], true) || ! in_array($a->confidence, ['confirmado', 'parcial'], true)
                || $i->cost === null || $a->cost === null || $a->cost <= 0 || $i->price === null || $i->price <= 0 || $i->qty <= 0) {
                continue;
            }
            $fcAvg = $a->cost / $i->price * 100;
            $fcLast = $i->cost / $i->price * 100;
            $rise = ($i->cost / $a->cost - 1) * 100;
            // Com o custo acima do preço, o problema é a ficha (as regras das fichas e das unidades), não a margem.
            if ($fcLast > (float) self::cfg('margin_max_food_cost', 100)) {
                continue;
            }
            if ($fcLast - $fcAvg > $ppLimit || $rise > $pctLimit) {
                $candidates[(string) $i->product_id] = [$i, $a, $fcAvg, $fcLast, $rise];
            }
        }
        if (! $candidates) {
            return;
        }
        $data = $this->data($scope);
        $media = (new RecipeCostService($companyId, $data->cost()->today()))->load(array_keys($candidates));
        $ultimo = (new RecipeCostService($companyId, $data->cost()->today()))->withPriceMode('ultimo')->load(array_keys($candidates));
        foreach ($candidates as $pid => [$i, $a, $fcAvg, $fcLast, $rise]) {
            $pid = (string) $pid;
            $cause = self::cause($media, $ultimo, $pid, $data->today());
            $monthlyQty = $i->qty / $days * 30.4;
            $eur = round(($i->cost - $a->cost) * $monthlyQty, 2);
            if ($eur <= 0) {
                continue;
            }
            $supplier = $cause ? self::lastSupplier($data->purchases()[$cause['component_id']] ?? []) : null;
            $causeText = $cause ? sprintf('%s subiu %s%s', mb_strtolower((string) $cause['description']), Fmt::pct($cause['pct']), $supplier ? " na {$supplier}" : '') : null;
            $vat = (float) ($i->detail['vat_pct'] ?? 0);
            $partial = $i->confidence === 'parcial';

            yield new AuditFindingData(
                entityType: 'artigo', entityId: $pid, entityLabel: trim(($i->code ? $i->code . ' · ' : '') . $i->description),
                title: Fmt::title((string) $i->description, sprintf('food cost de %s para %s', Fmt::pct($fcAvg), Fmt::pct($fcLast))),
                why: 'Quando um ingrediente sobe e o preço do prato fica igual, cada prato vendido deixa menos margem, sem que ninguém o veja na caixa.',
                suggestion: $cause ? sprintf('Veja porque %s. Se a subida é para ficar, reveja a porção ou o preço de venda: simule +0,25, +0,50 ou +1,00 €.', $causeText)
                    : 'Veja que ingredientes subiram no detalhe do prato. Se a subida é para ficar, reveja a porção ou o preço de venda.',
                evidence: [
                    'facts' => array_values(array_filter([
                        ['label' => 'Preço médio sem IVA', 'value' => round((float) $i->price, 2), 'format' => 'eur'],
                        ['label' => $partial ? 'Custo médio de 90 dias (pelo menos)' : 'Custo médio de 90 dias', 'value' => round((float) $a->cost, 2), 'format' => 'eur'],
                        ['label' => $partial ? 'Custo aos últimos preços (pelo menos)' : 'Custo aos últimos preços', 'value' => round((float) $i->cost, 2), 'format' => 'eur'],
                        ['label' => 'Food cost', 'value' => sprintf('%s → %s', Fmt::pct($fcAvg, 1), Fmt::pct($fcLast, 1))],
                        ['label' => 'Vendidos por mês', 'value' => Fmt::qty($monthlyQty, 0)],
                        $cause ? ['label' => 'O que mais pesou', 'value' => ucfirst($causeText), 'tip' => sprintf('Por prato, +%s.', Fmt::unitPrice($cause['delta']))] : null,
                    ])),
                    'table' => $cause ? [
                        'title' => 'Os ingredientes que subiram (por prato)',
                        'columns' => [['key' => 'description', 'label' => 'Ingrediente'], ['key' => 'avg', 'label' => 'Custo médio', 'align' => 'end', 'format' => 'eur4'],
                            ['key' => 'last', 'label' => 'Ao último preço', 'align' => 'end', 'format' => 'eur4'], ['key' => 'pct', 'label' => 'Subida', 'align' => 'end', 'format' => 'pct']],
                        'rows' => array_map(fn ($r) => ['description' => $r['description'], 'avg' => round($r['avg'], 4), 'last' => round($r['last'], 4), 'pct' => round($r['pct'], 1)], $cause['rows']),
                    ] : null,
                ],
                signature: sprintf('%.1f|%.1f', $fcAvg, $fcLast),
                impactCents: (int) round($eur * 100), severity: self::severityFor($eur),
                fixUrl: '/restauracao/engenharia-menu?tab=tabela&prato=' . rawurlencode($pid), fixLabel: 'Abrir na engenharia de menu',
                fixAction: ['type' => 'simular_preco', 'product_id' => $pid, 'price' => round((float) $i->price, 4), 'cost' => round((float) $i->cost, 4),
                    'cost_avg' => round((float) $a->cost, 4), 'monthly_qty' => round($monthlyQty, 2), 'vat_pct' => $vat, 'steps' => [0.25, 0.5, 1.0]],
                refs: array_values(array_filter([['artigo', $pid], $cause ? ['artigo', $cause['component_id']] : null])),
                seen: sprintf('%s passou de %s para %s de food cost%s. O custo por prato foi de %s (média de 90 dias) para %s aos últimos preços; o preço sem IVA é %s.',
                    $i->description, Fmt::pct($fcAvg), Fmt::pct($fcLast), $cause ? ': ' . $causeText : '', Fmt::eur($a->cost), Fmt::eur($i->cost), Fmt::eur($i->price)),
                stake: sprintf('%s por mês: +%s por prato × %s vendidos por mês (%s).', Fmt::eur($eur), Fmt::eur($i->cost - $a->cost), Fmt::qty($monthlyQty, 0), self::periodText($avgSnap)),
            );
        }
    }

    /**
     * O ingrediente que mais pesou: a linha da ficha com a maior subida do custo (descendo aos
     * semi-produtos), com a subida do preço unitário. Também a lista dos que subiram.
     */
    public static function cause(RecipeCostService $media, RecipeCostService $ultimo, string $pid, string $today): ?array
    {
        $v = RecipeCostService::versionInForce($media->versionsOf($pid), $today);
        if (! $v) {
            return null;
        }
        $rows = self::leafDeltas($media->costVersion($v, $pid)['lines'], $ultimo->costVersion($v, $pid)['lines'], 1.0);
        $rows = array_values(array_filter($rows, fn ($r) => $r['delta'] > 1e-6));
        if (! $rows) {
            return null;
        }
        usort($rows, fn ($a, $b) => $b['delta'] <=> $a['delta']);

        return $rows[0] + ['rows' => array_slice($rows, 0, 8)];
    }

    private static function leafDeltas(array $a, array $b, float $mult): array
    {
        $byKey = [];
        foreach ($b as $l) {
            $byKey[$l['key']] = $l;
        }
        $out = [];
        foreach ($a as $l) {
            $o = $byKey[$l['key']] ?? null;
            if (! $o || $l['line_cost'] === null || $o['line_cost'] === null) {
                continue;
            }
            if (($l['sub']['lines'] ?? null) && ($o['sub']['lines'] ?? null) && ($l['sub']['cost'] ?? 0) > 0) {
                // O semi-produto: a parte de cada ingrediente na linha desta ficha.
                $out = array_merge($out, self::leafDeltas($l['sub']['lines'], $o['sub']['lines'], $mult * $l['line_cost'] / $l['sub']['cost']));
                continue;
            }
            $avg = (float) ($l['unit_cost'] ?? 0);
            $last = (float) ($o['unit_cost'] ?? 0);
            $out[] = ['component_id' => (string) $l['component_id'], 'description' => (string) $l['description'],
                'delta' => ($o['line_cost'] - $l['line_cost']) * $mult, 'avg' => $avg, 'last' => $last,
                'pct' => $avg > 0 ? ($last / $avg - 1) * 100 : 0.0];
        }

        return $out;
    }

    private static function lastSupplier(array $lines): ?string
    {
        $l = $lines ? end($lines) : null;

        return $l && $l['supplier'] ? (string) $l['supplier'] : null;
    }

    private static function periodText(MenuEngineeringSnapshot $s): string
    {
        return sprintf('vendas de %s a %s', Fmt::date(substr((string) $s->period_from, 0, 10)), Fmt::date(substr((string) $s->period_to, 0, 10)));
    }
}
