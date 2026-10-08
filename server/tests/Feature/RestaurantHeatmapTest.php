<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CmReservationHourly;
use App\Models\CmReservationShiftSummary;
use App\Models\Company;
use App\Models\PingwinHourlySale;
use App\Models\PingwinHourlySalesDay;
use App\Models\PingwinLocation;
use App\Models\User;
use App\Services\CompanyModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — F2: mapa de calor da semana (dia da semana × hora), por loja: média das vendas
 * por hora e das pessoas com reserva por hora de chegada.
 */
class RestaurantHeatmapTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private PingwinLocation $baixa;
    private PingwinLocation $costa;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-08 10:00:00'); // quinta-feira
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500009800', 'fiscal_name' => 'Yuko', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        $this->company->forceFill(['pingwin_item_sales_enabled' => true])->save();
        $this->baixa = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => '1', 'display_name' => 'Baixa', 'is_active' => true, 'opened_on' => '2026-03-13']);
        $this->costa = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => '2', 'display_name' => 'Costa Cabral', 'is_active' => true, 'opened_on' => '2026-09-21']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user']);
    }

    private function day(PingwinLocation $l, string $date, string $status, array $hours = []): void
    {
        PingwinHourlySalesDay::create(['company_id' => $this->company->id, 'location_id' => $l->id, 'business_date' => $date, 'status' => $status, 'synced_at' => now()]);
        foreach ($hours as $h => $cents) {
            PingwinHourlySale::create(['company_id' => $this->company->id, 'location_id' => $l->id, 'business_date' => $date, 'hour' => $h, 'net_cents' => $cents]);
        }
    }

    public function test_averages_per_weekday_and_hour_with_days_without_sales_as_zero(): void
    {
        // Três terças (06/10, 29/09, 22/09) na Baixa: 100 €, 0 € (lido, sem vendas) e 200 € às 21h.
        $this->day($this->baixa, '2026-10-06', 'ok', [21 => 10000, 13 => 3000]);
        $this->day($this->baixa, '2026-09-29', 'empty');
        $this->day($this->baixa, '2026-09-22', 'ok', [21 => 20000, 0 => 500]);
        $this->day($this->baixa, '2026-09-15', 'empty_protected'); // por confirmar: fica de fora
        // Pessoas: duas terças com reservas lidas.
        foreach (['2026-10-06' => 12, '2026-09-29' => 8] as $d => $g) {
            CmReservationShiftSummary::create(['company_id' => $this->company->id, 'location_id' => $this->baixa->id, 'business_date' => $d, 'shift' => 'dinner']);
            CmReservationHourly::create(['company_id' => $this->company->id, 'location_id' => $this->baixa->id, 'business_date' => $d, 'hour' => 21, 'guests_total' => $g, 'reservations_count' => 3]);
        }

        $data = $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/integrations/pingwin/heatmap?location_id={$this->baixa->id}")
            ->assertOk()->json('data');

        $this->assertTrue($data['enabled']);
        $this->assertSame(3, $data['sales']['days'][2]);                  // 3 terças lidas
        $this->assertEquals(10000.0, $data['sales']['cells'][2][21]);     // (100 + 0 + 200) ÷ 3
        $this->assertEquals(1000.0, $data['sales']['cells'][2][13]);
        $this->assertEquals(10.0, $data['guests']['cells'][2][21]);       // (12 + 8) ÷ 2
        $this->assertSame([13, 21, 0], $data['hours']);                    // ordem do dia do restaurante
        $this->assertSame('2026-07-16', $data['from']);                    // 12 semanas (84 dias) até ontem
        $this->assertCount(2, $data['locations']);
    }

    public function test_window_starts_at_the_store_effective_start(): void
    {
        $this->day($this->costa, '2026-09-15', 'ok', [21 => 99999]); // antes da abertura indicada
        $this->day($this->costa, '2026-09-22', 'ok', [21 => 10000]);

        $data = $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/integrations/pingwin/heatmap?location_id={$this->costa->id}")->json('data');

        $this->assertSame('2026-09-21', $data['from']);
        $this->assertEquals(10000.0, $data['sales']['cells'][2][21]);
    }

    public function test_switch_off_returns_no_data_and_access_is_by_company(): void
    {
        $this->day($this->baixa, '2026-10-06', 'ok', [21 => 10000]);
        $this->company->forceFill(['pingwin_item_sales_enabled' => false])->save();
        $data = $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/integrations/pingwin/heatmap")->assertOk()->json('data');
        $this->assertFalse($data['enabled']);
        $this->assertNull($data['sales']);

        $planId = DB::table('plans')->value('id');
        $other = Company::create(['nipc' => '500009801', 'fiscal_name' => 'Outro', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($other->id, 'restaurant');
        $stranger = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/integrations/pingwin/heatmap")->assertStatus(403);
    }
}
