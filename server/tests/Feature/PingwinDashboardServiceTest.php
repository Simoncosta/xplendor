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
 * XPLENDOR — Dashboard de restauração: cards (anual/mensal/diário) com faturado +
 * líquido, comparações novas (dia da semana / ano) e PORTÃO DE HONESTIDADE
 * (opened_on + só compara se o período anterior estiver todo sincronizado).
 */
class PingwinDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private PingwinDashboardService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->company = Company::create(['nipc' => '500005100', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->svc = app(PingwinDashboardService::class);
    }

    private function location(?string $openedOn = null): PingwinLocation
    {
        return PingwinLocation::create([
            'company_id' => $this->company->id,
            'winrest_store_id' => 'w' . uniqid(),
            'display_name' => 'Loja',
            'opened_on' => $openedOn,
            'is_active' => true,
        ]);
    }

    private function sale(PingwinLocation $loc, string $date, int $invoiced, int $net): void
    {
        PingwinDailySale::create([
            'company_id' => $this->company->id,
            'location_id' => $loc->id,
            'business_date' => $date,
            'invoiced_cents' => $invoiced,
            'net_cents' => $net,
            'synced_at' => now(),
        ]);
    }

    private function markSynced(string $date): void
    {
        PingwinSyncRun::create(['company_id' => $this->company->id, 'business_date' => $date, 'status' => 'success', 'synced_at' => now()]);
    }

    public function test_cards_sum_amounts_in_cents(): void
    {
        $loc = $this->location();
        $this->sale($loc, '2026-06-01', 10000, 8000);
        $this->sale($loc, '2026-06-10', 5000, 4000);
        $this->markSynced('2026-06-01');
        $this->markSynced('2026-06-10');

        $d = $this->svc->build($this->company->id, '2026-06-10');

        // Anual (ano até à data) e mensal (mês até à data) somam os dois dias.
        $this->assertSame(15000, $d['annual']['invoiced_cents']);
        $this->assertSame(12000, $d['annual']['net_cents']);
        $this->assertSame(15000, $d['monthly']['invoiced_cents']);
        // Diário = só o dia de referência (os dois valores: faturado + líquido).
        $this->assertSame(5000, $d['daily']['invoiced_cents']);
        $this->assertSame(4000, $d['daily']['net_cents']);
    }

    public function test_daily_comparison_vs_same_weekday_when_prev_synced(): void
    {
        $loc = $this->location();
        $this->sale($loc, '2026-06-10', 10000, 8000);
        $this->sale($loc, '2026-06-03', 5000, 4000); // −7 dias (quarta anterior)
        $this->markSynced('2026-06-10');
        $this->markSynced('2026-06-03');

        $d = $this->svc->build($this->company->id, '2026-06-10');

        $this->assertSame('2026-06-03', $d['daily']['prev_date']);
        $this->assertTrue($d['daily']['comparable']);
        $this->assertSame(100.0, $d['daily']['delta_pct_invoiced']); // (10000-5000)/5000
        $this->assertSame(100.0, $d['daily']['delta_pct_net']);
    }

    public function test_daily_comparison_hidden_when_prev_day_not_synced(): void
    {
        $loc = $this->location();
        $this->sale($loc, '2026-06-10', 10000, 8000);
        $this->sale($loc, '2026-06-03', 5000, 4000); // há dados…
        $this->markSynced('2026-06-10');
        // …mas o dia anterior NÃO foi sincronizado → não comparar (não mentir).

        $d = $this->svc->build($this->company->id, '2026-06-10');

        $this->assertFalse($d['daily']['comparable']);
        $this->assertNull($d['daily']['delta_pct_invoiced']);
        $this->assertNull($d['daily']['prev']);
    }

    public function test_annual_comparison_is_dash_until_last_year_history_exists(): void
    {
        $loc = $this->location();
        $this->sale($loc, '2026-06-10', 10000, 8000);
        $this->markSynced('2026-06-10');

        $d = $this->svc->build($this->company->id, '2026-06-10');

        // Sem histórico do ano passado → "—" (delta null). Esperado no início.
        $this->assertFalse($d['annual']['comparable']);
        $this->assertNull($d['annual']['delta_pct_invoiced']);
    }

    public function test_opened_on_excludes_pre_opening_days(): void
    {
        $loc = $this->location(openedOn: '2026-06-05');
        // Dia ANTES da abertura não deve contar (não afundar médias/somas).
        $this->sale($loc, '2026-06-04', 9999, 9999);
        $this->sale($loc, '2026-06-10', 1000, 800);
        $this->markSynced('2026-06-04');
        $this->markSynced('2026-06-10');

        $d = $this->svc->build($this->company->id, '2026-06-10');

        // Anual só conta o dia pós-abertura (1000), não o pré-abertura (9999).
        $this->assertSame(1000, $d['annual']['invoiced_cents']);
    }

    public function test_locations_table_lists_active_stores_with_three_periods(): void
    {
        $loc = $this->location();
        // Dois dias no mesmo mês/ano: anual e mensal somam ambos; diário só o dia ref.
        $this->sale($loc, '2026-06-01', 10000, 8000);
        $this->sale($loc, '2026-06-10', 5000, 4000);
        $this->markSynced('2026-06-01');
        $this->markSynced('2026-06-10');

        $d = $this->svc->build($this->company->id, '2026-06-10');

        $this->assertCount(1, $d['locations']);
        $row = $d['locations'][0];
        // Anual e mensal (até à data) somam os dois dias; diário só o dia de referência.
        $this->assertSame(15000, $row['annual']['invoiced_cents']);
        $this->assertSame(12000, $row['annual']['net_cents']);
        $this->assertSame(15000, $row['monthly']['invoiced_cents']);
        $this->assertSame(5000, $row['daily']['invoiced_cents']);
        $this->assertSame(4000, $row['daily']['net_cents']);
        $this->assertNotNull($row['last_synced_at']);
    }

    public function test_locations_respect_opened_on_per_store(): void
    {
        // Loja aberta a meio do mês: o dia anterior à abertura não conta no anual.
        $loc = $this->location(openedOn: '2026-06-05');
        $this->sale($loc, '2026-06-04', 9999, 9999);
        $this->sale($loc, '2026-06-10', 1000, 800);
        $this->markSynced('2026-06-04');
        $this->markSynced('2026-06-10');

        $d = $this->svc->build($this->company->id, '2026-06-10');
        $this->assertSame(1000, $d['locations'][0]['annual']['invoiced_cents']);
    }
}
