<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CmReservationShiftSummary;
use App\Models\Company;
use App\Models\PingwinDailySale;
use App\Models\PingwinLocation;
use App\Services\PingwinDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Calendário de faturação: por dia, faturação + pessoas + ticket médio;
 * filtro por loja (default todas); dias sem dados não aparecem (portão de
 * honestidade); ticket só com faturação E pessoas.
 */
class PingwinCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private PingwinLocation $a;
    private PingwinLocation $b;
    private PingwinDashboardService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500011100', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->a = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => 'A', 'display_name' => 'Baixa', 'is_active' => true]);
        $this->b = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => 'B', 'display_name' => 'Costa', 'is_active' => true]);
        $this->svc = app(PingwinDashboardService::class);
    }

    private function sale(PingwinLocation $l, string $date, int $invoiced): void
    {
        PingwinDailySale::create(['company_id' => $this->company->id, 'location_id' => $l->id, 'business_date' => $date, 'invoiced_cents' => $invoiced, 'net_cents' => $invoiced, 'synced_at' => now()]);
    }

    private function guests(PingwinLocation $l, string $date, int $g): void
    {
        CmReservationShiftSummary::create(['company_id' => $this->company->id, 'location_id' => $l->id, 'business_date' => $date, 'shift' => 'dinner', 'guests_total' => $g, 'reservations_count' => 1, 'synced_at' => now()]);
    }

    public function test_calendar_sums_all_locations_per_day_with_ticket(): void
    {
        $this->sale($this->a, '2026-06-10', 10000);
        $this->sale($this->b, '2026-06-10', 6000);
        $this->guests($this->a, '2026-06-10', 20);
        $this->guests($this->b, '2026-06-10', 12);

        $days = $this->svc->calendar($this->company->id, 2026, 6);
        $this->assertCount(1, $days);
        $day = $days[0];
        $this->assertSame('2026-06-10', $day['date']);
        $this->assertSame(16000, $day['invoiced_cents']); // soma das 2 lojas
        $this->assertSame(32, $day['guests']);            // 20 + 12
        $this->assertSame(500, $day['avg_ticket_cents']); // 16000 / 32
    }

    public function test_calendar_filter_by_location(): void
    {
        $this->sale($this->a, '2026-06-10', 10000);
        $this->sale($this->b, '2026-06-10', 6000);
        $this->guests($this->a, '2026-06-10', 20);

        $days = $this->svc->calendar($this->company->id, 2026, 6, $this->a->id);
        $this->assertSame(10000, $days[0]['invoiced_cents']); // só a loja A
        $this->assertSame(20, $days[0]['guests']);
        $this->assertSame(500, $days[0]['avg_ticket_cents']);
    }

    public function test_days_without_data_are_absent_and_ticket_needs_both(): void
    {
        // Dia com vendas mas SEM reservas → aparece, mas ticket null.
        $this->sale($this->a, '2026-06-10', 10000);
        // Dia sem nada (2026-06-11) → não aparece.

        $days = $this->svc->calendar($this->company->id, 2026, 6);
        $this->assertCount(1, $days);
        $this->assertSame('2026-06-10', $days[0]['date']);
        $this->assertNull($days[0]['guests']);
        $this->assertNull($days[0]['avg_ticket_cents']); // requer faturação E pessoas
    }

    public function test_calendar_respects_opened_on(): void
    {
        $this->a->update(['opened_on' => '2026-06-05']);
        $this->sale($this->a, '2026-06-04', 9999); // pré-abertura → fora
        $this->sale($this->a, '2026-06-10', 1000);

        $days = collect($this->svc->calendar($this->company->id, 2026, 6))->keyBy('date');
        $this->assertArrayNotHasKey('2026-06-04', $days->all());
        $this->assertSame(1000, $days['2026-06-10']['invoiced_cents']);
    }
}
