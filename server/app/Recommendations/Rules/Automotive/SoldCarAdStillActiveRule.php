<?php

declare(strict_types=1);

namespace App\Recommendations\Rules\Automotive;

use App\Models\Car;
use App\Models\MetaAd;
use App\Recommendations\Contracts\RecommendationRule;
use App\Recommendations\Recommendation;
use App\Recommendations\RecommendationContext;
use App\Recommendations\RuleResult;
use App\Repositories\CarAdSpendRepository;
use App\Services\Automotive\AutomotiveStockSnapshot;
use Carbon\CarbonImmutable;

/**
 * "Anúncio de viatura vendida ainda ativo" (PRIORIDADE MÁXIMA).
 *
 * Viatura vendida e: (a) um anúncio com a tag dela está em effective_status ACTIVE
 * (meta_ads, lido do catálogo da Meta), ou (b) há gasto atribuído com data posterior
 * à venda nos últimos lookback_days (CarAdSpendRepository). A ação abre o anúncio no
 * Gestor de Anúncios: pausar é manual (a ligação só tem leitura, ads_read).
 */
class SoldCarAdStillActiveRule implements RecommendationRule
{
    public const KEY = 'automotive_sold_car_ad_active';
    public const PRIORITY = 100;

    public function __construct(private readonly CarAdSpendRepository $spend) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function verticals(): array
    {
        return ['automotive'];
    }

    public function defaultParams(): array
    {
        return ['lookback_days' => 30];
    }

    public function normalizeParams(array $params): array
    {
        return ['lookback_days' => max(7, min(180, (int) ($params['lookback_days'] ?? 30)))];
    }

    public function evaluate(RecommendationContext $context, array $params): RuleResult
    {
        $companyId = $context->company->id;
        $now = $context->now;
        $from = $now->subDays($params['lookback_days'] - 1)->toDateString();
        $to = $now->toDateString();

        // (a) Anúncios com tag ATIVOS, por viatura.
        $activeAds = [];
        MetaAd::where('company_id', $companyId)
            ->whereIn('tag_status', [MetaAd::TAG_MATCHED, MetaAd::TAG_SPLIT])
            ->where('effective_status', 'ACTIVE')
            ->orderBy('id')
            ->get()
            ->each(function (MetaAd $ad) use (&$activeAds) {
                foreach ((array) $ad->tag_car_ids as $carId) {
                    $activeAds[(int) $carId][] = $ad;
                }
            });

        // (b) Viaturas com gasto atribuído recente (fonte única).
        $recentSpendCars = $this->spend->dailyRows($companyId, $from, $to)
            ->whereNotNull('car_id')->distinct()->pluck('car_id')->map(fn ($id) => (int) $id)->all();

        $candidates = array_values(array_unique(array_merge(array_keys($activeAds), $recentSpendCars)));
        if ($candidates === []) {
            return RuleResult::none();
        }

        $cars = Car::where('company_id', $companyId)
            ->whereKey($candidates)
            ->where('status', 'sold')
            ->whereNotNull('sold_at')
            ->with(['brand:id,name', 'model:id,name'])
            ->orderBy('id')
            ->get();

        $recommendations = [];
        foreach ($cars as $car) {
            $soldDate = CarbonImmutable::parse($car->sold_at)->toDateString();
            $ads = $activeAds[$car->id] ?? [];

            $recentPostSale = round((float) $this->spend->dailyRows($companyId, $from, $to, [$car->id])
                ->where('date', '>', $soldDate)->sum('spend'), 2);

            if ($ads === [] && $recentPostSale <= 0) {
                continue;
            }

            $postSale = $this->spend->carSpendSummary($companyId, $car->id)['post_sale_spend'];

            // Anúncio a referir: o ativo, ou o último anúncio com tag que gastou depois da venda.
            $ad = $ads[0] ?? null;
            if ($ad === null) {
                $adId = $this->spend->dailyRows($companyId, null, null, [$car->id])
                    ->where('source', CarAdSpendRepository::SOURCE_TAG)
                    ->where('date', '>', $soldDate)
                    ->orderByDesc('date')
                    ->value('ad_id');
                $ad = $adId ? MetaAd::where('company_id', $companyId)->where('ad_id', $adId)->first() : null;
            }

            $title = AutomotiveStockSnapshot::carTitle($car);
            $soldLabel = CarbonImmutable::parse($soldDate)->format($now->year === CarbonImmutable::parse($soldDate)->year ? 'd/m' : 'd/m/Y');
            $adLabel = $ad?->ad_name ? '«' . $ad->ad_name . '»' : 'associado';
            $more = count($ads) > 1 ? sprintf(' (e mais %d)', count($ads) - 1) : '';
            $spendText = number_format($postSale, 0, ',', ' ') . ' €';

            $why = $ads !== []
                ? sprintf('A viatura %s (n.º %d) foi vendida a %s e o anúncio %s%s continua ativo: %s gastos desde a venda.', $title, $car->id, $soldLabel, $adLabel, $more, $spendText)
                : sprintf('A viatura %s (n.º %d) foi vendida a %s e o anúncio %s registou %s de gasto depois da venda.', $title, $car->id, $soldLabel, $adLabel, $spendText);

            $recommendations[] = new Recommendation(
                ruleKey: self::KEY,
                priority: self::PRIORITY,
                title: 'Anúncio de viatura vendida ainda ativo',
                why: $why,
                evidence: [
                    'car_id' => $car->id,
                    'car_title' => $title,
                    'issue_type' => 'sold_car_ad_active',
                    'sold_at' => $soldDate,
                    'active_ads' => array_map(fn (MetaAd $a) => ['ad_id' => (string) $a->ad_id, 'ad_name' => $a->ad_name], $ads),
                    'post_sale_spend' => $postSale,
                    'recent_post_sale_spend' => $recentPostSale,
                    'lookback_days' => $params['lookback_days'],
                ],
                action: [
                    'label' => 'Abrir no Gestor de Anúncios',
                    'suggestion' => 'Pausar o anúncio no Gestor de Anúncios. A XPLENDOR só tem acesso de leitura à conta.',
                    'url' => $ad ? self::adsManagerUrl($ad->account_id, $ads !== [] ? array_map(fn (MetaAd $a) => (string) $a->ad_id, $ads) : [(string) $ad->ad_id]) : '/meta-ads',
                ],
                generatedAt: $now,
            );
        }

        return new RuleResult($recommendations);
    }

    public static function adsManagerUrl(string $accountId, array $adIds): string
    {
        return 'https://adsmanager.facebook.com/adsmanager/manage/ads?act=' . rawurlencode($accountId)
            . '&selected_ad_ids=' . rawurlencode(implode(',', $adIds));
    }
}
