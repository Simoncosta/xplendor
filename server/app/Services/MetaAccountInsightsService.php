<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SyncMetaAccountInsightsJob;
use App\Models\CompanyIntegration;
use App\Models\MetaAccountInsightDaily;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Meta Ads: ingestão ao NÍVEL DA CONTA (todas as verticais).
 *
 * Porque existe: o pipeline antigo só guarda campanhas mapeadas a um CARRO, por
 * isso uma empresa sem carros (restauração, etc.) ficava sempre a zero. Aqui puxa-
 * se o que a conta de anúncios gastou, por campanha e por dia, directamente de
 * act_{account_id}/insights. O pipeline por carro NÃO é tocado (faz outra coisa:
 * reparte o gasto por viatura para a atribuição de vendas dos stands).
 *
 * Janelas:
 *   · backfill → últimos 90 dias (ao ligar / ao definir ou mudar a conta);
 *   · daily    → últimos 3 dias completos + hoje (a Meta ajusta a atribuição com
 *                atraso; re-buscar corrige os números).
 *
 * Escrita: SUBSTITUI a janela (apaga + insere numa transacção, só se a chamada
 * teve sucesso) — uma campanha que deixou de ter entrega não fica com dados velhos.
 *
 * Estado (company_integrations.insights_*): regista-se SEMPRE, incluindo quando não
 * há nada para fazer ou quando falha — o ecrã mostra a verdade em vez de zeros.
 */
class MetaAccountInsightsService
{
    public const BACKFILL_DAYS = 90;
    public const DAILY_LOOKBACK_DAYS = 3;

    public const MODE_BACKFILL = 'backfill';
    public const MODE_DAILY = 'daily';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NEEDS_ACCOUNT = 'needs_account';
    public const STATUS_TOKEN_EXPIRED = 'token_expired';

    public function __construct(private readonly MetaAdsService $metaAds) {}

    public static function normalizeAccountId(?string $accountId): ?string
    {
        if ($accountId === null) {
            return null;
        }
        $clean = preg_replace('/^act_/', '', trim($accountId));

        return $clean === '' ? null : $clean;
    }

    /**
     * [since, until] (datas Y-m-d) da janela para o modo pedido.
     *   backfill: hoje-89 … hoje (90 dias).
     *   daily:    hoje-3 … hoje — MAS se o último sync com sucesso parou antes
     *             (vários dias a falhar / fila parada), recua até ao dia a seguir à
     *             marca d'água (insights_synced_until) para não deixar buracos a
     *             zero; nunca mais de 90 dias.
     */
    public function windowFor(string $mode, ?CarbonImmutable $today = null, ?CarbonImmutable $syncedUntil = null): array
    {
        $today = $today ?? CarbonImmutable::today();
        $floor = $today->subDays(self::BACKFILL_DAYS - 1);

        if ($mode === self::MODE_BACKFILL) {
            $since = $floor;
        } else {
            $since = $today->subDays(self::DAILY_LOOKBACK_DAYS);
            if ($syncedUntil !== null) {
                $resume = $syncedUntil->addDay();
                if ($resume->lt($since)) {
                    $since = $resume->lt($floor) ? $floor : $resume;
                }
            }
        }

        return [$since->toDateString(), $today->toDateString()];
    }

    /**
     * Pede o backfill (assíncrono). Sem conta de anúncios não dá para buscar →
     * fica registado needs_account (o ecrã pede para escolher a conta).
     */
    public function scheduleBackfill(CompanyIntegration $integration): void
    {
        if ($integration->platform !== 'meta') {
            return;
        }

        if (self::normalizeAccountId($integration->account_id) === null) {
            $integration->update(['insights_sync_status' => self::STATUS_NEEDS_ACCOUNT]);

            return;
        }

        $integration->update(['insights_sync_status' => self::STATUS_PENDING]);
        SyncMetaAccountInsightsJob::dispatch($integration->id, self::MODE_BACKFILL);
    }

    /**
     * A conta de anúncios mudou: os dados da conta antiga deixam de ser desta
     * empresa → apagam-se, o "1.º backfill" volta a zero e dispara-se um novo.
     */
    public function onAccountChanged(CompanyIntegration $integration, ?string $previousAccountId): void
    {
        $new = self::normalizeAccountId($integration->account_id);
        $old = self::normalizeAccountId($previousAccountId);

        if ($new !== $old) {
            MetaAccountInsightDaily::where('company_id', $integration->company_id)
                ->where(function ($q) use ($new) {
                    $q->whereNull('account_id');
                    if ($new !== null) {
                        $q->orWhere('account_id', '!=', $new);
                    } else {
                        $q->orWhereNotNull('account_id');
                    }
                })
                ->delete();

            // Conta nova: sem histórico, sem marca d'água, sem erro herdado.
            $integration->update([
                'insights_backfilled_at' => null,
                'insights_synced_until'  => null,
                'insights_error'         => null,
            ]);
        }

        $this->scheduleBackfill($integration->fresh());
    }

    /**
     * Corre a sincronização (chamado pelo job). Devolve um resumo (para logs/testes).
     */
    public function sync(CompanyIntegration $integration, string $mode = self::MODE_DAILY): array
    {
        $now = now();

        if ($integration->status === 'revoked') {
            $integration->update(['insights_last_run_at' => $now]);

            return ['result' => 'skipped_revoked'];
        }

        if ($integration->status === 'expired' || $integration->isTokenExpired()) {
            $integration->update([
                'status'               => 'expired',
                'insights_sync_status' => self::STATUS_TOKEN_EXPIRED,
                'insights_last_run_at' => $now,
                'insights_error'       => 'Sessão Meta expirada. Reconecta a conta.',
            ]);

            return ['result' => 'token_expired'];
        }

        $accountId = self::normalizeAccountId($integration->account_id);
        if ($accountId === null) {
            $integration->update([
                'insights_sync_status' => self::STATUS_NEEDS_ACCOUNT,
                'insights_last_run_at' => $now,
            ]);

            return ['result' => 'needs_account'];
        }

        // Sem 1.º backfill concluído → o diário sobe a backfill (garante os 90 dias
        // mesmo que o disparo ao ligar se tenha perdido).
        if ($integration->insights_backfilled_at === null) {
            $mode = self::MODE_BACKFILL;
        }

        $syncedUntil = $integration->insights_synced_until
            ? CarbonImmutable::parse($integration->insights_synced_until->toDateString())
            : null;
        [$since, $until] = $this->windowFor($mode, null, $syncedUntil);

        $integration->update([
            'insights_sync_status' => self::STATUS_RUNNING,
            'insights_last_run_at' => $now,
        ]);

        $res = $this->metaAds->getAccountCampaignInsightsDaily(
            (string) $integration->access_token,
            $accountId,
            $since,
            $until
        );

        if (! $res['ok']) {
            if ($res['token_invalid']) {
                $integration->update([
                    'status'               => 'expired',
                    'insights_sync_status' => self::STATUS_TOKEN_EXPIRED,
                    'insights_error'       => 'Sessão Meta expirada. Reconecta a conta.',
                ]);

                return ['result' => 'token_expired'];
            }

            if (! empty($res['retryable'])) {
                // A Meta pediu para abrandar: não é falha definitiva. Fica pendente e
                // o job volta a tentar mais tarde (não mostra "falhou" no ecrã).
                $integration->update([
                    'insights_sync_status' => self::STATUS_PENDING,
                    'insights_error'       => 'A Meta pediu para abrandar; nova tentativa em breve.',
                ]);

                return ['result' => 'retryable', 'error' => $res['error']];
            }

            $integration->update([
                'insights_sync_status' => self::STATUS_FAILED,
                'insights_error'       => mb_substr((string) $res['error'], 0, 500),
            ]);

            return ['result' => 'failed', 'error' => $res['error']];
        }

        // Corrida: a conta pode ter mudado enquanto a Meta respondia (ex.: o
        // utilizador corrigiu um ID errado a meio do 1.º backfill). Se mudou, NÃO
        // escrever dados da conta antiga nem marcar backfill como feito — o
        // backfill da conta nova já foi pedido por onAccountChanged.
        $currentAccount = self::normalizeAccountId(
            CompanyIntegration::whereKey($integration->id)->value('account_id')
        );
        if ($currentAccount !== $accountId) {
            Log::info('MetaAccountInsights: conta mudou durante o sync — resultado descartado', [
                'company_id' => $integration->company_id,
                'fetched'    => $accountId,
                'current'    => $currentAccount,
            ]);

            return ['result' => 'account_changed'];
        }

        $this->replaceWindow($integration->company_id, $accountId, $since, $until, $res['rows']);

        $integration->update([
            'insights_sync_status'   => self::STATUS_DONE,
            'insights_backfilled_at' => $mode === self::MODE_BACKFILL
                ? $now
                : ($integration->insights_backfilled_at ?? $now),
            'insights_synced_at'     => $now,
            'insights_synced_until'  => $until,
            'insights_error'         => null,
            'last_synced_at'         => $now,
        ]);

        Log::info('MetaAccountInsights: sync concluído', [
            'company_id' => $integration->company_id,
            'mode'       => $mode,
            'since'      => $since,
            'until'      => $until,
            'rows'       => count($res['rows']),
        ]);

        return ['result' => 'done', 'mode' => $mode, 'since' => $since, 'until' => $until, 'rows' => count($res['rows'])];
    }

    /** Substitui as linhas da empresa+conta na janela (transacção). */
    private function replaceWindow(int $companyId, string $accountId, string $since, string $until, array $rows): void
    {
        DB::transaction(function () use ($companyId, $accountId, $since, $until, $rows) {
            MetaAccountInsightDaily::where('company_id', $companyId)
                ->where('account_id', $accountId)
                ->whereBetween('date', [$since, $until])
                ->delete();

            // Agrega duplicados defensivamente (mesma data+campanha).
            $byKey = [];
            foreach ($rows as $r) {
                if ($r['date'] < $since || $r['date'] > $until) {
                    continue;
                }
                $k = $r['date'] . '|' . $r['campaign_id'];
                if (! isset($byKey[$k])) {
                    $byKey[$k] = $r;
                } else {
                    $byKey[$k]['spend'] += $r['spend'];
                    $byKey[$k]['impressions'] += $r['impressions'];
                    $byKey[$k]['clicks'] += $r['clicks'];
                }
            }

            $now = now();
            $insert = array_map(fn ($r) => [
                'company_id'    => $companyId,
                'account_id'    => $accountId,
                'date'          => $r['date'],
                'campaign_id'   => $r['campaign_id'],
                'campaign_name' => $r['campaign_name'] !== null ? mb_substr($r['campaign_name'], 0, 255) : null,
                'spend'         => round((float) $r['spend'], 2),
                'impressions'   => (int) $r['impressions'],
                'clicks'        => (int) $r['clicks'],
                'created_at'    => $now,
                'updated_at'    => $now,
            ], array_values($byKey));

            foreach (array_chunk($insert, 500) as $chunk) {
                MetaAccountInsightDaily::insert($chunk);
            }
        });
    }
}
