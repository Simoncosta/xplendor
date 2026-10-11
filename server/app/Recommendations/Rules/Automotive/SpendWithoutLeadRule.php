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
use Illuminate\Support\Facades\DB;

/**
 * "Gasto sem contactos" (a chave interna continua automotive_spend_without_lead, porque as
 * definições das empresas estão gravadas por ela): gasto dos anúncios com a etiqueta AINDA
 * ATIVOS ≥ min_spend (30 € por omissão) em window_days (14 por omissão) e nenhum contacto da
 * viatura na mesma janela: nem leads (de qualquer origem), nem interações (WhatsApp, chamada,
 * ver ou copiar o telefone). Antes (até ao pré-deploy da Bússola do stand): contava o gasto de
 * todos os anúncios com a etiqueta e só as leads com origem paga.
 * Viaturas vendidas ficam de fora (são da regra "anúncio de viatura vendida ainda
 * ativo": a mesma viatura não aparece duas vezes pela mesma causa).
 */
class SpendWithoutLeadRule implements RecommendationRule
{
    public const KEY = 'automotive_spend_without_lead';

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
        return ['window_days' => 14, 'min_spend' => 30];
    }

    public function normalizeParams(array $params): array
    {
        return [
            'window_days' => max(7, min(60, (int) ($params['window_days'] ?? 14))),
            'min_spend' => max(5.0, min(1000.0, (float) ($params['min_spend'] ?? 30))),
        ];
    }

    public function evaluate(RecommendationContext $context, array $params): RuleResult
    {
        $companyId = $context->company->id;
        $from = $context->now->subDays($params['window_days'] - 1)->toDateString();
        $to = $context->now->toDateString();
        $min = $params['min_spend'];

        // Só os anúncios com a etiqueta ainda ativos (um anúncio pausado já não gasta).
        $activeAds = MetaAd::where('company_id', $companyId)
            ->whereIn('tag_status', [MetaAd::TAG_MATCHED, MetaAd::TAG_SPLIT])
            ->where('effective_status', 'ACTIVE')
            ->pluck('ad_id')->map(fn ($id) => (string) $id)->all();
        if ($activeAds === []) {
            return RuleResult::none();
        }

        $tagSpend = $this->spend->dailyRows($companyId, $from, $to)
            ->where('source', CarAdSpendRepository::SOURCE_TAG)
            ->whereIn('ad_id', $activeAds)
            ->whereNotNull('car_id')
            ->selectRaw('car_id, COALESCE(SUM(spend), 0) as spend')
            ->groupBy('car_id')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->car_id => round((float) $r->spend, 2)])
            ->filter(fn (float $s) => $s >= $min);

        if ($tagSpend->isEmpty()) {
            return RuleResult::none();
        }

        // Qualquer contacto na mesma janela: leads de qualquer origem e interações de contacto.
        $window = fn ($q) => $q->where('company_id', $companyId)->whereIn('car_id', $tagSpend->keys()->all())
            ->where('created_at', '>=', $from . ' 00:00:00')->where('created_at', '<=', $to . ' 23:59:59');
        $leads = $window(DB::table('car_leads'))->selectRaw('car_id, COUNT(*) as n')->groupBy('car_id')->pluck('n', 'car_id');
        $interactions = $window(DB::table('car_interactions'))->whereIn('interaction_type', \App\Services\Automotive\AutomotiveMarketingService::CONTACT_TYPES)
            ->selectRaw('car_id, COUNT(*) as n')->groupBy('car_id')->pluck('n', 'car_id');

        $cars = Car::where('company_id', $companyId)
            ->whereKey($tagSpend->keys()->all())
            ->where('status', '!=', 'sold')
            ->with(['brand:id,name', 'model:id,name'])
            ->orderBy('id')
            ->get();

        $adsByCar = [];
        MetaAd::where('company_id', $companyId)
            ->whereIn('tag_status', [MetaAd::TAG_MATCHED, MetaAd::TAG_SPLIT])
            ->where('effective_status', 'ACTIVE')
            ->get(['ad_id', 'account_id', 'tag_car_ids'])
            ->each(function (MetaAd $ad) use (&$adsByCar) {
                foreach ((array) $ad->tag_car_ids as $carId) {
                    $adsByCar[(int) $carId][] = $ad;
                }
            });

        $recommendations = [];
        foreach ($cars as $car) {
            if ((int) ($leads[$car->id] ?? 0) > 0 || (int) ($interactions[$car->id] ?? 0) > 0) {
                continue;
            }
            $spend = $tagSpend[$car->id];
            $priority = AutomotivePriority::scale(min(85, 60 + (int) floor(($spend - $min) / $min * 10)));
            $days = (int) ($car->daysInStock($context->now) ?? 0);
            $ads = $adsByCar[$car->id] ?? [];

            $recommendations[] = new Recommendation(
                ruleKey: self::KEY,
                priority: $priority,
                title: 'Gasto sem contactos',
                why: sprintf(
                    'Os anúncios ativos com a etiqueta desta viatura registaram %s € de gasto nos últimos %d dias e nenhum contacto no mesmo período (nem leads, nem cliques no WhatsApp, chamadas ou telefone visto) (limiar: %s €).',
                    number_format($spend, 2, ',', ' '),
                    $params['window_days'],
                    number_format($min, 0, ',', ' ')
                ),
                evidence: [
                    'car_id' => $car->id,
                    'car_title' => AutomotiveStockSnapshot::carTitle($car),
                    'issue_type' => 'spend_without_contacts',
                    'tag_spend' => $spend,
                    'active_ads' => count($ads),
                    'leads' => 0,
                    'contacts' => 0,
                    'min_spend' => $min,
                    'window_days' => $params['window_days'],
                    'impact_eur' => $spend,
                    'days_over_threshold' => AutomotivePriority::daysOverThreshold($car, $days),
                ],
                action: [
                    'label' => 'Rever no Gestor de Anúncios',
                    'suggestion' => 'Rever o público e o criativo do anúncio, ou pausá-lo no Gestor de Anúncios.',
                    'url' => $ads !== []
                        ? SoldCarAdStillActiveRule::adsManagerUrl($ads[0]->account_id, array_map(fn (MetaAd $a) => (string) $a->ad_id, $ads))
                        : "/cars/{$car->id}/analytics",
                ],
                generatedAt: $context->now,
            );
        }

        return new RuleResult($recommendations);
    }
}
