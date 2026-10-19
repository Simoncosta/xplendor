<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\PingwinLocation;
use App\Models\ProductUnitConversion;
use App\Models\RestaurantForecast;
use App\Models\StockCount;
use App\Services\Restaurant\Forecast\ForecastMath;
use App\Services\Restaurant\Forecast\PortugalHolidays;
use App\Services\Restaurant\Transfers\TransferService;
use App\Services\SupplierArticleMapService;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Compras sugeridas do Economato (SÓ LEITURA: nada se encomenda no PingWin).
 *
 *  1. Base: as linhas das faturas de fornecedor com a quantidade confirmada (a unidade de compra
 *     confirmada no Guião, ou a linha já na unidade base). Por artigo e fornecedor, a mediana semanal
 *     das últimas 8 semanas fechadas (sem as compras únicas, compradas numa só semana, nem as semanas
 *     fora do normal, acima de 3 × a mediana das semanas com compras).
 *  2. O ritmo do fornecedor: os dias em que costuma faturar e o intervalo médio entre faturas; daí a
 *     data provável da próxima entrega e quantas semanas a encomenda tem de cobrir.
 *  3. A previsão (Fatia 6a): a faturação prevista da semana seguinte ÷ a média das últimas 4 semanas;
 *     e o aviso dos feriados dessa semana.
 *  4. O stock: com uma contagem confirmada no Economato (nos últimos 14 dias), desconta-se o que existe;
 *     senão, avisa-se "sem stock contado".
 *  5. Arredonda-se à embalagem de compra do fornecedor (só conversões confirmadas).
 *  6. O erro medido: a sugestão de cada uma das últimas 4 semanas contra o que se comprou de facto.
 */
class PurchaseSuggestionService
{
    public const WEEKS = 8;
    public const OUTLIER_TIMES = 3.0;
    public const COUNT_DAYS = 14;
    public const ERROR_WEEKS = 4;
    /** O ajuste pela previsão fica entre estes limites (para nunca comprar de mais por uma previsão estranha). */
    public const FACTOR_MIN = 0.7;
    public const FACTOR_MAX = 1.3;

    private CarbonImmutable $today;
    private ?array $lines = null;

    public function __construct(private readonly int $companyId, ?CarbonImmutable $today = null)
    {
        $this->today = ($today ?? CarbonImmutable::now('Europe/Lisbon'))->startOfDay();
    }

    /** As linhas de compra (16 semanas, para o histórico e o erro medido), só com a quantidade confirmada. */
    private function lines(): array
    {
        if ($this->lines === null) {
            $cost = new RecipeCostService($this->companyId, $this->today);
            $this->lines = [];
            foreach ($cost->purchaseLines(null, $this->today->subWeeks(self::WEEKS + self::ERROR_WEEKS + 2)->toDateString()) as $pid => $ls) {
                foreach ($ls as $l) {
                    if ($l['strict_qnt_base'] !== null && $l['strict_qnt_base'] > 0 && $l['date'] < $this->today->toDateString()) {
                        $this->lines[] = $l + ['pid' => (string) $pid, 'sk' => self::supplierKey($l)];
                    }
                }
            }
        }

        return $this->lines;
    }

    public static function supplierKey(array $l): string
    {
        return (string) (SupplierArticleMapService::nif($l['nif'] ?? '') ?: ($l['supplier_id'] ?: $l['supplier']));
    }

    /** A segunda-feira da semana de uma data. */
    private static function week(string $date): string
    {
        return CarbonImmutable::parse($date)->startOfWeek()->toDateString();
    }

    /**
     * A mediana semanal de cada artigo e fornecedor nas 8 semanas fechadas antes de $weekStart.
     *
     * @return array<string, array{median: float, weeks: int, last: array}> chave "fornecedor|artigo"
     */
    public function weeklyMedians(string $weekStart): array
    {
        $from = CarbonImmutable::parse($weekStart)->subWeeks(self::WEEKS)->toDateString();
        $by = [];
        foreach ($this->lines() as $l) {
            if ($l['date'] < $from || $l['date'] >= $weekStart) {
                continue;
            }
            $k = $l['sk'] . '|' . $l['pid'];
            $w = self::week($l['date']);
            $by[$k]['weeks'][$w] = ($by[$k]['weeks'][$w] ?? 0) + (float) $l['strict_qnt_base'];
            if (! isset($by[$k]['last']) || [$l['date'], $l['id']] > [$by[$k]['last']['date'], $by[$k]['last']['id']]) {
                $by[$k]['last'] = $l;
            }
        }
        $out = [];
        foreach ($by as $k => $x) {
            if (count($x['weeks']) < 2) {
                continue;   // compra única: numa só semana das 8
            }
            $nonzero = ForecastMath::median(array_values($x['weeks']));
            $values = [];
            for ($i = 1; $i <= self::WEEKS; $i++) {
                $w = CarbonImmutable::parse($weekStart)->subWeeks($i)->toDateString();
                $v = $x['weeks'][$w] ?? 0.0;
                if ($v > self::OUTLIER_TIMES * $nonzero) {
                    continue;   // semana fora do normal
                }
                $values[] = $v;
            }
            $out[$k] = ['median' => (float) ForecastMath::median($values), 'weeks' => count($x['weeks']), 'last' => $x['last']];
        }

        return $out;
    }

    /** O ritmo de cada fornecedor: os dias da semana em que costuma faturar e o intervalo médio entre faturas. */
    public function rhythm(): array
    {
        $dates = [];
        foreach ($this->lines() as $l) {
            if ($l['date'] >= $this->today->subWeeks(self::WEEKS)->toDateString()) {
                $dates[$l['sk']][$l['date']] = true;
                $dates[$l['sk'] . '#name'] = $l['supplier'];
            }
        }
        $out = [];
        foreach ($dates as $sk => $set) {
            if (str_ends_with((string) $sk, '#name')) {
                continue;
            }
            $days = array_keys($set);
            sort($days);
            $dows = array_count_values(array_map(fn ($d) => (int) CarbonImmutable::parse($d)->dayOfWeekIso, $days));
            arsort($dows);
            $usual = array_keys(array_filter($dows, fn ($n) => $n >= max(2, 0.25 * count($days))));
            sort($usual);
            $gaps = [];
            for ($i = 1; $i < count($days); $i++) {
                $gaps[] = CarbonImmutable::parse($days[$i - 1])->diffInDays(CarbonImmutable::parse($days[$i]));
            }
            $interval = $gaps ? array_sum($gaps) / count($gaps) : 7.0;
            $out[(string) $sk] = ['name' => (string) $dates[$sk . '#name'], 'usual_days' => $usual, 'interval_days' => round($interval, 1),
                'invoices' => count($days), 'last' => end($days), 'next' => $this->nextDelivery($usual, end($days), $interval)];
        }

        return $out;
    }

    /** A data provável da próxima entrega: o próximo dia habitual a partir de amanhã; sem dias habituais, a última + o intervalo. */
    private function nextDelivery(array $usual, string $last, float $interval): string
    {
        $tomorrow = $this->today->addDay();
        if ($usual) {
            for ($d = $tomorrow; $d->lte($tomorrow->addDays(6)); $d = $d->addDay()) {
                if (in_array((int) $d->dayOfWeekIso, $usual, true)) {
                    return $d->toDateString();
                }
            }
        }
        $next = CarbonImmutable::parse($last)->addDays((int) max(1, round($interval)));

        return ($next->lt($tomorrow) ? $tomorrow : $next)->toDateString();
    }

    /** O ajuste pela previsão: a faturação prevista da semana seguinte ÷ a média das últimas 4 semanas fechadas. */
    public function forecastFactor(): array
    {
        $next = $this->today->startOfWeek()->addWeek();
        $locs = PingwinLocation::where('company_id', $this->companyId)->where('is_active', true)->pluck('id')->all();
        $rows = RestaurantForecast::whereIn('location_id', $locs)->where('metric', 'invoiced')
            ->whereBetween('business_date', [$next->toDateString(), $next->addDays(6)->toDateString()])->get();
        $days = $rows->groupBy(fn ($r) => substr((string) $r->business_date, 0, 10))->count();
        $predicted = (float) $rows->sum('value');
        // A média semanal das últimas 4 semanas fechadas só com os dias lidos (um dia em falta não baixa a média).
        $from = $this->today->startOfWeek()->subWeeks(4)->toDateString();
        $to = $this->today->startOfWeek()->subDay()->toDateString();
        $agg = DB::table('pingwin_daily_sales')->whereIn('location_id', $locs)->whereRaw('DATE(business_date) BETWEEN ? AND ?', [$from, $to])
            ->selectRaw('SUM(invoiced_cents) as s, COUNT(*) as n')->first();
        $past = $agg && (int) $agg->n > 0 ? ((int) $agg->s / 100) / (int) $agg->n * 7 * count($locs) : 0.0;
        $holidays = PortugalHolidays::between($next->toDateString(), $next->addDays(6)->toDateString(), (string) Settings::company($this->companyId, 'calendar.municipal_holiday'));
        if ($days < 7 || $past <= 0) {
            return ['factor' => 1.0, 'predicted' => null, 'average' => $past ?: null, 'note' => 'Sem previsão para a semana seguinte: sem ajuste.', 'holidays' => $holidays];
        }

        $raw = $predicted / $past;
        $factor = max(self::FACTOR_MIN, min(self::FACTOR_MAX, $raw));
        $note = sprintf('A faturação prevista da semana de %s é %s da média das últimas 4 semanas.', $next->format('d/m'), number_format($raw * 100, 0, ',', ' ') . ' %');
        if ($factor !== $raw) {
            $note .= sprintf(' O ajuste fica limitado a %s.', number_format($factor * 100, 0, ',', ' ') . ' %');
        }

        return ['factor' => round($factor, 3), 'raw_factor' => round($raw, 3), 'predicted' => round($predicted, 2), 'average' => round($past, 2), 'note' => $note, 'holidays' => $holidays];
    }

    /** O stock contado no Economato (contagem confirmada nos últimos 14 dias), por artigo, na unidade base. */
    public function countedStock(): ?array
    {
        $eco = TransferService::economato($this->companyId);
        if (! $eco) {
            return null;
        }
        $count = StockCount::where('company_id', $this->companyId)->where('warehouse_id', $eco['warehouse_id'])->whereNotNull('confirmed_at')
            ->where('confirmed_at', '>=', $this->today->subDays(self::COUNT_DAYS))->latest('confirmed_at')->first();
        if (! $count) {
            return null;
        }

        return ['date' => $count->dateString(), 'qty' => DB::table('stock_count_lines')->where('count_id', $count->id)->whereNotNull('qty_base')
            ->pluck('qty_base', 'product_id')->map(fn ($v) => (float) $v)->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all()];
    }

    /** A embalagem de compra confirmada do fornecedor para um artigo e a unidade da última compra. */
    private function pack(string $pid, array $last): ?array
    {
        $nif = SupplierArticleMapService::nif($last['nif'] ?? '');
        $conv = ProductUnitConversion::where('company_id', $this->companyId)->where('product_pingwin_id', $pid)->where('source', 'confirmado')
            ->where('unit_key', (string) $last['unit_key'])->whereIn('supplier_nif', array_values(array_unique([$nif, ''])))
            ->orderByRaw('supplier_nif = ? desc', [''])->get()->sortByDesc(fn ($c) => $c->supplier_nif === $nif)->first();

        return $conv && $conv->factor_to_base > 0 ? ['label' => (string) ($conv->unit_label ?: $last['unit_label']), 'factor' => (float) $conv->factor_to_base] : null;
    }

    /** As sugestões por fornecedor, com o erro medido, os avisos e os totais. */
    public function suggest(): array
    {
        $weekStart = $this->today->startOfWeek()->toDateString();
        $medians = $this->weeklyMedians($weekStart);
        $rhythm = $this->rhythm();
        $factor = $this->forecastFactor();
        $stock = $this->countedStock();
        $cost = new RecipeCostService($this->companyId, $this->today);
        // O catálogo dos artigos (a unidade base de cada um) antes de escolher a unidade e a embalagem.
        $cost->ensureItems(array_values(array_unique(array_map(fn ($k) => explode('|', (string) $k, 2)[1], array_keys($medians)))));
        $cost->units();
        $suppliers = [];
        foreach ($medians as $k => $m) {
            [$sk, $pid] = explode('|', $k, 2);
            if ($m['median'] <= 0 || ! isset($rhythm[$sk])) {
                continue;
            }
            // Só mercadoria: as despesas e os serviços (stockconfig 7500, ou as famílias que a engenharia de
            // menu já deixa de fora, como fornecimentos e serviços externos, recursos humanos ou cauções) não se encomendam.
            if (! self::isGoods($cost->item($pid))) {
                continue;
            }
            $r = $rhythm[$sk];
            $cover = max(1.0, $r['interval_days']) / 7;   // a encomenda cobre até à entrega seguinte
            $need = $m['median'] * $factor['factor'] * $cover;
            $have = $stock['qty'][$pid] ?? null;
            $toBuy = max(0.0, $need - ($have ?? 0.0));
            if ($toBuy <= 0) {
                continue;
            }
            $last = $m['last'];
            $pack = $this->pack($pid, $last);
            $baseUnit = self::baseLabel($cost, $pid);
            // Sem embalagem confirmada: na unidade base (as unidades inteiras arredondam para cima; kg e L a 2 casas).
            $qty = $pack ? (int) ceil($toBuy / $pack['factor'] - 1e-9) : (in_array($baseUnit, ['quilograma', 'litro'], true) ? ceil($toBuy * 100) / 100 : (float) ceil($toBuy - 1e-9));
            $base = $pack ? $qty * $pack['factor'] : $qty;
            $pricePerBase = $last['total'] / $last['strict_qnt_base'];
            $item = $cost->item($pid);
            $suppliers[$sk]['lines'][] = [
                'product_id' => $pid, 'code' => $item?->code ? ltrim((string) $item->code, "\u{FEFF}") : null, 'description' => (string) ($item?->description ?? $last['description']),
                'suggested' => $qty, 'unit' => $pack ? $pack['label'] : $baseUnit, 'pack_factor' => $pack['factor'] ?? 1.0, 'pack_confirmed' => (bool) $pack,
                'weekly_median_base' => round($m['median'], 3), 'need_base' => round($need, 3), 'stock_base' => $have, 'base_unit' => $baseUnit,
                'last_purchase' => ['date' => $last['date'], 'qty' => (float) $last['qnt'], 'unit' => (string) $last['unit_label'], 'document' => (string) $last['document']],
                'price_cents' => (int) round($pricePerBase * ($pack['factor'] ?? 1.0) * 100), 'total_cents' => (int) round($pricePerBase * $base * 100),
            ];
        }
        $out = [];
        foreach ($suppliers as $sk => $x) {
            usort($x['lines'], fn ($a, $b) => strcmp($a['description'], $b['description']));
            $r = $rhythm[$sk];
            $out[] = ['key' => $sk, 'name' => $r['name'], 'next_delivery' => $r['next'], 'usual_days' => $r['usual_days'], 'interval_days' => $r['interval_days'],
                'lines' => $x['lines'], 'total_cents' => array_sum(array_column($x['lines'], 'total_cents'))];
        }
        usort($out, fn ($a, $b) => [$a['next_delivery'], -$a['total_cents']] <=> [$b['next_delivery'], -$b['total_cents']]);

        return [
            'today' => $this->today->toDateString(),
            'suppliers' => $out,
            'forecast' => $factor,
            'stock' => $stock ? ['date' => $stock['date'], 'articles' => count($stock['qty'])] : null,
            'error' => $this->measuredError(),
        ];
    }

    public static function isGoods(?\App\Models\PingwinCatalogItem $item): bool
    {
        if ((string) ($item?->stockconfig_id ?? '') === '7500') {
            return false;
        }
        $family = mb_strtolower((string) ($item?->family ?? ''));
        foreach ((array) config('menu_engineering.purchase_exclude', []) as $x) {
            if ($x !== '' && str_contains($family, mb_strtolower((string) $x))) {
                return false;
            }
        }

        return true;
    }

    private static function baseLabel(RecipeCostService $cost, string $pid): string
    {
        $root = $cost->componentBaseRoot($pid);

        return mb_strtolower((string) ($cost->units()[$root]->description ?? 'unidade'));
    }

    /**
     * O erro medido: em cada uma das últimas 4 semanas fechadas, a sugestão de uma semana (a mediana
     * das 8 semanas antes, sem ajuste nem stock) contra o que se comprou de facto nessa semana.
     * Erro = Σ |sugerido − comprado| ÷ Σ comprado (em € ao último preço), por semana e no total.
     */
    public function measuredError(): array
    {
        $weeks = [];
        $sumAbs = 0.0;
        $sumBought = 0.0;
        $cost = new RecipeCostService($this->companyId, $this->today);
        $cost->ensureItems(array_values(array_unique(array_column($this->lines(), 'pid'))));
        for ($i = self::ERROR_WEEKS; $i >= 1; $i--) {
            $w = $this->today->startOfWeek()->subWeeks($i);
            $medians = $this->weeklyMedians($w->toDateString());
            $bought = [];
            foreach ($this->lines() as $l) {
                if ($l['date'] >= $w->toDateString() && $l['date'] < $w->addWeek()->toDateString()) {
                    $k = $l['sk'] . '|' . $l['pid'];
                    $bought[$k] = ($bought[$k] ?? 0) + (float) $l['strict_qnt_base'];
                }
            }
            $abs = 0.0;
            $tot = 0.0;
            foreach (array_unique(array_merge(array_keys($medians), array_keys($bought))) as $k) {
                $price = isset($medians[$k]) ? $medians[$k]['last']['total'] / $medians[$k]['last']['strict_qnt_base'] : null;
                if ($price === null || ! self::isGoods($cost->item(explode('|', (string) $k, 2)[1]))) {
                    continue;   // comprado sem histórico (compra nova): fora da medição
                }
                $abs += abs(($medians[$k]['median'] ?? 0) - ($bought[$k] ?? 0)) * $price;
                $tot += ($bought[$k] ?? 0) * $price;
            }
            // Uma semana sem nenhuma compra lida (o espelho parado) não se mede: seria um erro de 100 % que não é da sugestão.
            if ($tot <= 0) {
                $weeks[] = ['week' => $w->toDateString(), 'error_pct' => null, 'bought_cents' => 0, 'note' => 'Sem compras lidas nesta semana.'];
                continue;
            }
            $weeks[] = ['week' => $w->toDateString(), 'error_pct' => round($abs / $tot * 100, 1), 'bought_cents' => (int) round($tot * 100)];
            $sumAbs += $abs;
            $sumBought += $tot;
        }

        return ['pct' => $sumBought > 0 ? round($sumAbs / $sumBought * 100, 1) : null, 'weeks' => $weeks];
    }
}
