<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ScheduledRestaurantSyncJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinDailySale;
use App\Models\PingwinItemSale;
use App\Models\PingwinItemSalesDay;
use App\Models\PingwinLocation;
use App\Models\User;
use App\Services\AlertService;
use App\Services\CoverManagerService;
use App\Services\PingwinItemSalesService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * XPLENDOR — F1-1 do marketing da restauração: vendas por artigo e por dia (espelho do
 * "Vendas por artigo" do PingWin). O PingWin é simulado (sem docker, sem rede).
 */
class PingwinItemSalesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private PingwinLocation $baixa;
    private PingwinLocation $costa;
    /** @var array<int, array{0: string, 1: string}> */
    private array $calls = [];
    /** Linhas que o PingWin simulado devolve (filtradas pelo intervalo pedido). */
    private array $pingwinRows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-07 10:00:00');
        Sleep::fake();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500009300', 'fiscal_name' => 'Yuko', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->baixa = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => '1099845342604', 'display_name' => 'Baixa', 'is_active' => true]);
        $this->costa = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => '584955579139649880', 'display_name' => 'Costa Cabral', 'is_active' => true]);

        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private PingwinItemSalesTest $test) {}

            public function fetchItemSales(int $companyId, string $start, string $end): array
            {
                return $this->test->fakeFetch($start, $end);
            }

            public function sync(int $companyId, ?string $date = null): array
            {
                return $this->test->fakeSummary($companyId, (string) $date);
            }
        });
    }

    /** Chamado pelo PingWin simulado: regista o pedido e devolve as linhas do intervalo. */
    public function fakeFetch(string $start, string $end): array
    {
        $this->calls[] = [$start, $end];

        return array_values(array_filter($this->pingwinRows, fn ($r) => $r['date'] >= $start && $r['date'] <= $end));
    }

    /** Releituras do Resumo de Vendas pedidas e o que cada uma traz (loja => líquido em cêntimos). */
    public array $summaryCalls = [];
    public array $summaryUpdates = [];
    public bool $summaryFails = false;

    /** Chamado pelo PingWin simulado na releitura do Resumo de Vendas de um dia. */
    public function fakeSummary(int $companyId, string $date): array
    {
        $this->summaryCalls[] = $date;
        if ($this->summaryFails) {
            throw new \RuntimeException('servidor vazio');
        }
        foreach ($this->summaryUpdates[$date] ?? [] as $locationId => $cents) {
            PingwinDailySale::updateOrCreate(['location_id' => $locationId, 'business_date' => $date], ['company_id' => $companyId, 'net_cents' => $cents]);
        }

        return ['ok' => true];
    }

    private function row(string $store, string $date, string $product, float $net, array $extra = []): array
    {
        return array_merge([
            'store_id' => $store, 'date' => $date, 'product_id' => $product, 'product_code' => 'C' . $product,
            'product_name' => 'Artigo ' . $product, 'family_id' => '1649601156816',
            'family_path' => 'Família \\ Comidas \\ Francesinhas', 'qty' => 2.0,
            'net' => $net, 'tax' => round($net * 0.13, 2), 'gross' => round($net * 1.13, 2),
        ], $extra);
    }

    private function dailyNet(PingwinLocation $loc, string $date, int $cents): void
    {
        PingwinDailySale::create(['company_id' => $this->company->id, 'location_id' => $loc->id, 'business_date' => $date, 'net_cents' => $cents]);
    }

    private function service(): PingwinItemSalesService
    {
        return app(PingwinItemSalesService::class);
    }

    private function dayStatus(PingwinLocation $loc, string $date): ?string
    {
        return PingwinItemSalesDay::where('location_id', $loc->id)->where('business_date', $date)->value('status');
    }

    // ── Interruptor ─────────────────────────────────────────────────────────────

    public function test_switch_is_off_by_default_and_not_mass_assignable(): void
    {
        $this->assertFalse(PingwinItemSalesService::isEnabled($this->company->id));

        $this->company->update(['pingwin_item_sales_enabled' => true]); // fora do fillable
        $this->assertFalse(PingwinItemSalesService::isEnabled($this->company->id));
    }

    public function test_switch_command_shows_and_toggles(): void
    {
        $this->artisan('pingwin:item-sales-switch', ['company' => $this->company->id])
            ->expectsOutputToContain('desligadas')->assertSuccessful();
        $this->artisan('pingwin:item-sales-switch', ['company' => $this->company->id, 'state' => 'on'])
            ->expectsOutputToContain('LIGADAS')->assertSuccessful();
        $this->assertTrue(PingwinItemSalesService::isEnabled($this->company->id));
        $this->artisan('pingwin:item-sales-switch', ['company' => $this->company->id, 'state' => 'talvez'])->assertFailed();
        $this->artisan('pingwin:item-sales-switch', ['company' => $this->company->id, 'state' => 'off'])->assertSuccessful();
        $this->assertFalse(PingwinItemSalesService::isEnabled($this->company->id));
    }

    // ── Gravação e conferência ──────────────────────────────────────────────────

    public function test_stores_closed_list_in_cents_and_checks_against_daily_net(): void
    {
        $this->dailyNet($this->baixa, '2026-09-30', 124804);
        $this->pingwinRows = [
            $this->row('1099845342604', '2026-09-30', '1', 1000.00),
            $this->row('1099845342604', '2026-09-30', '2', 248.04, ['qty' => 1.5]),
        ];

        $this->service()->sync($this->company->id, '2026-09-30', '2026-09-30');

        $item = PingwinItemSale::where('location_id', $this->baixa->id)->where('product_pingwin_id', '2')->first();
        $this->assertSame(24804, $item->net_cents);
        $this->assertSame(3225, $item->tax_cents);
        $this->assertSame(28029, $item->gross_cents);
        $this->assertSame(1.5, $item->quantity);
        $this->assertSame('Família \\ Comidas \\ Francesinhas', $item->family_path);
        $this->assertSame('C2', $item->product_code);
        $this->assertSame(PingwinItemSalesDay::STATUS_OK, $this->dayStatus($this->baixa, '2026-09-30'));
        $day = PingwinItemSalesDay::where('location_id', $this->baixa->id)->first();
        $this->assertSame(124804, $day->items_net_cents);
        $this->assertSame(124804, $day->daily_net_cents);
        $this->assertSame(2, $day->rows_count);

        // A Costa Cabral não veio no relatório e não tem resumo: dia protegido.
        $this->assertSame(PingwinItemSalesDay::STATUS_EMPTY_PROTECTED, $this->dayStatus($this->costa, '2026-09-30'));

        // Só colunas de vendas: nada de empresa, descontos ou dados pessoais.
        $cols = Schema::getColumnListing('pingwin_item_sales_daily');
        foreach (['company', 'discount', 'employee', 'client', 'customer'] as $extra) {
            $this->assertNotContains($extra, $cols);
        }
    }

    public function test_each_read_replaces_the_day_like_a_mirror(): void
    {
        $this->pingwinRows = [$this->row('1099845342604', '2026-09-30', '1', 10), $this->row('1099845342604', '2026-09-30', '2', 20)];
        $this->service()->sync($this->company->id, '2026-09-30', '2026-09-30');
        $this->assertSame(2, PingwinItemSale::where('location_id', $this->baixa->id)->count());

        // No PingWin, o artigo 2 foi anulado e o 1 corrigido.
        $this->pingwinRows = [$this->row('1099845342604', '2026-09-30', '1', 12)];
        $this->service()->sync($this->company->id, '2026-09-30', '2026-09-30');

        $this->assertSame(['1'], PingwinItemSale::where('location_id', $this->baixa->id)->pluck('product_pingwin_id')->all());
        $this->assertSame(1200, PingwinItemSale::where('location_id', $this->baixa->id)->value('net_cents'));
    }

    public function test_empty_report_never_deletes_a_day_with_sales(): void
    {
        $this->dailyNet($this->baixa, '2026-09-30', 2000);
        $this->pingwinRows = [$this->row('1099845342604', '2026-09-30', '1', 20)];
        $this->service()->sync($this->company->id, '2026-09-30', '2026-09-30');

        // O servidor devolve o relatório sem linhas (intermitência): nada se apaga.
        $this->pingwinRows = [];
        $result = $this->service()->sync($this->company->id, '2026-09-30', '2026-09-30');

        $this->assertSame(1, PingwinItemSale::where('location_id', $this->baixa->id)->count());
        $this->assertSame(PingwinItemSalesDay::STATUS_EMPTY_PROTECTED, $this->dayStatus($this->baixa, '2026-09-30'));
        $this->assertSame('empty_protected', $result['days'][0]['status']);
    }

    public function test_empty_report_clears_the_day_only_when_daily_net_is_zero(): void
    {
        $this->pingwinRows = [$this->row('1099845342604', '2026-09-30', '1', 20)];
        $this->service()->sync($this->company->id, '2026-09-30', '2026-09-30');

        $this->dailyNet($this->baixa, '2026-09-30', 0);
        $this->pingwinRows = [];
        $this->service()->sync($this->company->id, '2026-09-30', '2026-09-30');

        $this->assertSame(0, PingwinItemSale::where('location_id', $this->baixa->id)->count());
        $this->assertSame(PingwinItemSalesDay::STATUS_EMPTY, $this->dayStatus($this->baixa, '2026-09-30'));
    }

    public function test_mismatch_and_unverified_days_are_marked(): void
    {
        $this->dailyNet($this->baixa, '2026-09-30', 10000);
        $this->dailyNet($this->baixa, '2026-10-01', 10000);
        $this->pingwinRows = [
            $this->row('1099845342604', '2026-09-30', '1', 100.99), // dentro de 1%
            $this->row('1099845342604', '2026-10-01', '1', 90.00),  // 10% abaixo
            $this->row('1099845342604', '2026-10-02', '1', 50.00),  // sem resumo
        ];

        $this->service()->sync($this->company->id, '2026-09-30', '2026-10-02');

        $this->assertSame(PingwinItemSalesDay::STATUS_OK, $this->dayStatus($this->baixa, '2026-09-30'));
        $this->assertSame(PingwinItemSalesDay::STATUS_MISMATCH, $this->dayStatus($this->baixa, '2026-10-01'));
        $this->assertSame(PingwinItemSalesDay::STATUS_UNVERIFIED, $this->dayStatus($this->baixa, '2026-10-02'));
        // Antes de marcar, releu-se o Resumo de Vendas desse dia (uma vez); continuou a não bater.
        $this->assertSame(['2026-10-01'], $this->summaryCalls);
        // Mesmo sem bater, o espelho guarda o que o PingWin tem (o dia fica marcado).
        $this->assertSame(1, PingwinItemSale::where('location_id', $this->baixa->id)->where('business_date', '2026-10-01')->count());
    }

    public function test_mismatch_rereads_the_daily_summary_once_and_rechecks(): void
    {
        // O resumo foi lido antes de o restaurante fechar (caso de 20/09): faltam 107,86 €.
        $this->dailyNet($this->baixa, '2026-09-20', 474783);
        $this->dailyNet($this->costa, '2026-09-20', 100000);
        $this->pingwinRows = [
            $this->row('1099845342604', '2026-09-20', '1', 4855.69),
            $this->row('584955579139649880', '2026-09-20', '2', 1200.00), // também não bate
        ];
        $this->summaryUpdates['2026-09-20'] = [$this->baixa->id => 485569, $this->costa->id => 120000];

        $result = $this->service()->sync($this->company->id, '2026-09-20', '2026-09-20');

        $this->assertSame(['2026-09-20'], $this->summaryCalls); // um só pedido para as duas lojas
        $this->assertSame(PingwinItemSalesDay::STATUS_OK, $this->dayStatus($this->baixa, '2026-09-20'));
        $this->assertSame(PingwinItemSalesDay::STATUS_OK, $this->dayStatus($this->costa, '2026-09-20'));
        $this->assertSame(485569, PingwinItemSalesDay::where('location_id', $this->baixa->id)->value('daily_net_cents'));
        $this->assertTrue(collect($result['days'])->firstWhere('location_id', $this->baixa->id)['daily_reread']);
        Sleep::assertSleptTimes(1); // 20 s antes da releitura
    }

    public function test_mismatch_stays_marked_when_the_reread_fails_and_dry_run_never_rereads(): void
    {
        $this->dailyNet($this->baixa, '2026-09-20', 474783);
        $this->pingwinRows = [$this->row('1099845342604', '2026-09-20', '1', 4855.69)];

        $dry = $this->service()->sync($this->company->id, '2026-09-20', '2026-09-20', true);
        $this->assertSame('mismatch', $dry['days'][0]['status']);
        $this->assertSame([], $this->summaryCalls);

        $this->summaryFails = true;
        $this->service()->sync($this->company->id, '2026-09-20', '2026-09-20');
        $this->assertSame(['2026-09-20'], $this->summaryCalls);
        $this->assertSame(PingwinItemSalesDay::STATUS_MISMATCH, $this->dayStatus($this->baixa, '2026-09-20'));
    }

    public function test_empty_day_before_the_first_sale_is_a_day_without_sales(): void
    {
        $this->baixa->forceFill(['sales_first_month' => '2026-03-01', 'sales_since' => '2026-03-13'])->save();

        $this->service()->sync($this->company->id, '2026-03-10', '2026-03-14');

        $this->assertSame(PingwinItemSalesDay::STATUS_EMPTY, $this->dayStatus($this->baixa, '2026-03-12'));
        $this->assertSame(PingwinItemSalesDay::STATUS_EMPTY_PROTECTED, $this->dayStatus($this->baixa, '2026-03-13'));
        $this->assertSame(PingwinItemSalesDay::STATUS_EMPTY_PROTECTED, $this->dayStatus($this->baixa, '2026-03-14'));
    }

    public function test_unknown_stores_out_of_range_days_and_repeated_keys(): void
    {
        $this->pingwinRows = [
            $this->row('1099845342604', '2026-09-30', '1', 10),
            $this->row('1099845342604', '2026-09-30', '1', 5, ['qty' => 1.0]), // chave repetida → soma
            $this->row('1099511639284', '2026-09-30', '9', 99),                // Yuko BO, sem cadastro
        ];
        $result = $this->service()->apply($this->company->id, '2026-09-30', '2026-09-30', [
            ...$this->pingwinRows,
            $this->row('1099845342604', '2026-10-01', '3', 7), // fora do bloco
        ]);

        $item = PingwinItemSale::where('location_id', $this->baixa->id)->sole();
        $this->assertSame(1500, $item->net_cents);
        $this->assertSame(3.0, $item->quantity);
        $this->assertSame(['1099511639284'], $result['ignored_stores']);
        $this->assertSame(1, PingwinItemSale::count());
    }

    public function test_dry_run_reads_and_checks_but_writes_nothing(): void
    {
        $this->dailyNet($this->baixa, '2026-09-30', 1000);
        $this->pingwinRows = [$this->row('1099845342604', '2026-09-30', '1', 10)];

        $result = $this->service()->sync($this->company->id, '2026-09-30', '2026-09-30', true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame('ok', $result['days'][0]['status']);
        $this->assertSame(0, PingwinItemSale::count());
        $this->assertSame(0, PingwinItemSalesDay::count());
    }

    // ── Pedidos ao PingWin ──────────────────────────────────────────────────────

    public function test_long_range_is_read_in_7_day_calls_with_spacing(): void
    {
        $this->service()->sync($this->company->id, '2026-09-01', '2026-09-20');

        $this->assertSame([['2026-09-01', '2026-09-07'], ['2026-09-08', '2026-09-14'], ['2026-09-15', '2026-09-20']], $this->calls);
        Sleep::assertSleptTimes(2);
        Sleep::assertSequence([Sleep::for(20)->seconds(), Sleep::for(20)->seconds()]);
    }

    public function test_rejects_invalid_ranges_without_calling_pingwin(): void
    {
        foreach ([['2026-09-01', '2026-10-05'], ['2026-10-06', '2026-10-07'], ['2026-10-02', '2026-10-01'], ['2026-02-30', '2026-03-01']] as [$from, $to]) {
            try {
                $this->service()->sync($this->company->id, $from, $to);
                $this->fail("Devia recusar {$from} a {$to}.");
            } catch (ValidationException) {
            }
        }
        $this->assertSame([], $this->calls);
    }

    public function test_nightly_window_is_the_7_days_ending_yesterday(): void
    {
        $this->assertSame(['2026-09-30', '2026-10-06'], PingwinItemSalesService::nightlyWindow());
        $this->assertSame(['2026-09-24', '2026-09-30'], PingwinItemSalesService::nightlyWindow('2026-09-30'));
    }

    // ── Job das 05:00 (atrás do interruptor) ───────────────────────────────────

    private function nightlySetup(): void
    {
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'pingwin', 'status' => 'active', 'access_token' => 'x', 'config' => ['username' => 'u', 'database' => 'd']]);
        $this->mock(CoverManagerService::class, function ($m) {
            $m->shouldReceive('companyToken')->andReturn(null);
            $m->shouldReceive('syncableLocations')->andReturn(collect());
        });
    }

    public function test_nightly_job_does_not_read_item_sales_with_switch_off(): void
    {
        $this->nightlySetup();
        $this->mock(PingwinService::class, function ($m) {
            $m->shouldReceive('sync')->andReturn([]);
            $m->shouldNotReceive('fetchItemSales');
            $m->shouldNotReceive('fetchHourlySales');
        });

        (new ScheduledRestaurantSyncJob())->handle(app(AlertService::class));
        $this->assertSame(0, PingwinItemSalesDay::count());
    }

    public function test_nightly_job_reads_the_7_previous_days_with_switch_on(): void
    {
        $this->nightlySetup();
        $this->company->forceFill(['pingwin_item_sales_enabled' => true])->save();
        $this->mock(PingwinService::class, function ($m) {
            $m->shouldReceive('sync')->andReturn([]);
            $m->shouldReceive('fetchItemSales')->once()->with($this->company->id, '2026-09-30', '2026-10-06')
                ->andReturn([$this->row('1099845342604', '2026-10-06', '1', 10)]);
            $m->shouldReceive('fetchHourlySales')->once()->with($this->company->id, '2026-09-30', '2026-10-06')->andReturn([]);
        });

        (new ScheduledRestaurantSyncJob())->handle(app(AlertService::class));

        $this->assertSame(1, PingwinItemSale::count());
        $this->assertSame(14, PingwinItemSalesDay::count()); // 2 lojas × 7 dias
    }

    public function test_nightly_item_sales_failure_goes_to_owner_summary(): void
    {
        $this->nightlySetup();
        $owner = Company::create(['nipc' => '500009301', 'fiscal_name' => 'XPLENDOR', 'plan_id' => $this->company->plan_id]);
        User::create(['name' => 'Dona', 'email' => 'root@x.pt', 'password' => Hash::make('x'), 'role' => 'root', 'company_id' => $owner->id]);
        $this->company->forceFill(['pingwin_item_sales_enabled' => true])->save();
        $this->mock(PingwinService::class, function ($m) {
            $m->shouldReceive('sync')->andReturn([]);
            $m->shouldReceive('fetchItemSales')->andThrow(new \RuntimeException('servidor vazio'));
        });

        (new ScheduledRestaurantSyncJob())->handle(app(AlertService::class));

        $this->assertDatabaseHas('alerts', ['company_id' => $owner->id, 'title' => 'Sync automático: falhas']);
    }

    // ── Comando manual ──────────────────────────────────────────────────────────

    public function test_command_writes_only_with_switch_on_and_dry_run_always_works(): void
    {
        $this->dailyNet($this->baixa, '2026-09-30', 1000);
        $this->pingwinRows = [$this->row('1099845342604', '2026-09-30', '1', 10)];
        $args = ['company' => $this->company->id, '--from' => '2026-09-30', '--to' => '2026-09-30'];

        $this->artisan('pingwin:item-sales', $args)->expectsOutputToContain('interruptor está desligado')->assertFailed();
        $this->assertSame([], $this->calls);

        $this->artisan('pingwin:item-sales', $args + ['--dry-run' => true])
            ->expectsOutputToContain('Simulação concluída')->assertSuccessful();
        $this->assertSame(0, PingwinItemSale::count());

        $this->company->forceFill(['pingwin_item_sales_enabled' => true])->save();
        $this->artisan('pingwin:item-sales', $args)->expectsOutputToContain('Concluído')->assertSuccessful();
        $this->assertSame(1, PingwinItemSale::count());
    }
}
