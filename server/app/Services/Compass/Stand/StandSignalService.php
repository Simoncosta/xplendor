<?php

declare(strict_types=1);

namespace App\Services\Compass\Stand;

use App\Jobs\GenerateStandCompassTextsJob;
use App\Models\Company;
use App\Models\CompanyRecommendationSetting;
use App\Models\CompassSignal;
use App\Recommendations\RecommendationContext;
use App\Recommendations\Rules\Automotive\HighViewsNoContactsRule;
use App\Recommendations\Rules\Automotive\SoldCarAdStillActiveRule;
use App\Recommendations\Rules\Automotive\SpendWithoutLeadRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bússola do stand: os sinais, calculados e guardados em compass_signals (fornecedor "stand").
 * Usa as regras que já existem, tal como estão, para nunca dizer uma coisa diferente das
 * Recomendações do dashboard: as regras da 2D (vendido com anúncio ativo, gasto sem contactos,
 * muitas vistas e zero contactos, com as definições de cada empresa) e a comparação exata.
 *  · stop_ad:{id}       "Parar o anúncio" (as regras da 2D: vendido com anúncio ativo, e gasto
 *                       dos anúncios ativos sem nenhum contacto em 14 dias);
 *  · review_price:{id}  à venda, no limiar de dias ou acima, e acima do mercado (comparação
 *                       exata, confiança média ou alta, mais de 5%, a regra PriceAboveMarketRule);
 *  · stale:{id}         à venda, no limiar de dias ou acima (para "Renovar o stock parado");
 *  · interest:{id}      muitas vistas e zero contactos (HighViewsNoContactsRule);
 *  · stage:{id}         preço alinhado ou abaixo (exata), sem anúncio ativo nem gasto nem
 *                       publicação da Bússola nos últimos 30 dias.
 * Só ficam os sinais de confiança alta e média. Recalcula-se de noite, na leitura (mais de 24
 * horas) e quando o stock, os preços ou os anúncios mudam (RecomputeStandCompassJob).
 */
final class StandSignalService
{
    public const PROVIDER = 'stand';
    public const STALE_AFTER_HOURS = 24;
    public const PRICE_MIN_DIFF = 5.0; // a mesma diferença mínima da regra PriceAboveMarketRule

    public function __construct(private readonly StandFacts $facts) {}

    public static function isEnabled(int $companyId): bool
    {
        return (bool) Company::where('id', $companyId)->value('bussola_stand_enabled');
    }

    public static function computedAt(int $companyId): ?CarbonImmutable
    {
        $at = DB::table('compass_runs')->where('provider', self::PROVIDER)->where('company_id', $companyId)->value('computed_at');

        return $at ? CarbonImmutable::parse($at) : null;
    }

    /** Recalcula se os sinais tiverem mais de 24 horas (ou nunca tiverem sido calculados). */
    public function ensureFresh(int $companyId): void
    {
        $at = self::computedAt($companyId);
        if (self::isEnabled($companyId) && (! $at || $at->lt(now()->subHours(self::STALE_AFTER_HOURS)))) {
            $this->compute($companyId);
        }
    }

    /** @return int o número de sinais guardados */
    public function compute(int $companyId, ?CarbonImmutable $now = null): int
    {
        if (! self::isEnabled($companyId)) {
            return 0;
        }
        $now ??= CarbonImmutable::now();
        $today = $now->setTimezone('Europe/Lisbon')->startOfDay();
        // As regras usam um retrato do stock guardado durante o pedido: cada recálculo vê o de agora.
        app()->forgetInstance(\App\Services\Automotive\AutomotiveStockSnapshot::class);
        $company = Company::findOrFail($companyId);
        $facts = $this->facts->forCompany($companyId, $today);
        $rows = [];
        $add = function (string $key, string $type, int $carId, ?string $vehicleType, string $confidence, array $numbers) use (&$rows) {
            $rows[$key] = ['signal_key' => $key, 'type' => $type, 'subject_type' => 'car', 'subject_id' => $carId,
                'vehicle_type' => $vehicleType, 'confidence' => $confidence, 'numbers' => $numbers];
        };

        // 1. Parar o anúncio: as regras da 2D (inclui os vendidos, que já não estão no stock).
        foreach ([SoldCarAdStillActiveRule::class => 'sold_active', SpendWithoutLeadRule::class => 'spend_no_contacts'] as $rule => $reason) {
            foreach ($this->rule($rule, $company, $now) as $e) {
                $carId = (int) $e['car_id'];
                $key = "stop_ad:{$carId}";
                if (isset($rows[$key])) {
                    continue; // vendido com anúncio ganha a gasto sem contactos
                }
                $car = \App\Models\Car::with(['brand', 'model'])->find($carId);
                if (! $car) {
                    continue;
                }
                $add($key, 'stop_ad', $carId, $car->vehicle_type, 'alta', [
                    'reason' => $reason, 'label' => StandFacts::label($car),
                    'spend' => round((float) ($reason === 'sold_active' ? ($e['post_sale_spend'] ?? 0) : ($e['tag_spend'] ?? 0)), 2),
                    'active_ads' => count((array) ($e['active_ads'] ?? [])),
                    'sold_at' => $e['sold_at'] ?? null,
                    'window_days' => (int) ($e['window_days'] ?? $e['lookback_days'] ?? 0),
                ]);
            }
        }

        // 2, 3 e 5. Preço, stock parado e palco (os veículos à venda).
        foreach ($facts as $f) {
            if (! $f['for_sale']) {
                continue;
            }
            $base = ['label' => $f['label'], 'days' => $f['days'], 'threshold' => $f['threshold']];
            $priceNums = ['diff_pct' => $f['diff_pct'], 'median' => $f['median'], 'comparables' => $f['comparables'], 'price' => $f['price'],
                'criteria_widened' => $f['criteria_widened']];
            $conf = $f['price_confidence'] === 'high' ? 'alta' : 'media';
            if ($f['stale'] && $f['position'] === 'above_market' && (float) $f['diff_pct'] > self::PRICE_MIN_DIFF) {
                $add("review_price:{$f['car_id']}", 'review_price', $f['car_id'], $f['vehicle_type'], $conf, $base + $priceNums);
            } elseif ($f['stale']) {
                $add("stale:{$f['car_id']}", 'stale', $f['car_id'], $f['vehicle_type'], 'alta', $base);
            }
            if (in_array($f['position'], ['below_market', 'aligned_market'], true) && $f['active_ads'] === 0 && $f['spend_30'] <= 0 && ! $f['compass_post_30']) {
                $add("stage:{$f['car_id']}", 'stage', $f['car_id'], $f['vehicle_type'], $conf, $base + $priceNums + ['position' => $f['position']]);
            }
        }

        // 4. Muitas vistas e zero contactos (a regra da 2D), só nos veículos à venda.
        foreach ($this->rule(HighViewsNoContactsRule::class, $company, $now) as $e) {
            $f = $facts[(int) $e['car_id']] ?? null;
            if (! $f || ! $f['for_sale']) {
                continue;
            }
            $views = (int) $e['views'];
            $add("interest:{$f['car_id']}", 'interest', $f['car_id'], $f['vehicle_type'], $views >= 2 * (int) $e['min_views'] ? 'alta' : 'media',
                ['label' => $f['label'], 'views' => $views, 'window_days' => (int) $e['window_days'], 'p75_views' => $e['p75_views'], 'days' => $f['days']]);
        }

        DB::transaction(function () use ($companyId, $rows, $now) {
            CompassSignal::where('provider', self::PROVIDER)->where('company_id', $companyId)->delete();
            foreach ($rows as $r) {
                CompassSignal::create($r + ['provider' => self::PROVIDER, 'company_id' => $companyId, 'computed_at' => $now]);
            }
            DB::table('compass_runs')->updateOrInsert(['provider' => self::PROVIDER, 'company_id' => $companyId], ['computed_at' => $now]);
        });
        GenerateStandCompassTextsJob::dispatch($companyId)->afterCommit();

        return count($rows);
    }

    /**
     * Uma regra das Recomendações, como o motor a corre (desligada ou com outros parâmetros
     * nas definições da empresa). @return list<array> a evidência de cada recomendação
     */
    private function rule(string $class, Company $company, CarbonImmutable $now): array
    {
        $rule = app($class);
        $setting = CompanyRecommendationSetting::where('company_id', $company->id)->where('rule_key', $rule->key())->first();
        if ($setting && ! $setting->enabled) {
            return [];
        }
        try {
            $result = $rule->evaluate(new RecommendationContext($company, 'automotive', $now),
                $rule->normalizeParams(array_merge($rule->defaultParams(), (array) ($setting->params ?? []))));
        } catch (\Throwable $e) {
            Log::warning('[Bússola do stand] regra falhou', ['rule' => $class, 'company_id' => $company->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return [];
        }

        return array_map(fn ($r) => $r->evidence, $result->recommendations);
    }
}
