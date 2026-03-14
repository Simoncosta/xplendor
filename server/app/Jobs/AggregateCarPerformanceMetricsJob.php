<?php

namespace App\Jobs;

use App\Models\Car;
use App\Models\CarPerformanceMetric;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AggregateCarPerformanceMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    private Carbon $periodStart;
    private Carbon $periodEnd;

    /**
     * Se não passar datas, agrega o dia anterior (execução diária às 00:30).
     * Para executar manualmente com um período específico:
     *   AggregateCarPerformanceMetricsJob::dispatch('2026-03-01', '2026-03-13');
     */
    public function __construct(
        ?string $periodStart = null,
        ?string $periodEnd   = null,
    ) {
        $this->periodStart = $periodStart
            ? Carbon::parse($periodStart)->startOfDay()
            : Carbon::yesterday()->startOfDay();

        $this->periodEnd = $periodEnd
            ? Carbon::parse($periodEnd)->endOfDay()
            : Carbon::yesterday()->endOfDay();
    }

    public function handle(): void
    {
        Log::info('[AggregateCarPerformanceMetrics] Iniciando', [
            'period_start' => $this->periodStart->toDateString(),
            'period_end'   => $this->periodEnd->toDateString(),
        ]);

        $processed = 0;

        // Busca todos os carros activos com views ou leads no período
        $carIds = $this->getActiveCarsInPeriod();

        foreach ($carIds as $row) {
            $this->processCarCompany((int) $row->car_id, (int) $row->company_id);
            $processed++;
        }

        Log::info('[AggregateCarPerformanceMetrics] Concluído', [
            'carros_processados' => $processed,
        ]);
    }

    // ── Core ──────────────────────────────────────────────────────────────────

    private function processCarCompany(int $carId, int $companyId): void
    {
        $viewsByChannel        = $this->aggregateViews($carId);
        $leadsByChannel        = $this->aggregateLeads($carId);
        $interactionsByChannel = $this->aggregateInteractions($carId);

        $channels = collect($viewsByChannel)->keys()
            ->merge(collect($leadsByChannel)->keys())
            ->merge(collect($interactionsByChannel)->keys())
            ->unique();

        foreach ($channels as $channel) {
            $views        = $viewsByChannel[$channel]        ?? ['sessions' => 0];
            $leads        = $leadsByChannel[$channel]        ?? ['leads_count' => 0];
            $interactions = $interactionsByChannel[$channel] ?? ['interactions_count' => 0, 'whatsapp_clicks' => 0, 'phone_clicks' => 0];

            $this->upsertMetric(
                carId: $carId,
                companyId: $companyId,
                channel: $channel,
                sessions: (int) $views['sessions'],
                leadsCount: (int) $leads['leads_count'],
                interactionsCount: (int) $interactions['interactions_count'],
                whatsappClicks: (int) $interactions['whatsapp_clicks'],
                phoneClicks: (int) $interactions['phone_clicks'],
            );
        }
    }

    // ── Agregação de views ────────────────────────────────────────────────────

    private function aggregateViews(int $carId): array
    {
        $rows = DB::table('car_views')
            ->select([
                DB::raw("COALESCE(channel, 'direct') AS channel"),
                DB::raw('COUNT(*) AS sessions'),
                DB::raw('COUNT(DISTINCT session_id) AS unique_visitors'),
            ])
            ->where('car_id', $carId)
            ->whereBetween('created_at', [$this->periodStart, $this->periodEnd])
            ->groupBy('channel')
            ->get();

        return $rows->keyBy('channel')->map(fn($r) => [
            'sessions'        => $r->sessions,
            'unique_visitors' => $r->unique_visitors,
        ])->toArray();
    }

    // ── Agregação de leads ────────────────────────────────────────────────────

    private function aggregateLeads(int $carId): array
    {
        $rows = DB::table('car_leads')
            ->select([
                DB::raw("COALESCE(channel, 'direct') AS channel"),
                DB::raw('COUNT(*) AS leads_count'),
            ])
            ->where('car_id', $carId)
            ->whereBetween('created_at', [$this->periodStart, $this->periodEnd])
            ->groupBy('channel')
            ->get();

        return $rows->keyBy('channel')->map(fn($r) => [
            'leads_count' => $r->leads_count,
        ])->toArray();
    }

    // ── Agregação de interações ───────────────────────────────────────────────

    private function aggregateInteractions(int $carId): array
    {
        $rows = DB::table('car_interactions')
            ->select([
                DB::raw("COALESCE(channel, 'direct') AS channel"),
                DB::raw('COUNT(*) AS interactions_count'),
                DB::raw("SUM(CASE WHEN interaction_type = 'whatsapp_click' THEN 1 ELSE 0 END) AS whatsapp_clicks"),
                DB::raw("SUM(CASE WHEN interaction_type IN ('call_click','show_phone','copy_phone') THEN 1 ELSE 0 END) AS phone_clicks"),
            ])
            ->where('car_id', $carId)
            ->whereBetween('created_at', [$this->periodStart, $this->periodEnd])
            ->groupBy('channel')
            ->get();

        return $rows->keyBy('channel')->map(fn($r) => [
            'interactions_count' => $r->interactions_count,
            'whatsapp_clicks'    => $r->whatsapp_clicks,
            'phone_clicks'       => $r->phone_clicks,
        ])->toArray();
    }

    // ── Upsert ────────────────────────────────────────────────────────────────

    private function upsertMetric(
        int    $carId,
        int    $companyId,
        string $channel,
        int    $sessions,
        int    $leadsCount,
        int    $interactionsCount = 0,
        int    $whatsappClicks    = 0,
        int    $phoneClicks       = 0,
    ): void {
        $existing = CarPerformanceMetric::where('car_id', $carId)
            ->where('company_id', $companyId)
            ->where('channel', $channel)
            ->whereDate('period_start', $this->periodStart->toDateString())
            ->whereDate('period_end',   $this->periodEnd->toDateString())
            ->first();

        $behavioralData = [
            'sessions'            => $sessions,
            'leads_count'         => $leadsCount,
            'interactions_count'  => $interactionsCount,
            'whatsapp_clicks'     => $whatsappClicks,
            'phone_clicks'        => $phoneClicks,
        ];

        if ($existing) {
            // Nunca sobrescreve spend/impressions/purchase_price inseridos manualmente
            $existing->fill($behavioralData)->save();
        } else {
            CarPerformanceMetric::create([
                ...$behavioralData,
                'car_id'       => $carId,
                'company_id'   => $companyId,
                'channel'      => $channel,
                'period_start' => $this->periodStart->toDateString(),
                'period_end'   => $this->periodEnd->toDateString(),
                'data_source'  => 'calculated',
            ]);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Carros com actividade no período — une views, leads e interactions.
     */
    private function getActiveCarsInPeriod()
    {
        $views = DB::table('car_views')
            ->select('car_id', 'company_id')
            ->whereBetween('created_at', [$this->periodStart, $this->periodEnd]);

        $leads = DB::table('car_leads')
            ->select('car_id', 'company_id')
            ->whereBetween('created_at', [$this->periodStart, $this->periodEnd]);

        return DB::table('car_interactions')
            ->select('car_id', 'company_id')
            ->whereNotNull('car_id')
            ->whereBetween('created_at', [$this->periodStart, $this->periodEnd])
            ->union($views)
            ->union($leads)
            ->get()
            ->unique(fn($r) => $r->car_id . '_' . $r->company_id);
    }
}
