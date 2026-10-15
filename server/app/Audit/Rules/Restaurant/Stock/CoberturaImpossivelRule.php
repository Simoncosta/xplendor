<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Stock;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;

/**
 * Fatia 7a · Cobertura impossível (importante): o stock (todos os armazéns ativos) dava para mais de
 * 90 dias do consumo teórico médio dos últimos 28 dias. Costuma querer dizer que a ficha usa pouco
 * deste artigo, que as compras entram numa unidade errada ou que o stock não foi acertado. Os artigos
 * sem consumo nenhum ficam para "Comprado mas nunca consumido". A relevância é o valor do stock acima
 * de 90 dias de consumo, ao custo médio.
 */
final class CoberturaImpossivelRule extends StockRule
{
    public const MAX_DAYS = 90;

    public function code(): string
    {
        return 'restauracao.cobertura_impossivel';
    }

    public function entityType(): string
    {
        return 'artigo';
    }

    public function title(): string
    {
        return 'Artigos com stock para mais de 90 dias';
    }

    public function description(): string
    {
        return 'Artigos cujo stock dava para mais de 90 dias ao ritmo do consumo teórico dos últimos 28 dias.';
    }

    public function severity(): string
    {
        return 'media';
    }

    public function screens(): array
    {
        return ['stock'];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $data = self::data($scope);
        $avg = $data->consumption()->averages();
        $w = $avg['window'];

        foreach ($data->stock() as $pid => $lines) {
            $pid = (string) $pid;
            $c = $avg['components'][$pid] ?? null;
            $qnt = array_sum(array_map(fn ($l) => $l->qnt, $lines));
            $perDay = $c ? self::perDayInStockUnit($c['per_day'], $lines) : 0.0;
            if ($qnt <= 0 || $perDay <= 0 || $qnt / $perDay <= self::MAX_DAYS) {
                continue;
            }
            $days = $qnt / $perDay;
            $item = $data->item($pid);
            $name = (string) ($item?->description ?? $pid);
            $unit = self::unitOf($lines);
            $value = array_sum(array_map(fn ($l) => $l->pcm !== null ? $l->qnt * $l->pcm : 0.0, $lines));
            $excess = $qnt > 0 ? max(0.0, $value * (1 - self::MAX_DAYS / $days)) : 0.0;
            $dishes = array_values(array_filter(array_keys($c['sources']), fn ($s) => (string) $s !== $pid));
            $dishNames = array_map(fn ($d) => $data->item((string) $d)?->description ?? (string) $d, array_slice($dishes, 0, 3));

            yield new AuditFindingData(
                entityType: 'artigo', entityId: $pid, entityLabel: $data->label($pid),
                title: Fmt::title($name, sprintf('stock para %s dias', Fmt::qty(round($days), 0))),
                why: 'Um stock para mais de 90 dias costuma querer dizer que a ficha usa pouco deste artigo, que as compras entram numa unidade errada ou que o stock do PingWin não foi acertado. Também é dinheiro parado.',
                suggestion: sprintf('Confirme o stock com um inventário no PingWin. Se estiver certo, confirme as quantidades nas fichas que usam o artigo%s. Se é um stock de reserva pensado (vinho de guarda, por exemplo), ignore com esse motivo.',
                    $dishNames ? ' (' . self::join($dishNames) . ')' : ''),
                evidence: [
                    'facts' => [
                        ['label' => 'Stock total', 'value' => self::qty($qnt, $unit, 0)],
                        ['label' => "Consumo teórico por dia ({$w['days']} dias)", 'value' => self::qty($perDay, $unit, 2)],
                        ['label' => 'Dias de cobertura', 'value' => Fmt::qty(round($days), 0)],
                    ],
                    'table' => $this->stockTable($companyId, $lines),
                ],
                signature: 'cobertura',
                weightCents: $excess > 0 ? (int) round($excess * 100) : null,
                fixUrl: self::stockUrl($pid), fixLabel: 'Ver o stock',
                refs: array_merge([['artigo', $pid]], array_map(fn ($d) => ['artigo', (string) $d], array_slice($dishes, 0, 3))),
                seen: sprintf('Há %s em stock, em todos os armazéns. De %s a %s, as fichas consumiram, em teoria, %s por dia. A este ritmo, o stock dava para %s dias.%s',
                    self::qty($qnt, $unit, 0), Fmt::date($w['from']), Fmt::date($w['to']), self::qty($perDay, $unit, 2), Fmt::qty(round($days), 0),
                    $c['low_confidence'] ? ' O consumo passa por preparados da cozinha sem rendimento confirmado: é uma estimativa.' : ''),
                stake: $excess > 0
                    ? sprintf('Não há um valor direto em jogo. A relevância é o valor do stock acima de %d dias de consumo, ao custo médio: %s.', self::MAX_DAYS, Fmt::eur($excess))
                    : 'Não há um valor direto em jogo: o PingWin não tem custo médio para este artigo.',
            );
        }
    }
}
