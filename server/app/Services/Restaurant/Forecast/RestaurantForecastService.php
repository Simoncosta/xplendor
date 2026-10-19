<?php

declare(strict_types=1);

namespace App\Services\Restaurant\Forecast;

use App\Models\CmReservationSnapshot;
use App\Models\PingwinLocation;
use App\Models\RestaurantForecast;
use App\Models\RestaurantForecastError;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fatia 6a: a previsão por loja e dia, para as pessoas (CoverManager) e a faturação com IVA (PingWin).
 *
 *  · de 2 a 30 dias: a média do mesmo dia da semana nas últimas 4 semanas com dados, sem o maior e
 *    o menor (os feriados ficam de fora da história);
 *  · amanhã: a mesma média ajustada pelas reservas (as pessoas já reservadas ÷ a mediana das
 *    reservadas com a mesma antecedência nas últimas 4 semanas, entre 0,7 e 1,4). Só fica se o erro
 *    medido for menor do que o da média simples nos mesmos dias; senão fica a média e diz-se porquê;
 *  · o erro medido: backtest das últimas 8 semanas (erro percentual médio), por loja e modelo;
 *  · o intervalo: os percentis 10 e 90 dos erros do backtest.
 *
 * Nunca se promete acerto: mostra-se sempre o erro medido.
 */
class RestaurantForecastService
{
    public const METRICS = ['guests', 'invoiced'];
    public const BACKTEST_DAYS = 56;
    public const HORIZON = 30;
    public const MIN_SAMPLES = 10;
    private const HISTORY_DAYS = 220;

    private CarbonImmutable $today;
    /** @var array<string, string> */
    private array $holidays;
    /** @var array<int, array<string, array<string, float>>> */
    private array $series = [];

    public function __construct(private readonly int $companyId, ?CarbonImmutable $today = null)
    {
        $this->today = ($today ?? CarbonImmutable::now('Europe/Lisbon'))->startOfDay();
        $this->holidays = PortugalHolidays::between($this->today->subDays(self::HISTORY_DAYS + 70)->toDateString(), $this->today->addDays(self::HORIZON + 10)->toDateString(),
            (string) Settings::company($companyId, 'calendar.municipal_holiday'));
    }

    public function holidays(): array
    {
        return $this->holidays;
    }

    /** As séries de uma loja: guests (pessoas), invoiced (faturação com IVA, €) e reserved (pessoas reservadas na véspera ou antes). */
    public function series(int $locationId): array
    {
        if (isset($this->series[$locationId])) {
            return $this->series[$locationId];
        }
        $from = $this->today->subDays(self::HISTORY_DAYS)->toDateString();
        $to = $this->today->subDay()->toDateString();
        $s = ['guests' => [], 'invoiced' => [], 'reserved' => []];
        foreach (DB::table('pingwin_daily_sales')->where('company_id', $this->companyId)->where('location_id', $locationId)
            ->whereRaw('DATE(business_date) BETWEEN ? AND ?', [$from, $to])->get(['business_date', 'invoiced_cents']) as $r) {
            $s['invoiced'][substr((string) $r->business_date, 0, 10)] = ((int) $r->invoiced_cents) / 100;
        }
        foreach (DB::table('cm_reservation_shift_summary')->where('company_id', $this->companyId)->where('location_id', $locationId)
            ->whereRaw('DATE(business_date) BETWEEN ? AND ?', [$from, $to])->groupByRaw('DATE(business_date)')
            ->selectRaw('DATE(business_date) as d, SUM(guests_total) as g')->get() as $r) {
            $d = substr((string) $r->d, 0, 10);
            $s['guests'][$d] = (float) $r->g;
            $s['reserved'][$d] = 0.0;   // com o CoverManager lido nesse dia: 0 até haver reservas com antecedência
        }
        foreach (DB::table('cm_reservation_leadtime_daily')->where('company_id', $this->companyId)->where('location_id', $locationId)
            ->whereRaw('DATE(business_date) BETWEEN ? AND ?', [$from, $to])->where('bucket', '!=', 'same_day')
            ->groupByRaw('DATE(business_date)')->selectRaw('DATE(business_date) as d, SUM(guests_total) as g')->get() as $r) {
            $s['reserved'][substr((string) $r->d, 0, 10)] = (float) $r->g;
        }
        foreach ($s as &$x) {
            ksort($x);
        }

        return $this->series[$locationId] = $s;
    }

    /** A média do mesmo dia da semana: as últimas 4 ocorrências com dados antes de $before (exclusivo), sem feriados, sem o maior e o menor. */
    public function baseline(array $series, string $date, string $before): ?float
    {
        $values = [];
        $d = CarbonImmutable::parse($date);
        for ($k = 1; $k <= 12 && count($values) < 4; $k++) {
            $x = $d->subWeeks($k)->toDateString();
            if ($x >= $before || isset($this->holidays[$x]) || ! array_key_exists($x, $series)) {
                continue;
            }
            $values[] = $series[$x];
        }

        return ForecastMath::trimmedMean($values);
    }

    /** As pessoas reservadas com antecedência no mesmo dia da semana (últimas 4 com dados, sem feriados): a mediana. */
    public function reservedMedian(array $reserved, string $date): ?float
    {
        $values = [];
        $d = CarbonImmutable::parse($date);
        for ($k = 1; $k <= 8 && count($values) < 4; $k++) {
            $x = $d->subWeeks($k)->toDateString();
            if (! isset($this->holidays[$x]) && array_key_exists($x, $reserved)) {
                $values[] = $reserved[$x];
            }
        }

        return count($values) >= 2 ? ForecastMath::median($values) : null;
    }

    /**
     * Backtest das últimas 8 semanas (até ao último dia com vendas): os erros relativos de cada modelo.
     *
     * @return array<string, array<string, array{errors: list<float>, from: ?string, to: ?string}>>
     */
    public function backtest(int $locationId): array
    {
        $s = $this->series($locationId);
        $last = $s['invoiced'] ? array_key_last($s['invoiced']) : null;
        $out = [];
        if (! $last) {
            return $out;
        }
        $end = CarbonImmutable::parse($last);
        for ($d = $end->subDays(self::BACKTEST_DAYS - 1); $d->lte($end); $d = $d->addDay()) {
            $day = $d->toDateString();
            if (isset($this->holidays[$day])) {
                continue;
            }
            foreach (self::METRICS as $m) {
                $actual = $s[$m][$day] ?? null;
                if ($actual === null || $actual <= 0) {
                    continue;
                }
                $f1 = $this->baseline($s[$m], $day, $d->subDay()->toDateString());
                $f7 = $this->baseline($s[$m], $day, $d->subDays(7)->toDateString());
                if ($f1 !== null) {
                    $this->push($out, $m, 'media_d1', ForecastMath::pctError($f1, $actual), $day);
                }
                if ($f7 !== null) {
                    $this->push($out, $m, 'media_d7', ForecastMath::pctError($f7, $actual), $day);
                }
                // Amanhã com reservas: só nos dias em que há reservas com antecedência e a mediana delas.
                $r = $s['reserved'][$day] ?? null;
                $med = $this->reservedMedian($s['reserved'], $day);
                $ratio = $r !== null && $med !== null ? ForecastMath::reservationRatio($r, $med) : null;
                if ($f1 !== null && $ratio !== null) {
                    $this->push($out, $m, 'reservas_d1', ForecastMath::pctError($f1 * $ratio, $actual), $day);
                    $this->push($out, $m, 'media_d1_res', ForecastMath::pctError($f1, $actual), $day);
                }
            }
        }

        return $out;
    }

    private function push(array &$out, string $m, string $model, ?float $e, string $day): void
    {
        if ($e === null) {
            return;
        }
        $out[$m][$model]['errors'][] = $e;
        $out[$m][$model]['from'] ??= $day;
        $out[$m][$model]['to'] = $day;
    }

    /** Guarda o erro medido de cada modelo (e devolve-o). */
    public function saveErrors(PingwinLocation $l): array
    {
        $bt = $this->backtest($l->id);
        $now = now();
        $saved = [];
        foreach ($bt as $m => $models) {
            foreach ($models as $model => $x) {
                $row = ['mape' => ForecastMath::mape($x['errors']), 'p10' => ForecastMath::percentile($x['errors'], 10), 'p90' => ForecastMath::percentile($x['errors'], 90),
                    'n' => count($x['errors']), 'from' => $x['from'], 'to' => $x['to'], 'computed_at' => $now];
                RestaurantForecastError::updateOrCreate(['location_id' => $l->id, 'metric' => $m, 'model' => $model], ['company_id' => $this->companyId] + $row);
                $saved[$m][$model] = $row;
            }
        }

        return $saved;
    }

    /** As reservas mais recentes de uma loja para um dia (a última fotografia). */
    public static function latestSnapshot(int $locationId, string $date): ?CmReservationSnapshot
    {
        return CmReservationSnapshot::where('location_id', $locationId)->whereDate('business_date', $date)->orderByDesc('read_at')->first();
    }

    /** O modelo de amanhã: as reservas só se o erro medido for menor do que o da média nos mesmos dias. */
    public static function tomorrowModel(array $errors, string $metric): array
    {
        $res = $errors[$metric]['reservas_d1'] ?? null;
        $base = $errors[$metric]['media_d1_res'] ?? null;
        if (! $res || ! $base || $res['n'] < self::MIN_SAMPLES) {
            return ['model' => 'media', 'reason' => 'Média simples: ainda não há dias suficientes com reservas para medir se ajudam.'];
        }
        if ($res['mape'] >= $base['mape']) {
            return ['model' => 'media', 'reason' => sprintf('Média simples: com as reservas, o erro medido nas últimas 8 semanas não baixou (%s contra %s).',
                self::pct1($res['mape']), self::pct1($base['mape']))];
        }

        return ['model' => 'reservas', 'reason' => sprintf('Ajustada pelas reservas: o erro medido nas últimas 8 semanas baixou de %s para %s.', self::pct1($base['mape']), self::pct1($res['mape']))];
    }

    /** Calcula e guarda a previsão de hoje e dos próximos 30 dias de uma loja (hoje: a média, com o intervalo da véspera). */
    public function forecast(PingwinLocation $l, array $errors): int
    {
        $s = $this->series($l->id);
        $before = $this->today->toDateString();
        $tomorrow = $this->today->addDay()->toDateString();
        $now = now();
        $rows = 0;
        for ($i = 0; $i <= self::HORIZON; $i++) {
            $day = $this->today->addDays($i)->toDateString();
            foreach (self::METRICS as $m) {
                $base = $this->baseline($s[$m], $day, $before);
                $model = 'media';
                $notes = [];
                $value = $base;
                $err = $i <= 1 ? ($errors[$m]['media_d1'] ?? null) : ($errors[$m]['media_d7'] ?? null);
                if ($day === $tomorrow && $base !== null) {
                    $choice = self::tomorrowModel($errors, $m);
                    if ($choice['model'] === 'reservas') {
                        $snap = self::latestSnapshot($l->id, $day);
                        $med = $this->reservedMedian($s['reserved'], $day);
                        $ratio = $snap && $med !== null ? ForecastMath::reservationRatio((float) $snap->guests_total, $med) : null;
                        if ($ratio !== null) {
                            $value = $base * $ratio;
                            $model = 'reservas';
                            $err = $errors[$m]['reservas_d1'];
                            $notes[] = $choice['reason'];
                        } else {
                            $notes[] = 'Média simples: sem reservas lidas para amanhã.';
                        }
                    } else {
                        $notes[] = $choice['reason'];
                    }
                }
                if (isset($this->holidays[$day])) {
                    array_unshift($notes, 'Dia atípico: a média pode não servir (' . $this->holidays[$day] . ').');
                }
                [$low, $high] = $value !== null && $err && $err['p10'] !== null ? ForecastMath::interval($value, (float) $err['p10'], (float) $err['p90']) : [null, null];
                RestaurantForecast::updateOrCreate(['location_id' => $l->id, 'business_date' => $day, 'metric' => $m], [
                    'company_id' => $this->companyId, 'model' => $model, 'value' => $value !== null ? round($value, 2) : null,
                    'low' => $low !== null ? round($low, 2) : null, 'high' => $high !== null ? round($high, 2) : null,
                    'note' => $notes ? mb_substr(implode(' ', $notes), 0, 255) : null, 'computed_at' => $now,
                ]);
                $rows++;
            }
        }

        return $rows;
    }

    /** Todas as lojas ativas com vendas: erro medido e previsão. */
    public function run(): array
    {
        $out = [];
        foreach (PingwinLocation::where('company_id', $this->companyId)->where('is_active', true)->orderBy('id')->get() as $l) {
            if (! DB::table('pingwin_daily_sales')->where('location_id', $l->id)->exists()) {
                continue;
            }
            $errors = $this->saveErrors($l);
            $out[$l->id] = ['errors' => $errors, 'rows' => $this->forecast($l, $errors)];
        }

        return $out;
    }

    /**
     * As anomalias de dias passados: o real fora do intervalo da previsão de véspera (média simples).
     *
     * @return array<string, list<array{metric: string, pct: float, text: string}>> por dia
     */
    public function anomalies(int $locationId, string $from, string $to, array $errors): array
    {
        $s = $this->series($locationId);
        $out = [];
        foreach (['invoiced', 'guests'] as $m) {
            $err = $errors[$m]['media_d1'] ?? null;
            if (! $err || $err['p10'] === null || $err['n'] < self::MIN_SAMPLES) {
                continue;
            }
            foreach ($s[$m] as $day => $actual) {
                if ($day < $from || $day > $to || isset($this->holidays[$day])) {
                    continue;
                }
                $f = $this->baseline($s[$m], $day, CarbonImmutable::parse($day)->subDay()->toDateString());
                if ($f === null || $f <= 0) {
                    continue;
                }
                [$lo, $hi] = ForecastMath::interval($f, (float) $err['p10'], (float) $err['p90']);
                if ($actual >= $lo && $actual <= $hi) {
                    continue;
                }
                $pct = ($actual / $f - 1) * 100;
                $out[$day][] = ['metric' => $m, 'pct' => round($pct, 1), 'text' => self::anomalyText($m, $pct)];
            }
        }

        return $out;
    }

    /**
     * O bloco do Calendário de um mês (uma loja ou todas somadas): a previsão dos dias de hoje em
     * diante, as reservas lidas, os feriados, as anomalias dos dias passados e o erro medido por loja.
     */
    public function calendarBlock(string $month, ?int $locationId): array
    {
        $from = CarbonImmutable::parse("{$month}-01");
        $to = $from->endOfMonth();
        [$f, $t] = [$from->toDateString(), $to->toDateString()];
        $today = $this->today->toDateString();
        $locations = PingwinLocation::where('company_id', $this->companyId)->where('is_active', true)
            ->when($locationId, fn ($q) => $q->whereKey($locationId))->orderBy('id')->get();
        $names = $locations->mapWithKeys(fn ($l) => [$l->id => $l->display_name ?: ($l->winrest_name ?? (string) $l->id)])->all();
        $ids = array_keys($names);
        $many = count($ids) > 1;

        $errors = [];
        foreach (RestaurantForecastError::whereIn('location_id', $ids)->get() as $e) {
            $errors[$e->location_id][$e->metric][$e->model] = ['mape' => $e->mape, 'p10' => $e->p10, 'p90' => $e->p90, 'n' => (int) $e->n,
                'from' => $e->from ? substr((string) $e->from, 0, 10) : null, 'to' => $e->to ? substr((string) $e->to, 0, 10) : null];
        }

        $days = [];
        foreach (RestaurantForecast::whereIn('location_id', $ids)->whereBetween('business_date', [max($f, $today), $t])->get() as $r) {
            $d = substr((string) $r->business_date, 0, 10);
            $x = &$days[$d][$r->metric];
            $x ??= ['value' => 0.0, 'low' => 0.0, 'high' => 0.0, 'complete' => true, 'model' => 'media'];
            if ($r->value === null) {
                $x['complete'] = false;
            } else {
                $x['value'] += $r->value;
                $x['low'] += $r->low ?? $r->value;
                $x['high'] += $r->high ?? $r->value;
            }
            if ($r->model === 'reservas') {
                $x['model'] = 'reservas';
            }
            // As notas do modelo (o feriado vai no campo próprio).
            $note = trim((string) preg_replace('/^Dia atípico: [^)]*\)\.\s*/u', '', (string) $r->note));
            if ($note !== '') {
                $days[$d]['notes'][] = ($many ? $names[$r->location_id] . ', ' : '') . ($r->metric === 'invoiced' ? 'faturação' : 'pessoas') . ': ' . $note;
            }
            unset($x);
        }
        $out = [];
        ksort($days);
        foreach ($days as $d => $x) {
            $row = ['date' => $d, 'holiday' => $this->holidays[$d] ?? null, 'notes' => array_values(array_unique($x['notes'] ?? []))];
            foreach (self::METRICS as $m) {
                $v = $x[$m] ?? null;
                $row[$m] = $v && $v['complete'] ? ['value' => round($v['value'], 2), 'low' => round($v['low'], 2), 'high' => round($v['high'], 2), 'model' => $v['model']] : null;
            }
            $out[] = $row;
        }

        // As reservas lidas (a fotografia mais recente de cada loja e dia), de hoje em diante.
        $reservations = [];
        if ($t >= $today) {
            $latest = CmReservationSnapshot::whereIn('location_id', $ids)->whereBetween('business_date', [max($f, $today), $t])
                ->selectRaw('location_id, DATE(business_date) as d, MAX(read_at) as r')->groupByRaw('location_id, DATE(business_date)')->get();
            foreach ($latest as $l) {
                $snap = CmReservationSnapshot::where('location_id', $l->location_id)->whereDate('business_date', $l->d)->where('read_at', $l->r)->first();
                if (! $snap) {
                    continue;
                }
                $d = substr((string) $l->d, 0, 10);
                $reservations[$d]['tables'] = ($reservations[$d]['tables'] ?? 0) + (int) $snap->tables_count;
                $reservations[$d]['guests'] = ($reservations[$d]['guests'] ?? 0) + (int) $snap->guests_total;
                $read = $snap->read_at->copy()->setTimezone('Europe/Lisbon')->format('Y-m-d H:i');
                $reservations[$d]['read_at'] = min($reservations[$d]['read_at'] ?? $read, $read);
            }
            ksort($reservations);
        }

        // As anomalias dos dias passados (por loja; com várias lojas, o nome à frente).
        $anomalies = [];
        foreach ($ids as $id) {
            foreach ($this->anomalies($id, $f, min($t, $this->today->subDay()->toDateString()), $errors[$id] ?? []) as $d => $list) {
                foreach ($list as $a) {
                    $anomalies[$d][] = $a + ['text_full' => ($many ? $names[$id] . ': ' : '') . $a['text']];
                }
            }
        }
        ksort($anomalies);

        $measured = [];
        foreach ($ids as $id) {
            $e = $errors[$id] ?? [];
            if (! $e) {
                continue;
            }
            $row = ['location_id' => $id, 'name' => $names[$id]];
            foreach (self::METRICS as $m) {
                $choice = self::tomorrowModel($e, $m);
                $row[$m] = [
                    'days_2_30' => $e[$m]['media_d7']['mape'] ?? null, 'tomorrow_simple' => $e[$m]['media_d1']['mape'] ?? null,
                    'tomorrow_reservations' => $e[$m]['reservas_d1']['mape'] ?? null, 'tomorrow_model' => $choice['model'], 'tomorrow_reason' => $choice['reason'],
                    'p10' => $e[$m]['media_d7']['p10'] ?? null, 'p90' => $e[$m]['media_d7']['p90'] ?? null, 'n' => $e[$m]['media_d7']['n'] ?? 0,
                    'from' => $e[$m]['media_d7']['from'] ?? null, 'to' => $e[$m]['media_d7']['to'] ?? null,
                ];
            }
            $measured[] = $row;
        }

        return [
            'today' => $today,
            'days' => $out,
            'reservations' => (object) $reservations,
            'holidays' => (object) array_filter($this->holidays, fn ($d) => $d >= $f && $d <= $t, ARRAY_FILTER_USE_KEY),
            'anomalies' => (object) $anomalies,
            'errors' => $measured,
        ];
    }

    public static function anomalyText(string $metric, float $pct): string
    {
        $n = self::pct(abs($pct));

        return $metric === 'invoiced'
            ? sprintf('Faturou %s %s do previsto.', $n, $pct < 0 ? 'abaixo' : 'acima')
            : sprintf('Teve %s %s pessoas do que o previsto.', $n, $pct < 0 ? 'menos' : 'mais');
    }

    private static function pct(float $v): string
    {
        return number_format($v, 0, ',', ' ') . ' %';
    }

    private static function pct1(float $v): string
    {
        return number_format($v, 1, ',', ' ') . ' %';
    }
}
