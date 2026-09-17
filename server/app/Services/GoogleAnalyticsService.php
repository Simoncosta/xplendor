<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ga4\Ga4ClientInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * XPLENDOR — Tráfego do site do cliente via GA4 Data API. Monta os relatórios
 * (visão geral, páginas, origens, dispositivos, geografia, tendência, demografia),
 * normaliza a resposta para o painel e CACHEIA por empresa+intervalo (protege a
 * quota da Data API e mantém o painel rápido).
 *
 * ⚠️ Demografia é BEST-EFFORT: o GA4 esconde linhas com pouco volume (thresholds
 * de privacidade) e exige Google Signals ativo. Quando vem vazia ou marcada com
 * threshold, devolvemos available=false + um motivo — o painel mostra "sem dados
 * suficientes" em vez de um gráfico vazio.
 */
class GoogleAnalyticsService
{
    public function __construct(private readonly Ga4ClientInterface $ga4) {}

    /**
     * Tráfego completo, cacheado. `$days` = janela (7/28/90...). `$fresh=true`
     * ignora a cache e vai buscar dados frescos (para testar depois de a Service
     * Account ganhar acesso, sem esperar as 12h de cache).
     *
     * ⚠️ Só SUCESSOS entram na cache. Se `buildTraffic` rebentar (permissão, config,
     * quota…), a exceção sobe e NADA é cacheado — um erro temporário nunca fica
     * preso 12h; a próxima leitura tenta de novo.
     */
    public function getTraffic(int $companyId, int $propertyId, int $days = 28, bool $fresh = false): array
    {
        $days = max(1, min($days, 365));
        $minutes = (int) config('services.ga4.cache_minutes', 720);
        $key = "ga4:traffic:{$companyId}:{$propertyId}:{$days}";

        if ($fresh) {
            Cache::forget($key);
        }

        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }

        // Pode lançar — de propósito: o erro real sobe ao controller (que o expõe
        // no log e, em debug, ao Simon). Só chegamos ao put() em caso de sucesso.
        $data = $this->buildTraffic($propertyId, $days);

        Cache::put($key, $data, now()->addMinutes($minutes));

        return $data;
    }

    /** Limpa a cache de uma empresa/propriedade (ex.: forçar refresh). */
    public function forget(int $companyId, int $propertyId, int $days = 28): void
    {
        Cache::forget("ga4:traffic:{$companyId}:{$propertyId}:{$days}");
    }

    private function buildTraffic(int $propertyId, int $days): array
    {
        $end = CarbonImmutable::today();
        $start = $end->subDays($days - 1);
        $range = ['start' => $start->toDateString(), 'end' => $end->toDateString()];

        return [
            'range' => $range + ['days' => $days],
            'overview' => $this->overview($propertyId, $range),
            'top_pages' => $this->topPages($propertyId, $range),
            'traffic_sources' => $this->trafficSources($propertyId, $range),
            'devices' => $this->devices($propertyId, $range),
            'geo' => $this->geo($propertyId, $range),
            'trend' => $this->trend($propertyId, $range),
            'demographics' => $this->demographics($propertyId, $range),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function overview(int $propertyId, array $range): array
    {
        $metrics = ['activeUsers', 'newUsers', 'sessions', 'screenPageViews', 'averageSessionDuration', 'engagementRate', 'bounceRate'];
        $res = $this->ga4->runReport($propertyId, $range + ['metrics' => $metrics]);
        $row = $res['rows'][0]['metrics'] ?? [];

        return [
            'active_users' => $this->int($row[0] ?? null),
            'new_users' => $this->int($row[1] ?? null),
            'sessions' => $this->int($row[2] ?? null),
            'page_views' => $this->int($row[3] ?? null),
            'avg_session_duration' => $this->float($row[4] ?? null), // segundos
            'engagement_rate' => $this->float($row[5] ?? null),      // 0..1
            'bounce_rate' => $this->float($row[6] ?? null),          // 0..1
        ];
    }

    private function topPages(int $propertyId, array $range): array
    {
        $res = $this->ga4->runReport($propertyId, $range + [
            'dimensions' => ['pagePath', 'pageTitle'],
            'metrics' => ['screenPageViews'],
            'orderByMetric' => 'screenPageViews',
            'limit' => 10,
        ]);

        return array_map(fn ($r) => [
            'path' => $r['dimensions'][0] ?? '',
            'title' => $r['dimensions'][1] ?? '',
            'views' => $this->int($r['metrics'][0] ?? null),
        ], $res['rows']);
    }

    private function trafficSources(int $propertyId, array $range): array
    {
        $res = $this->ga4->runReport($propertyId, $range + [
            'dimensions' => ['sessionDefaultChannelGroup'],
            'metrics' => ['sessions'],
            'orderByMetric' => 'sessions',
            'limit' => 10,
        ]);

        return array_map(fn ($r) => [
            'channel' => $r['dimensions'][0] ?: '(other)',
            'sessions' => $this->int($r['metrics'][0] ?? null),
        ], $res['rows']);
    }

    private function devices(int $propertyId, array $range): array
    {
        $res = $this->ga4->runReport($propertyId, $range + [
            'dimensions' => ['deviceCategory'],
            'metrics' => ['sessions'],
            'orderByMetric' => 'sessions',
        ]);

        return array_map(fn ($r) => [
            'device' => $r['dimensions'][0] ?? '',
            'sessions' => $this->int($r['metrics'][0] ?? null),
        ], $res['rows']);
    }

    private function geo(int $propertyId, array $range): array
    {
        $res = $this->ga4->runReport($propertyId, $range + [
            'dimensions' => ['country', 'city'],
            'metrics' => ['sessions'],
            'orderByMetric' => 'sessions',
            'limit' => 10,
        ]);

        return array_map(fn ($r) => [
            'country' => $r['dimensions'][0] ?? '',
            'city' => $r['dimensions'][1] ?: '(not set)',
            'sessions' => $this->int($r['metrics'][0] ?? null),
        ], $res['rows']);
    }

    private function trend(int $propertyId, array $range): array
    {
        $res = $this->ga4->runReport($propertyId, $range + [
            'dimensions' => ['date'],
            'metrics' => ['activeUsers', 'sessions'],
        ]);

        $rows = array_map(fn ($r) => [
            'date' => $this->isoDate($r['dimensions'][0] ?? ''),
            'active_users' => $this->int($r['metrics'][0] ?? null),
            'sessions' => $this->int($r['metrics'][1] ?? null),
        ], $res['rows']);

        // GA4 não garante a ordem por data — ordenar para o gráfico.
        usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $rows;
    }

    /**
     * Demografia BEST-EFFORT (idade + género). Se vazio ou com threshold aplicado
     * → available=false + motivo. Um erro (ex.: Signals off) também vira no_data,
     * para não rebentar o resto do painel.
     */
    private function demographics(int $propertyId, array $range): array
    {
        try {
            $ageRes = $this->ga4->runReport($propertyId, $range + [
                'dimensions' => ['userAgeBracket'],
                'metrics' => ['activeUsers'],
                'orderByMetric' => 'activeUsers',
            ]);
            $genderRes = $this->ga4->runReport($propertyId, $range + [
                'dimensions' => ['userGender'],
                'metrics' => ['activeUsers'],
                'orderByMetric' => 'activeUsers',
            ]);
        } catch (\Throwable $e) {
            return ['available' => false, 'reason' => 'no_data', 'age' => [], 'gender' => []];
        }

        $thresholded = ($ageRes['subjectToThresholding'] ?? false) || ($genderRes['subjectToThresholding'] ?? false);

        $age = array_values(array_filter(array_map(fn ($r) => [
            'bracket' => $r['dimensions'][0] ?? '',
            'users' => $this->int($r['metrics'][0] ?? null),
        ], $ageRes['rows']), fn ($r) => $r['bracket'] !== '' && $r['bracket'] !== '(not set)'));

        $gender = array_values(array_filter(array_map(fn ($r) => [
            'gender' => $r['dimensions'][0] ?? '',
            'users' => $this->int($r['metrics'][0] ?? null),
        ], $genderRes['rows']), fn ($r) => $r['gender'] !== '' && $r['gender'] !== '(not set)'));

        if (empty($age) && empty($gender)) {
            return ['available' => false, 'reason' => $thresholded ? 'thresholded' : 'no_data', 'age' => [], 'gender' => []];
        }

        return [
            'available' => true,
            'reason' => $thresholded ? 'thresholded' : 'ok', // pode ter dados parciais
            'age' => $age,
            'gender' => $gender,
        ];
    }

    private function int(?string $v): int
    {
        return (int) round((float) ($v ?? 0));
    }

    private function float(?string $v): float
    {
        return round((float) ($v ?? 0), 4);
    }

    /** "20260916" → "2026-09-16". */
    private function isoDate(string $yyyymmdd): string
    {
        if (strlen($yyyymmdd) !== 8) {
            return $yyyymmdd;
        }

        return substr($yyyymmdd, 0, 4) . '-' . substr($yyyymmdd, 4, 2) . '-' . substr($yyyymmdd, 6, 2);
    }
}
