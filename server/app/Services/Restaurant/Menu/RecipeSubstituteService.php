<?php

declare(strict_types=1);

namespace App\Services\Restaurant\Menu;

use App\Audit\Rules\Restaurant\DuplicadosRule;
use App\Audit\Rules\Restaurant\RestaurantAuditData;
use App\Models\ArticleGroup;
use App\Services\Restaurant\RecipeCostService;
use App\Services\Restaurant\RecipeHints;

/**
 * XPLENDOR — Auditoria v2 (M2): o substituto de um ingrediente sem compras numa ficha. Primeiro o
 * artigo principal do mesmo grupo de duplicados (ou outro do grupo, com compras); depois um artigo da
 * mesma família com a descrição parecida e com compras (ex.: 100366 → 100415). Para cada um, o custo
 * por porção e por mês antes e depois. Não grava nada: a troca faz-se ficha a ficha no editor da
 * Fatia 4, com confirmação e releitura.
 */
final class RecipeSubstituteService
{
    public function __construct(private int $companyId) {}

    public function for(string $dishId, string $componentId): array
    {
        $cost = (new RecipeCostService($this->companyId))->load([$dishId], [$componentId]);
        $data = new RestaurantAuditData($this->companyId);
        $today = $cost->today()->toDateString();
        $versions = $cost->versionsOf($dishId);
        $v = RecipeCostService::versionInForce($versions, $today) ?? collect($versions)->where('deleted', false)->sortBy('valid_from')->last();
        $line = $v ? collect($v['lines'])->firstWhere('component_id', $componentId) : null;
        if (! $v || ! $line) {
            return ['found' => false, 'reason' => 'A ficha em vigor já não usa este ingrediente.', 'candidates' => []];
        }
        $item = $cost->item($componentId) ?? $data->item($componentId);
        $desc = (string) ($item?->description ?? $line['description'] ?? $componentId);
        $sold = (float) ($data->sales90()[$dishId]['qty'] ?? 0);
        $old = $cost->purchaseOf($componentId);
        $oldPrice = $old['avg'] ?? $old['last'] ?? null;
        $before = $oldPrice !== null ? round($line['qnt_base'] * $oldPrice * (1 + $line['waste'] / 100), 4) : null;

        $candidates = [];
        $add = function (string $pid, string $why) use (&$candidates, $componentId) {
            if ($pid !== $componentId && ! isset($candidates[$pid])) {
                $candidates[$pid] = $why;
            }
        };
        // 1) O mesmo grupo de duplicados: o principal escolhido; senão os outros do grupo com compras.
        $key = DuplicadosRule::groupKey($desc);
        if ($key !== '') {
            $main = ArticleGroup::where('company_id', $this->companyId)->where('key', $key)->value('main_product_pingwin_id');
            if ($main) {
                $add((string) $main, 'O artigo principal do mesmo grupo de duplicados.');
            }
            foreach ($data->catalog() as $pid => $it) {
                if (DuplicadosRule::groupKey($it->description) === $key) {
                    $add((string) $pid, 'Do mesmo grupo de duplicados (o mesmo nome).');
                }
            }
        }
        // 2) Da mesma família, com a descrição parecida e com compras.
        $similar = $cost->similarPurchased($componentId, $desc);
        if ($similar) {
            $add($similar['product_id'], sprintf('Descrição parecida (%d %% das palavras iguais) e com compras.', (int) round($similar['score'] * 100)));
        }
        $tokens = RecipeHints::nameTokens($desc);
        foreach ($data->catalog() as $pid => $it) {
            if ((string) $it->family === (string) $item?->family && $item?->family && RecipeHints::similarity($tokens, RecipeHints::nameTokens($it->description)) >= 0.5) {
                $add((string) $pid, 'Da mesma família, com a descrição parecida.');
            }
        }

        $cost->ensureItems(array_keys($candidates));
        $rootOld = $cost->componentBaseRoot($componentId, $line['unit_id']);
        $out = [];
        foreach ($candidates as $pid => $why) {
            $pid = (string) $pid;
            $c = (new RecipeCostService($this->companyId))->load([], [$pid]);
            $stats = $c->purchaseOf($pid);
            if (! in_array($stats['status'], ['ok', 'antigo'], true) || ($stats['avg'] ?? $stats['last']) === null) {
                continue; // sem compras: não serve de substituto
            }
            $rootNew = $c->componentBaseRoot($pid);
            $sameFamily = $rootNew === $rootOld;
            $price = (float) ($stats['avg'] ?? $stats['last']);
            $after = $sameFamily ? round($line['qnt_base'] * $price * (1 + $line['waste'] / 100), 4) : null;
            $it = $c->item($pid);
            $units = $c->unitsFor($pid);
            $keepUnit = $sameFamily && in_array($line['unit_id'], array_column($units, 'id'), true);
            $out[] = [
                'product_id' => $pid, 'code' => $it?->code ? ltrim((string) $it->code, "\u{FEFF}") : null, 'description' => (string) ($it?->description ?? $pid),
                'family' => RestaurantAuditData::familyPath($it?->family), 'why' => $why, 'last_purchase' => $stats['last_date'],
                'price_per_base_eur' => $price, 'same_unit_family' => $sameFamily,
                'cost_before_eur' => $before, 'cost_after_eur' => $after,
                'monthly_before_eur' => $before !== null ? round($before * $sold / 3, 2) : null,
                'monthly_after_eur' => $after !== null ? round($after * $sold / 3, 2) : null,
                'note' => $sameFamily ? null : 'Conta numa unidade de outra família: a quantidade da linha tem de ser revista no editor.',
                // O que vai para o editor das fichas (Fatia 4): a mesma linha, com o substituto.
                // Sem a chave: o PingWin não troca o componente de uma linha existente (provado na ficha do
                // 101335); a troca grava-se como apagar a linha antiga e acrescentar a nova.
                'line' => ['key' => null, 'replaces_key' => $line['key'], 'component_id' => $pid, 'description' => (string) ($it?->description ?? $pid),
                    'unit_id' => $keepUnit ? $line['unit_id'] : ($rootNew ?? $line['unit_id']), 'qnt' => $keepUnit ? $line['qnt'] : $line['qnt_base'], 'units' => $units],
            ];
        }
        usort($out, fn ($a, $b) => [! $a['same_unit_family'], $a['why'] === 'Da mesma família, com a descrição parecida.'] <=> [! $b['same_unit_family'], $b['why'] === 'Da mesma família, com a descrição parecida.']);

        return [
            'found' => (bool) $out,
            'reason' => $out ? null : 'Não há nenhum artigo parecido com compras: confirme na cozinha que artigo se usa e troque-o no editor da ficha.',
            'dish' => ['product_id' => $dishId, 'label' => $data->label($dishId), 'sold_qty_90d' => $sold, 'bom_id' => $v['bom_id']],
            'component' => ['product_id' => $componentId, 'label' => $data->label($componentId, $desc), 'last_purchase' => $old['last_date'],
                'qnt' => $line['qnt'], 'unit' => $line['unit'], 'line_key' => $line['key']],
            'candidates' => array_slice($out, 0, 3),
        ];
    }
}
