<?php

declare(strict_types=1);

namespace App\Services\Restaurant\Menu;

/**
 * XPLENDOR — Fatia 3: a matriz de Kasavana e Smith, por família (função pura).
 *  · Popularidade: "muito vendido" se a quota do artigo na família ≥ fator × (1 ÷ N), com N = artigos
 *    incluídos na família (com e sem custo) e o fator 70 %.
 *  · Margem unitária = preço médio sem IVA − custo. Linha da margem = margem média da família, pesada
 *    pela quantidade, só com os artigos com custo (Confirmado ou Parcial).
 *  · Classes: Estrela (muito vendido, margem ≥ linha), Cavalo de trabalho (muito vendido, margem
 *    abaixo), Quebra-cabeças (pouco vendido, margem ≥ linha), Cão (pouco vendido, margem abaixo). Os
 *    artigos sem custo ficam fora da matriz (só no eixo da popularidade, na faixa "Sem custo").
 *  · Classe frágil: família com menos de 4 artigos com custo; preços quase iguais (coeficiente de
 *    variação < 5 %); cobertura da família < 60 % da receita; ou o artigo é Parcial.
 */
final class MenuMatrix
{
    public const CLASSES = ['estrela', 'cavalo', 'quebra_cabecas', 'cao'];

    public const CLASS_LABELS = ['estrela' => 'Estrela', 'cavalo' => 'Cavalo de trabalho', 'quebra_cabecas' => 'Quebra-cabeças', 'cao' => 'Cão'];

    /**
     * @param  list<array{id:string, family:string, qty:float, revenue:float, price:?float, cost:?float, confidence:string}>  $items  (só os incluídos)
     * @return array{items: array<string, array>, families: array<string, array>}
     */
    public static function classify(array $items, array $opt = []): array
    {
        $factor = (float) ($opt['popularity_factor'] ?? 0.70);
        $minCosted = (int) ($opt['fragile_min_costed'] ?? 4);
        $cvLimit = (float) ($opt['fragile_price_cv_pct'] ?? 5.0);
        $covLimit = (float) ($opt['fragile_coverage_pct'] ?? 60.0);

        $byFamily = [];
        foreach ($items as $it) {
            $byFamily[(string) $it['family']][] = $it;
        }
        $outItems = [];
        $families = [];
        foreach ($byFamily as $family => $list) {
            $n = count($list);
            $qtyTotal = array_sum(array_map(fn ($i) => (float) $i['qty'], $list));
            $revTotal = array_sum(array_map(fn ($i) => (float) $i['revenue'], $list));
            $costed = array_values(array_filter($list, fn ($i) => $i['cost'] !== null && $i['price'] !== null && $i['confidence'] !== 'sem_custo'));
            $costedQty = array_sum(array_map(fn ($i) => (float) $i['qty'], $costed));
            $line = $costedQty > 0 ? array_sum(array_map(fn ($i) => (float) $i['qty'] * ((float) $i['price'] - (float) $i['cost']), $costed)) / $costedQty : null;
            $threshold = $n > 0 ? $factor / $n : null;
            $prices = array_map(fn ($i) => (float) $i['price'], $costed);
            $cv = self::cv($prices);
            $coverage = $revTotal > 0 ? array_sum(array_map(fn ($i) => (float) $i['revenue'], $costed)) / $revTotal * 100 : null;
            $fragile = [];
            if (count($costed) < $minCosted) {
                $fragile[] = ['code' => 'poucos_artigos', 'text' => sprintf('A família tem só %d %s com custo (menos de %d).', count($costed), count($costed) === 1 ? 'artigo' : 'artigos', $minCosted)];
            }
            if ($cv !== null && $cv < $cvLimit) {
                $fragile[] = ['code' => 'precos_iguais', 'text' => sprintf('Os preços da família são quase iguais (variação de %s %%): a margem separa pouco.', number_format($cv, 1, ',', ' '))];
            }
            if ($coverage !== null && $coverage < $covLimit) {
                $fragile[] = ['code' => 'cobertura_baixa', 'text' => sprintf('Só %s %% da receita da família tem custo.', number_format($coverage, 0, ',', ' '))];
            }
            $families[$family] = [
                'family' => $family, 'items' => $n, 'costed' => count($costed), 'qty' => $qtyTotal, 'revenue' => $revTotal,
                'popularity_threshold_pct' => $threshold !== null ? round($threshold * 100, 3) : null,
                'margin_line' => $line !== null ? round($line, 4) : null,
                'price_cv_pct' => $cv !== null ? round($cv, 2) : null,
                'coverage_pct' => $coverage !== null ? round($coverage, 1) : null,
                'fragile' => $fragile,
            ];
            foreach ($list as $it) {
                $share = $qtyTotal > 0 ? (float) $it['qty'] / $qtyTotal : 0.0;
                $popular = $threshold !== null && $share + 1e-12 >= $threshold;
                $hasCost = $it['cost'] !== null && $it['price'] !== null && $it['confidence'] !== 'sem_custo';
                $margin = $hasCost ? (float) $it['price'] - (float) $it['cost'] : null;
                $high = $hasCost && $line !== null ? $margin + 1e-9 >= $line : null;
                $class = ! $hasCost || $high === null ? null : ($popular ? ($high ? 'estrela' : 'cavalo') : ($high ? 'quebra_cabecas' : 'cao'));
                $reasons = $class ? $fragile : [];
                if ($class && $it['confidence'] === 'parcial') {
                    $reasons[] = ['code' => 'parcial', 'text' => 'O custo do artigo é parcial (um mínimo): a margem pode ser menor.'];
                }
                $outItems[(string) $it['id']] = [
                    'share_pct' => round($share * 100, 3), 'popular' => $popular, 'margin' => $margin !== null ? round($margin, 4) : null,
                    'high_margin' => $high, 'class' => $class, 'fragile' => $reasons !== [], 'fragile_reasons' => $reasons,
                ];
            }
        }

        return ['items' => $outItems, 'families' => $families];
    }

    /** Coeficiente de variação (%) — desvio padrão da população ÷ média. Null com menos de 2 valores. */
    public static function cv(array $values): ?float
    {
        $n = count($values);
        if ($n < 2) {
            return null;
        }
        $mean = array_sum($values) / $n;
        if ($mean <= 0) {
            return null;
        }
        $var = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / $n;

        return sqrt($var) / $mean * 100;
    }
}
