<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use Illuminate\Support\Facades\DB;

/**
 * Regra 1 · IVA de venda incoerente (alta): artigo vendido com taxa efetiva (tax ÷ net, por dia)
 * de 0%, ou com mais do que uma taxa, nos últimos 90 dias. O impacto é o IVA em jogo se a taxa
 * certa for 23% (o IVA incluído no que se cobrou, nos dias abaixo de 23%, nos últimos 12 meses).
 */
final class IvaVendaRule extends RestaurantRule
{
    /** As taxas de IVA (continente e ilhas): a taxa efetiva de um dia arredonda para a mais próxima. */
    private const RATES = [0, 4, 5, 6, 9, 12, 13, 16, 22, 23];
    private const TARGET = 23;
    /** Dias com menos do que isto (cêntimos sem IVA) não contam: o arredondamento engana. */
    private const MIN_NET = 100;

    public function code(): string
    {
        return 'restauracao.iva_venda';
    }

    public function entityType(): string
    {
        return 'artigo';
    }

    public function severity(): string
    {
        return 'alta';
    }

    public function title(): string
    {
        return 'IVA de venda que não bate certo';
    }

    public function description(): string
    {
        return 'Artigos vendidos com IVA a 0%, ou com mais do que uma taxa de IVA, nos últimos 90 dias.';
    }

    public function screens(): array
    {
        return ['artigos'];
    }

    public static function snap(float $rate): int
    {
        $best = null;
        foreach (self::RATES as $r) {
            if ($best === null || abs($rate - $r) < abs($rate - $best)) {
                $best = $r;
            }
        }

        return abs($rate - $best) <= 1.5 ? $best : (int) round($rate);
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $data = RestaurantAuditData::for($scope);
        $since90 = $data->daysAgo(90);
        $days = DB::table('pingwin_item_sales_daily')->where('company_id', $companyId)
            ->whereBetween('business_date', [$data->daysAgo(365), $data->today()])
            ->where('quantity', '>', 0)->where('net_cents', '>=', self::MIN_NET)
            ->groupBy('product_pingwin_id', 'business_date')
            ->orderBy('business_date')
            ->get(['product_pingwin_id', 'business_date', DB::raw('MAX(product_name) as name'), DB::raw('SUM(quantity) as qty'),
                DB::raw('SUM(net_cents) as net'), DB::raw('SUM(tax_cents) as tax'), DB::raw('SUM(gross_cents) as gross')])
            ->groupBy('product_pingwin_id');

        foreach ($days as $pid => $rows) {
            $pid = (string) $pid;
            $list = [];
            foreach ($rows as $r) {
                $list[] = [
                    'date' => substr((string) $r->business_date, 0, 10), 'qty' => (float) $r->qty, 'net' => (int) $r->net,
                    'tax' => (int) $r->tax, 'gross' => (int) $r->gross ?: (int) $r->net + (int) $r->tax,
                    'rate' => self::snap((int) $r->tax / max(1, (int) $r->net) * 100), 'name' => $r->name,
                ];
            }
            $recent = array_values(array_filter($list, fn ($d) => $d['date'] >= $since90));
            if (! $recent) {
                continue;
            }
            $recentRates = array_values(array_unique(array_column($recent, 'rate')));
            sort($recentRates);
            $zero = in_array(0, $recentRates, true);
            if (! $zero && count($recentRates) < 2) {
                continue;
            }

            // Por taxa (12 meses): dias, de/até, unidades, vendas e IVA.
            $byRate = [];
            foreach ($list as $d) {
                $b = &$byRate[$d['rate']];
                $b ??= ['rate' => $d['rate'], 'days' => 0, 'from' => $d['date'], 'to' => $d['date'], 'qty' => 0.0, 'net_cents' => 0, 'tax_cents' => 0, 'gross' => 0];
                $b['days']++;
                $b['to'] = $d['date'];
                $b['qty'] += $d['qty'];
                $b['net_cents'] += $d['net'];
                $b['tax_cents'] += $d['tax'];
                $b['gross'] += $d['gross'];
                unset($b);
            }
            ksort($byRate);
            // A taxa de agora e desde quando (o troço final com a mesma taxa).
            $last = end($list);
            $since = $last['date'];
            $streakQty = 0.0;
            for ($i = count($list) - 1; $i >= 0 && $list[$i]['rate'] === $last['rate']; $i--) {
                $since = $list[$i]['date'];
                $streakQty += $list[$i]['qty'];
            }
            $before = $i >= 0 ? $list[$i]['rate'] : null;
            // O IVA em jogo se a taxa certa for 23%: o IVA incluído no que se cobrou, menos o que se declarou.
            $impact = 0;
            foreach ($list as $d) {
                if ($d['rate'] < self::TARGET) {
                    $impact += (int) round($d['gross'] * self::TARGET / (100 + self::TARGET) - $d['gross'] * $d['rate'] / (100 + $d['rate']));
                }
            }
            $name = (string) ($data->item($pid)?->description ?? $last['name']);
            $label = $data->label($pid, $last['name']);
            $zeroNow = $zero && $last['rate'] === 0;
            $rateDays = [];
            foreach ($recent as $d) {
                $rateDays[$d['rate']] = ($rateDays[$d['rate']] ?? 0) + 1;
            }
            ksort($rateDays);

            if ($zeroNow) {
                $title = Fmt::title($name, 'vendido com IVA a 0%');
                $seen = sprintf('Desde %s, foi vendido com IVA a 0%%. Até %s vendeu %s, num total de %s sem IVA.%s',
                    Fmt::date($since), Fmt::date($last['date']), Fmt::units($streakQty, 'unidade'), Fmt::cents($byRate[0]['net_cents'] ?? 0),
                    $before !== null ? " Antes era vendido a {$before}%." : '');
                $why = 'Um artigo vendido ao público raramente tem IVA a 0%. Se a taxa estiver errada, o IVA entregue ao Estado fica abaixo do devido.';
                $todo = 'Confirme a taxa certa com o contabilista. Corrija o grupo de IVA do artigo no PingWin. Se a taxa de 0% estiver certa, ignore com esse motivo.';
            } else {
                $title = Fmt::title($name, sprintf('vendido com %d taxas de IVA diferentes', count($recentRates)));
                $seen = 'Nos últimos 90 dias foi vendido com IVA a ' . implode(' e a ', array_map(
                    fn ($r, $n) => "{$r}% em {$n} " . ($n === 1 ? 'dia' : 'dias'), array_keys($rateDays), array_values($rateDays))) . '.';
                $why = 'Um artigo tem uma só taxa de IVA. Com mais do que uma, uma delas está errada e o IVA entregue não bate certo.';
                $todo = 'Confirme a taxa certa com o contabilista. Corrija o grupo de IVA do artigo no PingWin, para todas as lojas.';
            }
            $stake = $impact > 0
                ? sprintf('%s de IVA, se a taxa certa for 23%%: é o IVA que ficaria por declarar no que cobrou nos últimos 12 meses.', Fmt::cents($impact))
                : 'Não há um valor de IVA em falta calculável: as taxas usadas não ficam abaixo de 23%.';

            yield new AuditFindingData(
                entityType: 'artigo', entityId: $pid, entityLabel: $label, title: $title, why: $why, suggestion: $todo,
                evidence: [
                    'facts' => array_values(array_filter([
                        ['label' => 'Taxa de IVA de agora', 'value' => "{$last['rate']}%"],
                        ['label' => 'Desde', 'value' => Fmt::date($since)],
                        $before !== null ? ['label' => 'Taxa de IVA antes', 'value' => "{$before}%"] : null,
                        ['label' => 'IVA em jogo se a taxa certa for 23%', 'value' => $impact / 100, 'format' => 'eur'],
                    ])),
                    'table' => [
                        'title' => 'As vendas por taxa de IVA (últimos 12 meses)',
                        'columns' => [
                            ['key' => 'rate', 'label' => 'Taxa de IVA', 'format' => 'pct'], ['key' => 'days', 'label' => 'Dias', 'align' => 'end'],
                            ['key' => 'from', 'label' => 'De', 'format' => 'date'], ['key' => 'to', 'label' => 'Até', 'format' => 'date'],
                            ['key' => 'qty', 'label' => 'Unidades', 'align' => 'end', 'format' => 'qty'],
                            ['key' => 'net_cents', 'label' => 'Vendas sem IVA', 'align' => 'end', 'format' => 'cents'],
                            ['key' => 'tax_cents', 'label' => 'IVA', 'align' => 'end', 'format' => 'cents'],
                        ],
                        'rows' => array_values(array_map(fn ($b) => array_diff_key($b, ['gross' => 1]), $byRate)),
                    ],
                    'note' => 'A taxa de cada dia é o IVA a dividir pelas vendas sem IVA (todas as lojas), arredondada à taxa mais próxima.',
                ],
                signature: implode(',', $recentRates),
                impactCents: $impact > 0 ? $impact : null,
                weightCents: (int) ($data->sales90()[$pid]['net'] ?? 0),
                fixUrl: self::articleUrl($pid), fixLabel: 'Abrir o artigo',
                refs: [['artigo', $pid]],
                seen: $seen, stake: $stake,
            );
        }
    }
}
