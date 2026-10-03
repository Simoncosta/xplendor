<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SyncMetaAdInsightsJob;
use App\Models\CompanyIntegration;
use App\Models\MetaAd;
use App\Models\MetaAdCarSpendDaily;
use App\Models\MetaAdInsightDaily;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Meta Ads: ingestão ao NÍVEL DO ANÚNCIO + atribuição por viatura.
 *
 * Mesmas regras da ingestão por conta (MetaAccountInsightsService): 13 meses de
 * backfill, diário = últimos 3 dias + hoje com recuperação pela marca d'água,
 * escrita que SUBSTITUI a janela só quando a chamada teve sucesso, throttling da
 * Meta tratado como "tentar mais tarde", estado registado sempre.
 *
 * Diferenças:
 *   · o backfill é feito MÊS A MÊS (por anúncio há muito mais linhas e o limite de
 *     paginação é 50×500). O cursor (ad_insights_backfill_cursor) guarda o próximo
 *     mês: se um mês falhar, a próxima corrida retoma aí, sem refazer os anteriores;
 *   · no fim de cada corrida lê-se o catálogo act_{id}/ads (nome actual +
 *     effective_status) e reconstrói-se a atribuição por viatura (MetaAdCarAllocator).
 *
 * Estado próprio em company_integrations.ad_insights_* (independente da ingestão por
 * conta, para um erro aqui nunca esconder os números da conta).
 */
class MetaAdInsightsService
{
    public const MODE_BACKFILL = MetaAccountInsightsService::MODE_BACKFILL;
    public const MODE_DAILY = MetaAccountInsightsService::MODE_DAILY;

    public const STATUS_PENDING = MetaAccountInsightsService::STATUS_PENDING;
    public const STATUS_RUNNING = MetaAccountInsightsService::STATUS_RUNNING;
    public const STATUS_DONE = MetaAccountInsightsService::STATUS_DONE;
    public const STATUS_FAILED = MetaAccountInsightsService::STATUS_FAILED;
    public const STATUS_NEEDS_ACCOUNT = MetaAccountInsightsService::STATUS_NEEDS_ACCOUNT;
    public const STATUS_TOKEN_EXPIRED = MetaAccountInsightsService::STATUS_TOKEN_EXPIRED;

    public function __construct(
        private readonly MetaAdsService $metaAds,
        private readonly MetaAdCarAllocator $allocator,
        private readonly MetaAccountInsightsService $accountInsights,
    ) {}

    /**
     * Pede a sincronização (assíncrona). Em modo diário: se o backfill ainda não foi
     * feito, o próprio sync sobe a backfill — por isso é seguro chamar sempre.
     */
    public function schedule(CompanyIntegration $integration, string $mode = self::MODE_DAILY): void
    {
        if ($integration->platform !== 'meta' || $integration->status === 'revoked') {
            return;
        }

        if (MetaAccountInsightsService::normalizeAccountId($integration->account_id) === null) {
            $integration->update(['ad_insights_sync_status' => self::STATUS_NEEDS_ACCOUNT]);

            return;
        }

        $integration->update(['ad_insights_sync_status' => self::STATUS_PENDING]);
        SyncMetaAdInsightsJob::dispatch($integration->id, $mode);
    }

    /**
     * Partes mensais de [since, until]: [[2025-09-01, 2025-09-30], …, [2026-10-01, 2026-10-03]].
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function monthChunks(string $since, string $until): array
    {
        $chunks = [];
        $cursor = CarbonImmutable::parse($since);
        $end = CarbonImmutable::parse($until);

        while ($cursor->lte($end)) {
            $monthEnd = $cursor->endOfMonth()->startOfDay();
            $chunkEnd = $monthEnd->lt($end) ? $monthEnd : $end;
            $chunks[] = [$cursor->toDateString(), $chunkEnd->toDateString()];
            $cursor = $chunkEnd->addDay();
        }

        return $chunks;
    }

    /**
     * Corre a sincronização. $maxChunks limita os meses buscados nesta corrida (o
     * job usa 1 no backfill para cada execução ficar curta; devolve
     * 'backfill_in_progress' e o job volta a enfileirar-se para o mês seguinte).
     */
    public function sync(CompanyIntegration $integration, string $mode = self::MODE_DAILY, ?int $maxChunks = null): array
    {
        $now = now();

        if ($integration->status === 'revoked') {
            $integration->update(['ad_insights_last_run_at' => $now]);

            return ['result' => 'skipped_revoked'];
        }

        if ($integration->status === 'expired' || $integration->isTokenExpired()) {
            $integration->update([
                'status'                  => 'expired',
                'ad_insights_sync_status' => self::STATUS_TOKEN_EXPIRED,
                'ad_insights_last_run_at' => $now,
                'ad_insights_error'       => 'Sessão Meta expirada. Reconecta a conta.',
            ]);

            return ['result' => 'token_expired'];
        }

        $accountId = MetaAccountInsightsService::normalizeAccountId($integration->account_id);
        if ($accountId === null) {
            $integration->update([
                'ad_insights_sync_status' => self::STATUS_NEEDS_ACCOUNT,
                'ad_insights_last_run_at' => $now,
            ]);

            return ['result' => 'needs_account'];
        }

        // A conta mudou desde a última ingestão: os dados da conta antiga deixam de ser
        // desta empresa → apagam-se e o backfill recomeça do zero.
        $storedAccount = $integration->ad_insights_account_id;
        if ($storedAccount !== null && $storedAccount !== $accountId) {
            $this->purgeOtherAccounts($integration->company_id, $accountId);
            $integration->update([
                'ad_insights_backfilled_at'   => null,
                'ad_insights_backfill_cursor' => null,
                'ad_insights_synced_until'    => null,
                'ad_insights_error'           => null,
            ]);
            $integration->refresh();
        }

        if ($integration->ad_insights_backfilled_at === null) {
            $mode = self::MODE_BACKFILL;
        }

        $today = CarbonImmutable::today();
        if ($mode === self::MODE_BACKFILL) {
            $cursor = $integration->ad_insights_backfill_cursor
                ? CarbonImmutable::parse($integration->ad_insights_backfill_cursor->toDateString())
                : MetaAccountInsightsService::backfillStart($today);
            $since = $cursor->toDateString();
            $until = $today->toDateString();
        } else {
            $syncedUntil = $integration->ad_insights_synced_until
                ? CarbonImmutable::parse($integration->ad_insights_synced_until->toDateString())
                : null;
            [$since, $until] = $this->accountInsights->windowFor(self::MODE_DAILY, $today, $syncedUntil);
        }

        $integration->update([
            'ad_insights_sync_status' => self::STATUS_RUNNING,
            'ad_insights_last_run_at' => $now,
            'ad_insights_account_id'  => $accountId,
        ]);

        $chunks = self::monthChunks($since, $until);
        $processed = 0;
        $rows = 0;

        foreach ($chunks as [$chunkSince, $chunkUntil]) {
            if ($maxChunks !== null && $processed >= $maxChunks) {
                // Ainda há meses por buscar: o job volta a enfileirar-se.
                $integration->update(['ad_insights_sync_status' => self::STATUS_PENDING]);

                return ['result' => 'backfill_in_progress', 'next' => $chunkSince, 'rows' => $rows];
            }

            $res = $this->metaAds->getAccountAdInsightsDaily(
                (string) $integration->access_token,
                $accountId,
                $chunkSince,
                $chunkUntil
            );

            if (! $res['ok']) {
                return $this->registerFailure($integration, $res, $chunkSince);
            }

            // Corrida: a conta pode ter mudado enquanto a Meta respondia.
            $currentAccount = MetaAccountInsightsService::normalizeAccountId(
                CompanyIntegration::whereKey($integration->id)->value('account_id')
            );
            if ($currentAccount !== $accountId) {
                Log::info('MetaAdInsights: conta mudou durante o sync — resultado descartado', [
                    'company_id' => $integration->company_id,
                    'fetched'    => $accountId,
                    'current'    => $currentAccount,
                ]);

                return ['result' => 'account_changed'];
            }

            $this->replaceWindow($integration->company_id, $accountId, $chunkSince, $chunkUntil, $res['rows']);
            $this->allocator->rebuild($integration->company_id, $accountId, $chunkSince, $chunkUntil);
            $this->logReconciliation($integration->company_id, $accountId, $chunkSince, $chunkUntil);

            $processed++;
            $rows += count($res['rows']);

            if ($mode === self::MODE_BACKFILL) {
                $integration->update([
                    'ad_insights_backfill_cursor' => CarbonImmutable::parse($chunkUntil)->addDay()->toDateString(),
                ]);
            }
        }

        // Catálogo: nome actual + effective_status. Falhar aqui não invalida os
        // insights já escritos (fica no log; o próximo ciclo volta a tentar).
        $this->syncCatalog($integration, $accountId);
        $this->allocator->rebuild($integration->company_id, $accountId, $since, $until);

        $integration->update([
            'ad_insights_sync_status'     => self::STATUS_DONE,
            'ad_insights_backfilled_at'   => $mode === self::MODE_BACKFILL
                ? $now
                : ($integration->ad_insights_backfilled_at ?? $now),
            'ad_insights_backfill_cursor' => null,
            'ad_insights_synced_at'       => $now,
            'ad_insights_synced_until'    => $until,
            'ad_insights_error'           => null,
        ]);

        Log::info('MetaAdInsights: sync concluído', [
            'company_id' => $integration->company_id,
            'mode'       => $mode,
            'since'      => $since,
            'until'      => $until,
            'months'     => $processed,
            'rows'       => $rows,
        ]);

        return ['result' => 'done', 'mode' => $mode, 'since' => $since, 'until' => $until, 'months' => $processed, 'rows' => $rows];
    }

    private function registerFailure(CompanyIntegration $integration, array $res, string $chunkSince): array
    {
        if ($res['token_invalid']) {
            $integration->update([
                'status'                  => 'expired',
                'ad_insights_sync_status' => self::STATUS_TOKEN_EXPIRED,
                'ad_insights_error'       => 'Sessão Meta expirada. Reconecta a conta.',
            ]);

            return ['result' => 'token_expired'];
        }

        if (! empty($res['retryable'])) {
            $integration->update([
                'ad_insights_sync_status' => self::STATUS_PENDING,
                'ad_insights_error'       => 'A Meta pediu para abrandar; nova tentativa em breve.',
            ]);

            return ['result' => 'retryable', 'error' => $res['error'], 'next' => $chunkSince];
        }

        $integration->update([
            'ad_insights_sync_status' => self::STATUS_FAILED,
            'ad_insights_error'       => mb_substr((string) $res['error'], 0, 500),
        ]);

        return ['result' => 'failed', 'error' => $res['error'], 'next' => $chunkSince];
    }

    /** Substitui as linhas da empresa+conta na janela e actualiza o catálogo com o que veio. */
    private function replaceWindow(int $companyId, string $accountId, string $since, string $until, array $rows): void
    {
        DB::transaction(function () use ($companyId, $accountId, $since, $until, $rows) {
            MetaAdInsightDaily::where('company_id', $companyId)
                ->where('account_id', $accountId)
                ->whereBetween('date', [$since, $until])
                ->delete();

            // Agrega duplicados defensivamente (mesma data+anúncio).
            $byKey = [];
            $ads = [];
            foreach ($rows as $r) {
                if ($r['date'] < $since || $r['date'] > $until) {
                    continue;
                }
                $k = $r['date'] . '|' . $r['ad_id'];
                if (! isset($byKey[$k])) {
                    $byKey[$k] = $r;
                } else {
                    $byKey[$k]['spend'] += $r['spend'];
                    $byKey[$k]['impressions'] += $r['impressions'];
                    $byKey[$k]['clicks'] += $r['clicks'];
                }
                // O nome mais recente de cada anúncio (as linhas trazem o nome actual).
                $ads[$r['ad_id']] = $r;
            }

            $now = now();
            $insert = array_map(fn ($r) => [
                'company_id'  => $companyId,
                'account_id'  => $accountId,
                'date'        => $r['date'],
                'campaign_id' => $r['campaign_id'],
                'adset_id'    => $r['adset_id'],
                'ad_id'       => $r['ad_id'],
                'ad_name'     => $r['ad_name'] !== null ? mb_substr($r['ad_name'], 0, 255) : null,
                'spend'       => round((float) $r['spend'], 2),
                'impressions' => (int) $r['impressions'],
                'clicks'      => (int) $r['clicks'],
                'created_at'  => $now,
                'updated_at'  => $now,
            ], array_values($byKey));

            foreach (array_chunk($insert, 500) as $chunk) {
                MetaAdInsightDaily::insert($chunk);
            }

            foreach ($ads as $adId => $r) {
                $this->upsertAd($companyId, $accountId, (string) $adId, [
                    'ad_name'     => $r['ad_name'] !== null ? mb_substr($r['ad_name'], 0, 255) : null,
                    'campaign_id' => $r['campaign_id'],
                    'adset_id'    => $r['adset_id'],
                ]);
            }
        });
    }

    /**
     * act_{id}/ads → nome actual e effective_status. Anúncios que deixaram de vir no
     * catálogo ficam com effective_status null (desconhecido), para nunca ficarem
     * "ACTIVE" para sempre depois de arquivados.
     */
    private function syncCatalog(CompanyIntegration $integration, string $accountId): void
    {
        $res = $this->metaAds->getAccountAdsCatalog((string) $integration->access_token, $accountId);

        if (! $res['ok']) {
            Log::warning('MetaAdInsights: catálogo de anúncios não lido', [
                'company_id' => $integration->company_id,
                'error'      => $res['error'],
            ]);

            return;
        }

        $now = now();
        $seen = [];
        foreach ($res['rows'] as $a) {
            $seen[] = $a['ad_id'];
            $this->upsertAd($integration->company_id, $accountId, $a['ad_id'], array_filter([
                'ad_name'          => $a['ad_name'] !== null ? mb_substr($a['ad_name'], 0, 255) : null,
                'campaign_id'      => $a['campaign_id'],
                'adset_id'         => $a['adset_id'],
            ], fn ($v) => $v !== null) + [
                'effective_status' => $a['effective_status'],
                'status_synced_at' => $now,
            ]);
        }

        MetaAd::where('company_id', $integration->company_id)
            ->where('account_id', $accountId)
            ->when($seen !== [], fn ($q) => $q->whereNotIn('ad_id', $seen))
            ->whereNotNull('effective_status')
            ->update(['effective_status' => null, 'status_synced_at' => $now]);
    }

    private function upsertAd(int $companyId, string $accountId, string $adId, array $attributes): void
    {
        $ad = MetaAd::firstOrNew(['company_id' => $companyId, 'account_id' => $accountId, 'ad_id' => $adId]);
        $ad->fill($attributes);
        if (! $ad->exists || $ad->isDirty()) {
            $ad->save();
        }
    }

    private function purgeOtherAccounts(int $companyId, string $accountId): void
    {
        DB::transaction(function () use ($companyId, $accountId) {
            foreach ([MetaAdInsightDaily::class, MetaAd::class, MetaAdCarSpendDaily::class] as $model) {
                $model::where('company_id', $companyId)->where('account_id', '!=', $accountId)->delete();
            }
        });
    }

    /**
     * Rede de segurança: o gasto por anúncio de um mês deve bater com o gasto da conta
     * (meta_account_insights_daily) nos mesmos dias. Se não bater, fica no log (não
     * bloqueia: a conta pode ainda não ter feito o seu backfill).
     */
    private function logReconciliation(int $companyId, string $accountId, string $since, string $until): void
    {
        $account = DB::table('meta_account_insights_daily')
            ->where('company_id', $companyId)->where('account_id', $accountId)
            ->whereBetween('date', [$since, $until]);

        if (! (clone $account)->exists()) {
            return;
        }

        $accountSpend = (float) $account->sum('spend');
        $adSpend = (float) MetaAdInsightDaily::where('company_id', $companyId)->where('account_id', $accountId)
            ->whereBetween('date', [$since, $until])->sum('spend');

        $diff = abs($accountSpend - $adSpend);
        if ($diff > 1.0 && $diff > 0.01 * max($accountSpend, $adSpend)) {
            Log::warning('MetaAdInsights: gasto por anúncio não bate com o gasto da conta', [
                'company_id'    => $companyId,
                'since'         => $since,
                'until'         => $until,
                'account_spend' => round($accountSpend, 2),
                'ad_spend'      => round($adSpend, 2),
            ]);
        }
    }
}
