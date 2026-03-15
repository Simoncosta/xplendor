<?php

namespace App\Repositories;

use App\Models\CarPerformanceMetric;
use App\Repositories\Contracts\CarPerformanceMetricRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CarPerformanceMetricRepository implements CarPerformanceMetricRepositoryInterface
{
    // ── Leitura ───────────────────────────────────────────────────────────────

    /**
     * Todos os registos de um carro, opcionalmente filtrados.
     */
    public function getForCar(
        int $carId,
        int $companyId,
        ?string $channel = null,
        ?string $from    = null,
        ?string $to      = null
    ): Collection {
        return CarPerformanceMetric::query()
            ->forCompany($companyId)
            ->forCar($carId)
            ->when($channel, fn($q) => $q->forChannel($channel))
            ->when($from && $to, fn($q) => $q->inPeriod($from, $to))
            ->orderBy('period_start', 'desc')
            ->get();
    }

    /**
     * Resumo agregado por carro — usado pelos dashboards.
     *
     * Retorna:
     *   total_spend, total_impressions, total_clicks, total_sessions,
     *   total_leads, avg_ctr, avg_cpc, avg_cost_per_lead,
     *   avg_conversion_rate, gross_margin, roi
     */
    public function getSummary(int $carId, int $companyId): array
    {
        $raw = CarPerformanceMetric::query()
            ->forCompany($companyId)
            ->forCar($carId)
            ->selectRaw('
                SUM(spend_amount)           AS total_spend,
                SUM(impressions)            AS total_impressions,
                SUM(clicks)                 AS total_clicks,
                SUM(sessions)               AS total_sessions,
                SUM(leads_count)            AS total_leads,
                SUM(interactions_count)     AS total_interactions,
                SUM(whatsapp_clicks)        AS total_whatsapp_clicks,
                SUM(phone_clicks)           AS total_phone_clicks,
                AVG(ctr)                    AS avg_ctr,
                AVG(cpc)                    AS avg_cpc,
                AVG(cost_per_lead)          AS avg_cost_per_lead,
                AVG(conversion_rate)        AS avg_conversion_rate,
                MIN(time_to_first_lead_hours) AS time_to_first_lead_hours,
                MAX(time_to_sale_days)      AS time_to_sale_days,
                MAX(sale_price)             AS sale_price,
                MAX(purchase_price)         AS purchase_price,
                MAX(gross_margin)           AS gross_margin,
                MAX(roi)                    AS roi
            ')
            ->first()
            ->toArray();

        $totalSpend        = (float) ($raw['total_spend']            ?? 0);
        $totalLeads        = (int)   ($raw['total_leads']            ?? 0);
        $totalClicks       = (int)   ($raw['total_clicks']           ?? 0);
        $totalSessions     = (int)   ($raw['total_sessions']         ?? 0);
        $totalInteractions = (int)   ($raw['total_interactions']     ?? 0);
        $totalWhatsapp     = (int)   ($raw['total_whatsapp_clicks']  ?? 0);
        $totalPhone        = (int)   ($raw['total_phone_clicks']     ?? 0);

        // Recalcular métricas de custo a partir dos totais (mais fiável que AVG de AVGs)
        $raw['avg_cpc']             = $totalClicks   > 0 ? round($totalSpend / $totalClicks, 4)              : null;
        $raw['avg_cost_per_lead']   = $totalLeads    > 0 ? round($totalSpend / $totalLeads, 2)               : null;
        $raw['avg_conversion_rate'] = $totalSessions > 0 ? round(($totalLeads / $totalSessions) * 100, 2)    : null;

        // Taxa de engajamento ponderada por intenção:
        // Interações de contacto (WhatsApp + telefone) pesam 1.5×
        // Leads pesam 3× (já converteram)
        // Interações soft (favorito, partilha...) = interactions - whatsapp - phone, pesam 0.5×
        $softInteractions  = max(0, $totalInteractions - $totalWhatsapp - $totalPhone);
        $weightedScore     = ($totalLeads * 3) + (($totalWhatsapp + $totalPhone) * 1.5) + ($softInteractions * 0.5);
        $raw['weighted_engagement_rate'] = $totalSessions > 0
            ? round(($weightedScore / $totalSessions) * 100, 2)
            : null;

        // Custo por interação de intenção (WhatsApp + telefone + lead)
        $totalIntent = $totalLeads + $totalWhatsapp + $totalPhone;
        $raw['cost_per_intent'] = ($totalSpend > 0 && $totalIntent > 0)
            ? round($totalSpend / $totalIntent, 2)
            : null;

        return $raw;
    }

    /**
     * Resumo agrupado por canal — para o gráfico de distribuição.
     */
    public function getSummaryByChannel(int $carId, int $companyId): Collection
    {
        return CarPerformanceMetric::query()
            ->forCompany($companyId)
            ->forCar($carId)
            ->selectRaw('
                channel,
                SUM(spend_amount)           AS total_spend,
                SUM(impressions)            AS total_impressions,
                SUM(clicks)                 AS total_clicks,
                SUM(sessions)               AS total_sessions,
                SUM(leads_count)            AS total_leads,
                SUM(interactions_count)     AS total_interactions,
                SUM(whatsapp_clicks)        AS total_whatsapp_clicks,
                SUM(phone_clicks)           AS total_phone_clicks,
                AVG(ctr)                    AS avg_ctr,
                AVG(conversion_rate)        AS avg_conversion_rate
            ')
            ->groupBy('channel')
            ->orderByDesc('total_interactions') // ordenar por intenção, não por spend
            ->get()
            ->map(function ($row) {
                // Adicionar weighted_engagement_rate por canal
                $sessions    = (int) $row->total_sessions;
                $leads       = (int) $row->total_leads;
                $whatsapp    = (int) $row->total_whatsapp_clicks;
                $phone       = (int) $row->total_phone_clicks;
                $interactions = (int) $row->total_interactions;
                $soft        = max(0, $interactions - $whatsapp - $phone);

                $weighted = ($leads * 3) + (($whatsapp + $phone) * 1.5) + ($soft * 0.5);
                $row->weighted_engagement_rate = $sessions > 0
                    ? round(($weighted / $sessions) * 100, 2)
                    : null;

                return $row;
            });
    }

    // ── Escrita ───────────────────────────────────────────────────────────────

    /**
     * Criar registo manual.
     * Campos calculados são ignorados aqui — o Observer trata deles.
     */
    public function create(array $data): CarPerformanceMetric
    {
        return CarPerformanceMetric::create($data);
    }

    /**
     * Atualizar apenas campos manuais permitidos.
     * Campos calculados são recalculados automaticamente pelo Observer.
     */
    public function update(CarPerformanceMetric $metric, array $data): CarPerformanceMetric
    {
        $allowed = [
            'spend_amount',
            'purchase_price',
            'sale_price',
            'cost_per_sale',
            'impressions',
            'clicks',
            'sessions',
            'leads_count',
            'notes',
            'data_source',
        ];

        $metric->update(array_intersect_key($data, array_flip($allowed)));

        return $metric->fresh();
    }

    /**
     * Buscar registo específico garantindo scope de company.
     */
    public function findForCompany(int $metricId, int $companyId): ?CarPerformanceMetric
    {
        return CarPerformanceMetric::where('id', $metricId)
            ->where('company_id', $companyId)
            ->first();
    }

    /**
     * Registos que precisam de revisão manual.
     */
    public function getPendingReview(int $companyId): Collection
    {
        return CarPerformanceMetric::query()
            ->forCompany($companyId)
            ->requiresReview()
            ->with('car:id,car_brand_id,car_model_id')
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
