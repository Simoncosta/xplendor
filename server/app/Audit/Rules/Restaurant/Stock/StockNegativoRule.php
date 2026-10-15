<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Stock;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Services\Restaurant\RecipeHints;

/**
 * Fatia 7a · Stock negativo (urgente): o artigo tem quantidade negativa em pelo menos um armazém
 * ativo. Saiu mais do que entrou: as compras estão a entrar noutro artigo ou noutro armazém, ou o
 * inventário não foi acertado. Se as compras de um artigo parecido entram e se acumulam (o caso da
 * Coca Cola Zero), diz-se qual. A relevância é o valor, ao custo médio de cada armazém, do que saiu
 * sem ter entrado. A assinatura são os armazéns negativos (reabre se aparecer outro).
 */
final class StockNegativoRule extends StockRule
{
    public function code(): string
    {
        return 'restauracao.stock_negativo';
    }

    public function entityType(): string
    {
        return 'artigo';
    }

    public function title(): string
    {
        return 'Artigos com stock negativo';
    }

    public function description(): string
    {
        return 'Artigos com quantidade negativa em algum armazém do PingWin: saiu mais do que entrou.';
    }

    public function severity(): string
    {
        return 'alta';
    }

    public function screens(): array
    {
        return ['stock', 'artigos'];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $data = self::data($scope);
        $avg = $data->consumption()->averages();
        $stock = $data->stock();

        foreach ($stock as $pid => $lines) {
            $neg = array_values(array_filter($lines, fn ($l) => $l->qnt < -1e-6));
            if (! $neg) {
                continue;
            }
            $item = $data->item((string) $pid);
            $name = (string) ($item?->description ?? $pid);
            $unit = self::unitOf($lines);
            $parts = array_map(fn ($l) => sprintf('%s no %s', self::qty($l->qnt, $unit, 0), $this->warehouseName($companyId, (string) $l->warehouse_pingwin_id)), $neg);
            $value = array_sum(array_map(fn ($l) => $l->pcm !== null ? abs($l->qnt) * $l->pcm : 0.0, $neg));
            $c = $avg['components'][$pid] ?? null;
            $perDay = $c ? self::perDayInStockUnit($c['per_day'], $lines) : 0.0;

            $seen = sprintf('No PingWin, o stock está negativo: %s.', self::join($parts));
            if ($perDay > 0) {
                $top = array_key_first($c['sources']);
                $seen .= sprintf(' Nos últimos %d dias, as vendas consumiram, em teoria, %s por dia%s.', $avg['window']['days'], self::qty($perDay, $unit, 2),
                    $top !== null && (string) $top !== (string) $pid ? ' (sobretudo ' . ($data->item((string) $top)?->description ?? $top) . ')' : '');
            }
            $pcms = array_filter(array_map(fn ($l) => $l->pcm, $lines), fn ($p) => $p !== null && $p > 0);
            if (count($pcms) > 1 && max($pcms) > 5 * min($pcms)) {
                $seen .= sprintf(' O custo médio varia muito entre armazéns: de %s a %s.', Fmt::unitPrice(min($pcms)), Fmt::unitPrice(max($pcms)));
            }

            $twin = $this->purchasedTwin($data, (string) $pid, $stock, $avg);
            if ($twin) {
                $seen .= sprintf(' As compras parecem entrar noutro artigo: %s, que tem %s em stock e nenhum consumo.', $twin['label'], self::qty($twin['qnt'], $unit, 0));
            }
            $dish = $c ? array_key_first(array_filter($c['sources'], fn ($q, $s) => (string) $s !== (string) $pid, ARRAY_FILTER_USE_BOTH)) : null;

            yield new AuditFindingData(
                entityType: 'artigo', entityId: (string) $pid, entityLabel: $data->label((string) $pid),
                title: Fmt::title($name, 'stock negativo no PingWin'),
                why: 'Stock negativo quer dizer que saiu mais do que entrou. O valor do stock e o custo do que se vendeu ficam errados, e o PingWin deixa de avisar quando o artigo acaba.',
                suggestion: $twin && $dish
                    ? sprintf('Abra a ficha de %s e troque o ingrediente pelo artigo que compra (%s). Depois, acerte o stock dos dois artigos com um inventário no PingWin.', $data->item((string) $dish)?->description ?? $dish, $twin['label'])
                    : 'Confirme se as compras deste artigo estão a ser lançadas noutro artigo ou noutro armazém. Depois, acerte o stock com um inventário no PingWin. Se o artigo já não se usa, ignore com esse motivo.',
                evidence: [
                    'facts' => array_values(array_filter([
                        ['label' => 'Stock total', 'value' => self::qty(array_sum(array_map(fn ($l) => $l->qnt, $lines)), $unit, 0)],
                        $perDay > 0 ? ['label' => "Consumo teórico por dia ({$avg['window']['days']} dias)", 'value' => self::qty($perDay, $unit, 2)] : null,
                        $twin ? ['label' => 'Artigo parecido que se compra', 'value' => $twin['label']] : null,
                    ])),
                    'table' => $this->stockTable($companyId, $lines),
                ],
                signature: implode(',', array_map(fn ($l) => (string) $l->warehouse_pingwin_id, $neg)),
                weightCents: $value > 0 ? (int) round($value * 100) : null,
                fixUrl: $twin && $dish ? self::recipeUrl((string) $dish, ['editar' => 1]) : self::stockUrl((string) $pid),
                fixLabel: $twin && $dish ? 'Abrir a ficha' : 'Ver o stock',
                refs: array_values(array_filter([['artigo', (string) $pid], $twin ? ['artigo', $twin['id']] : null, $dish ? ['artigo', (string) $dish] : null])),
                seen: $seen,
                stake: $value > 0
                    ? sprintf('Não há um valor direto em jogo. A relevância é o valor, ao custo médio de cada armazém, do que saiu sem ter entrado: %s.', Fmt::eur($value))
                    : 'Não há um valor direto em jogo: o PingWin não tem custo médio para este artigo.',
            );
        }
    }

    /**
     * O artigo parecido que se compra e se acumula (stock positivo, sem consumo), se houver.
     *
     * @return array{id:string, label:string, qnt:float}|null
     */
    private function purchasedTwin($data, string $pid, array $stock, array $avg): ?array
    {
        $tokens = RecipeHints::nameTokens($data->item($pid)?->description);
        if (! $tokens) {
            return null;
        }
        $purchases = $data->purchases();
        $best = null;
        foreach ($stock as $other => $lines) {
            $other = (string) $other;
            if ($other === $pid || ! isset($purchases[$other]) || ($avg['components'][$other]['per_day'] ?? 0) > 0) {
                continue;
            }
            $qnt = array_sum(array_map(fn ($l) => $l->qnt, $lines));
            if ($qnt <= 0) {
                continue;
            }
            $score = RecipeHints::similarity($tokens, RecipeHints::nameTokens($data->item($other)?->description));
            if ($score >= 0.75 && (! $best || $score > $best['score'])) {
                $best = ['id' => $other, 'label' => str_replace(' · ', ' ', $data->label($other)), 'qnt' => $qnt, 'score' => $score];
            }
        }

        return $best;
    }
}
