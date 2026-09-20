<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PingwinDailySale;
use App\Models\PingwinLocation;
use App\Models\PingwinSyncRun;
use App\Services\PingwinDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Gráfico de faturação mensal por loja: uma série por loja (12 meses);
 * meses sem sincronização → null (não afundam a linha); opced_on respeitado.
 */
class PingwinMonthlyBillingTest extends TestCase
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
        $this->company = Company::create(['nipc' => '500012100', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->a = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => 'A', 'display_name' => 'Baixa', 'is_active' => true]);
        $this->b = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => 'B', 'display_name' => 'Costa', 'is_active' => true]);
        $this->svc = app(PingwinDashboardService::class);
    }

    private function sale(PingwinLocation $l, string $date, int $invoiced): void
    {
        PingwinDailySale::create(['company_id' => $this->company->id, 'location_id' => $l->id, 'business_date' => $date, 'invoiced_cents' => $invoiced, 'net_cents' => $invoiced, 'synced_at' => now()]);
        PingwinSyncRun::firstOrCreate(['company_id' => $this->company->id, 'business_date' => $date], ['status' => 'success', 'synced_at' => now()]);
    }

    public function test_one_series_per_location_with_monthly_totals(): void
    {
        // Junho: A fatura em dois dias (100+50=150€); B fatura 80€.
        $this->sale($this->a, '2026-06-05', 10000);
        $this->sale($this->a, '2026-06-20', 5000);
        $this->sale($this->b, '2026-06-05', 8000);

        $out = $this->svc->monthlyByLocation($this->company->id, 2026);
        $this->assertCount(2, $out['series']);

        $baixa = collect($out['series'])->firstWhere('location_id', $this->a->id);
        // Junho = índice 5 (Jan=0). 15000 cêntimos → 150.00 €.
        $this->assertSame(150.0, $baixa['data'][5]);
        // Janeiro (índice 0) NÃO sincronizado → null (não desce a 0).
        $this->assertNull($baixa['data'][0]);

        $costa = collect($out['series'])->firstWhere('location_id', $this->b->id);
        $this->assertSame(80.0, $costa['data'][5]);
    }

    public function test_synced_month_with_no_sales_is_real_zero_not_null(): void
    {
        // Junho sincronizado (A faturou); B não faturou nesse mês → 0 real (não null).
        $this->sale($this->a, '2026-06-05', 10000);

        $out = $this->svc->monthlyByLocation($this->company->id, 2026);
        $costa = collect($out['series'])->firstWhere('location_id', $this->b->id);
        $this->assertSame(0.0, $costa['data'][5]);   // mês sincronizado, sem vendas → 0 real
        $this->assertNull($costa['data'][6]);        // Julho não sincronizado → null
    }

    public function test_opened_on_makes_pre_opening_months_null(): void
    {
        $this->a->update(['opened_on' => '2026-06-01']);
        // Sincroniza Maio e Junho; a loja só abriu em Junho.
        $this->sale($this->a, '2026-05-10', 9999);
        $this->sale($this->a, '2026-06-10', 10000);

        $out = $this->svc->monthlyByLocation($this->company->id, 2026);
        $baixa = collect($out['series'])->firstWhere('location_id', $this->a->id);
        $this->assertNull($baixa['data'][4]);         // Maio: antes da abertura → null
        $this->assertSame(100.0, $baixa['data'][5]);  // Junho: 100€
    }

    public function test_endpoint_gated_and_tenant_scoped(): void
    {
        $planId = DB::table('plans')->where('name', 'P')->value('id');
        $auto = Company::create(['nipc' => '500012101', 'fiscal_name' => 'Auto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(\App\Services\CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        $autoUser = \App\Models\User::factory()->create(['company_id' => $auto->id, 'role' => 'admin']);

        $this->actingAs($autoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$auto->id}/analytics/pingwin/monthly-billing?year=2026")
            ->assertStatus(403);
    }
}
