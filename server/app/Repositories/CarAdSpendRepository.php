<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\MetaAd;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — FONTE ÚNICA do gasto Meta por viatura.
 *
 * Duas origens, NUNCA somadas para a mesma campanha:
 *   · TAG    → meta_ad_car_spend_daily (anúncio com [id:N] no nome);
 *   · LEGADO → campaign_car_metrics_daily (mapeamento manual CarAdCampaign).
 *
 * Precedência: se a empresa usa tags (algum anúncio com tag, mesmo mal escrita), a
 * TAG ganha e o legado só conta para as campanhas que NÃO têm nenhum anúncio com
 * tag (campanhas antigas). Uma campanha com tag nunca entra pelas duas vias — é o
 * risco principal (dupla contagem). Sem tags, a leitura é exactamente a do legado
 * (os números ficam iguais aos de antes).
 *
 * Viatura removida: linhas com car_id null (tagged_car_id guarda o N). Contam nos
 * totais da empresa, não aparecem em listas por viatura.
 * Pós-venda: linhas com data DEPOIS de cars.sold_at (o dia da venda conta como
 * antes). O gasto continua atribuído à viatura (ROI da venda).
 */
class CarAdSpendRepository
{
    public const SOURCE_TAG = 'tag';
    public const SOURCE_LEGACY = 'legacy';

    /** A empresa já usa tags [id:N] nos anúncios (matched, split ou invalid). */
    public function usesTags(int $companyId): bool
    {
        return MetaAd::where('company_id', $companyId)
            ->whereIn('tag_status', MetaAd::TAGGED_STATUSES)
            ->exists();
    }

    /** Subquery: campanhas da empresa com pelo menos um anúncio com tag. */
    private function taggedCampaignIds(int $companyId): Builder
    {
        return DB::table('meta_ads')
            ->select('campaign_id')
            ->where('company_id', $companyId)
            ->whereIn('tag_status', MetaAd::TAGGED_STATUSES)
            ->whereNotNull('campaign_id');
    }

    /**
     * Linhas diárias normalizadas (as duas origens, sem dupla contagem):
     *   car_id, date, campaign_id, adset_id, ad_id, mapping_id, impressions, clicks, spend, source.
     *
     * @param  list<int>|null  $carIds  null = todas (inclui viaturas removidas, car_id null)
     */
    public function dailyRows(int $companyId, ?string $from = null, ?string $to = null, ?array $carIds = null): Builder
    {
        $legacy = DB::table('campaign_car_metrics_daily as l')
            ->leftJoin('car_ad_campaigns as m', 'm.id', '=', 'l.mapping_id')
            ->where('l.company_id', $companyId)
            ->when($from !== null, fn ($q) => $q->where('l.date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('l.date', '<=', $to))
            ->when($carIds !== null, fn ($q) => $q->whereIn('l.car_id', $carIds))
            ->select([
                'l.car_id', 'l.date', 'l.campaign_id', 'l.adset_id', 'm.ad_id', 'l.mapping_id',
                'l.impressions', 'l.clicks', DB::raw('l.spend_normalized as spend'),
                DB::raw("'" . self::SOURCE_LEGACY . "' as source"),
            ]);

        if (! $this->usesTags($companyId)) {
            return DB::query()->fromSub($legacy, 'car_ad_spend');
        }

        // A TAG ganha: o legado só para campanhas sem nenhum anúncio com tag.
        $legacy->whereNotIn('l.campaign_id', $this->taggedCampaignIds($companyId));

        $tag = DB::table('meta_ad_car_spend_daily as t')
            ->where('t.company_id', $companyId)
            ->when($from !== null, fn ($q) => $q->where('t.date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('t.date', '<=', $to))
            ->when($carIds !== null, fn ($q) => $q->whereIn('t.car_id', $carIds))
            ->select([
                't.car_id', 't.date', 't.campaign_id', 't.adset_id', 't.ad_id', DB::raw('NULL as mapping_id'),
                DB::raw('t.impressions_allocated as impressions'), DB::raw('t.clicks_allocated as clicks'),
                DB::raw('t.spend_allocated as spend'),
                DB::raw("'" . self::SOURCE_TAG . "' as source"),
            ]);

        return DB::query()->fromSub($legacy->unionAll($tag), 'car_ad_spend');
    }

    /**
     * Totais por viatura: [car_id => ['impressions' => int, 'clicks' => int, 'spend' => float]].
     * Viaturas sem gasto não aparecem.
     *
     * @param  list<int>  $carIds
     */
    public function totalsByCar(int $companyId, array $carIds, ?string $from = null, ?string $to = null): array
    {
        if ($carIds === []) {
            return [];
        }

        return $this->dailyRows($companyId, $from, $to, $carIds)
            ->selectRaw('car_id, COALESCE(SUM(impressions), 0) as impressions, COALESCE(SUM(clicks), 0) as clicks, COALESCE(SUM(spend), 0) as spend')
            ->groupBy('car_id')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->car_id => [
                'impressions' => (int) round((float) $r->impressions),
                'clicks'      => (int) round((float) $r->clicks),
                'spend'       => round((float) $r->spend, 2),
            ]])
            ->all();
    }

    /** @return array{impressions: int, clicks: int, spend: float} */
    public function totalsForCar(int $companyId, int $carId, ?string $from = null, ?string $to = null): array
    {
        return $this->totalsByCar($companyId, [$carId], $from, $to)[$carId]
            ?? ['impressions' => 0, 'clicks' => 0, 'spend' => 0.0];
    }

    /**
     * Gasto por dia de uma viatura: [Y-m-d => float], só dias com linhas.
     */
    public function dailySpendForCar(int $companyId, int $carId, string $from, string $to): array
    {
        return $this->dailyRows($companyId, $from, $to, [$carId])
            ->selectRaw('date, COALESCE(SUM(spend), 0) as spend')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->mapWithKeys(fn ($r) => [substr((string) $r->date, 0, 10) => round((float) $r->spend, 2)])
            ->all();
    }

    /**
     * Gasto de uma viatura por alvo Meta (mapeamento legado ou anúncio com tag):
     * linhas com mapping_id (null nas da tag), campaign_id, adset_id, ad_id,
     * impressions, clicks, spend. Ordenadas: legado por mapping_id, depois tags.
     */
    public function targetsForCar(int $companyId, int $carId, string $from, string $to): array
    {
        return $this->dailyRows($companyId, $from, $to, [$carId])
            ->selectRaw('mapping_id, campaign_id, adset_id, ad_id, source, COALESCE(SUM(impressions), 0) as impressions, COALESCE(SUM(clicks), 0) as clicks, COALESCE(SUM(spend), 0) as spend')
            ->groupBy('mapping_id', 'campaign_id', 'adset_id', 'ad_id', 'source')
            ->get()
            ->sortBy(fn ($r) => [$r->source === self::SOURCE_LEGACY ? 0 : 1, (int) $r->mapping_id, (string) $r->ad_id])
            ->values()
            ->map(fn ($r) => [
                'mapping_id'  => $r->mapping_id !== null ? (int) $r->mapping_id : null,
                'campaign_id' => $r->campaign_id,
                'adset_id'    => $r->adset_id,
                'ad_id'       => $r->ad_id,
                'source'      => $r->source,
                'impressions' => (int) round((float) $r->impressions),
                'clicks'      => (int) round((float) $r->clicks),
                'spend'       => round((float) $r->spend, 2),
            ])
            ->all();
    }

    /**
     * Totais da empresa (todas as viaturas, incluindo as removidas).
     *
     * @return array{impressions: int, clicks: int, spend: float}
     */
    public function companyTotals(int $companyId, ?string $from = null, ?string $to = null): array
    {
        $r = $this->dailyRows($companyId, $from, $to)
            ->selectRaw('COALESCE(SUM(impressions), 0) as impressions, COALESCE(SUM(clicks), 0) as clicks, COALESCE(SUM(spend), 0) as spend')
            ->first();

        return [
            'impressions' => (int) round((float) ($r->impressions ?? 0)),
            'clicks'      => (int) round((float) ($r->clicks ?? 0)),
            'spend'       => round((float) ($r->spend ?? 0), 2),
        ];
    }

    /** Há algum gasto por viatura guardado para a empresa (qualquer origem)? */
    public function hasAnyRows(int $companyId): bool
    {
        return DB::table('campaign_car_metrics_daily')->where('company_id', $companyId)->exists()
            || DB::table('meta_ad_car_spend_daily')->where('company_id', $companyId)->exists();
    }

    /**
     * Campanhas activas por viatura: [car_id => int]. Legado = mapeamentos activos
     * (só de campanhas sem tag, se a empresa usa tags); tag = anúncios com a viatura
     * na tag e effective_status ACTIVE.
     *
     * @param  list<int>  $carIds
     */
    public function activeCampaignsByCar(int $companyId, array $carIds): array
    {
        if ($carIds === []) {
            return [];
        }

        $usesTags = $this->usesTags($companyId);

        $counts = DB::table('car_ad_campaigns')
            ->select('car_id', DB::raw('COUNT(*) as active_campaigns'))
            ->where('company_id', $companyId)
            ->whereIn('car_id', $carIds)
            ->where('is_active', true)
            ->when($usesTags, fn ($q) => $q->whereNotIn('campaign_id', $this->taggedCampaignIds($companyId)))
            ->groupBy('car_id')
            ->pluck('active_campaigns', 'car_id')
            ->map(fn ($n) => (int) $n)
            ->all();

        if ($usesTags) {
            $wanted = array_flip($carIds);
            MetaAd::where('company_id', $companyId)
                ->whereIn('tag_status', [MetaAd::TAG_MATCHED, MetaAd::TAG_SPLIT])
                ->where('effective_status', 'ACTIVE')
                ->get(['tag_car_ids'])
                ->each(function (MetaAd $ad) use (&$counts, $wanted) {
                    foreach ((array) $ad->tag_car_ids as $carId) {
                        $carId = (int) $carId;
                        if (isset($wanted[$carId])) {
                            $counts[$carId] = ($counts[$carId] ?? 0) + 1;
                        }
                    }
                });
        }

        return $counts;
    }

    /**
     * Resumo do gasto de UMA viatura (para o hub do 2c): total, antes/depois da venda,
     * por tipo de atribuição e por origem.
     *
     * @return array{spend: float, pre_sale_spend: float, post_sale_spend: float, single_spend: float,
     *   split_spend: float, tag_spend: float, legacy_spend: float, sold_at: ?string}
     */
    public function carSpendSummary(int $companyId, int $carId, ?string $from = null, ?string $to = null): array
    {
        $soldAt = DB::table('cars')->where('company_id', $companyId)->where('id', $carId)->value('sold_at');
        $soldDate = $soldAt ? substr((string) $soldAt, 0, 10) : null;

        $bySource = $this->dailyRows($companyId, $from, $to, [$carId])
            ->selectRaw('source, COALESCE(SUM(spend), 0) as spend')
            ->groupBy('source')
            ->pluck('spend', 'source')
            ->map(fn ($v) => (float) $v);

        $post = $soldDate === null ? 0.0 : (float) $this->dailyRows($companyId, $from, $to, [$carId])
            ->where('date', '>', $soldDate)
            ->sum('spend');

        $byType = DB::table('meta_ad_car_spend_daily')
            ->where('company_id', $companyId)
            ->where('car_id', $carId)
            ->when($from !== null, fn ($q) => $q->where('date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('date', '<=', $to))
            ->selectRaw('allocation_type, COALESCE(SUM(spend_allocated), 0) as spend')
            ->groupBy('allocation_type')
            ->pluck('spend', 'allocation_type')
            ->map(fn ($v) => (float) $v);

        $total = (float) $bySource->sum();

        return [
            'spend'           => round($total, 2),
            'pre_sale_spend'  => round($total - $post, 2),
            'post_sale_spend' => round($post, 2),
            'single_spend'    => round((float) ($byType['single'] ?? 0), 2),
            'split_spend'     => round((float) ($byType['split'] ?? 0), 2),
            'tag_spend'       => round((float) ($bySource[self::SOURCE_TAG] ?? 0), 2),
            'legacy_spend'    => round((float) ($bySource[self::SOURCE_LEGACY] ?? 0), 2),
            'sold_at'         => $soldDate,
        ];
    }

    /**
     * Gasto atribuído a viaturas que já não existem ("viatura removida"), por N da
     * tag: [tagged_car_id => float]. Inclui linhas com car_id null e linhas cujo
     * car_id já não existe (apagada fora do Eloquent).
     */
    public function removedCarsSpend(int $companyId, ?string $from = null, ?string $to = null): array
    {
        return DB::table('meta_ad_car_spend_daily as t')
            ->leftJoin('cars', function ($j) use ($companyId) {
                $j->on('cars.id', '=', 't.car_id')->where('cars.company_id', '=', $companyId);
            })
            ->where('t.company_id', $companyId)
            ->whereNull('cars.id')
            ->when($from !== null, fn ($q) => $q->where('t.date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('t.date', '<=', $to))
            ->selectRaw('t.tagged_car_id, COALESCE(SUM(t.spend_allocated), 0) as spend')
            ->groupBy('t.tagged_car_id')
            ->pluck('spend', 'tagged_car_id')
            ->mapWithKeys(fn ($v, $k) => [(int) $k => round((float) $v, 2)])
            ->all();
    }

    /**
     * AVISOS DE QUALIDADE: anúncios com tag inválida (ID inexistente ou de outra
     * empresa, ou pedaço que não é um ID). Inclui o gasto desses anúncios nos
     * últimos $days dias — é gasto que ficou por atribuir.
     *
     * @return list<array{ad_id: string, ad_name: ?string, campaign_id: ?string, tag_status: string,
     *   invalid_ids: list<string>, car_ids: list<int>, effective_status: ?string, spend_recent: float}>
     */
    public function invalidTagWarnings(int $companyId, int $days = 90): array
    {
        $ads = MetaAd::where('company_id', $companyId)
            ->whereNotNull('tag_invalid_ids')
            ->orderBy('ad_name')
            ->get()
            ->filter(fn (MetaAd $ad) => ! empty($ad->tag_invalid_ids));

        if ($ads->isEmpty()) {
            return [];
        }

        $spend = DB::table('meta_ad_insights_daily')
            ->where('company_id', $companyId)
            ->whereIn('ad_id', $ads->pluck('ad_id')->all())
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->selectRaw('ad_id, COALESCE(SUM(spend), 0) as spend')
            ->groupBy('ad_id')
            ->pluck('spend', 'ad_id');

        return $ads->map(fn (MetaAd $ad) => [
            'ad_id'            => (string) $ad->ad_id,
            'ad_name'          => $ad->ad_name,
            'campaign_id'      => $ad->campaign_id,
            'tag_status'       => $ad->tag_status,
            'invalid_ids'      => array_values(array_map('strval', (array) $ad->tag_invalid_ids)),
            'car_ids'          => array_values(array_map('intval', (array) $ad->tag_car_ids)),
            'effective_status' => $ad->effective_status,
            'spend_recent'     => round((float) ($spend[$ad->ad_id] ?? 0), 2),
        ])->values()->all();
    }
}
