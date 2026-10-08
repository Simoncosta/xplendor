<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ScheduledRestaurantSyncJob;
use App\Jobs\SyncItemSalesPeriodJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinDailySale;
use App\Models\PingwinItemSale;
use App\Models\PingwinItemSalesDay;
use App\Models\PingwinLocation;
use App\Models\User;
use App\Services\AlertService;
use App\Services\CompanyModuleService;
use App\Services\CoverManagerService;
use App\Services\PingwinItemHistoryService;
use App\Services\PingwinItemSalesService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * XPLENDOR — F1-2 do marketing da restauração: início de cada loja, histórico das vendas
 * por artigo, releitura dos dias marcados, catálogo completo e período manual. O PingWin
 * é simulado (sem docker, sem rede).
 */
class PingwinItemHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private PingwinLocation $baixa;
    private PingwinLocation $costa;

    /** Pedidos feitos ao PingWin simulado: ['item_sales', from, to] | ['year', store, year] | ['catalog', complete, byFamily]. */
    public array $calls = [];
    public array $pingwinRows = [];
    /** [store][year] => 12 acumulados. */
    public array $years = [];
    /** IDs dos artigos ativos e dos anulados que a leitura do catálogo devolve. */
    public array $catalogPlain = [];
    public array $catalogDeleted = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-07 10:00:00');
        Sleep::fake();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500009400', 'fiscal_name' => 'Yuko', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->baixa = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => '1099845342604', 'display_name' => 'Baixa', 'is_active' => true]);
        $this->costa = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => '584955579139649880', 'display_name' => 'Costa Cabral', 'is_active' => true]);
        $this->company->forceFill(['pingwin_item_sales_enabled' => true])->save();

        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private PingwinItemHistoryTest $t) {}

            public function sync(int $companyId, ?string $date = null): array
            {
                return ['ok' => true];
            }

            public function fetchItemSales(int $companyId, string $start, string $end): array
            {
                $this->t->calls[] = ['item_sales', $start, $end];

                return array_values(array_filter($this->t->pingwinRows, fn ($r) => $r['date'] >= $start && $r['date'] <= $end));
            }

            public function fetchHourlySales(int $companyId, string $start, string $end): array
            {
                $this->t->calls[] = ['hourly_sales', $start, $end];

                return [];
            }

            public function fetchStoreYear(int $companyId, string $storeId, int $year, string $locals = ''): array
            {
                $this->t->calls[] = ['year', $storeId, $year, $locals];

                return $this->t->years[$storeId][$year] ?? array_fill(0, 12, 0.0);
            }

            public function syncCatalog(int $companyId, bool $complete = false): int
            {
                $this->t->calls[] = ['catalog', $complete];
                foreach ($this->t->catalogPlain as $id) {
                    PingwinCatalogItem::updateOrCreate(['company_id' => $companyId, 'pingwin_id' => (string) $id], ['description' => "Artigo {$id}", 'is_active' => true]);
                }
                $this->lastCatalogDiagnostics = ['pages' => [], 'announced_total' => null, 'stopped' => 'página vazia', 'collected' => count($this->t->catalogPlain)];
                $this->lastCatalogDeletedIds = $this->t->catalogDeleted;

                return count($this->t->catalogPlain);
            }
        });
    }

    private function row(PingwinLocation $loc, string $date, string $product, float $net): array
    {
        return ['store_id' => $loc->winrest_store_id, 'date' => $date, 'product_id' => $product, 'product_code' => $product,
            'product_name' => "Artigo {$product}", 'family_id' => '1', 'family_path' => 'Família \\ Comidas \\ Francesinhas',
            'qty' => 1.0, 'net' => $net, 'tax' => 0.0, 'gross' => $net];
    }

    private function cumulative(array $monthly): array
    {
        $acc = 0.0;

        return array_map(function ($v) use (&$acc) {
            $acc += $v;

            return $acc;
        }, $monthly);
    }

    private function history(): PingwinItemHistoryService
    {
        return app(PingwinItemHistoryService::class);
    }

    private function callsOf(string $kind): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c[0] === $kind));
    }

    // ── Início de cada loja ────────────────────────────────────────────────────

    public function test_detects_first_month_per_store_going_back_while_january_has_sales(): void
    {
        // Costa Cabral: começa em junho de 2026. Baixa: vende desde março de 2025.
        $this->years['584955579139649880'][2026] = $this->cumulative([0, 0, 0, 0, 0, 900, 1000, 1000, 1000, 500, 0, 0]);
        $this->years['1099845342604'][2026] = $this->cumulative([300, 300, 300, 300, 300, 300, 300, 300, 300, 100, 0, 0]);
        $this->years['1099845342604'][2025] = $this->cumulative([0, 0, 50, 300, 300, 300, 300, 300, 300, 300, 300, 300]);

        $result = $this->history()->detectStarts($this->company->id);

        $this->assertSame('2025-03-01', $this->baixa->fresh()->sales_first_month->toDateString());
        $this->assertSame('2026-06-01', $this->costa->fresh()->sales_first_month->toDateString());
        $this->assertNotNull($this->costa->fresh()->sales_start_checked_at);
        // Baixa: 2026 e 2025; Costa: só 2026. Locals vazio por omissão.
        $this->assertSame([['year', '1099845342604', 2026, ''], ['year', '1099845342604', 2025, ''], ['year', '584955579139649880', 2026, '']], $this->calls);
        $this->assertSame([2026, 2025], array_keys($result[0]['years']));
        Sleep::assertSleptTimes(2); // 20 s entre pedidos, nada antes do primeiro

        // Já detetadas: não se volta a pedir.
        $this->calls = [];
        $this->history()->detectStarts($this->company->id);
        $this->assertSame([], $this->calls);
    }

    public function test_refuses_to_conclude_when_annual_report_contradicts_daily_net(): void
    {
        // O líquido diário tem vendas em maio de 2026, mas o anual dá 0 em maio.
        PingwinDailySale::create(['company_id' => $this->company->id, 'location_id' => $this->costa->id, 'business_date' => '2026-05-20', 'net_cents' => 50000]);
        $this->years['584955579139649880'][2026] = $this->cumulative([0, 0, 0, 0, 0, 900, 1000, 1000, 1000, 500, 0, 0]);
        $this->years['1099845342604'][2026] = $this->cumulative([0, 0, 0, 0, 0, 0, 0, 0, 100, 100, 0, 0]);

        try {
            $this->history()->detectStarts($this->company->id);
            $this->fail('Devia recusar.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('não fiável', $e->getMessage());
            $this->assertStringContainsString('05/2026', $e->getMessage());
        }
        $this->assertNull($this->costa->fresh()->sales_first_month);
        $this->assertNull($this->costa->fresh()->sales_start_checked_at);
    }

    public function test_detection_uses_the_locals_from_the_integration_config_unless_given(): void
    {
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'pingwin', 'status' => 'active', 'access_token' => 'x',
            'config' => ['username' => 'u', 'database' => 'd', 'annual_locals' => '584955579139621602,1649601157547']]);

        $this->history()->detectStarts($this->company->id);
        $this->assertSame('584955579139621602,1649601157547', $this->calls[0][3]);

        $this->calls = [];
        $this->history()->detectStarts($this->company->id, '11,22', true); // o comando sobrepõe-se
        $this->assertSame('11,22', $this->calls[0][3]);
    }

    public function test_passes_explicit_locals_and_store_without_sales_has_no_start(): void
    {
        $this->history()->detectStarts($this->company->id, '11,22');

        $this->assertSame('11,22', $this->calls[0][3]);
        $this->assertNull($this->baixa->fresh()->sales_first_month);
        $this->assertNotNull($this->baixa->fresh()->sales_start_checked_at);
        $this->assertCount(2, $this->calls); // um ano por loja, sem vendas → para
    }

    // ── Histórico ──────────────────────────────────────────────────────────────

    public function test_backfill_goes_back_in_7_day_blocks_until_first_month_and_completes(): void
    {
        $this->baixa->forceFill(['sales_first_month' => '2026-09-01', 'sales_start_checked_at' => now()])->save();
        $this->costa->forceFill(['sales_first_month' => '2026-09-20', 'sales_start_checked_at' => now()])->save();
        // Já lido pela noite: de 30/09 a 06/10.
        $this->pingwinRows = [
            $this->row($this->baixa, '2026-10-01', 'A', 10),
            $this->row($this->baixa, '2026-09-03', 'B', 5),
            $this->row($this->costa, '2026-09-21', 'C', 7),
        ];
        app(PingwinItemSalesService::class)->sync($this->company->id, '2026-09-30', '2026-10-06');
        $this->calls = [];

        $result = $this->history()->backfill($this->company->id);

        $this->assertSame([
            ['item_sales', '2026-09-23', '2026-09-29'], ['item_sales', '2026-09-16', '2026-09-22'],
            ['item_sales', '2026-09-09', '2026-09-15'], ['item_sales', '2026-09-02', '2026-09-08'],
            ['item_sales', '2026-09-01', '2026-09-01'],
        ], $this->calls);
        $this->assertTrue($result['complete']);
        Sleep::assertSleptTimes(4);
        $this->assertSame('2026-09-03', $this->baixa->fresh()->sales_since->toDateString());
        $this->assertSame('2026-09-21', $this->costa->fresh()->sales_since->toDateString());
        $this->assertNotNull($this->costa->fresh()->history_complete_at);
        // A Costa Cabral não tem dias marcados antes do seu primeiro mês (20/09).
        $this->assertSame(0, PingwinItemSalesDay::where('location_id', $this->costa->id)->where('business_date', '<', '2026-09-20')->count());
        // E os dias antes do primeiro dia com vendas (20/09 na Costa, 01/09 e 02/09 na Baixa)
        // ficam como "sem vendas", não como vazios protegidos para voltar a ler.
        $this->assertSame('empty', PingwinItemSalesDay::where('location_id', $this->costa->id)->where('business_date', '2026-09-20')->value('status'));
        $this->assertSame(['empty', 'empty'], PingwinItemSalesDay::where('location_id', $this->baixa->id)->whereIn('business_date', ['2026-09-01', '2026-09-02'])->orderBy('business_date')->pluck('status')->all());
        $this->assertSame('empty_protected', PingwinItemSalesDay::where('location_id', $this->baixa->id)->where('business_date', '2026-09-04')->value('status'));
        $this->assertSame(36, PingwinItemSalesDay::where('location_id', $this->baixa->id)->count()); // 01/09 a 06/10
    }

    public function test_backfill_respects_the_nightly_budget_and_resumes_from_data(): void
    {
        $this->baixa->forceFill(['sales_first_month' => '2026-08-01', 'sales_start_checked_at' => now()])->save();
        $this->costa->forceFill(['sales_first_month' => '2026-08-01', 'sales_start_checked_at' => now()])->save();

        $first = $this->history()->backfill($this->company->id, 2);
        $this->assertSame(2, $first['calls']);
        $this->assertFalse($first['complete']);
        $this->assertSame('2026-09-23', $first['reached']);
        $this->assertNull($this->baixa->fresh()->history_complete_at);

        $this->calls = [];
        $second = $this->history()->backfill($this->company->id, 2);
        $this->assertSame([['item_sales', '2026-09-16', '2026-09-22'], ['item_sales', '2026-09-09', '2026-09-15']], $this->calls);
        $this->assertSame('2026-09-09', $second['reached']);
    }

    public function test_backfill_does_nothing_without_detected_start(): void
    {
        $result = $this->history()->backfill($this->company->id);
        $this->assertSame(0, $result['calls']);
        $this->assertSame([], $this->calls);
    }

    public function test_marked_days_are_reread_up_to_three_times(): void
    {
        $mark = fn (PingwinLocation $l, string $date, string $status, int $reads, string $synced = '2026-10-01 05:00:00') => PingwinItemSalesDay::create([
            'company_id' => $this->company->id, 'location_id' => $l->id, 'business_date' => $date, 'status' => $status, 'reads_count' => $reads,
            'synced_at' => $synced,
        ]);
        $mark($this->baixa, '2026-09-10', 'mismatch', 1);       // relê
        $mark($this->costa, '2026-09-12', 'empty_protected', 2); // mesmo bloco
        $mark($this->baixa, '2026-09-25', 'mismatch', 3);       // já leu 3 vezes
        $mark($this->baixa, '2026-10-02', 'mismatch', 1);       // dentro da janela da noite
        $mark($this->baixa, '2026-09-05', 'ok', 1);
        $mark($this->baixa, '2026-09-01', 'mismatch', 1, '2026-10-07 04:00:00'); // já lido hoje

        $result = $this->history()->rereadMarked($this->company->id, 5);

        $this->assertSame([['item_sales', '2026-09-10', '2026-09-16']], $this->calls);
        $this->assertSame(1, $result['calls']);
        $this->assertSame(2, PingwinItemSalesDay::where('location_id', $this->baixa->id)->where('business_date', '2026-09-10')->value('reads_count'));
        $this->assertSame(3, PingwinItemSalesDay::where('location_id', $this->costa->id)->where('business_date', '2026-09-12')->value('reads_count'));
    }

    public function test_days_before_the_first_sale_are_not_reread(): void
    {
        $this->baixa->forceFill(['sales_first_month' => '2026-03-01', 'sales_since' => '2026-03-13', 'history_complete_at' => now()])->save();
        foreach (['2026-03-05', '2026-03-12', '2026-03-15'] as $d) {
            PingwinItemSalesDay::create(['company_id' => $this->company->id, 'location_id' => $this->baixa->id, 'business_date' => $d,
                'status' => 'empty_protected', 'reads_count' => 1, 'synced_at' => '2026-10-01 05:00:00']);
        }

        $result = $this->history()->rereadMarked($this->company->id, 5);

        $this->assertSame([['item_sales', '2026-03-15', '2026-03-21']], $this->calls); // só o dia depois da primeira venda
        $this->assertSame(1, $result['calls']);
        $this->assertSame(['empty', 'empty'], PingwinItemSalesDay::where('location_id', $this->baixa->id)->whereIn('business_date', ['2026-03-05', '2026-03-12'])->pluck('status')->all());
    }

    // ── Catálogo completo ──────────────────────────────────────────────────────

    public function test_sold_articles_missing_are_looked_up_in_the_annulled_ones(): void
    {
        $sale = fn (string $id, string $date, string $name) => ['company_id' => $this->company->id, 'location_id' => $this->baixa->id,
            'business_date' => $date, 'product_pingwin_id' => $id, 'product_code' => "C{$id}", 'product_name' => $name,
            'family_pingwin_id' => '7', 'family_path' => 'Família \\ Bebidas \\ Vinho Branco', 'net_cents' => 100, 'quantity' => 1];
        PingwinItemSale::insert([
            $sale('1', '2026-09-01', 'Cerveja'),
            $sale('56161405156018077', '2026-08-17', 'Soalheiro'),   // anulado no PingWin
            $sale('777', '2026-09-02', 'Desconhecido'),              // nem ativo nem anulado
            $sale('999', '2026-05-01', 'Antigo'),                    // fora dos 90 dias
        ]);
        $this->catalogPlain = ['1', '100'];
        $this->catalogDeleted = ['56161405156018077', '888'];

        $r = $this->history()->syncCatalogComplete($this->company->id);

        $this->assertSame([['catalog', true]], $this->calls); // um só pedido, sem leitura por família
        $this->assertSame(3, $r['sold']);
        $this->assertSame(3, $r['missing_before']);
        $this->assertSame(1, $r['annulled_added']);
        $this->assertSame(1, $r['missing_after']);
        $this->assertSame(['777'], $r['missing_sample']);
        $item = PingwinCatalogItem::where('pingwin_id', '56161405156018077')->first();
        $this->assertFalse((bool) $item->is_active); // entra como anulado
        $this->assertSame('Soalheiro', $item->description);
        $this->assertSame('C56161405156018077', $item->code);
        $this->assertSame('Vinho Branco', $item->family);
        $this->assertNull(PingwinCatalogItem::where('pingwin_id', '888')->first()); // anulados não vendidos não entram
        $this->assertSame(['777'], PingwinItemHistoryService::missingSoldProductIds($this->company->id));
    }

    // ── Job das 05:00 ──────────────────────────────────────────────────────────

    private function nightlySetup(): void
    {
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'pingwin', 'status' => 'active', 'access_token' => 'x', 'config' => ['username' => 'u', 'database' => 'd']]);
        $this->mock(CoverManagerService::class, function ($m) {
            $m->shouldReceive('companyToken')->andReturn(null);
            $m->shouldReceive('syncableLocations')->andReturn(collect());
        });
    }

    public function test_nightly_job_runs_window_detection_history_and_sunday_catalog(): void
    {
        $this->nightlySetup();
        $this->travelTo('2026-10-04 05:00:00'); // domingo
        $this->years['1099845342604'][2026] = $this->cumulative([0, 0, 0, 0, 0, 0, 0, 0, 100, 100, 0, 0]);
        $this->years['584955579139649880'][2026] = $this->cumulative([0, 0, 0, 0, 0, 0, 0, 0, 100, 100, 0, 0]);

        (new ScheduledRestaurantSyncJob())->handle(app(AlertService::class));

        $this->assertSame(['item_sales', '2026-09-27', '2026-10-03'], $this->calls[0]); // janela da noite
        $this->assertCount(2, $this->callsOf('year'));
        $this->assertSame([['item_sales', '2026-09-27', '2026-10-03'], ['item_sales', '2026-09-20', '2026-09-26'],
            ['item_sales', '2026-09-13', '2026-09-19'], ['item_sales', '2026-09-06', '2026-09-12'], ['item_sales', '2026-09-01', '2026-09-05']],
            $this->callsOf('item_sales'));
        $this->assertSame([['catalog', true]], $this->callsOf('catalog'));
        $this->assertNotNull($this->baixa->fresh()->history_complete_at);
        // F1-3: o retrato da qualidade dos dados fica atualizado na mesma noite.
        $this->assertSame(1, \App\Models\RestaurantDataQuality::where('company_id', $this->company->id)->count());
        // F3: os sinais de "O que publicar e quando" calculados no fim da noite.
        $this->assertNotNull(\App\Models\RestaurantDataQuality::where('company_id', $this->company->id)->value('signals_computed_at'));
    }

    public function test_nightly_job_without_sunday_skips_catalog_and_switch_off_skips_everything(): void
    {
        $this->nightlySetup();
        (new ScheduledRestaurantSyncJob())->handle(app(AlertService::class)); // quarta-feira
        $this->assertSame([], $this->callsOf('catalog'));

        $this->calls = [];
        $this->company->forceFill(['pingwin_item_sales_enabled' => false])->save();
        $this->travelTo('2026-10-11 05:00:00'); // domingo
        (new ScheduledRestaurantSyncJob())->handle(app(AlertService::class));
        $this->assertSame([], $this->calls);
    }

    // ── Período manual ─────────────────────────────────────────────────────────

    public function test_manual_period_rereads_item_sales_after_the_days_only_with_switch_on(): void
    {
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        $user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->mock(CoverManagerService::class, function ($m) {
            $m->shouldReceive('companyToken')->andReturn(null);
            $m->shouldReceive('syncableLocations')->andReturn(collect());
        });

        // Inclui hoje: o job só lê até ontem, em blocos de 7 dias.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/companies/{$this->company->id}/integrations/restaurant/sync-period", ['from' => '2026-09-28', 'to' => '2026-10-07'])
            ->assertStatus(200);
        $this->assertSame([['item_sales', '2026-09-28', '2026-10-04'], ['item_sales', '2026-10-05', '2026-10-06']], $this->callsOf('item_sales'));

        $this->calls = [];
        $this->company->forceFill(['pingwin_item_sales_enabled' => false])->save();
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/companies/{$this->company->id}/integrations/restaurant/sync-period", ['from' => '2026-09-28', 'to' => '2026-10-02'])
            ->assertStatus(200);
        $this->assertSame([], $this->callsOf('item_sales'));
    }

    public function test_period_job_accepts_92_days(): void
    {
        (new SyncItemSalesPeriodJob($this->company->id, '2026-07-07', '2026-10-06'))
            ->handle(app(PingwinItemSalesService::class), app(AlertService::class));

        $this->assertCount(14, $this->callsOf('item_sales'));
    }

    // ── Comandos (interruptor) ─────────────────────────────────────────────────

    public function test_commands_require_the_switch(): void
    {
        $this->company->forceFill(['pingwin_item_sales_enabled' => false])->save();
        $this->artisan('pingwin:item-history', ['company' => $this->company->id])->expectsOutputToContain('interruptor está desligado')->assertFailed();
        $this->artisan('pingwin:catalog-complete', ['company' => $this->company->id])->expectsOutputToContain('interruptor está desligado')->assertFailed();
        $this->assertSame([], $this->calls);
    }

    public function test_history_command_detects_and_imports(): void
    {
        $this->years['1099845342604'][2026] = $this->cumulative([0, 0, 0, 0, 0, 0, 0, 0, 100, 100, 0, 0]);
        $this->years['584955579139649880'][2026] = $this->cumulative([0, 0, 0, 0, 0, 0, 0, 0, 100, 100, 0, 0]);

        $this->artisan('pingwin:item-history', ['company' => $this->company->id, '--calls' => 20])
            ->expectsOutputToContain('primeiro mês com vendas 2026-09-01')
            ->expectsOutputToContain('Histórico completo.')
            ->assertSuccessful();
        $this->assertNotNull($this->costa->fresh()->history_complete_at);
    }
}
