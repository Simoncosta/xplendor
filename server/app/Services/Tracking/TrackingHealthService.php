<?php

declare(strict_types=1);

namespace App\Services\Tracking;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\TrackingDailyCount;
use App\Models\TrackingEventMap;
use App\Services\Ga4\Ga4ClientInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Auditoria (tracking dos sites, GA4 × XPLENDOR): as contagens diárias por tipo de
 * evento, dos últimos 28 dias, no GA4 (Data API: eventCount por eventName e por dia, com a conta de
 * serviço que já existe, como "Visualizador") e na XPLENDOR (car_views, car_leads, car_interactions).
 * A tabela de correspondência por site (tracking_event_maps) diz que evento do GA4 é que tipo da
 * XPLENDOR, com filtros opcionais sobre o page_context; sem linhas, os nomes são iguais.
 * Os dias são de Lisboa. Quando a propriedade GA4 é partilhada por mais do que uma empresa, conta só
 * o domínio do site da empresa (dimensão hostName).
 */
final class TrackingHealthService
{
    public const DAYS = 28;
    /** Os tipos que a XPLENDOR guarda (o page_view não se guarda). */
    public const TYPES = ['whatsapp_click', 'whatsapp_send', 'call_click', 'show_phone', 'copy_phone', 'favorite', 'share',
        'form_open', 'form_start', 'location_view', 'car_view', 'car_lead'];
    private const TZ = 'Europe/Lisbon';

    public function __construct(private readonly Ga4ClientInterface $ga4) {}

    /**
     * A correspondência da empresa: por omissão, os nomes iguais (o evento do GA4 com o nome do tipo);
     * um tipo com linhas próprias (tracking:map) usa só essas, e os outros tipos continuam iguais.
     *
     * @return list<array{ga4_event:string, xplendor_type:string, filters:array}>
     */
    public function maps(int $companyId): array
    {
        $own = TrackingEventMap::where('company_id', $companyId)->where('is_active', true)->orderBy('id')->get()->groupBy('xplendor_type');
        $out = [];
        foreach (self::TYPES as $type) {
            if (! $own->has($type)) {
                $out[] = ['ga4_event' => $type, 'xplendor_type' => $type, 'filters' => []];
            }
        }
        foreach ($own as $rows) {
            foreach ($rows as $m) {
                $out[] = ['ga4_event' => $m->ga4_event, 'xplendor_type' => $m->xplendor_type, 'filters' => (array) ($m->filters ?? [])];
            }
        }

        return $out;
    }

    /** A propriedade GA4 ativa da empresa, ou null. */
    public static function propertyId(int $companyId): ?int
    {
        $i = CompanyIntegration::where('company_id', $companyId)->where('platform', 'google')->where('status', 'active')->first();

        return $i && $i->property_id ? (int) $i->property_id : null;
    }

    /**
     * Atualiza as contagens dos últimos 28 dias (até ontem, em Lisboa).
     *
     * @return array{from:string, until:string, ga4:bool, ga4_error:?string}
     */
    public function sync(int $companyId, ?CarbonImmutable $until = null): array
    {
        $until ??= CarbonImmutable::now(self::TZ)->subDay()->startOfDay();
        $from = $until->subDays(self::DAYS - 1);
        $maps = $this->maps($companyId);
        $counts = ['xplendor' => $this->xplendorCounts($companyId, $maps, $from, $until), 'ga4' => []];
        $ga4Error = null;
        $property = self::propertyId($companyId);
        if ($property) {
            try {
                $counts['ga4'] = $this->ga4Counts($companyId, $property, $maps, $from, $until);
            } catch (\Throwable $e) {
                $ga4Error = mb_substr($e->getMessage(), 0, 200);
                Log::warning('[Auditoria] GA4 falhou', ['company_id' => $companyId, 'error' => $ga4Error]);
            }
        }

        DB::transaction(function () use ($companyId, $from, $until, $counts, $property, $ga4Error) {
            $sources = $property && $ga4Error === null ? ['xplendor', 'ga4'] : ['xplendor'];
            TrackingDailyCount::where('company_id', $companyId)->whereIn('source', $sources)
                ->whereBetween('day', [$from->toDateString(), $until->toDateString()])->delete();
            TrackingDailyCount::where('company_id', $companyId)->where('day', '<', $from->subDays(7)->toDateString())->delete();
            $now = now();
            $rows = [];
            foreach ($sources as $source) {
                foreach ($counts[$source] as $type => $byDay) {
                    foreach ($byDay as $day => $n) {
                        $rows[] = ['company_id' => $companyId, 'day' => $day, 'source' => $source, 'event_type' => $type, 'count' => $n, 'created_at' => $now, 'updated_at' => $now];
                    }
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                TrackingDailyCount::insert($chunk);
            }
        });

        return ['from' => $from->toDateString(), 'until' => $until->toDateString(), 'ga4' => $property !== null && $ga4Error === null, 'ga4_error' => $ga4Error];
    }

    /** @return array<string, array<string, int>> tipo → dia → contagem (todos os dias, com zeros) */
    public function xplendorCounts(int $companyId, array $maps, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $start = $from->startOfDay()->utc();
        $end = $until->endOfDay()->utc();
        $out = [];
        foreach ($maps as $m) {
            $type = $m['xplendor_type'];
            $out[$type] ??= $this->zeros($from, $until);
            $q = match ($type) {
                'car_view' => DB::table('car_views')->select('created_at'),
                'car_lead' => DB::table('car_leads')->select('created_at'),
                default => DB::table('car_interactions')->select('created_at', 'page_context')->where('interaction_type', $type),
            };
            $q->where('company_id', $companyId)->whereBetween('created_at', [$start->toDateTimeString(), $end->toDateTimeString()]);
            $include = (array) ($m['filters']['page_context'] ?? []);
            $exclude = (array) ($m['filters']['exclude_page_context'] ?? []);
            if (! in_array($type, ['car_view', 'car_lead'], true)) {
                if ($include) {
                    $q->whereIn('page_context', $include);
                }
                if ($exclude) {
                    $q->where(fn ($w) => $w->whereNull('page_context')->orWhereNotIn('page_context', $exclude));
                }
            }
            // Um mesmo tipo pode vir de vários eventos do GA4: na XPLENDOR conta-se uma vez.
            if (isset($out["__seen_{$type}"])) {
                continue;
            }
            $out["__seen_{$type}"] = true;
            foreach ($q->orderBy('created_at')->cursor() as $r) {
                $day = CarbonImmutable::parse((string) $r->created_at, 'UTC')->setTimezone(self::TZ)->toDateString();
                if (isset($out[$type][$day])) {
                    $out[$type][$day]++;
                }
            }
        }

        return array_filter($out, fn ($k) => ! str_starts_with((string) $k, '__seen_'), ARRAY_FILTER_USE_KEY);
    }

    /** @return array<string, array<string, int>> tipo → dia → contagem */
    public function ga4Counts(int $companyId, int $propertyId, array $maps, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $res = $this->ga4->runReport($propertyId, [
            'start' => $from->toDateString(), 'end' => $until->toDateString(),
            'dimensions' => ['date', 'eventName', 'hostName'], 'metrics' => ['eventCount'], 'limit' => 100000,
        ]);
        $host = $this->siteHost($companyId, $propertyId);
        $byEvent = [];
        foreach ($maps as $m) {
            $byEvent[$m['ga4_event']][] = $m['xplendor_type'];
        }
        $out = [];
        foreach ($maps as $m) {
            $out[$m['xplendor_type']] ??= $this->zeros($from, $until);
        }
        foreach ($res['rows'] ?? [] as $row) {
            [$date, $event, $rowHost] = array_pad($row['dimensions'] ?? [], 3, '');
            if (! isset($byEvent[$event]) || ($host !== null && ! self::sameHost((string) $rowHost, $host))) {
                continue;
            }
            $day = strlen($date) === 8 ? substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2) : $date;
            foreach ($byEvent[$event] as $type) {
                if (isset($out[$type][$day])) {
                    $out[$type][$day] += (int) ($row['metrics'][0] ?? 0);
                }
            }
        }

        return $out;
    }

    /** O domínio do site da empresa, quando a propriedade é partilhada (senão null: conta tudo). */
    private function siteHost(int $companyId, int $propertyId): ?string
    {
        $shared = CompanyIntegration::where('platform', 'google')->where('status', 'active')->where('property_id', (string) $propertyId)->count() > 1;
        if (! $shared) {
            return null;
        }
        $site = (string) Company::whereKey($companyId)->value('website');
        $host = parse_url(str_contains($site, '://') ? $site : "https://{$site}", PHP_URL_HOST);

        return $host ? preg_replace('/^www\./', '', mb_strtolower($host)) : '__sem_site__';
    }

    private static function sameHost(string $rowHost, string $host): bool
    {
        $rowHost = preg_replace('/^www\./', '', mb_strtolower($rowHost));

        return $rowHost === $host || str_ends_with($rowHost, ".{$host}");
    }

    /** @return array<string, int> */
    private function zeros(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $days = [];
        for ($d = $from; $d->lte($until); $d = $d->addDay()) {
            $days[$d->toDateString()] = 0;
        }

        return $days;
    }
}
