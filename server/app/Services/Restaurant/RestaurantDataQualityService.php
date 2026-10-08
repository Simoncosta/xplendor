<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\CmReservationShiftSummary;
use App\Models\CmReservationStatusDaily;
use App\Models\PingwinItemSale;
use App\Models\PingwinItemSalesDay;
use App\Models\PingwinLocation;
use App\Models\RestaurantDataQuality;
use App\Models\RestaurantFamilyCategory;
use App\Services\CoverManagerService;
use App\Services\PingwinItemHistoryService;
use App\Services\PingwinItemSalesService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — F1-3: retrato da qualidade dos dados de restauração de uma empresa, para o
 * cartão "Dados para o marketing" (documents/PINGWIN-F1-DESENHO.md §5):
 *  · por loja: abertura (manual), início detetado, início efetivo (a abertura manda quando
 *    preenchida, decisão 1) e aviso quando diferem mais de 7 dias; dias lidos; histórico;
 *  · conferência com o líquido diário nos últimos 90 dias;
 *  · cobertura do catálogo (artigos vendidos nos últimos 90 dias que estão no catálogo);
 *  · famílias com vendas (todo o histórico) por confirmar e o seu peso na faturação;
 *  · reservas do CoverManager com um código de estado fora do mapa ("por classificar").
 */
class RestaurantDataQualityService
{
    public const WINDOW_DAYS = 90;
    /** Diferença (dias) entre a abertura manual e o início detetado que dá aviso. */
    public const START_WARNING_DAYS = 7;

    public function compute(int $companyId): RestaurantDataQuality
    {
        $from = CarbonImmutable::today()->subDays(self::WINDOW_DAYS)->toDateString();

        $sold = PingwinItemHistoryService::soldProductIds($companyId);
        $missing = PingwinItemHistoryService::missingSoldProductIds($companyId);

        $days = PingwinItemSalesDay::where('company_id', $companyId)->where('business_date', '>=', $from)
            ->select('status', DB::raw('COUNT(*) as n'))->groupBy('status')->pluck('n', 'status');
        $marked = 0;
        foreach (PingwinItemSalesDay::STATUSES_TO_REREAD as $status) {
            $marked += (int) ($days[$status] ?? 0);
        }

        // Famílias: todo o histórico (como a página "Categorias das famílias").
        $revenue = PingwinItemSale::where('company_id', $companyId)
            ->whereNotNull('family_pingwin_id')
            ->select('family_pingwin_id', DB::raw('SUM(net_cents) as net'))->groupBy('family_pingwin_id')
            ->pluck('net', 'family_pingwin_id');
        $confirmed = RestaurantFamilyCategory::where('company_id', $companyId)->whereNotNull('category')
            ->pluck('family_pingwin_id')->flip();
        $total = (int) $revenue->sum();
        $unconfirmed = $revenue->filter(fn ($net, $family) => ! $confirmed->has((string) $family));

        return RestaurantDataQuality::updateOrCreate(['company_id' => $companyId], [
            'catalog_sold_count' => count($sold),
            'catalog_missing_count' => count($missing),
            'catalog_coverage_pct' => $sold === [] ? null : round((count($sold) - count($missing)) / count($sold) * 100, 1),
            'days_checked' => (int) $days->sum(),
            'days_ok' => (int) ($days[PingwinItemSalesDay::STATUS_OK] ?? 0),
            'days_marked' => $marked,
            'families_total' => $revenue->count(),
            'families_unconfirmed' => $unconfirmed->count(),
            'revenue_unconfirmed_pct' => $total > 0 ? round((int) $unconfirmed->sum() / $total * 100, 1) : null,
            'locations' => $this->locations($companyId),
            'computed_at' => now(),
        ]);
    }

    /** Estado por loja ativa. */
    private function locations(int $companyId): array
    {
        return PingwinLocation::where('company_id', $companyId)->where('is_active', true)->orderBy('id')->get()
            ->map(function (PingwinLocation $l) {
                $detected = $l->sales_since ?? $l->sales_first_month;
                $opened = $l->opened_on;
                $diff = ($opened && $detected) ? abs($opened->diffInDays($detected)) : null;
                $read = PingwinItemSalesDay::where('location_id', $l->id);

                return [
                    'location_id' => $l->id,
                    'name' => $l->display_name ?: $l->winrest_name ?: (string) $l->winrest_store_id,
                    'opened_on' => $opened?->toDateString(),
                    'detected_start' => $detected?->toDateString(),
                    'detected_is_month' => $l->sales_since === null && $l->sales_first_month !== null,
                    'effective_start' => ($opened ?? $detected)?->toDateString(),
                    'start_warning' => $diff !== null && $diff > self::START_WARNING_DAYS,
                    'start_difference_days' => $diff !== null ? (int) $diff : null,
                    'start_checked' => $l->sales_start_checked_at !== null,
                    'days_read' => (clone $read)->count(),
                    'oldest_day_read' => ($oldest = (clone $read)->min('business_date')) ? substr((string) $oldest, 0, 10) : null,
                    'history_complete' => $l->history_complete_at !== null,
                    'history_complete_at' => $l->history_complete_at?->toIso8601String(),
                ];
            })->values()->all();
    }

    /** Resposta do cartão: interruptor + retrato atual. */
    public function card(int $companyId): array
    {
        $q = $this->compute($companyId);
        $lastRead = PingwinItemSalesDay::where('company_id', $companyId)->max('synced_at');

        return [
            'enabled' => PingwinItemSalesService::isEnabled($companyId),
            'last_read_at' => $lastRead ? CarbonImmutable::parse($lastRead)->toIso8601String() : null,
            'catalog' => ['sold' => $q->catalog_sold_count, 'missing' => $q->catalog_missing_count, 'coverage_pct' => $q->catalog_coverage_pct],
            'days' => ['checked' => $q->days_checked, 'ok' => $q->days_ok, 'marked' => $q->days_marked],
            'families' => ['total' => $q->families_total, 'unconfirmed' => $q->families_unconfirmed, 'revenue_unconfirmed_pct' => $q->revenue_unconfirmed_pct],
            'locations' => $q->locations ?? [],
            'reservations' => $this->unclassifiedReservations($companyId),
            'computed_at' => $q->computed_at?->toIso8601String(),
        ];
    }

    /**
     * Reservas com um código de estado fora do mapa nos últimos 90 dias (não contam nas
     * válidas nem nas anuladas). Os códigos vêm dos agregados por código, que só existem
     * com o interruptor ligado; o total vem do resumo por turno, que existe sempre.
     */
    private function unclassifiedReservations(int $companyId): array
    {
        $from = CarbonImmutable::today()->subDays(self::WINDOW_DAYS)->toDateString();
        $codes = CmReservationStatusDaily::where('company_id', $companyId)->where('business_date', '>=', $from)
            ->whereNotIn('status_code', array_map('strval', array_keys(CoverManagerService::STATUS_MAP)))
            ->select('status_code', DB::raw('SUM(reservations_count) as n'))->groupBy('status_code')
            ->orderByDesc('n')->pluck('n', 'status_code')->map(fn ($n) => (int) $n)->all();

        return [
            'days' => self::WINDOW_DAYS,
            'unclassified' => (int) CmReservationShiftSummary::where('company_id', $companyId)->where('business_date', '>=', $from)->sum('unclassified_count'),
            'codes' => $codes,
        ];
    }
}
