<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CompanyIntegration;
use App\Services\MetaAccountInsightsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Meta (Facebook/Instagram Ads), LEITURA. RE-EXPÕE os dados que os
 * pipelines JÁ ingerem — NÃO chama a Graph API aqui (zero fetch novo, zero escrita).
 * Só agrega o que está na BD:
 *   · meta_account_insights_daily — gasto/impressões/cliques por dia+campanha AO NÍVEL
 *                                   DA CONTA (todas as verticais) — fonte principal;
 *   · campaign_car_metrics_daily  — pipeline por carro (rede de transição p/ stands
 *                                   ainda sem backfill da conta);
 *   · car_ad_campaigns          — nomes das campanhas;
 *   · car_sale_attributions     — vendas atribuídas a campanhas Meta (já calculadas).
 *
 * Factual, sem interpretação/IA. Guard tenant de 2 camadas (scoped por company_id).
 */
class MetaInsightsController extends Controller
{
    private function authorizeCompanyAccess(int $companyId): bool
    {
        $user = Auth::user();

        return $user->company_id === $companyId || $user->role === 'root';
    }

    public function overview(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', 'meta')
            ->first();

        $connected = $integration && $integration->status !== 'revoked';

        $days = max(1, min((int) $request->query('days', 28), 365));
        $end = CarbonImmutable::today();
        $start = $end->subDays($days - 1);
        $from = $start->toDateString();
        $to = $end->toDateString();

        $accountId = MetaAccountInsightsService::normalizeAccountId($integration?->account_id);
        $backfilled = $integration?->insights_backfilled_at !== null;

        // ── FONTE dos dados ──
        //   'account'    → ingestão ao NÍVEL DA CONTA (todas as verticais), assim que o
        //                  1.º backfill concluiu para a conta actual;
        //   'car_legacy' → rede de transição: stands que ainda não fizeram o backfill
        //                  continuam a ver o pipeline por carro (não ficam a zero);
        //   'none'       → nada para mostrar (o `state` explica porquê).
        if ($connected && $accountId !== null && $backfilled) {
            $source = 'account';
        } elseif (DB::table('campaign_car_metrics_daily')->where('company_id', $companyId)->exists()) {
            $source = 'car_legacy';
        } else {
            $source = 'none';
        }

        // Colunas normalizadas por fonte: spend / impressions / clicks / campaign_id / date.
        $spendCol = $source === 'account' ? 'spend' : 'spend_normalized';
        $base = function () use ($source, $companyId, $accountId, $from, $to) {
            if ($source === 'account') {
                return DB::table('meta_account_insights_daily')
                    ->where('company_id', $companyId)
                    ->where('account_id', $accountId)
                    ->whereBetween('date', [$from, $to]);
            }
            // car_legacy (e 'none' devolve vazio pela mesma via — sem linhas).
            return DB::table('campaign_car_metrics_daily')
                ->where('company_id', $companyId)
                ->whereBetween('date', [$from, $to]);
        };

        $tot = $base()->selectRaw("COALESCE(SUM({$spendCol}),0) spend, COALESCE(SUM(impressions),0) impressions, COALESCE(SUM(clicks),0) clicks")->first();
        $spend = (float) ($tot->spend ?? 0);
        $impressions = (int) ($tot->impressions ?? 0);
        $clicks = (int) ($tot->clicks ?? 0);

        $overview = [
            'spend' => round($spend, 2),
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0.0,  // %
            'cpc' => $clicks > 0 ? round($spend / $clicks, 2) : 0.0,                    // €/clique
        ];

        // ── Por campanha ──
        // Nomes: os da ingestão ao nível da conta (vêm da Meta) têm prioridade; os do
        // mapping por carro servem o legado e as vendas atribuídas.
        $names = DB::table('car_ad_campaigns')
            ->where('company_id', $companyId)
            ->pluck('campaign_name', 'campaign_id')
            ->all();
        $accountNames = DB::table('meta_account_insights_daily')
            ->where('company_id', $companyId)
            ->whereNotNull('campaign_name')
            ->when($accountId !== null, fn ($q) => $q->where('account_id', $accountId))
            ->selectRaw('campaign_id, MAX(campaign_name) campaign_name')
            ->groupBy('campaign_id')
            ->pluck('campaign_name', 'campaign_id')
            ->all();
        $names = $accountNames + $names;

        $byCampaign = $base()
            ->selectRaw("campaign_id, COALESCE(SUM({$spendCol}),0) spend, COALESCE(SUM(impressions),0) impressions, COALESCE(SUM(clicks),0) clicks")
            ->groupBy('campaign_id')
            ->orderByDesc('spend')
            ->limit(50)
            ->get()
            ->map(function ($r) use ($names) {
                $imp = (int) $r->impressions;
                $clk = (int) $r->clicks;
                return [
                    'campaign_id' => $r->campaign_id,
                    'campaign_name' => $names[$r->campaign_id] ?? ('Campanha ' . $r->campaign_id),
                    'spend' => round((float) $r->spend, 2),
                    'impressions' => $imp,
                    'clicks' => $clk,
                    'ctr' => $imp > 0 ? round($clk / $imp * 100, 2) : 0.0,
                ];
            });

        // ── Tendência diária (gasto + cliques) ──
        $trend = $base()
            ->selectRaw("date, COALESCE(SUM({$spendCol}),0) spend, COALESCE(SUM(clicks),0) clicks")
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date' => (string) $r->date,
                'spend' => round((float) $r->spend, 2),
                'clicks' => (int) $r->clicks,
            ]);

        // ── Vendas ATRIBUÍDAS a campanhas Meta (já calculadas pelo motor) ──
        $attr = DB::table('car_sale_attributions')
            ->where('company_id', $companyId)
            ->where('attributed_platform', 'meta')
            ->whereBetween('sold_at', [$start->startOfDay(), $end->endOfDay()]);

        $attrTot = (clone $attr)->selectRaw('COUNT(*) sales, COALESCE(SUM(sale_price),0) revenue, AVG(confidence_score) conf')->first();
        $attributed = [
            'sales' => (int) ($attrTot->sales ?? 0),
            'revenue' => round((float) ($attrTot->revenue ?? 0), 2),
            'avg_confidence' => $attrTot->conf !== null ? round((float) $attrTot->conf, 1) : null,
            'by_campaign' => (clone $attr)
                ->selectRaw('attributed_campaign_id, COUNT(*) sales, COALESCE(SUM(sale_price),0) revenue')
                ->groupBy('attributed_campaign_id')
                ->orderByDesc('revenue')
                ->limit(50)
                ->get()
                ->map(fn ($r) => [
                    'campaign_id' => $r->attributed_campaign_id,
                    'campaign_name' => $names[$r->attributed_campaign_id] ?? ('Campanha ' . $r->attributed_campaign_id),
                    'sales' => (int) $r->sales,
                    'revenue' => round((float) $r->revenue, 2),
                ]),
        ];

        // ── ESTADO honesto (o ecrã só reflecte) — por ordem de precedência ──
        $tokenExpired = $integration !== null && (
            $integration->status === 'expired'
            || $integration->isTokenExpired()
            || $integration->insights_sync_status === MetaAccountInsightsService::STATUS_TOKEN_EXPIRED
        );
        if (! $connected) {
            $state = 'not_connected';
        } elseif ($tokenExpired) {
            $state = 'token_expired';          // "Sessão Meta expirada — reconectar"
        } elseif ($accountId === null) {
            $state = 'needs_account';          // "Falta escolher a conta de anúncios"
        } elseif ($integration->insights_sync_status === MetaAccountInsightsService::STATUS_FAILED) {
            // Sync a falhar (1.º ou diário): NUNCA mostrar "sem gasto" — os números
            // podem estar só desactualizados. Os dados antigos (se houver) ficam
            // visíveis com o aviso (source continua 'account').
            $state = 'sync_failed';
        } elseif (! $backfilled) {
            $state = 'syncing_first';          // "A sincronizar pela primeira vez…"
        } else {
            $state = ($spend <= 0 && $impressions <= 0) ? 'no_spend' : 'ok'; // zero REAL vs dados
        }

        return ApiResponse::success([
            'connected' => $connected,
            'state' => $state,
            'source' => $source,
            'account_id' => $accountId,
            'sync' => [
                'status' => $integration?->insights_sync_status,
                'backfilled_at' => optional($integration?->insights_backfilled_at)->toIso8601String(),
                'last_run_at' => optional($integration?->insights_last_run_at)->toIso8601String(),
                // Erro PRÓPRIO do sync por conta (error_message é partilhado com o
                // job por carro, que o repõe a cada 30 min).
                'error' => $integration?->insights_error,
            ],
            'status' => $integration->status ?? null,
            // "Último sync" do pipeline que alimenta os números mostrados.
            'last_synced_at' => optional(
                $source === 'account' ? $integration?->insights_synced_at : $integration?->last_synced_at
            )->toIso8601String(),
            'range' => ['start' => $from, 'end' => $to, 'days' => $days],
            'overview' => $overview,
            'by_campaign' => $byCampaign,
            'trend' => $trend,
            'attributed' => $attributed,
        ], 'Meta insights fetched successfully.');
    }
}
