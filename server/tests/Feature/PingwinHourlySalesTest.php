<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ScheduledRestaurantSyncJob;
use App\Jobs\SyncItemSalesPeriodJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinDailySale;
use App\Models\PingwinHourlySale;
use App\Models\PingwinHourlySalesDay;
use App\Models\PingwinLocation;
use App\Services\AlertService;
use App\Services\CoverManagerService;
use App\Services\PingwinHourlySalesService;
use App\Services\PingwinItemHistoryService;
use App\Services\PingwinItemSalesService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * XPLENDOR — F2 do marketing da restauração: vendas por hora (espelho das "Vendas por
 * hora" do PingWin), conferência com o líquido diário, histórico e job das 05:00. O PingWin
 * é simulado (sem docker, sem rede), com valores da captura h3 (06/10).
 */
class PingwinHourlySalesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private PingwinLocation $baixa;
    private PingwinLocation $costa;
    public array $calls = [];
    public array $hourRows = [];
    public array $summaryUpdates = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-08 10:00:00');
        Sleep::fake();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500009600', 'fiscal_name' => 'Yuko', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->baixa = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => '1099845342604', 'display_name' => 'Baixa', 'is_active' => true]);
        $this->costa = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => '584955579139649880', 'display_name' => 'Costa Cabral', 'is_active' => true]);
        $this->company->forceFill(['pingwin_item_sales_enabled' => true])->save();

        $t = $this;
        $this->app->instance(PingwinService::class, new class($t) extends PingwinService {
            public function __construct(private PingwinHourlySalesTest $t) {}

            public function sync(int $companyId, ?string $date = null): array
            {
                $this->t->calls[] = ['summary', $date];
                foreach ($this->t->summaryUpdates[$date] ?? [] as $loc => $cents) {
                    PingwinDailySale::updateOrCreate(['location_id' => $loc, 'business_date' => $date], ['company_id' => $companyId, 'net_cents' => $cents]);
                }

                return ['ok' => true];
            }

            public function fetchItemSales(int $companyId, string $start, string $end): array
            {
                $this->t->calls[] = ['item_sales', $start, $end];

                return [];
            }

            public function fetchHourlySales(int $companyId, string $start, string $end): array
            {
                $this->t->calls[] = ['hourly_sales', $start, $end];

                return array_values(array_filter($this->t->hourRows, fn ($r) => $r['date'] >= $start && $r['date'] <= $end));
            }

            public function fetchStoreYear(int $companyId, string $storeId, int $year, string $locals = ''): array
            {
                return array_fill(0, 12, 0.0);
            }
        });
    }

    private function hour(PingwinLocation $l, string $date, int $hour, float $net): array
    {
        return ['store_id' => $l->winrest_store_id, 'date' => $date, 'hour' => $hour, 'net' => $net];
    }

    private function dailyNet(PingwinLocation $l, string $date, int $cents): void
    {
        PingwinDailySale::create(['company_id' => $this->company->id, 'location_id' => $l->id, 'business_date' => $date, 'net_cents' => $cents]);
    }

    private function dayStatus(PingwinLocation $l, string $date): ?string
    {
        return PingwinHourlySalesDay::where('location_id', $l->id)->where('business_date', $date)->value('status');
    }

    private function service(): PingwinHourlySalesService
    {
        return app(PingwinHourlySalesService::class);
    }

    private function callsOf(string $kind): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c[0] === $kind));
    }

    public function test_stores_hours_in_cents_and_checks_against_daily_net(): void
    {
        // Baixa a 06/10 (captura h3): 1 231,26 € em 7 horas.
        $this->dailyNet($this->baixa, '2026-10-06', 123126);
        $this->hourRows = [
            $this->hour($this->baixa, '2026-10-06', 13, 113.02), $this->hour($this->baixa, '2026-10-06', 14, 35.78),
            $this->hour($this->baixa, '2026-10-06', 15, 113.19), $this->hour($this->baixa, '2026-10-06', 20, 92.19),
            $this->hour($this->baixa, '2026-10-06', 21, 387.11), $this->hour($this->baixa, '2026-10-06', 22, 266.47),
            $this->hour($this->baixa, '2026-10-06', 23, 223.50), $this->hour($this->baixa, '2026-10-06', 24, 999.0), // hora inválida
        ];

        $result = $this->service()->sync($this->company->id, '2026-10-06', '2026-10-06');

        $this->assertSame(7, PingwinHourlySale::where('location_id', $this->baixa->id)->count());
        $this->assertSame(38711, PingwinHourlySale::where('location_id', $this->baixa->id)->where('hour', 21)->value('net_cents'));
        $this->assertSame('ok', $this->dayStatus($this->baixa, '2026-10-06'));
        $this->assertSame(123126, PingwinHourlySalesDay::where('location_id', $this->baixa->id)->value('hours_net_cents'));
        $this->assertSame('empty_protected', $this->dayStatus($this->costa, '2026-10-06'));
        $this->assertArrayHasKey('21h', $result['hours']);
        // Só agregados: loja, dia, hora, valor.
        $this->assertEqualsCanonicalizing(['id', 'company_id', 'location_id', 'business_date', 'hour', 'net_cents', 'synced_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('pingwin_hourly_sales'));
    }

    public function test_each_read_replaces_the_day_and_mismatch_rereads_the_summary(): void
    {
        $this->dailyNet($this->baixa, '2026-10-06', 50000);
        $this->hourRows = [$this->hour($this->baixa, '2026-10-06', 13, 300), $this->hour($this->baixa, '2026-10-06', 21, 300)];
        $this->summaryUpdates['2026-10-06'] = [$this->baixa->id => 60000];

        $this->service()->sync($this->company->id, '2026-10-06', '2026-10-06');
        $this->assertSame([['summary', '2026-10-06']], $this->callsOf('summary'));
        $this->assertSame('ok', $this->dayStatus($this->baixa, '2026-10-06'));

        $this->hourRows = [$this->hour($this->baixa, '2026-10-06', 21, 600)];
        $this->service()->sync($this->company->id, '2026-10-06', '2026-10-06');
        $this->assertSame([21], PingwinHourlySale::where('location_id', $this->baixa->id)->pluck('hour')->all());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->hourRows = [$this->hour($this->baixa, '2026-10-06', 13, 10)];
        $result = $this->service()->sync($this->company->id, '2026-10-06', '2026-10-06', true);

        $this->assertSame('unverified', $result['days'][0]['status']);
        $this->assertSame(0, PingwinHourlySale::count());
        $this->assertSame(0, PingwinHourlySalesDay::count());
    }

    public function test_hourly_history_goes_back_to_the_first_sale_day_and_completes(): void
    {
        $this->baixa->forceFill(['sales_first_month' => '2026-09-01', 'sales_since' => '2026-09-03', 'history_complete_at' => now()])->save();
        $this->costa->forceFill(['sales_first_month' => '2026-09-01', 'sales_since' => '2026-09-20', 'history_complete_at' => now()])->save();
        $this->hourRows = [$this->hour($this->baixa, '2026-09-10', 13, 10)];

        $r = app(PingwinItemHistoryService::class)->backfillHourly($this->company->id, 10);

        $this->assertSame([['hourly_sales', '2026-10-01', '2026-10-07'], ['hourly_sales', '2026-09-24', '2026-09-30'],
            ['hourly_sales', '2026-09-17', '2026-09-23'], ['hourly_sales', '2026-09-10', '2026-09-16'], ['hourly_sales', '2026-09-03', '2026-09-09']],
            $this->callsOf('hourly_sales'));
        $this->assertTrue($r['complete']);
        $this->assertNotNull($this->baixa->fresh()->hourly_history_complete_at);
        // Os dias da Costa antes da primeira venda (20/09) ficam "sem vendas".
        $this->assertSame('empty', $this->dayStatus($this->costa, '2026-09-19'));
        Sleep::assertSleptTimes(4);
    }

    public function test_hourly_history_waits_for_the_first_sale_day(): void
    {
        $r = app(PingwinItemHistoryService::class)->backfillHourly($this->company->id, 10);
        $this->assertSame(0, $r['calls']);
        $this->assertSame([], $this->calls);
    }

    public function test_nightly_job_reads_items_then_hours_with_spacing_only_with_switch_on(): void
    {
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'pingwin', 'status' => 'active', 'access_token' => 'x', 'config' => ['username' => 'u', 'database' => 'd']]);
        $this->mock(CoverManagerService::class, function ($m) {
            $m->shouldReceive('companyToken')->andReturn(null);
            $m->shouldReceive('syncableLocations')->andReturn(collect());
        });

        (new ScheduledRestaurantSyncJob())->handle(app(AlertService::class));
        $this->assertSame(['item_sales', '2026-10-01', '2026-10-07'], $this->callsOf('item_sales')[0]);
        $this->assertSame(['hourly_sales', '2026-10-01', '2026-10-07'], $this->callsOf('hourly_sales')[0]);
        // 20 s entre os artigos e as horas, e antes de cada leitura anual da deteção (2 lojas).
        Sleep::assertSlept(fn ($duration) => $duration->totalSeconds == 20, 3);

        $this->calls = [];
        $this->company->forceFill(['pingwin_item_sales_enabled' => false])->save();
        (new ScheduledRestaurantSyncJob())->handle(app(AlertService::class));
        $this->assertSame([], $this->callsOf('hourly_sales'));
    }

    public function test_manual_period_also_rereads_hours(): void
    {
        (new SyncItemSalesPeriodJob($this->company->id, '2026-09-28', '2026-10-07'))->handle(app(PingwinItemSalesService::class), app(AlertService::class));

        $this->assertSame([['hourly_sales', '2026-09-28', '2026-10-04'], ['hourly_sales', '2026-10-05', '2026-10-07']], $this->callsOf('hourly_sales'));
    }

    public function test_commands_require_the_switch_and_dry_run_always_works(): void
    {
        $this->company->forceFill(['pingwin_item_sales_enabled' => false])->save();
        $this->hourRows = [$this->hour($this->baixa, '2026-10-06', 13, 10)];
        $args = ['company' => $this->company->id, '--from' => '2026-10-06', '--to' => '2026-10-06'];

        $this->artisan('pingwin:hourly-sales', $args)->expectsOutputToContain('interruptor está desligado')->assertFailed();
        $this->artisan('pingwin:hourly-history', ['company' => $this->company->id])->expectsOutputToContain('interruptor está desligado')->assertFailed();
        $this->artisan('pingwin:hourly-sales', $args + ['--dry-run' => true])->expectsOutputToContain('Simulação concluída')->assertSuccessful();
        $this->assertSame(0, PingwinHourlySale::count());
    }
}
