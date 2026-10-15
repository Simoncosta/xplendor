<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PingwinDailySale;
use App\Models\PingwinItemSale;
use App\Models\PingwinItemSalesDay;
use App\Models\PingwinLocation;
use App\Models\PingwinSyncRun;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\PingwinDashboardService;
use App\Services\PingwinService;
use App\Services\Restaurant\RestaurantSignalService;
use App\Services\RestaurantMarketingService;
use Carbon\CarbonPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BUG-MAIO: maio de 2026 na Yuko. A Baixa tem vendas (abriu a 13/03); a Costa Cabral só abriu a
 * 09/06 e não tem dados em maio. Antes: a Costa Cabral aparecia com 0 €, um dia em falta da Baixa
 * aparecia com 0 € e "−100%" contra a semana anterior, e "todas as lojas" não dizia o que faltava.
 * Agora (RestaurantSalesCoverage): loja sem dados → "sem dados neste período" (null, nunca zero),
 * dia em falta → null (as reservas continuam), "todas" soma só as que têm dados e diz quais faltam,
 * e só se comparam períodos completos.
 */
class RestaurantMissingSalesDataTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private PingwinLocation $baixa;
    private PingwinLocation $costa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 10:00:00');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500009950', 'fiscal_name' => 'Yuko', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->baixa = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => 'B', 'winrest_name' => 'Tabern Yuko Baixa',
            'display_name' => 'Yuko Baixa', 'is_active' => true, 'opened_on' => '2026-05-01']);
        $this->costa = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => 'C', 'winrest_name' => 'Tabern Yuko Original',
            'display_name' => 'Yuko Costa Cabral', 'is_active' => true, 'opened_on' => '2026-06-09']);
    }

    /** Dias sincronizados: a linha de cada loja aberta (como a sincronização real), salvo os dias em falta. */
    private function month(string $from, string $to, array $missingBaixa = [], array $missingCosta = []): void
    {
        foreach (CarbonPeriod::create($from, $to) as $d) {
            $day = $d->toDateString();
            PingwinSyncRun::firstOrCreate(['company_id' => $this->company->id, 'business_date' => $day], ['status' => 'success', 'synced_at' => now()]);
            if (! in_array($day, $missingBaixa, true)) {
                $this->sale($this->baixa, $day, 10000);
            }
            if ($day >= '2026-06-09' && ! in_array($day, $missingCosta, true)) {
                $this->sale($this->costa, $day, 30000);
            }
            $this->guests($this->baixa, $day, 50);
        }
    }

    private function sale(PingwinLocation $l, string $date, int $cents): void
    {
        PingwinDailySale::updateOrCreate(['location_id' => $l->id, 'business_date' => $date],
            ['company_id' => $this->company->id, 'invoiced_cents' => $cents, 'net_cents' => (int) round($cents / 1.15), 'synced_at' => now()]);
    }

    private function guests(PingwinLocation $l, string $date, int $n): void
    {
        DB::table('cm_reservation_shift_summary')->insert(['company_id' => $this->company->id, 'location_id' => $l->id, 'business_date' => $date,
            'shift' => 'dinner', 'guests_total' => $n, 'reservations_count' => $n, 'walk_ins_count' => 0, 'cancelled_count' => 0,
            'synced_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function dashboard(string $date): array
    {
        return app(PingwinDashboardService::class)->build($this->company->id, $date);
    }

    // ── 1. Loja sem dados no período ───────────────────────────────────────────

    public function test_store_without_data_in_the_period_shows_no_data_not_zero(): void
    {
        $this->month('2026-05-01', '2026-05-31');

        // Calendário da Costa Cabral em maio: sem dias, com a nota (antes: grelha vazia, sem explicação).
        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/companies/{$this->company->id}/analytics/pingwin/calendar?month=2026-05&location_id={$this->costa->id}")->assertOk();
        $this->assertSame([], $res->json('data.days'));
        $this->assertSame('no_data', $res->json('data.coverage.status'));
        $this->assertSame('Sem dados de Yuko Costa Cabral neste período (só tem vendas desde 09/06/2026).', $res->json('data.coverage.note'));

        // Dashboard a 31/05: a linha da Costa Cabral sem dados (null + not_open), não 0 €.
        $row = collect($this->dashboard('2026-05-31')['locations'])->firstWhere('id', $this->costa->id);
        foreach (['annual', 'monthly', 'daily'] as $p) {
            $this->assertNull($row[$p]['invoiced_cents'], $p);
            $this->assertSame('not_open', $row[$p]['status'], $p);
        }

        // Faturação mensal: maio da Costa Cabral null; junho com dados.
        $this->month('2026-06-01', '2026-06-30');
        $costa = collect(app(PingwinDashboardService::class)->monthlyByLocation($this->company->id, 2026)['series'])->firstWhere('location_id', $this->costa->id);
        $this->assertNull($costa['data'][4]);
        $this->assertSame(22 * 300.0, $costa['data'][5]);   // 09/06 a 30/06
        $this->assertSame(0, $costa['missing_days'][5]);
    }

    // ── 2. Período em que só uma loja tem dados ───────────────────────────────

    public function test_all_stores_sum_only_the_stores_with_data_and_say_which_are_missing(): void
    {
        $this->month('2026-05-01', '2026-05-31');

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/companies/{$this->company->id}/analytics/pingwin/calendar?month=2026-05")->assertOk();
        $day = collect($res->json('data.days'))->firstWhere('date', '2026-05-10');
        $this->assertSame(10000, $day['invoiced_cents']);        // só a Baixa
        $this->assertSame([], $day['missing_locations']);        // a Costa Cabral ainda não tinha aberto: não "falta"
        $this->assertSame('complete', $res->json('data.coverage.status'));
        $this->assertStringContainsString('Sem dados de Yuko Costa Cabral neste período', $res->json('data.coverage.note'));

        $d = $this->dashboard('2026-05-31');
        $this->assertSame(31 * 10000, $d['monthly']['invoiced_cents']);
        $this->assertTrue($d['monthly']['coverage']['complete']);
        $this->assertStringContainsString('Yuko Costa Cabral', $d['monthly']['coverage']['note']);
        // Os dois dias completos (só a Baixa estava aberta) → compara.
        $this->assertTrue($d['daily']['comparable']);
        $this->assertSame(0.0, $d['daily']['delta_pct_invoiced']);

        // Junho: a Costa Cabral abre a 09/06 e falta-lhe o dia 20 → "todas" é parcial e diz quem falta nesse dia.
        $this->month('2026-06-01', '2026-06-30', [], ['2026-06-20']);
        $june = collect(app(PingwinDashboardService::class)->calendar($this->company->id, 2026, 6))->keyBy('date');
        $this->assertSame(10000, $june['2026-06-20']['invoiced_cents']);
        $this->assertSame(['Yuko Costa Cabral'], $june['2026-06-20']['missing_locations']);
        $this->assertSame(40000, $june['2026-06-21']['invoiced_cents']);
        $this->assertSame([], $june['2026-06-21']['missing_locations']);
    }

    // ── 3. Dia em falta a meio do mês ─────────────────────────────────────────

    public function test_missing_day_mid_month_is_missing_not_zero_and_not_compared(): void
    {
        $this->month('2026-05-01', '2026-05-31', ['2026-05-15']);

        // Calendário: o dia 15 tem as reservas, mas a faturação a null (não 0 €), e sem ticket.
        $days = collect(app(PingwinDashboardService::class)->calendar($this->company->id, 2026, 5, $this->baixa->id))->keyBy('date');
        $this->assertNull($days['2026-05-15']['invoiced_cents']);
        $this->assertSame(50, $days['2026-05-15']['guests']);
        $this->assertNull($days['2026-05-15']['avg_ticket_cents']);
        $this->assertSame(10000, $days['2026-05-16']['invoiced_cents']);
        $cov = app(PingwinDashboardService::class)->monthCoverage($this->company->id, 2026, 5, $this->baixa->id);
        $this->assertSame('partial', $cov['status']);
        $this->assertSame('Faltam as vendas de Yuko Baixa no dia 15/05.', $cov['note']);

        // Dashboard no próprio dia: sem dados (null), sem "−100%"; uma semana depois também não compara.
        $d = $this->dashboard('2026-05-15');
        $this->assertNull($d['daily']['invoiced_cents']);
        $this->assertFalse($d['daily']['comparable']);
        $this->assertNull($d['daily']['delta_pct_invoiced']);
        $this->assertFalse($this->dashboard('2026-05-22')['daily']['comparable']);
        // O mês soma só os dias lidos e diz que falta um.
        $this->assertSame(30 * 10000, $this->dashboard('2026-05-31')['monthly']['invoiced_cents']);
        $this->assertSame(1, collect($this->dashboard('2026-05-31')['locations'])->firstWhere('id', $this->baixa->id)['monthly']['missing_days']);

        // Faturação mensal: maio da Baixa com o valor dos dias lidos e 1 dia em falta.
        $baixa = collect(app(PingwinDashboardService::class)->monthlyByLocation($this->company->id, 2026)['series'])->firstWhere('location_id', $this->baixa->id);
        $this->assertSame(3000.0, $baixa['data'][4]);
        $this->assertSame(1, $baixa['missing_days'][4]);

        // Bloco de marketing de maio: o dia 15 a null na série, sem comparação nem peso do marketing.
        $m = app(RestaurantMarketingService::class)->build($this->company->id, '2026-05');
        $series = collect($m['metrics']['revenue']['series'])->keyBy('date');
        $this->assertNull($series['2026-05-15']['value']);
        $this->assertEquals(100.0, $series['2026-05-16']['value']);
        $this->assertSame(['tier' => null, 'reason' => 'incomplete_period'], $m['metrics']['revenue']['comparison']);
        $this->assertSame('Faltam as vendas de Yuko Baixa no dia 15/05. Sem dados de Yuko Costa Cabral neste período (só tem vendas desde 09/06/2026).', $m['coverage']['note']);
    }

    // ── A raiz: a sincronização não grava zeros antes da abertura ─────────────

    public function test_sync_does_not_store_zero_rows_before_a_store_opened(): void
    {
        $persist = new \ReflectionMethod(PingwinService::class, 'persistSyncResult');
        $saved = $persist->invoke(app(PingwinService::class), $this->company->id, '2026-05-29', [
            ['loja' => 'Tabern Yuko Baixa', 'valor_faturado' => 4006.3, 'vendas_liquidas' => 3485.41],
            ['loja' => 'Tabern Yuko Original', 'valor_faturado' => 0, 'vendas_liquidas' => 0], // ainda não tinha aberto
        ]);

        $this->assertSame(1, $saved);
        $this->assertSame(1, PingwinDailySale::where('location_id', $this->baixa->id)->count());
        $this->assertSame(0, PingwinDailySale::where('location_id', $this->costa->id)->count());
        $this->assertSame(1, PingwinSyncRun::where('company_id', $this->company->id)->whereDate('business_date', '2026-05-29')->value('locations_count'));
    }

    // ── O mesmo padrão na Bússola: variação de uma loja aberta a meio da janela anterior ──

    public function test_compass_variation_is_null_when_the_store_opened_inside_the_previous_window(): void
    {
        $this->company->forceFill(['pingwin_item_sales_enabled' => true])->save();
        // Janelas de 28 dias até ontem (13/10): agora 16/09–13/10; antes 19/08–15/09.
        $recent = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => 'N', 'display_name' => 'Nova',
            'is_active' => true, 'opened_on' => '2026-09-01']);
        foreach ([$this->baixa, $recent] as $l) {
            foreach (CarbonPeriod::create(max('2026-08-19', $l->opened_on->toDateString()), '2026-10-13') as $d) {
                PingwinItemSalesDay::firstOrCreate(['location_id' => $l->id, 'business_date' => $d->toDateString()], ['company_id' => $this->company->id, 'status' => 'ok']);
                PingwinItemSale::create(['company_id' => $this->company->id, 'location_id' => $l->id, 'business_date' => $d->toDateString(),
                    'product_pingwin_id' => 'P1', 'product_name' => 'Francesinha', 'family_pingwin_id' => 'F1', 'quantity' => 10, 'net_cents' => 10000]);
            }
        }
        $signals = app(RestaurantSignalService::class);

        $this->assertSame(0.0, $signals->compassFacts($this->company->id, $this->baixa)['variation_pct']); // as duas janelas inteiras
        // A loja nova só tem 15 dias na janela anterior: antes dava +87%; agora não compara.
        $this->assertNull($signals->compassFacts($this->company->id, $recent)['variation_pct']);
    }

    public function test_tenancy_of_the_calendar_coverage(): void
    {
        $other = Company::create(['nipc' => '500009951', 'fiscal_name' => 'Outra', 'plan_id' => DB::table('plans')->value('id'), 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($other->id, 'restaurant');
        $stranger = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        $this->month('2026-05-01', '2026-05-31');

        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/analytics/pingwin/calendar?month=2026-05")->assertStatus(403);
        // Pela própria empresa, com a loja da outra: a loja é ignorada e não há dados.
        $res = $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/companies/{$other->id}/analytics/pingwin/calendar?month=2026-05&location_id={$this->baixa->id}")->assertOk();
        $this->assertSame([], $res->json('data.days'));
        $this->assertSame('no_data', $res->json('data.coverage.status'));
    }
}
