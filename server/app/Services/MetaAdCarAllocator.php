<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Car;
use App\Models\MetaAd;
use App\Models\MetaAdCarSpendDaily;
use App\Models\MetaAdInsightDaily;
use App\Support\AdCarTag;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Atribuição do gasto Meta por viatura a partir da tag [id:N].
 *
 * Trabalha só com dados locais (meta_ads + meta_ad_insights_daily), por isso pode
 * correr sempre que for preciso sem chamar a Meta:
 *
 *   1. evaluateTags(): lê a tag do nome ACTUAL de cada anúncio e valida os IDs
 *      contra a empresa — Car::where('company_id', X)->whereKey(N). Um ID de outra
 *      empresa ou inexistente é inválido (aviso, nunca atribuído). Um ID de uma
 *      viatura DESTA empresa que já foi apagada mas tem histórico atribuído continua
 *      a receber o gasto como "viatura removida" (car_id null, tagged_car_id N).
 *   2. rebuild(): substitui as linhas de meta_ad_car_spend_daily da janela pedida
 *      (e de todo o histórico dos anúncios cuja tag mudou — ex.: o nome passou a ter
 *      [id:N]; a Meta devolve sempre o nome actual, por isso a tag vale para trás).
 */
class MetaAdCarAllocator
{
    /**
     * Avalia a tag de todos os anúncios da empresa+conta e grava o resultado em
     * meta_ads. Devolve os ad_id cuja atribuição mudou (estado ou viaturas).
     *
     * @return list<string>
     */
    public function evaluateTags(int $companyId, string $accountId): array
    {
        $ads = MetaAd::where('company_id', $companyId)->where('account_id', $accountId)->get();
        if ($ads->isEmpty()) {
            return [];
        }

        $parsed = [];
        $allIds = [];
        foreach ($ads as $ad) {
            $p = AdCarTag::parse($ad->ad_name);
            $parsed[$ad->id] = $p;
            array_push($allIds, ...$p['ids']);
        }
        $allIds = array_values(array_unique($allIds));

        // Tenancy: só contam viaturas DESTA empresa.
        $validIds = $allIds === []
            ? []
            : Car::where('company_id', $companyId)->whereKey($allIds)->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Viaturas desta empresa já apagadas, mas com histórico atribuído.
        $removedIds = [];
        $missing = array_values(array_diff($allIds, $validIds));
        if ($missing !== []) {
            $removedIds = MetaAdCarSpendDaily::where('company_id', $companyId)
                ->whereIn('tagged_car_id', $missing)
                ->distinct()
                ->pluck('tagged_car_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $now = now();
        $changed = [];
        foreach ($ads as $ad) {
            $r = AdCarTag::resolve($parsed[$ad->id], $validIds, $removedIds);
            $carIds = $r['car_ids'] ?: null;
            $invalid = $r['invalid_ids'] ?: null;

            $before = [$ad->tag_status, $ad->tag_car_ids ?: null];
            $after = [$r['status'], $carIds];

            if ($before !== $after) {
                $changed[] = (string) $ad->ad_id;
            }

            if ($before !== $after || ($ad->tag_invalid_ids ?: null) !== $invalid || $ad->tag_evaluated_at === null) {
                $ad->forceFill([
                    'tag_status'       => $r['status'],
                    'tag_car_ids'      => $carIds,
                    'tag_invalid_ids'  => $invalid,
                    'tag_evaluated_at' => $now,
                ])->save();
            }
        }

        return $changed;
    }

    /**
     * Reavalia as tags e reconstrói a atribuição da janela [since, until] (null =
     * todo o histórico) + todo o histórico dos anúncios cuja tag mudou.
     *
     * @return array{changed_ads: int, rows: int}
     */
    public function rebuild(int $companyId, string $accountId, ?string $since = null, ?string $until = null): array
    {
        $changed = $this->evaluateTags($companyId, $accountId);

        $rows = DB::transaction(function () use ($companyId, $accountId, $since, $until, $changed) {
            $n = $this->rebuildScope($companyId, $accountId, $since, $until, null);
            if ($changed !== [] && ($since !== null || $until !== null)) {
                $n += $this->rebuildScope($companyId, $accountId, null, null, $changed);
            }

            return $n;
        });

        return ['changed_ads' => count($changed), 'rows' => $rows];
    }

    /** Apaga e volta a escrever as linhas de atribuição de um âmbito. */
    private function rebuildScope(int $companyId, string $accountId, ?string $since, ?string $until, ?array $adIds): int
    {
        $scope = function ($q) use ($companyId, $accountId, $since, $until, $adIds) {
            $q->where('company_id', $companyId)->where('account_id', $accountId);
            if ($since !== null) {
                $q->where('date', '>=', $since);
            }
            if ($until !== null) {
                $q->where('date', '<=', $until);
            }
            if ($adIds !== null) {
                $q->whereIn('ad_id', $adIds);
            }
        };

        MetaAdCarSpendDaily::query()->where($scope)->delete();

        // Só os anúncios com viaturas a quem atribuir (matched/split).
        $targets = MetaAd::where('company_id', $companyId)
            ->where('account_id', $accountId)
            ->whereIn('tag_status', [MetaAd::TAG_MATCHED, MetaAd::TAG_SPLIT])
            ->when($adIds !== null, fn ($q) => $q->whereIn('ad_id', $adIds))
            ->get(['ad_id', 'tag_car_ids'])
            ->mapWithKeys(fn (MetaAd $ad) => [(string) $ad->ad_id => array_map('intval', (array) $ad->tag_car_ids)])
            ->filter(fn (array $ids) => $ids !== [])
            ->all();

        if ($targets === []) {
            return 0;
        }

        // Para saber se o alvo ainda existe (car_id) ou foi removido (car_id null).
        $allTargetIds = array_values(array_unique(array_merge(...array_values($targets))));
        $existing = Car::where('company_id', $companyId)->whereKey($allTargetIds)->pluck('id')
            ->map(fn ($id) => (int) $id)->flip()->all();

        $now = now();
        $count = 0;

        MetaAdInsightDaily::query()
            ->where($scope)
            ->whereIn('ad_id', array_map('strval', array_keys($targets)))
            ->orderBy('id')
            ->chunk(1000, function ($insights) use ($targets, $existing, $companyId, $accountId, $now, &$count) {
                $insert = [];
                foreach ($insights as $row) {
                    $carIds = $targets[(string) $row->ad_id] ?? [];
                    $n = count($carIds);
                    if ($n === 0) {
                        continue;
                    }

                    $spendParts = AdCarTag::splitEvenly((float) $row->spend, $n);
                    $impParts = AdCarTag::splitEvenly((float) $row->impressions, $n);
                    $clickParts = AdCarTag::splitEvenly((float) $row->clicks, $n);
                    $share = round(1 / $n, 6);

                    foreach ($carIds as $i => $carId) {
                        $insert[] = [
                            'company_id'            => $companyId,
                            'account_id'            => $accountId,
                            'date'                  => $row->date->toDateString(),
                            'campaign_id'           => $row->campaign_id,
                            'adset_id'              => $row->adset_id,
                            'ad_id'                 => $row->ad_id,
                            'car_id'                => isset($existing[$carId]) ? $carId : null,
                            'tagged_car_id'         => $carId,
                            'share'                 => $share,
                            'spend_allocated'       => $spendParts[$i],
                            'impressions_allocated' => $impParts[$i],
                            'clicks_allocated'      => $clickParts[$i],
                            'allocation_type'       => $n > 1 ? MetaAdCarSpendDaily::TYPE_SPLIT : MetaAdCarSpendDaily::TYPE_SINGLE,
                            'created_at'            => $now,
                            'updated_at'            => $now,
                        ];
                    }
                }

                foreach (array_chunk($insert, 500) as $chunk) {
                    MetaAdCarSpendDaily::insert($chunk);
                }
                $count += count($insert);
            });

        return $count;
    }
}
