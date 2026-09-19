<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CmReservationShiftSummary;
use App\Models\Company;
use App\Models\PingwinDailySale;
use App\Models\PingwinLocation;
use App\Models\PingwinSyncRun;
use App\Services\PingwinDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — CoverManager Etapa 2: ticket médio = faturação PingWin ÷ pessoas
 * CoverManager (guests_total). Cobre: cálculo diário; agregação SOMA-ANTES-DE-
 * DIVIDIR (não média de médias); guests 0 → null ("—"); requer as duas
 * integrações (cruza por location_id+business_date); flag desligada → não calcula.
 */
class CoverManagerAvgTicketTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private PingwinLocation $loc;
    private PingwinDashboardService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->company = Company::create(['nipc' => '500008100', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->loc = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => 'w1', 'display_name' => 'Yuko', 'is_active' => true]);
        $this->svc = app(PingwinDashboardService::class);
    }

    private function sale(string $date, int $invoiced): void
    {
        PingwinDailySale::create(['company_id' => $this->company->id, 'location_id' => $this->loc->id, 'business_date' => $date, 'invoiced_cents' => $invoiced, 'net_cents' => $invoiced, 'synced_at' => now()]);
        PingwinSyncRun::create(['company_id' => $this->company->id, 'business_date' => $date, 'status' => 'success', 'synced_at' => now()]);
    }

    private function guests(string $date, int $guests, string $shift = 'dinner'): void
    {
        CmReservationShiftSummary::create(['company_id' => $this->company->id, 'location_id' => $this->loc->id, 'business_date' => $date, 'shift' => $shift, 'guests_total' => $guests, 'reservations_count' => 1, 'synced_at' => now()]);
    }

    public function test_daily_avg_ticket_is_invoiced_divided_by_guests(): void
    {
        $this->sale('2026-06-10', 10000); // €100,00
        $this->guests('2026-06-10', 20);  // 20 pessoas
        $d = $this->svc->build($this->company->id, '2026-06-10');
        // 10000 / 20 = 500 cêntimos (€5,00)
        $this->assertSame(500, $d['avg_ticket']['daily']);
    }

    public function test_monthly_annual_sum_before_divide_not_average_of_averages(): void
    {
        // Dia 1: 10000 / 100 = 100 c;  Dia 2: 20000 / 100 = 200 c.
        // Média de médias daria (100+200)/2 = 150. O CORRETO: (30000)/(200) = 150?
        // Escolho números que EXPÕEM a diferença:
        // Dia 1: 10000 / 100 pessoas;  Dia 2: 90000 / 300 pessoas.
        // Média de médias: (100 + 300)/2 = 200 c. SOMA-antes: 100000/400 = 250 c.
        $this->sale('2026-06-01', 10000); $this->guests('2026-06-01', 100);
        $this->sale('2026-06-10', 90000); $this->guests('2026-06-10', 300);

        $d = $this->svc->build($this->company->id, '2026-06-10');
        // SUM(invoiced)=100000 ÷ SUM(guests)=400 = 250 c (não 200 da média de médias).
        $this->assertSame(250, $d['avg_ticket']['monthly']);
        $this->assertSame(250, $d['avg_ticket']['annual']);
    }

    public function test_zero_guests_gives_null_no_division_by_zero(): void
    {
        $this->sale('2026-06-10', 10000);
        $this->guests('2026-06-10', 0); // dia sem pessoas
        $d = $this->svc->build($this->company->id, '2026-06-10');
        $this->assertNull($d['avg_ticket']['daily']);
    }

    public function test_requires_both_integrations(): void
    {
        // Vendas mas SEM reservas nesse dia → null (requer as duas).
        $this->sale('2026-06-10', 10000);
        $d = $this->svc->build($this->company->id, '2026-06-10');
        $this->assertNull($d['avg_ticket']['daily']);

        // Reservas mas SEM vendas nesse dia → null.
        $this->guests('2026-06-09', 10);
        $d2 = $this->svc->build($this->company->id, '2026-06-09');
        $this->assertNull($d2['avg_ticket']['daily']);
    }

    public function test_flag_disabled_does_not_calculate(): void
    {
        $this->sale('2026-06-10', 10000);
        $this->guests('2026-06-10', 20);
        $this->company->update(['cm_avg_ticket_enabled' => false]);

        $d = $this->svc->build($this->company->id, '2026-06-10');
        $this->assertFalse($d['avg_ticket_enabled']);
        $this->assertNull($d['avg_ticket']['daily']);
        $this->assertNull($d['avg_ticket']['monthly']);
        $this->assertNull($d['avg_ticket']['annual']);
    }

    public function test_opened_on_excludes_pre_opening_days_from_avg_ticket(): void
    {
        $this->loc->update(['opened_on' => '2026-06-05']);
        // Dia pré-abertura com vendas+reservas não deve contar.
        $this->sale('2026-06-04', 99999); $this->guests('2026-06-04', 1);
        $this->sale('2026-06-10', 10000);  $this->guests('2026-06-10', 20);

        $d = $this->svc->build($this->company->id, '2026-06-10');
        // Só o dia pós-abertura: 10000/20 = 500 c (o pré-abertura, 99999/1, fica de fora).
        $this->assertSame(500, $d['avg_ticket']['annual']);
    }

    // ── Lotação (Etapa 3 simples) — total + almoço + jantar ────────────────────

    public function test_occupancy_totals_by_shift(): void
    {
        $this->guests('2026-06-10', 8, 'lunch');
        $this->guests('2026-06-10', 20, 'dinner');
        $this->guests('2026-06-10', 2, 'other');

        $occ = $this->svc->build($this->company->id, '2026-06-10')['occupancy']['daily'];
        $this->assertTrue($occ['has_data']);
        $this->assertSame(30, $occ['total']);   // 8 + 20 + 2 (other entra no total)
        $this->assertSame(8, $occ['lunch']);
        $this->assertSame(20, $occ['dinner']);
        $this->assertSame(2, $occ['other']);
    }

    public function test_occupancy_period_sums_across_days(): void
    {
        $this->guests('2026-06-01', 10, 'dinner');
        $this->guests('2026-06-10', 5, 'lunch');
        $this->guests('2026-06-10', 15, 'dinner');

        $d = $this->svc->build($this->company->id, '2026-06-10');
        // Mensal/anual = soma de todos os dias do período.
        $this->assertSame(30, $d['occupancy']['monthly']['total']); // 10 + 5 + 15
        $this->assertSame(25, $d['occupancy']['monthly']['dinner']); // 10 + 15
        $this->assertSame(5, $d['occupancy']['monthly']['lunch']);
        // Diário = só o dia de referência.
        $this->assertSame(20, $d['occupancy']['daily']['total']);
    }

    public function test_occupancy_no_reservations_has_no_data(): void
    {
        // Sem reservas sincronizadas → has_data false (o card mostra "—", não 0 falso).
        $occ = $this->svc->build($this->company->id, '2026-06-10')['occupancy']['daily'];
        $this->assertFalse($occ['has_data']);
        $this->assertSame(0, $occ['total']);
    }

    public function test_occupancy_respects_opened_on(): void
    {
        $this->loc->update(['opened_on' => '2026-06-05']);
        $this->guests('2026-06-04', 99, 'dinner'); // pré-abertura → fora
        $this->guests('2026-06-10', 12, 'dinner');

        $d = $this->svc->build($this->company->id, '2026-06-10');
        $this->assertSame(12, $d['occupancy']['annual']['total']);
    }
}
