<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\CmReservationChannelDaily;
use App\Models\CmReservationLeadtimeDaily;
use App\Models\Company;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinDailySale;
use App\Models\PingwinHourlySale;
use App\Models\PingwinHourlySalesDay;
use App\Models\PingwinItemSale;
use App\Models\PingwinItemSalesDay;
use App\Models\PingwinLocation;
use App\Models\RestaurantDataQuality;
use App\Models\RestaurantFamilyCategory;
use App\Models\RestaurantSignal;
use App\Services\PingwinItemSalesService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — F3 do marketing da restauração: os sinais de "O que publicar e quando"
 * (documents/PINGWIN-F3-DESENHO.md). Calculados por loja, a partir do início efetivo
 * da loja, só com agregados; guardados em restaurant_signals (substituídos a cada cálculo).
 *
 * Regras de todos os sinais:
 *  · só confiança Alta ou Média (a baixa nunca se guarda nem aparece);
 *  · sem comparações com o ano anterior até a loja ter 12 meses de vendas (nenhum sinal
 *    desta versão compara anos);
 *  · frases descritivas, nunca causais;
 *  · rankings pelo valor sem IVA, com pelo menos 100 € no período (deixa de fora
 *    modificadores e extras a 0 €);
 *  · as categorias confirmadas "Excluir" e "Entrega" ficam fora de S1, S2 e S4;
 *  · os sinais por categoria só com todas as famílias com vendas confirmadas.
 */
class RestaurantSignalService
{
    public const WINDOW_DAYS = 28;
    public const COMPARE_DAYS = 56;
    public const MIN_NET_CENTS = 10000;
    public const MIN_COVERAGE = 0.9;
    public const STALE_DAYS = 30;
    public const STALE_BEFORE_DAYS = 56;
    public const RESERVATION_DAYS = 90;
    public const MIN_RESERVATIONS = 80;
    public const HIGH_RESERVATIONS = 200;
    public const CATALOG_FRESH_DAYS = 14;
    public const WEAK_THRESHOLD = 0.8;
    public const MIN_OCCURRENCES = 6;
    public const AFTERNOON_MIN_SHARE = 0.10;
    public const IGNORE_DAYS = 28;
    public const STALE_AFTER_HOURS = 24;
    public const EXCLUDED_CATEGORIES = ['excluir', 'entrega'];

    /** Turnos (horas do relatório "Vendas por hora"). A tarde só conta com 10% das vendas. */
    public const SHIFTS = [
        'almoco' => [11, 12, 13, 14, 15],
        'tarde' => [16, 17, 18],
        'jantar' => [19, 20, 21, 22, 23, 0, 1],
    ];

    public const LEAD_ORDER = ['same_day', 'd1_2', 'd3_7', 'd8_30', 'd31_plus'];
    /** Dias de antecedência para publicar, pelo escalão mais frequente das reservas. */
    public const LEAD_OFFSET = ['same_day' => 1, 'd1_2' => 2, 'd3_7' => 7, 'd8_30' => 7, 'd31_plus' => 7];
    public const DEFAULT_OFFSET = 2;

    private const WEEKDAY = [1 => 'segunda-feira', 2 => 'terça-feira', 3 => 'quarta-feira', 4 => 'quinta-feira', 5 => 'sexta-feira', 6 => 'sábado', 7 => 'domingo'];
    private const WEEKDAY_SHORT = [1 => 'segunda', 2 => 'terça', 3 => 'quarta', 4 => 'quinta', 5 => 'sexta', 6 => 'sábado', 7 => 'domingo'];
    private const WEEKDAY_PLURAL = [1 => 'segundas', 2 => 'terças', 3 => 'quartas', 4 => 'quintas', 5 => 'sextas', 6 => 'sábados', 7 => 'domingos'];
    private const SHIFT_WORDS = [
        'almoco' => ['almoço', 'almoços', 'Os'],
        'tarde' => ['tarde', 'tardes', 'As'],
        'jantar' => ['jantar', 'jantares', 'Os'],
    ];
    private const LEAD_PHRASE = [
        'same_day' => 'no próprio dia',
        'd1_2' => 'com 1 a 2 dias de antecedência',
        'd3_7' => 'com 3 a 7 dias de antecedência',
        'd8_30' => 'com 8 a 30 dias de antecedência',
        'd31_plus' => 'com mais de 30 dias de antecedência',
    ];

    private CarbonImmutable $end;
    private CarbonImmutable $today;
    private Company $company;
    /** @var array<string, string> família => categoria confirmada */
    private array $categories = [];
    /** @var array<string, string> família por confirmar => categoria sugerida pelas regras ou pela IA */
    private array $suggested = [];
    /** @var array<string, string> datas especiais (feriados e âncoras) */
    private array $special = [];

    public function __construct(private readonly RestaurantSpecialDays $specialDays) {}

    // ── Cálculo ────────────────────────────────────────────────────────────────

    /** Recalcula os sinais da empresa (só com o interruptor ligado). Devolve o resumo. */
    public function compute(int $companyId): array
    {
        if (! PingwinItemSalesService::isEnabled($companyId)) {
            return ['computed' => false, 'signals' => 0];
        }
        $this->company = Company::findOrFail($companyId);
        $this->today = CarbonImmutable::now('Europe/Lisbon')->startOfDay();
        $this->end = $this->today->subDay();
        $this->categories = RestaurantFamilyCategory::where('company_id', $companyId)->whereNotNull('category')
            ->pluck('category', 'family_pingwin_id')->map(fn ($c) => (string) $c)->all();
        // Por precaução, enquanto uma família não está confirmada, a sugestão das regras
        // ("Entrega" ou "Excluir") também a deixa fora dos rankings (nada fica confirmado).
        app(RestaurantFamilyCategoryService::class)->refresh($companyId);
        $this->suggested = RestaurantFamilyCategory::where('company_id', $companyId)->whereNull('category')->whereNotNull('suggested_category')
            ->pluck('suggested_category', 'family_pingwin_id')->map(fn ($c) => (string) $c)->all();
        $this->special = $this->specialDays->between($this->company, $this->end->subDays(self::STALE_BEFORE_DAYS + self::STALE_DAYS)->toDateString(), $this->end->toDateString());

        $signals = [];
        $availability = [];
        foreach (PingwinLocation::where('company_id', $companyId)->where('is_active', true)->orderBy('id')->get() as $location) {
            [$locSignals, $locAvailability] = $this->forLocation($location);
            array_push($signals, ...$locSignals);
            $availability[] = $locAvailability;
        }

        $now = now();
        DB::transaction(function () use ($companyId, $signals, $availability, $now) {
            RestaurantSignal::where('company_id', $companyId)->delete();
            foreach ($signals as $s) {
                RestaurantSignal::create($s + ['company_id' => $companyId, 'computed_at' => $now]);
            }
            RestaurantDataQuality::updateOrCreate(['company_id' => $companyId], ['signals_computed_at' => $now, 'signals_availability' => $availability]);
        });
        Log::info('[Restauração Sinais] calculados', ['company_id' => $companyId, 'sinais' => count($signals)]);

        return ['computed' => true, 'signals' => count($signals), 'availability' => $availability];
    }

    /** Recalcula se o último cálculo tiver mais de 24 horas (ou nunca tiver sido feito). */
    public function ensureFresh(int $companyId): void
    {
        if (! PingwinItemSalesService::isEnabled($companyId)) {
            return;
        }
        $at = RestaurantDataQuality::where('company_id', $companyId)->value('signals_computed_at');
        if (! $at || CarbonImmutable::parse($at)->lt(now()->subHours(self::STALE_AFTER_HOURS))) {
            $this->compute($companyId);
        }
    }

    /**
     * F3 §4: os sinais para a IA ("Gerar ideias do mês" e "Sugerir criativo"): só confiança
     * Alta e Média e sem as sugestões ignoradas; primeiro as sugestões (os períodos fracos só
     * os do período dado, ou do dia da semana dado), depois a informação. Vazio sem o
     * PingWin ou sem o interruptor.
     *
     * @return array<int, string>
     */
    public function aiLines(int $companyId, ?string $from = null, ?string $to = null, int $limit = 8, ?int $weekday = null): array
    {
        if (! app(\App\Services\CompanyModuleService::class)->isEnabled($companyId, 'pingwin') || ! PingwinItemSalesService::isEnabled($companyId)) {
            return [];
        }
        $hidden = array_flip(\App\Models\RestaurantSignalAction::hiddenKeys($companyId, CarbonImmutable::now('Europe/Lisbon')->toDateString()));
        $signals = RestaurantSignal::where('company_id', $companyId)->whereIn('confidence', ['alta', 'media'])
            ->orderByDesc('priority')->orderBy('id')->get()
            ->reject(fn (RestaurantSignal $s) => isset($hidden[$s->signal_key]));

        $inPeriod = function (RestaurantSignal $s) use ($from, $to, $weekday): bool {
            if ($s->type !== 'weak_period') {
                return true;
            }
            $dates = array_filter([$s->suggested_date?->toDateString(), $s->numbers['target_date'] ?? null]);
            foreach ($dates as $d) {
                if ($from && $to && $d >= $from && $d <= $to) {
                    return true;
                }
            }

            return $weekday !== null && (int) ($s->numbers['weekday'] ?? 0) === $weekday;
        };
        $suggestions = $signals->filter(fn ($s) => $s->kind === RestaurantSignal::KIND_SUGGESTION && $inPeriod($s));
        $info = $signals->filter(fn ($s) => $s->kind === RestaurantSignal::KIND_INFO);

        return $suggestions->concat($info)->take($limit)
            ->map(fn (RestaurantSignal $s) => '- ' . $s->sentence . ' (confiança ' . ($s->confidence === 'alta' ? 'alta' : 'média') . ')')
            ->values()->all();
    }

    /** @return array{0: array<int, array>, 1: array} sinais e disponibilidade de uma loja */
    private function forLocation(PingwinLocation $location): array
    {
        $name = $location->display_name ?: $location->winrest_name ?: (string) $location->winrest_store_id;
        $firstSale = PingwinItemSale::where('location_id', $location->id)->where('net_cents', '>', 0)->min('business_date');
        $start = $location->opened_on ?? $location->sales_since ?? ($firstSale ? CarbonImmutable::parse(substr((string) $firstSale, 0, 10)) : null);
        $start = $start ? CarbonImmutable::parse($start)->startOfDay() : null;

        $availability = ['location_id' => $location->id, 'name' => $name, 'start' => $start?->toDateString(),
            'yoy_from' => $start?->addYear()->toDateString(), 'signals' => []];
        if (! $start) {
            foreach (['top_items', 'top_categories', 'changes', 'weak_periods', 'stale_items', 'lead_time', 'channels', 'delivery_share'] as $type) {
                $availability['signals'][$type] = ['available' => false, 'from' => null, 'reason' => 'Ainda sem vendas lidas desta loja.'];
            }

            return [[], $availability];
        }

        $ctx = ['location' => $location, 'name' => $name, 'start' => $start];
        $signals = [];
        $lead = $this->leadBuckets($location);

        $this->topItems($ctx, $signals, $availability);
        $this->topCategoriesAndDelivery($ctx, $signals, $availability);
        $this->changes($ctx, $signals, $availability);
        $this->weakPeriods($ctx, $lead, $signals, $availability);
        $this->staleItems($ctx, $signals, $availability);
        $this->leadTime($ctx, $lead, $signals, $availability);
        $this->channels($ctx, $signals, $availability);

        return [$signals, $availability];
    }

    // ── S1: os mais vendidos ───────────────────────────────────────────────────

    private function topItems(array $ctx, array &$signals, array &$availability): void
    {
        [$from, $to] = $this->window(self::WINDOW_DAYS);
        if (! $this->windowReady($ctx, $from, $to, 'top_items', $availability)) {
            return;
        }
        $items = $this->itemTotals($ctx['location']->id, $from, $to);
        $storeNet = array_sum(array_column($items, 'net'));
        $ranked = array_values(array_filter($items, fn ($i) => $this->countsForRanking($i) && $i['net'] >= self::MIN_NET_CENTS && $i['qty'] >= 10));
        usort($ranked, fn ($a, $b) => $b['net'] <=> $a['net']);
        $ranked = array_slice($ranked, 0, 5);
        if ($ranked === [] || $storeNet <= 0) {
            return;
        }
        $rows = array_map(fn ($i) => [
            'product_id' => $i['product'], 'name' => $i['name'], 'qty' => (int) round($i['qty']), 'net_cents' => $i['net'],
            'share_pct' => round($i['net'] / $storeNet * 100, 1), 'confidence' => $i['qty'] >= 30 ? 'alta' : 'media',
        ], $ranked);
        $top = $rows[0];
        $signals[] = $this->signal($ctx, 'top_items', "top_items:{$ctx['location']->id}", RestaurantSignal::KIND_INFO, $top['confidence'],
            "Os mais vendidos ({$ctx['name']})",
            sprintf('Nas últimas 4 semanas, %s foi o artigo que mais vendeu na loja %s: %s unidades, %s sem IVA (%s%% das vendas da loja).',
                $top['name'], $ctx['name'], $this->int($top['qty']), $this->eur($top['net_cents']), $this->pct($top['share_pct'])),
            ['items' => $rows, 'store_net_cents' => $storeNet],
            ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => self::WINDOW_DAYS]);
    }

    // ── S1 por categoria e S6 (peso da entrega) ────────────────────────────────

    private function topCategoriesAndDelivery(array $ctx, array &$signals, array &$availability): void
    {
        [$from, $to] = $this->window(self::WINDOW_DAYS);
        $ready = $this->windowReady($ctx, $from, $to, 'top_categories', $availability);
        $availability['signals']['delivery_share'] = $availability['signals']['top_categories'];
        if (! $ready) {
            return;
        }
        $items = $this->itemTotals($ctx['location']->id, $from, $to);
        $families = array_unique(array_filter(array_column($items, 'family')));
        $pending = count(array_filter($families, fn ($f) => ! isset($this->categories[$f])));
        if ($pending > 0) {
            $reason = "Confirme as categorias das famílias ({$pending} por confirmar nesta loja).";
            $availability['signals']['top_categories'] = ['available' => false, 'from' => null, 'reason' => $reason];
            $availability['signals']['delivery_share'] = ['available' => false, 'from' => null, 'reason' => $reason];

            return;
        }
        $storeNet = array_sum(array_column($items, 'net'));
        if ($storeNet <= 0) {
            return;
        }
        $byCategory = [];
        foreach ($items as $i) {
            $c = $this->categories[$i['family']] ?? null;
            if ($c !== null) {
                $byCategory[$c] = ($byCategory[$c] ?? 0) + $i['net'];
            }
        }
        $delivery = $byCategory['entrega'] ?? 0;
        $ranked = array_filter($byCategory, fn ($net, $c) => ! in_array($c, self::EXCLUDED_CATEGORIES, true) && $net > 0, ARRAY_FILTER_USE_BOTH);
        arsort($ranked);
        if ($ranked !== []) {
            $rows = [];
            foreach ($ranked as $c => $net) {
                $rows[] = ['category' => $c, 'label' => FamilyCategoryRules::label($c), 'net_cents' => $net, 'share_pct' => round($net / $storeNet * 100, 1)];
            }
            $first = $rows[0];
            $rest = array_slice($rows, 1, 2);
            $sentence = sprintf('Nas últimas 4 semanas, na loja %s, a categoria com mais peso nas vendas foi %s (%s%%)', $ctx['name'], $first['label'], $this->pct($first['share_pct']));
            if ($rest !== []) {
                $sentence .= ', seguida de ' . $this->joinList(array_map(fn ($r) => "{$r['label']} ({$this->pct($r['share_pct'])}%)", $rest));
            }
            $signals[] = $this->signal($ctx, 'top_categories', "top_categories:{$ctx['location']->id}", RestaurantSignal::KIND_INFO, 'alta',
                "Categorias com mais peso ({$ctx['name']})", $sentence . '.', ['categories' => $rows, 'store_net_cents' => $storeNet],
                ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => self::WINDOW_DAYS]);
        }
        if ($delivery > 0) {
            $share = round($delivery / $storeNet * 100, 1);
            $signals[] = $this->signal($ctx, 'delivery_share', "delivery_share:{$ctx['location']->id}", RestaurantSignal::KIND_INFO, 'alta',
                "Peso da entrega ({$ctx['name']})",
                sprintf('Nas últimas 4 semanas, a entrega representou %s%% das vendas da loja %s (%s sem IVA).', $this->pct($share), $ctx['name'], $this->eur($delivery)),
                ['net_cents' => $delivery, 'share_pct' => $share, 'store_net_cents' => $storeNet],
                ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => self::WINDOW_DAYS]);
        }
    }

    // ── S2: em subida e em descida ─────────────────────────────────────────────

    private function changes(array $ctx, array &$signals, array &$availability): void
    {
        [$from, $to] = $this->window(self::WINDOW_DAYS);
        $prevTo = $from->subDay();
        $prevFrom = $prevTo->subDays(self::WINDOW_DAYS - 1);
        if (! $this->windowReady($ctx, $prevFrom, $to, 'changes', $availability)) {
            return;
        }
        $now = $this->itemTotals($ctx['location']->id, $from, $to);
        $before = $this->itemTotals($ctx['location']->id, $prevFrom, $prevTo);
        $storeNow = array_sum(array_column($now, 'net'));
        $storeBefore = array_sum(array_column($before, 'net'));
        if ($storeBefore <= 0) {
            return;
        }
        $storeVar = ($storeNow - $storeBefore) / $storeBefore;
        $specialNames = array_values(array_unique(array_values(array_filter($this->special,
            fn ($d) => $d >= $prevFrom->toDateString() && $d <= $to->toDateString(), ARRAY_FILTER_USE_KEY))));

        foreach ($now as $product => $i) {
            $b = $before[$product] ?? null;
            if (! $b || ! $this->countsForRanking($i)) {
                continue;
            }
            if ($i['qty'] < 20 || $b['qty'] < 20 || $i['net'] < self::MIN_NET_CENTS || $b['net'] < self::MIN_NET_CENTS) {
                continue;
            }
            $var = ($i['qty'] - $b['qty']) / $b['qty'];
            $up = $var >= 0.25 && ($var - $storeVar) >= 0.20;
            $down = $var <= -0.25 && ($storeVar - $var) >= 0.20;
            if (! $up && ! $down) {
                continue;
            }
            $confidence = (min($i['qty'], $b['qty']) >= 40 && abs($var) >= 0.40) ? 'alta' : 'media';
            $type = $up ? 'item_up' : 'item_down';
            $sentence = sprintf('%s vendeu %s unidades nas últimas 4 semanas na loja %s, contra %s nas 4 anteriores (%s; %s).',
                $i['name'], $this->int((int) round($i['qty'])), $ctx['name'], $this->int((int) round($b['qty'])),
                $this->signedPct($var), abs($storeVar) < 0.005 ? 'a loja manteve-se' : 'a loja variou ' . $this->signedPct($storeVar));
            if ($specialNames !== []) {
                $sentence .= ' Inclui datas especiais: ' . implode(', ', $specialNames) . '.';
            }
            $signals[] = $this->signal($ctx, $type, "{$type}:{$ctx['location']->id}:{$product}", RestaurantSignal::KIND_SUGGESTION, $confidence,
                ($up ? 'Em subida: ' : 'Em descida: ') . "{$i['name']} ({$ctx['name']})", $sentence,
                ['product_id' => $product, 'name' => $i['name'], 'qty_now' => (int) round($i['qty']), 'qty_before' => (int) round($b['qty']),
                    'net_now_cents' => $i['net'], 'net_before_cents' => $b['net'], 'variation_pct' => round($var * 100, 1), 'store_variation_pct' => round($storeVar * 100, 1)],
                ['from' => $prevFrom->toDateString(), 'to' => $to->toDateString(), 'days' => self::COMPARE_DAYS, 'special_days' => $specialNames],
                $up ? "{$i['name']} em destaque" : "Voltar a mostrar: {$i['name']}",
                $this->today->addDay(),
                ($confidence === 'alta' ? 1000 : 0) + ($up ? 200 + min(99, (int) round(abs($var) * 100 / 3)) : 50 + min(49, (int) round(abs($var) * 100 / 3))));
        }
    }

    // ── S3: períodos fracos e quando publicar ──────────────────────────────────

    private function weakPeriods(array $ctx, ?array $lead, array &$signals, array &$availability): void
    {
        [$from, $to] = $this->window(self::COMPARE_DAYS);
        $from = $from->max($ctx['start']);
        $locId = $ctx['location']->id;
        $lead = ($lead && $lead['mode'] !== null) ? $lead : null; // menos de 80 reservas: sem antecedência típica
        $minFrom = $ctx['start']->addDays(7 * self::MIN_OCCURRENCES - 1);
        if ($this->end->lt($minFrom)) {
            $availability['signals']['weak_periods'] = ['available' => false, 'from' => $minFrom->addDay()->toDateString(),
                'reason' => 'Precisa de 6 semanas de vendas desta loja.'];

            return;
        }

        // Por hora (turnos) se houver vendas por hora lidas em 90% dos dias; senão, por dia.
        $hourDays = PingwinHourlySalesDay::where('location_id', $locId)->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', ['ok', 'unverified', 'empty'])->pluck('business_date')->map(fn ($d) => substr((string) $d, 0, 10))->all();
        $span = (int) $from->diffInDays($to) + 1;
        $values = []; // [shift][date] => cêntimos
        if (count($hourDays) / $span >= self::MIN_COVERAGE) {
            $mode = 'hours';
            $days = array_values(array_filter($hourDays, fn ($d) => ! isset($this->special[$d])));
            $hours = PingwinHourlySale::where('location_id', $locId)->whereIn('business_date', $days)->get(['business_date', 'hour', 'net_cents']);
            $byDate = [];
            foreach ($hours as $h) {
                $byDate[substr((string) $h->business_date, 0, 10)][(int) $h->hour] = (int) $h->net_cents;
            }
            $total = 0;
            $afternoon = 0;
            foreach ($days as $d) {
                foreach (self::SHIFTS as $shift => $shiftHours) {
                    $v = 0;
                    foreach ($shiftHours as $h) {
                        $v += $byDate[$d][$h] ?? 0;
                    }
                    $values[$shift][$d] = $v;
                }
                $total += array_sum($byDate[$d] ?? []);
                $afternoon += $values['tarde'][$d];
            }
            // Ajuste 2: a tarde só conta onde vale pelo menos 10% das vendas do dia.
            if ($total <= 0 || $afternoon / $total < self::AFTERNOON_MIN_SHARE) {
                unset($values['tarde']);
            }
        } else {
            $mode = 'days';
            $rows = PingwinDailySale::where('location_id', $locId)->whereRaw('DATE(business_date) BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
                ->get(['business_date', 'net_cents']);
            foreach ($rows as $r) {
                $d = substr((string) $r->business_date, 0, 10);
                if (! isset($this->special[$d])) {
                    $values['dia'][$d] = (int) $r->net_cents;
                }
            }
        }
        $availability['signals']['weak_periods'] = ['available' => true, 'from' => null, 'reason' => null, 'mode' => $mode];

        foreach ($values as $shift => $byDate) {
            if ($byDate === []) {
                continue;
            }
            $mean = array_sum($byDate) / count($byDate);
            if ($mean <= 0) {
                continue;
            }
            $byWeekday = [];
            foreach ($byDate as $d => $v) {
                $byWeekday[CarbonImmutable::parse($d)->dayOfWeekIso][$d] = $v;
            }
            foreach ($byWeekday as $wd => $occ) {
                $n = count($occ);
                $zeros = count(array_filter($occ, fn ($v) => $v <= 0));
                if ($n < self::MIN_OCCURRENCES || $zeros * 2 >= $n) {
                    continue; // poucas ocorrências, ou a loja está fechada nesse dia ou turno
                }
                $avg = array_sum($occ) / $n;
                if ($avg > self::WEAK_THRESHOLD * $mean) {
                    continue;
                }
                $below = count(array_filter($occ, fn ($v) => $v < $mean));
                $confidence = ($n >= 8 && $below >= 6) ? 'alta' : 'media';
                [$target, $publish, $offset] = $this->publishDate($wd, $lead);
                $pctBelow = (int) round((1 - $avg / $mean) * 100);
                $leadText = $lead
                    ? 'A maior parte das reservas da loja é feita ' . self::LEAD_PHRASE[$lead['mode']]
                    : 'Sem dados de antecedência das reservas desta loja';
                if ($shift === 'dia') {
                    $subject = 'As ' . self::WEEKDAY_PLURAL[$wd] . " na loja {$ctx['name']}";
                    $compare = 'dos dias da semana';
                    $title = 'Período fraco: ' . self::WEEKDAY_SHORT[$wd] . " ({$ctx['name']})";
                    $theme = ucfirst(self::WEEKDAY_SHORT[$wd]) . " na loja {$ctx['name']}";
                } else {
                    [$one, $many, $art] = self::SHIFT_WORDS[$shift];
                    $subject = "{$art} {$many} de " . self::WEEKDAY_SHORT[$wd] . " na loja {$ctx['name']}";
                    $compare = "dos {$many} da semana";
                    if ($shift === 'tarde') {
                        $compare = 'das tardes da semana';
                    }
                    $title = "Período fraco: {$one} de " . self::WEEKDAY_SHORT[$wd] . " ({$ctx['name']})";
                    $theme = ucfirst($one) . ' de ' . self::WEEKDAY_SHORT[$wd] . " na loja {$ctx['name']}";
                }
                $sentence = sprintf('%s ficaram %d%% abaixo da média %s (média de %s contra %s, em %d %s). %s: sugestão de publicar %s, %s.',
                    $subject, $pctBelow, $compare, $this->eur((int) round($avg)), $this->eur((int) round($mean)), $n, self::WEEKDAY_PLURAL[$wd],
                    $leadText, $this->onWeekday($publish), $publish->format('d/m'));
                $signals[] = $this->signal($ctx, 'weak_period', "weak_period:{$locId}:{$wd}:{$shift}", RestaurantSignal::KIND_SUGGESTION, $confidence,
                    $title, $sentence,
                    ['weekday' => $wd, 'shift' => $shift, 'mode' => $mode, 'avg_cents' => (int) round($avg), 'mean_cents' => (int) round($mean),
                        'pct_below' => $pctBelow, 'occurrences' => $n, 'below_count' => $below, 'lead_mode' => $lead['mode'] ?? null,
                        'offset_days' => $offset, 'target_date' => $target->toDateString()],
                    ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'occurrences' => $n,
                        'special_days_excluded' => array_values(array_filter(array_keys($this->special), fn ($d) => $d >= $from->toDateString() && $d <= $to->toDateString()))],
                    $theme, $publish,
                    ($confidence === 'alta' ? 1000 : 0) + 300 + max(0, 30 - (int) round($this->today->diffInDays($publish))));
            }
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: int} dia fraco seguinte, data de publicação e antecedência */
    private function publishDate(int $weekday, ?array $lead): array
    {
        $offset = $lead ? self::LEAD_OFFSET[$lead['mode']] : self::DEFAULT_OFFSET;
        $target = $this->today->addDay();
        while ($target->dayOfWeekIso !== $weekday) {
            $target = $target->addDay();
        }
        while ($target->subDays($offset)->lt($this->today)) {
            $target = $target->addWeek();
        }

        return [$target, $target->subDays($offset), $offset];
    }

    // ── S4: artigos parados ────────────────────────────────────────────────────

    private function staleItems(array $ctx, array &$signals, array &$availability): void
    {
        $recentTo = $this->end;
        $recentFrom = $recentTo->subDays(self::STALE_DAYS - 1);
        $beforeTo = $recentFrom->subDay();
        $beforeFrom = $beforeTo->subDays(self::STALE_BEFORE_DAYS - 1);
        if (! $this->windowReady($ctx, $beforeFrom, $recentTo, 'stale_items', $availability)) {
            return;
        }
        $catalogAt = PingwinCatalogItem::where('company_id', $this->company->id)->max('synced_at');
        if (! $catalogAt || CarbonImmutable::parse($catalogAt)->lt(now()->subDays(self::CATALOG_FRESH_DAYS))) {
            $availability['signals']['stale_items'] = ['available' => false, 'from' => null,
                'reason' => 'Precisa do catálogo lido nos últimos 14 dias (a leitura do catálogo completo é ao domingo).'];

            return;
        }
        $recent = $this->itemTotals($ctx['location']->id, $recentFrom, $recentTo);
        $before = $this->itemTotals($ctx['location']->id, $beforeFrom, $beforeTo);
        $active = PingwinCatalogItem::where('company_id', $this->company->id)->where('is_active', true)->where('forsale', true)
            ->pluck('pingwin_id')->flip();
        foreach ($before as $product => $b) {
            if (isset($recent[$product]) && $recent[$product]['qty'] > 0) {
                continue;
            }
            if (! isset($active[$product]) || ! $this->countsForRanking($b) || $b['qty'] < 10 || $b['net'] < self::MIN_NET_CENTS) {
                continue;
            }
            $last = PingwinItemSale::where('location_id', $ctx['location']->id)->where('product_pingwin_id', $product)->where('quantity', '>', 0)->max('business_date');
            $days = $last ? (int) round(CarbonImmutable::parse(substr((string) $last, 0, 10))->diffInDays($this->today)) : self::STALE_DAYS;
            $signals[] = $this->signal($ctx, 'stale_item', "stale_item:{$ctx['location']->id}:{$product}", RestaurantSignal::KIND_SUGGESTION, 'media',
                "Parado: {$b['name']} ({$ctx['name']})",
                sprintf('%s não vende há %d dias na loja %s; nas 8 semanas anteriores vendeu %s unidades.', $b['name'], $days, $ctx['name'], $this->int((int) round($b['qty']))),
                ['product_id' => $product, 'name' => $b['name'], 'days_without_sales' => $days, 'qty_before' => (int) round($b['qty']), 'net_before_cents' => $b['net']],
                ['from' => $beforeFrom->toDateString(), 'to' => $recentTo->toDateString(), 'days' => self::STALE_DAYS + self::STALE_BEFORE_DAYS],
                "Voltar a mostrar: {$b['name']}", $this->today->addDay(), 100 + min(99, (int) round($b['qty'])));
        }
    }

    // ── S5: antecedência das reservas ──────────────────────────────────────────

    private function leadTime(array $ctx, ?array $lead, array &$signals, array &$availability): void
    {
        if (! $lead || $lead['total'] < self::MIN_RESERVATIONS) {
            $availability['signals']['lead_time'] = ['available' => false, 'from' => null,
                'reason' => 'Precisa de pelo menos 80 reservas lidas nos últimos 90 dias (CoverManager).'];

            return;
        }
        $availability['signals']['lead_time'] = ['available' => true, 'from' => null, 'reason' => null];
        $shares = $lead['shares'];
        arsort($shares);
        $top = array_slice($shares, 0, 2, true);
        $parts = [];
        foreach ($top as $bucket => $share) {
            $parts[] = $this->pct(round($share * 100, 0)) . '% ' . (count($parts) === 0 ? 'das reservas são feitas ' : '') . self::LEAD_PHRASE[$bucket];
        }
        // O prazo que cobre 80% das reservas, para o dia mais forte da loja.
        $cum = 0.0;
        $cover = 'd31_plus';
        foreach (self::LEAD_ORDER as $bucket) {
            $cum += $lead['shares'][$bucket] ?? 0;
            if ($cum >= 0.8) {
                $cover = $bucket;
                break;
            }
        }
        $strong = $this->strongestWeekday($ctx['location']->id);
        $when = match ($cover) {
            'same_day' => 'se sair até ao próprio dia',
            'd1_2' => 'se sair até ' . $this->weekdayName(($strong + 5 - 1) % 7 + 1) . ' (2 dias antes)',
            'd3_7' => 'se sair uma semana antes',
            'd8_30' => 'se sair cerca de um mês antes',
            default => 'se sair com mais de um mês de antecedência',
        };
        $sentence = sprintf('Na loja %s, %s (últimos 90 dias, %s reservas). Uma publicação para %s chega antes de 80%% das reservas %s.',
            $ctx['name'], $this->joinList($parts), $this->int($lead['total']), $this->weekdayName($strong), $when);
        $signals[] = $this->signal($ctx, 'lead_time', "lead_time:{$ctx['location']->id}", RestaurantSignal::KIND_INFO,
            $lead['total'] >= self::HIGH_RESERVATIONS ? 'alta' : 'media',
            "Antecedência das reservas ({$ctx['name']})", $sentence,
            ['shares' => array_map(fn ($s) => round($s * 100, 1), $lead['shares']), 'total' => $lead['total'], 'mode' => $lead['mode'],
                'cover80' => $cover, 'strongest_weekday' => $strong],
            ['days' => self::RESERVATION_DAYS, 'reservations' => $lead['total']]);
    }

    /** Escalões de antecedência dos últimos 90 dias (reservas válidas, sem walk-ins). */
    private function leadBuckets(PingwinLocation $location): ?array
    {
        [$from, $to] = $this->window(self::RESERVATION_DAYS);
        $rows = CmReservationLeadtimeDaily::where('location_id', $location->id)->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
            ->select('bucket', DB::raw('SUM(reservations_count) as n'))->groupBy('bucket')->pluck('n', 'bucket');
        $total = (int) $rows->sum();
        if ($total < self::MIN_RESERVATIONS) {
            return $total > 0 ? ['total' => $total, 'shares' => [], 'mode' => null] : null;
        }
        $shares = [];
        foreach (self::LEAD_ORDER as $b) {
            $shares[$b] = (int) ($rows[$b] ?? 0) / $total;
        }
        $mode = array_search(max($shares), $shares, true);

        return ['total' => $total, 'shares' => $shares, 'mode' => $mode];
    }

    // ── S6: canais de reserva ──────────────────────────────────────────────────

    private function channels(array $ctx, array &$signals, array &$availability): void
    {
        [$from, $to] = $this->window(self::RESERVATION_DAYS);
        $rows = CmReservationChannelDaily::where('location_id', $ctx['location']->id)->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
            ->select('channel', DB::raw('SUM(reservations_count) as n'))->groupBy('channel')->pluck('n', 'channel')->map(fn ($n) => (int) $n)->all();
        $total = array_sum($rows);
        if ($total < self::MIN_RESERVATIONS) {
            $availability['signals']['channels'] = ['available' => false, 'from' => null,
                'reason' => 'Precisa de pelo menos 80 reservas lidas nos últimos 90 dias (CoverManager).'];

            return;
        }
        $availability['signals']['channels'] = ['available' => true, 'from' => null, 'reason' => null];
        arsort($rows);
        $top = array_slice($rows, 0, 3, true);
        $others = $total - array_sum($top);
        $parts = [];
        $first = true;
        foreach ($top as $channel => $n) {
            $p = $this->pct(round($n / $total * 100, 0));
            if ($channel === 'walk in') {
                $parts[] = "{$p}% foram walk-ins";
            } else {
                $parts[] = $first ? "{$p}% chegaram por {$channel}" : "{$p}% por {$channel}";
                $first = false;
            }
        }
        if ($others > 0) {
            $parts[] = $this->pct(round($others / $total * 100, 0)) . '% por outros canais';
        }
        $signals[] = $this->signal($ctx, 'channels', "channels:{$ctx['location']->id}", RestaurantSignal::KIND_INFO,
            $total >= self::HIGH_RESERVATIONS ? 'alta' : 'media',
            "Canais de reserva ({$ctx['name']})",
            sprintf('Das reservas dos últimos 90 dias na loja %s, %s.', $ctx['name'], $this->joinList($parts)),
            ['channels' => array_map(fn ($n) => ['reservations' => $n, 'share_pct' => round($n / $total * 100, 1)], $rows), 'total' => $total],
            ['days' => self::RESERVATION_DAYS, 'reservations' => $total]);
    }

    // ── Ajudas ─────────────────────────────────────────────────────────────────

    /** [início, fim] dos últimos $days dias fechados (até ontem). */
    private function window(int $days): array
    {
        return [$this->end->subDays($days - 1), $this->end];
    }

    /**
     * A loja tem de existir em todo o intervalo e ter as vendas por artigo lidas em 90% dos
     * dias; senão, regista porquê e quando o sinal fica disponível.
     */
    private function windowReady(array $ctx, CarbonImmutable $from, CarbonImmutable $to, string $type, array &$availability): bool
    {
        $span = (int) round($from->diffInDays($to)) + 1;
        if ($ctx['start']->gt($from)) {
            $availableFrom = $ctx['start']->addDays($span);
            $weeks = (int) round($span / 7);
            $availability['signals'][$type] = ['available' => false, 'from' => $availableFrom->toDateString(),
                'reason' => "Precisa de {$weeks} semanas de vendas desta loja."];

            return false;
        }
        $read = PingwinItemSalesDay::where('location_id', $ctx['location']->id)
            ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', ['ok', 'unverified', 'empty'])->count();
        if ($read / $span < self::MIN_COVERAGE) {
            $availability['signals'][$type] = ['available' => false, 'from' => null,
                'reason' => 'Faltam dias por ler nas vendas por artigo deste período.'];

            return false;
        }
        $availability['signals'][$type] = ['available' => true, 'from' => null, 'reason' => null];

        return true;
    }

    /** @return array<string, array{product: string, name: string, family: ?string, qty: float, net: int}> */
    private function itemTotals(int $locationId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        PingwinItemSale::where('location_id', $locationId)->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
            ->select('product_pingwin_id', DB::raw('MAX(product_name) as name'), DB::raw('MAX(family_pingwin_id) as family'),
                DB::raw('SUM(quantity) as qty'), DB::raw('SUM(net_cents) as net'))
            ->groupBy('product_pingwin_id')->get()
            ->each(function ($r) use (&$out) {
                $out[(string) $r->product_pingwin_id] = ['product' => (string) $r->product_pingwin_id, 'name' => (string) ($r->name ?: $r->product_pingwin_id),
                    'family' => $r->family !== null ? (string) $r->family : null, 'qty' => (float) $r->qty, 'net' => (int) $r->net];
            });

        return $out;
    }

    /**
     * Ajuste 3: as categorias "Excluir" e "Entrega" ficam fora de S1, S2 e S4. Vale a categoria
     * confirmada; sem ela, por precaução, a sugerida (as regras sugerem, a equipa confirma).
     */
    private function countsForRanking(array $item): bool
    {
        $family = $item['family'];
        $category = $family !== null ? ($this->categories[$family] ?? $this->suggested[$family] ?? null) : null;

        return ! in_array($category, self::EXCLUDED_CATEGORIES, true);
    }

    /** Dia da semana com a maior média de vendas nas últimas 8 semanas (6 = sábado por omissão). */
    private function strongestWeekday(int $locationId): int
    {
        [$from, $to] = $this->window(self::COMPARE_DAYS);
        $byWd = [];
        PingwinDailySale::where('location_id', $locationId)->whereRaw('DATE(business_date) BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
            ->get(['business_date', 'net_cents'])->each(function ($r) use (&$byWd) {
                $d = substr((string) $r->business_date, 0, 10);
                if (! isset($this->special[$d])) {
                    $byWd[CarbonImmutable::parse($d)->dayOfWeekIso][] = (int) $r->net_cents;
                }
            });
        $best = 6;
        $bestAvg = -1;
        foreach ($byWd as $wd => $vals) {
            $avg = array_sum($vals) / count($vals);
            if ($avg > $bestAvg) {
                [$best, $bestAvg] = [$wd, $avg];
            }
        }

        return $best;
    }

    private function signal(array $ctx, string $type, string $key, string $kind, string $confidence, string $title, string $sentence,
        array $numbers, array $sample, ?string $theme = null, ?CarbonImmutable $date = null, int $priority = 0): array
    {
        return [
            'location_id' => $ctx['location']->id,
            'type' => $type,
            'signal_key' => mb_substr($key, 0, 160),
            'kind' => $kind,
            'confidence' => $confidence,
            'title' => mb_substr($title, 0, 255),
            'sentence' => $sentence,
            'numbers' => $numbers,
            'sample' => $sample,
            'theme' => $theme ? mb_substr($theme, 0, 255) : null,
            'suggested_date' => $date?->toDateString(),
            'priority' => $priority,
        ];
    }

    private function weekdayName(int $wd): string
    {
        return self::WEEKDAY[$wd];
    }

    /** "na segunda-feira" / "no sábado". */
    private function onWeekday(CarbonImmutable $d): string
    {
        $wd = $d->dayOfWeekIso;

        return ($wd >= 6 ? 'no ' : 'na ') . self::WEEKDAY[$wd];
    }

    private function eur(int $cents): string
    {
        return number_format($cents / 100, 0, ',', ' ') . ' €';
    }

    private function int(int $n): string
    {
        return number_format($n, 0, ',', ' ');
    }

    private function pct(float $v): string
    {
        return number_format($v, $v == round($v) ? 0 : 1, ',', ' ');
    }

    /** "mais 38%" / "menos 27%". */
    private function signedPct(float $ratio): string
    {
        return ($ratio >= 0 ? 'mais ' : 'menos ') . $this->pct(round(abs($ratio) * 100, 0)) . '%';
    }

    private function joinList(array $parts): string
    {
        if (count($parts) <= 1) {
            return (string) ($parts[0] ?? '');
        }
        $last = array_pop($parts);

        return implode(', ', $parts) . ' e ' . $last;
    }
}
