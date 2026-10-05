<?php

declare(strict_types=1);

namespace App\Services\Blog;

use App\Models\CarSale;
use App\Models\CompanyIntegration;
use App\Services\GoogleAnalyticsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Público MEDIDO da empresa para o prompt da IA, só acima dos mínimos:
 *  · GA4 (90 dias): idade e género só quando o Google não os esconde (thresholding).
 *  · Meta (90 dias): pelo menos 1000 impressões em meta_audience_insights.
 *  · Vendas registadas (24 meses): pelo menos 30 com idade e género do comprador.
 * Abaixo disso a fonte não entra e o prompt diz que não há dados suficientes. Nunca
 * se inventa: devolve percentagens calculadas e o motivo de cada fonte ficar de fora.
 */
class AudienceSummaryService
{
    public const GA4_DAYS = 90;
    public const META_DAYS = 90;
    public const META_MIN_IMPRESSIONS = 1000;
    public const SALES_MONTHS = 24;
    public const SALES_MIN = 30;

    public const NO_DATA_WARNING = 'Sem dados de público suficientes; não presumir idade nem género.';

    private const GENDER_PT = ['male' => 'masculino', 'female' => 'feminino', 'unknown' => 'desconhecido', 'company' => 'empresa'];

    public function __construct(private readonly GoogleAnalyticsService $ga4) {}

    public function forCompany(int $companyId): array
    {
        $sources = [
            'ga4'   => $this->ga4Source($companyId),
            'meta'  => $this->metaSource($companyId),
            'sales' => $this->salesSource($companyId),
        ];
        $hasData = (bool) array_filter($sources, fn ($s) => $s['usable']);

        return [
            'sources'  => $sources,
            'has_data' => $hasData,
            'warning'  => $hasData ? null : self::NO_DATA_WARNING,
        ];
    }

    /** Linhas de texto para o prompt (só as fontes utilizáveis). */
    public static function promptLines(array $summary): array
    {
        if (! ($summary['has_data'] ?? false)) {
            return [self::NO_DATA_WARNING];
        }

        $labels = [
            'ga4'   => 'Visitantes do site (GA4, últimos ' . self::GA4_DAYS . ' dias)',
            'meta'  => 'Pessoas alcançadas nos anúncios Meta (últimos ' . self::META_DAYS . ' dias)',
            'sales' => 'Compradores registados (últimos ' . self::SALES_MONTHS . ' meses)',
        ];
        $lines = [];
        foreach ($summary['sources'] as $key => $s) {
            if (! $s['usable']) {
                continue;
            }
            $age = implode(', ', array_map(fn ($r) => "{$r['label']} {$r['pct']}%", $s['age']));
            $gender = implode(', ', array_map(fn ($r) => "{$r['label']} {$r['pct']}%", $s['gender']));
            $lines[] = "{$labels[$key]}: idade {$age}" . ($gender !== '' ? "; género {$gender}" : '') . '.';
        }

        return $lines;
    }

    // ── fontes ───────────────────────────────────────────────────────────────

    private function ga4Source(int $companyId): array
    {
        $integration = CompanyIntegration::where('company_id', $companyId)->where('platform', 'google')->first();
        if (! $integration || $integration->status === 'revoked' || empty($integration->property_id)) {
            return $this->unusable('not_connected');
        }

        try {
            $traffic = $this->ga4->getTraffic($companyId, (int) $integration->property_id, self::GA4_DAYS);
        } catch (\Throwable $e) {
            Log::info('[Blog IA] GA4 indisponível para o público', ['company_id' => $companyId, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return $this->unusable('error');
        }

        $demo = $traffic['demographics'] ?? [];
        if (! ($demo['available'] ?? false)) {
            return $this->unusable((string) ($demo['reason'] ?? 'no_data'));
        }
        // Escondido por volume baixo (mesmo que parcial): não entra.
        if (($demo['reason'] ?? 'ok') !== 'ok') {
            return $this->unusable('thresholded');
        }

        return [
            'usable' => true,
            'reason' => 'ok',
            'age'    => $this->shares(array_column($demo['age'] ?? [], 'users', 'bracket')),
            'gender' => $this->shares(array_column($demo['gender'] ?? [], 'users', 'gender'), true),
        ];
    }

    private function metaSource(int $companyId): array
    {
        $since = now()->subDays(self::META_DAYS)->toDateString();
        $rows = DB::table('meta_audience_insights')
            ->where('company_id', $companyId)
            ->where('period_end', '>=', $since)
            ->selectRaw('age_range, gender, SUM(impressions) as impressions')
            ->groupBy('age_range', 'gender')
            ->get();

        $total = (int) $rows->sum('impressions');
        if ($total < self::META_MIN_IMPRESSIONS) {
            return $this->unusable($total === 0 ? 'no_data' : 'below_minimum') + ['volume' => $total, 'minimum' => self::META_MIN_IMPRESSIONS];
        }

        $age = [];
        $gender = [];
        foreach ($rows as $r) {
            $age[$r->age_range] = ($age[$r->age_range] ?? 0) + (int) $r->impressions;
            $gender[$r->gender] = ($gender[$r->gender] ?? 0) + (int) $r->impressions;
        }

        return [
            'usable' => true, 'reason' => 'ok', 'volume' => $total, 'minimum' => self::META_MIN_IMPRESSIONS,
            'age' => $this->shares($age), 'gender' => $this->shares($gender, true),
        ];
    }

    private function salesSource(int $companyId): array
    {
        $rows = CarSale::query()
            ->where('company_id', $companyId)
            ->where('sold_at', '>=', now()->subMonths(self::SALES_MONTHS))
            ->whereNotNull('buyer_age_range')
            ->whereNotNull('buyer_gender')
            ->get(['buyer_age_range', 'buyer_gender']);

        $count = $rows->count();
        if ($count < self::SALES_MIN) {
            return $this->unusable($count === 0 ? 'no_data' : 'below_minimum') + ['volume' => $count, 'minimum' => self::SALES_MIN];
        }

        return [
            'usable' => true, 'reason' => 'ok', 'volume' => $count, 'minimum' => self::SALES_MIN,
            'age' => $this->shares($rows->countBy('buyer_age_range')->all()),
            'gender' => $this->shares($rows->countBy('buyer_gender')->all(), true),
        ];
    }

    private function unusable(string $reason): array
    {
        return ['usable' => false, 'reason' => $reason, 'age' => [], 'gender' => []];
    }

    /** [rótulo => contagem] → [{label, pct}] por ordem decrescente, sem "(not set)". */
    private function shares(array $counts, bool $gender = false): array
    {
        unset($counts['(not set)'], $counts['']);
        $total = array_sum($counts);
        if ($total <= 0) {
            return [];
        }
        arsort($counts);
        $out = [];
        foreach ($counts as $label => $n) {
            $out[] = [
                'label' => $gender ? (self::GENDER_PT[strtolower((string) $label)] ?? (string) $label) : (string) $label,
                'pct'   => (int) round($n * 100 / $total),
            ];
        }

        return $out;
    }
}
