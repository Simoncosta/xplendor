<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CompanyIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Meta (Facebook/Instagram Ads), LEITURA. RE-EXPÕE os dados que o
 * pipeline JÁ ingere (a cada 30min) — NÃO chama a Graph API aqui (zero fetch novo,
 * zero escrita). Só agrega o que está na BD:
 *   · campaign_car_metrics_daily — gasto/impressões/cliques/CTR/CPC por dia+campanha;
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

        // ── Métricas diárias (gasto/impressões/cliques) — só desta empresa ──
        $base = fn () => DB::table('campaign_car_metrics_daily')
            ->where('company_id', $companyId)
            ->whereBetween('date', [$from, $to]);

        $tot = $base()->selectRaw('COALESCE(SUM(spend_normalized),0) spend, COALESCE(SUM(impressions),0) impressions, COALESCE(SUM(clicks),0) clicks')->first();
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

        // ── Por campanha (nome resolvido do mapping) ──
        $names = DB::table('car_ad_campaigns')
            ->where('company_id', $companyId)
            ->pluck('campaign_name', 'campaign_id');

        $byCampaign = $base()
            ->selectRaw('campaign_id, COALESCE(SUM(spend_normalized),0) spend, COALESCE(SUM(impressions),0) impressions, COALESCE(SUM(clicks),0) clicks')
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
            ->selectRaw('date, COALESCE(SUM(spend_normalized),0) spend, COALESCE(SUM(clicks),0) clicks')
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

        return ApiResponse::success([
            'connected' => $connected,
            'status' => $integration->status ?? null,
            'last_synced_at' => optional($integration?->last_synced_at)->toIso8601String(),
            'range' => ['start' => $from, 'end' => $to, 'days' => $days],
            'overview' => $overview,
            'by_campaign' => $byCampaign,
            'trend' => $trend,
            'attributed' => $attributed,
        ], 'Meta insights fetched successfully.');
    }
}
