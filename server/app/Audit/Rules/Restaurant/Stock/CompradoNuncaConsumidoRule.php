<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Stock;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Models\PingwinStockSnapshot;
use App\Services\Restaurant\RecipeCostService;
use App\Services\Restaurant\RecipeHints;
use Carbon\CarbonImmutable;

/**
 * Fatia 7a · Comprado mas nunca consumido (urgente): o artigo tem compras nos últimos 90 dias e stock
 * a subir, nenhuma ficha em vigor o usa e não se vende diretamente. No PingWin, os artigos de venda
 * com ficha não têm stock: ao vender, sai o stock dos componentes. Se a ficha usa outro artigo (o
 * caso da Coca Cola 0: a ficha usa o 100366 COCA-COLA ZERO GARRAFA e as compras entram no 100415
 * COCA COLA ZERO 0.35), o comprado acumula-se e o da ficha fica negativo. Indica o artigo parecido
 * que as fichas usam; "Corrigir" abre a ficha do artigo de venda.
 *
 * Stock a subir: a fotografia de hoje acima da de há 7 a 30 dias; sem fotografias antigas, conta
 * como a subir (há compras e nada sai). O impacto é o valor do stock ao custo médio: se as unidades
 * foram vendidas pela ficha do outro artigo, este stock não existe.
 */
final class CompradoNuncaConsumidoRule extends StockRule
{
    public const PURCHASE_DAYS = 90;

    public function code(): string
    {
        return 'restauracao.comprado_nunca_consumido';
    }

    public function entityType(): string
    {
        return 'artigo';
    }

    public function title(): string
    {
        return 'Artigos comprados que nada consome';
    }

    public function description(): string
    {
        return 'Artigos com compras e stock a subir que nenhuma ficha em vigor usa e que não se vendem diretamente.';
    }

    public function severity(): string
    {
        return 'alta';
    }

    public function screens(): array
    {
        return ['stock', 'artigos', 'fichas'];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $data = self::data($scope);
        $consumption = $data->consumption();
        $avg = $consumption->averages();
        $since = $data->daysAgo(self::PURCHASE_DAYS);
        $sales = $data->sales90();
        $purchases = $data->purchases();
        $today = $data->today();
        $components = $this->componentsInForce($consumption, $today);

        foreach ($data->stock() as $pid => $lines) {
            $pid = (string) $pid;
            $qnt = array_sum(array_map(fn ($l) => $l->qnt, $lines));
            $bought = array_values(array_filter($purchases[$pid] ?? [], fn ($r) => $r['date'] >= $since));
            if ($qnt <= 0 || ! $bought || ($avg['components'][$pid]['per_day'] ?? 0) > 0 || ($sales[$pid]['qty'] ?? 0) > 0
                || $consumption->usedInForceBy($pid, $today)) {
                continue;
            }
            $rising = $this->rising($companyId, $pid, $qnt);
            if ($rising === false) {
                continue;
            }
            $item = $data->item($pid);
            $name = (string) ($item?->description ?? $pid);
            $unit = self::unitOf($lines);
            $boughtQty = array_sum(array_map(fn ($r) => $r['qnt_base'], $bought));
            $value = array_sum(array_map(fn ($l) => $l->pcm !== null ? $l->qnt * $l->pcm : 0.0, $lines));
            $twin = $this->twin($data, $pid, $components);
            $dish = $twin ? ($twin['dishes'][0] ?? null) : null;
            $byWh = self::join(array_map(fn ($l) => sprintf('%s no %s', self::qty($l->qnt, $unit, 0), $this->warehouseName($companyId, (string) $l->warehouse_pingwin_id)),
                array_values(array_filter($lines, fn ($l) => abs($l->qnt) > 1e-6))));

            $seen = sprintf('Nos últimos %d dias comprou %s de %s. Tem %s em stock (%s)%s. Nenhuma ficha em vigor usa este artigo e ele não se vende diretamente.',
                self::PURCHASE_DAYS, self::qty($boughtQty, $unit, 0), $name, self::qty($qnt, $unit, 0), $byWh,
                is_array($rising) ? sprintf(', mais %s do que a %s', self::qty($rising['diff'], $unit, 0), Fmt::date($rising['date'])) : '');
            if ($twin) {
                $seen .= sprintf(' As fichas usam um artigo parecido: %s%s.', $twin['label'],
                    $twin['dishes'] ? ' (em ' . self::join(array_map(fn ($d) => $data->item($d)?->description ?? $d, array_slice($twin['dishes'], 0, 3))) . ')' : '');
            }

            yield new AuditFindingData(
                entityType: 'artigo', entityId: $pid, entityLabel: $data->label($pid),
                title: Fmt::title($name, 'comprado, mas nada o consome'),
                why: 'No PingWin, ao vender um prato, sai o stock dos ingredientes da ficha. Se a ficha usa outro artigo, este acumula-se e o da ficha fica negativo: o stock e o custo dos dois ficam errados.',
                suggestion: $twin && $dish
                    ? sprintf('Abra a ficha de %s e troque %s por este artigo (botão «Editar ficha»). Depois, acerte o stock dos dois artigos com um inventário no PingWin.', $data->item($dish)?->description ?? $dish, $twin['label'])
                    : 'Confirme em que fichas este artigo devia entrar e acrescente-o. Se o artigo se vende tal como se compra, ligue-o à venda no PingWin. Se é um stock que não se consome (material de limpeza, por exemplo), ignore com esse motivo.',
                evidence: [
                    'facts' => array_values(array_filter([
                        ['label' => 'Comprado (' . self::PURCHASE_DAYS . ' dias)', 'value' => self::qty($boughtQty, $unit, 0)],
                        ['label' => 'Stock total', 'value' => self::qty($qnt, $unit, 0)],
                        ['label' => 'Última compra', 'value' => Fmt::date((string) end($bought)['date'])],
                        $twin ? ['label' => 'Artigo parecido que as fichas usam', 'value' => $twin['label']] : null,
                    ])),
                    'table' => $this->stockTable($companyId, $lines),
                ],
                signature: 'nunca_consumido' . ($twin ? '|' . $twin['id'] : ''),
                impactCents: $value > 0 ? (int) round($value * 100) : null,
                weightCents: $value > 0 ? (int) round($value * 100) : null,
                fixUrl: $dish ? self::recipeUrl($dish, ['editar' => 1]) : self::stockUrl($pid),
                fixLabel: $dish ? 'Abrir a ficha' : 'Ver o stock',
                refs: array_values(array_filter([['artigo', $pid], $twin ? ['artigo', $twin['id']] : null, $dish ? ['artigo', $dish] : null])),
                seen: $seen,
                stake: $value > 0
                    ? sprintf('%s: o valor, ao custo médio, das %s em stock que nada desconta. Se foram vendidas pela ficha de outro artigo, este stock já não existe.', Fmt::eur($value), self::qty($qnt, $unit, 0))
                    : 'Não há um valor calculável: o PingWin não tem custo médio para este artigo.',
            );
        }
    }

    /**
     * Os componentes das fichas em vigor hoje (em alguma loja), com os artigos de venda que os usam.
     *
     * @return array<string, list<string>>
     */
    private function componentsInForce($consumption, string $today): array
    {
        $out = [];
        foreach ($consumption->productsWithBom() as $pid) {
            $v = RecipeCostService::versionInForce($consumption->versionsOf($pid), $today);
            foreach ($v['lines'] ?? [] as $l) {
                if ($l['component_id'] !== '') {
                    $out[$l['component_id']][] = $pid;
                }
            }
        }

        return $out;
    }

    /**
     * O componente parecido que as fichas usam (Jaccard ≥ 0,75 pelo nome; o mesmo critério das fichas),
     * com os artigos de venda que o usam, os mais vendidos primeiro.
     *
     * @param  array<string, list<string>>  $components
     * @return array{id:string, label:string, dishes:list<string>}|null
     */
    private function twin($data, string $pid, array $components): ?array
    {
        $tokens = RecipeHints::nameTokens($data->item($pid)?->description);
        if (! $tokens) {
            return null;
        }
        $best = null;
        foreach ($components as $cid => $dishes) {
            $cid = (string) $cid;
            if ($cid === $pid) {
                continue;
            }
            $score = RecipeHints::similarity($tokens, RecipeHints::nameTokens($data->item($cid)?->description));
            if ($score >= 0.75 && (! $best || $score > $best['score'])) {
                $best = ['id' => $cid, 'score' => $score, 'dishes' => $dishes];
            }
        }
        if (! $best) {
            return null;
        }
        $sales = $data->sales90();
        $dishes = array_values(array_unique(array_map('strval', $best['dishes'])));
        usort($dishes, fn ($a, $b) => ($sales[$b]['net'] ?? 0) <=> ($sales[$a]['net'] ?? 0));

        return ['id' => $best['id'], 'label' => str_replace(' · ', ' ', $data->label($best['id'])), 'dishes' => $dishes];
    }

    /**
     * O stock está a subir? Compara com a fotografia mais antiga entre 7 e 30 dias. Sem fotografia
     * antiga: true (há compras e nada sai). Com subida: {date, diff}. Sem subida: false.
     */
    private function rising(int $companyId, string $pid, float $qnt): array|bool
    {
        $now = CarbonImmutable::now('Europe/Lisbon');
        $old = PingwinStockSnapshot::where('company_id', $companyId)->where('product_pingwin_id', $pid)
            ->whereBetween('snapshot_date', [$now->subDays(30)->toDateString(), $now->subDays(7)->toDateString()])
            ->selectRaw('snapshot_date, SUM(qnt) as qnt')->groupBy('snapshot_date')->orderBy('snapshot_date')->first();
        if (! $old) {
            return true;
        }
        $diff = $qnt - (float) $old->qnt;

        return $diff > 1e-6 ? ['date' => substr((string) $old->snapshot_date, 0, 10), 'diff' => $diff] : false;
    }
}
