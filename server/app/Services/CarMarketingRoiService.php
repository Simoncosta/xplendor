<?php

namespace App\Services;

use App\Repositories\Contracts\CarMarketingRoiRepositoryInterface;
use Illuminate\Support\Facades\Log;

class CarMarketingRoiService extends BaseService
{
    public function __construct(
        protected CarMarketingRoiRepositoryInterface $carMarketingRoiRepository
    ) {
        parent::__construct($carMarketingRoiRepository);
    }

    public function getCompanyMarketingRoi(int $companyId): array
    {
        $from = now()->subDays(6)->toDateString();
        $to = now()->toDateString();

        $metaSpendSummary = $this->carMarketingRoiRepository->getMetaSpendSummary($companyId, $from, $to);
        $metaSpendByCar = $this->carMarketingRoiRepository->getMetaSpendByCar($companyId, $from, $to);
        $channelRows = $this->carMarketingRoiRepository->getMetricsByChannel($companyId, $from, $to);
        $campaignRows = $this->carMarketingRoiRepository->getCampaignPerformance($companyId, $from, $to);
        $carRows = $this->carMarketingRoiRepository->getCarPerformance($companyId, $from, $to);

        $byChannel = $channelRows->map(function ($row) {
            $sessions = (int) ($row->sessions ?? 0);
            $leads = (int) ($row->leads ?? 0);
            $spend = (float) ($row->spend ?? 0);
            $conversionRate = $sessions > 0 ? round(($leads / $sessions) * 100, 2) : 0;
            $costPerLead = $leads > 0 ? round($spend / $leads, 2) : 0;

            return [
                'channel' => $this->normalizeChannelLabel((string) $row->channel),
                'sessions' => $sessions,
                'leads' => $leads,
                'conversion_rate' => $conversionRate,
                'total_spend' => round($spend, 2),
                'cost_per_lead' => $costPerLead,
                'status' => $this->resolveChannelStatus($sessions, $leads, $spend, $conversionRate, $costPerLead),
            ];
        })->values();

        $topCampaigns = $campaignRows->map(function ($row) {
            $impressions = (int) ($row->impressions ?? 0);
            $clicks = (int) ($row->clicks ?? 0);
            $sessions = (int) ($row->sessions ?? 0);
            $leads = (int) ($row->leads ?? 0);
            $spend = (float) ($row->spend ?? 0);

            return [
                'campaign_id' => (string) ($row->campaign_id ?? ''),
                'campaign_name' => (string) ($row->campaign_name ?? ''),
                'platform' => (string) ($row->platform ?? 'meta'),
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0,
                'spend' => round($spend, 2),
                'leads' => $leads,
                'conversion_rate' => $sessions > 0 ? round(($leads / $sessions) * 100, 2) : 0,
                'cost_per_lead' => $leads > 0 ? round($spend / $leads, 2) : 0,
            ];
        })
            ->filter(fn(array $campaign) => $campaign['spend'] > 0 || $campaign['leads'] > 0)
            ->sortBy([
                ['leads', 'desc'],
                ['cost_per_lead', 'asc'],
                ['spend', 'asc'],
            ])
            ->take(5)
            ->values();

        $topCarsToPromote = $carRows->map(function ($row) {
            $views = (int) ($row->views ?? 0);
            $leads = (int) ($row->leads ?? 0);
            $spend = (float) ($row->spend ?? 0);
            $ipsScore = (int) ($row->ips_score ?? 0);
            $costPerLead = $leads > 0 ? round($spend / $leads, 2) : 0;
            $promotionScore = ($ipsScore * 1.5) + min($views, 300) * 0.2 + ($leads * 20) - ($costPerLead * 0.8) - ($spend * 0.03);

            return [
                'car_id' => (int) ($row->car_id ?? 0),
                'car_name' => trim((string) ($row->car_name ?? '')),
                'ips_score' => $ipsScore,
                'views' => $views,
                'leads' => $leads,
                'spend' => round($spend, 2),
                'cost_per_lead' => $costPerLead,
                'recommendation' => $this->buildCarRecommendation($ipsScore, $views, $leads, $spend, $costPerLead),
                '_promotion_score' => round($promotionScore, 2),
            ];
        })
            ->sortByDesc('_promotion_score')
            ->take(5)
            ->map(function (array $car) {
                unset($car['_promotion_score']);
                return $car;
            })
            ->values();

        $totalSessions = (int) $byChannel->sum('sessions');
        $totalLeads = (int) $byChannel->sum('leads');
        $totalSpend = round((float) ($metaSpendSummary->total_spend ?? 0), 2);
        $overallConversionRate = $totalSessions > 0 ? round(($totalLeads / $totalSessions) * 100, 2) : 0;
        $avgCostPerLead = $totalLeads > 0 ? round($totalSpend / $totalLeads, 2) : 0;

        $bestChannel = $byChannel
            ->filter(fn(array $channel) => $channel['leads'] > 0)
            ->sortBy([
                ['cost_per_lead', 'asc'],
                ['leads', 'desc'],
            ])
            ->first();

        $bestCampaign = $topCampaigns
            ->filter(fn(array $campaign) => $campaign['leads'] > 0)
            ->sortBy([
                ['cost_per_lead', 'asc'],
                ['leads', 'desc'],
            ])
            ->first();

        Log::info('[MarketingROI] Calculated company dashboard block', [
            'company_id' => $companyId,
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
            'summary' => [
                'total_spend' => $totalSpend,
                'total_leads' => $totalLeads,
                'overall_conversion_rate' => $overallConversionRate,
                'avg_cost_per_lead' => $avgCostPerLead,
            ],
            'meta_spend_summary' => [
                'total_spend' => $totalSpend,
                'total_reach' => (int) ($metaSpendSummary->total_reach ?? 0),
                'total_clicks' => (int) ($metaSpendSummary->total_clicks ?? 0),
            ],
            'channels_found' => $byChannel->map(fn(array $channel) => [
                'channel' => $channel['channel'],
                'spend' => $channel['total_spend'],
                'sessions' => $channel['sessions'],
                'leads' => $channel['leads'],
            ])->all(),
            'meta_spend_by_car' => $metaSpendByCar->map(fn($row) => [
                'car_id' => (int) ($row->car_id ?? 0),
                'spend' => round((float) ($row->spend ?? 0), 2),
                'reach' => (int) ($row->reach ?? 0),
                'audience_clicks' => (int) ($row->audience_clicks ?? 0),
            ])->all(),
            'campaigns_found_count' => $campaignRows->count(),
            'campaigns_found' => $topCampaigns->map(fn(array $campaign) => [
                'campaign_id' => $campaign['campaign_id'],
                'campaign_name' => $campaign['campaign_name'],
                'spend' => $campaign['spend'],
                'leads' => $campaign['leads'],
                'platform' => $campaign['platform'],
            ])->all(),
            'best_campaign' => $bestCampaign['campaign_name'] ?? null,
        ]);

        return [
            'summary' => [
                'total_spend' => $totalSpend,
                'total_leads' => $totalLeads,
                'overall_conversion_rate' => $overallConversionRate,
                'avg_cost_per_lead' => $avgCostPerLead,
                'best_channel' => $bestChannel['channel'] ?? '',
                'best_campaign' => $bestCampaign['campaign_name'] ?? '',
            ],
            'by_channel' => $byChannel->all(),
            'top_campaigns' => $topCampaigns->all(),
            'top_cars_to_promote' => $topCarsToPromote->all(),
            'insights' => $this->buildInsights($byChannel->all(), $topCampaigns->all(), $topCarsToPromote->all(), $totalLeads),
        ];
    }

    private function normalizeChannelLabel(string $channel): string
    {
        return match ($channel) {
            'paid' => 'Meta Ads',
            'organic_search' => 'Pesquisa orgânica',
            'organic_social' => 'Social orgânico',
            'direct' => 'Direto',
            'referral' => 'Referral',
            'email' => 'Email',
            'utm' => 'UTM',
            default => ucfirst(str_replace('_', ' ', $channel)),
        };
    }

    private function resolveChannelStatus(int $sessions, int $leads, float $spend, float $conversionRate, float $costPerLead): string
    {
        if ($leads > 0 && $conversionRate >= 2 && ($costPerLead <= 25 || $spend <= 0)) {
            return 'A escalar';
        }

        if ($leads > 0 || $sessions >= 20) {
            return 'Estável';
        }

        return 'Fraco';
    }

    private function buildCarRecommendation(int $ipsScore, int $views, int $leads, float $spend, float $costPerLead): string
    {
        if ($leads >= 3 && ($costPerLead > 0 && $costPerLead <= 20)) {
            return 'Escalar investimento com a campanha atual.';
        }

        if ($ipsScore >= 70 && $views >= 80 && $leads >= 1) {
            return 'Carro com procura forte; aumentar budget de forma gradual.';
        }

        if ($views >= 120 && $leads === 0 && $spend > 0) {
            return 'Rever criativo e CTA antes de investir mais.';
        }

        if ($ipsScore >= 70) {
            return 'Boa promessa comercial; testar nova campanha Meta.';
        }

        return 'Manter monitorização e otimizar o anúncio.';
    }

    private function buildInsights(array $byChannel, array $topCampaigns, array $topCarsToPromote, int $totalLeads): array
    {
        $insights = [];

        $metaChannel = collect($byChannel)->firstWhere('channel', 'Meta Ads');
        if ($metaChannel && $totalLeads > 0) {
            $share = round(($metaChannel['leads'] / max($totalLeads, 1)) * 100);
            $insights[] = "Meta Ads está a gerar {$share}% das leads nos últimos 7 dias.";
        }

        $bestCampaign = collect($topCampaigns)
            ->filter(fn(array $campaign) => $campaign['leads'] > 0)
            ->sortBy([
                ['cost_per_lead', 'asc'],
                ['leads', 'desc'],
            ])
            ->first();

        if ($bestCampaign) {
            $insights[] = "{$bestCampaign['campaign_name']} tem o melhor custo por lead do período.";
        }

        $topCar = $topCarsToPromote[0] ?? null;
        if ($topCar) {
            $insights[] = "{$topCar['car_name']} é o carro com maior potencial para escalar investimento.";
        }

        $problematicPaid = collect($topCarsToPromote)
            ->filter(fn(array $car) => $car['spend'] > 0 && $car['leads'] === 0)
            ->count();

        if ($problematicPaid > 0) {
            $insights[] = "Há {$problematicPaid} carros com tráfego pago sem conversão que exigem revisão do anúncio.";
        }

        $bestChannel = collect($byChannel)
            ->filter(fn(array $channel) => $channel['leads'] > 0)
            ->sortBy([
                ['cost_per_lead', 'asc'],
                ['leads', 'desc'],
            ])
            ->first();

        if ($bestChannel) {
            $insights[] = "O canal {$bestChannel['channel']} gera leads com melhor eficiência neste momento.";
        }

        return array_values(array_unique($insights));
    }
}
