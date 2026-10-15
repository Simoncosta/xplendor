<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DispatchBomSyncJob;
use App\Jobs\SyncPingwinBomsJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinProductBom;
use App\Models\PingwinProductBomLine;
use App\Models\PingwinProductBomLineCost;
use App\Models\PingwinProductBomStore;
use App\Models\PingwinUnit;
use App\Models\PingwinWarehouse;
use App\Services\PingwinService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRecipes;
use Tests\TestCase;

/**
 * Fatia 1 — espelho das fichas técnicas: gravação (versões, lojas, linhas, custo por armazém, artigo
 * e unidades), substituição numa nova leitura, transação por artigo, falhas que não param os outros,
 * o comando pingwin:sync-boms (lotes, --product, --limit, --dry-run) e o agendamento noturno.
 */
class PingwinBomSyncTest extends TestCase
{
    use BuildsRecipes;
    use RefreshDatabase;

    private Company $company;
    /** @var list<array> pedidos feitos ao "Python" */
    public array $requests = [];
    /** @var array<string, array> fichas que o "Python" devolve */
    public array $boms = [];
    /** @var array<string, string> erros que o "Python" devolve */
    public array $errors = [];

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '514148497', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'pingwin', 'status' => 'active',
            'access_token' => 'x', 'config' => ['username' => 'op', 'database' => 'yuko']]);

        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $test) {}

            protected function invoke(array $payload): array
            {
                $this->test->requests[] = $payload;
                $ids = $payload['product_ids'];
                $boms = [];
                foreach ($ids as $id) {
                    if (isset($this->test->boms[$id])) {
                        $boms[$id] = $this->test->boms[$id];
                        // semi-produtos vêm no mesmo lote (como no Python)
                        foreach ($this->test->boms[$id]['versions'] as $v) {
                            foreach ($v['lines'][''] as $l) {
                                if ($l['isbom'] && isset($this->test->boms[$l['component_id']])) {
                                    $boms[$l['component_id']] = $this->test->boms[$l['component_id']];
                                }
                            }
                        }
                    }
                }

                return ['ok' => true, 'mode' => 'boms', 'boms' => $boms,
                    'errors' => array_intersect_key($this->test->errors, array_flip($ids)), 'product_list' => null];
            }
        });
    }

    private function coca(): array
    {
        return $this->bom('1649601156985', [
            ['00000000', '584955579139626657', '2026-05-06', ['1099511639284' => true, '1099845342604' => true, '584955579139649880' => false],
                [$this->bomLine('62590249681593592', 1, ['component_code' => '100366', 'component_description' => 'COCA-COLA ZERO GARRAFA', 'unit_cost' => 0.5275, 'cost' => 0.5275])]],
        ], [
            'code' => '23', 'description' => 'Coca Cola 0', 'cost_warehouse_id' => 'W1',
            'unit_list' => [['id' => 'CX24', 'description' => 'Caixa 24 Unidades', 'shortname' => 'CX 24', 'unit_value' => 1.0, 'parent_id' => '11001', 'parent_qnt' => 1.0]],
        ]);
    }

    public function test_persist_writes_versions_stores_lines_costs_article_and_units(): void
    {
        $item = $this->catalog($this->company->id, '1649601156985', ['has_bom' => true, 'forpurchase' => true]);
        PingwinUnit::create(['company_id' => $this->company->id, 'pingwin_id' => 'CX24', 'description' => 'Caixa 24 Unidades', 'unit_value' => 1]);
        $this->persistBom($this->company->id, $this->coca());

        $bom = PingwinProductBom::where('company_id', $this->company->id)->sole();
        $this->assertSame(['1649601156985', '584955579139626657', '00000000', '2026-05-06', false],
            [$bom->product_pingwin_id, $bom->bom_pingwin_id, $bom->version_key, $bom->valid_from->toDateString(), $bom->deleted]);
        $stores = PingwinProductBomStore::where('bom_pingwin_id', $bom->bom_pingwin_id)->orderBy('store_pingwin_id')->get();
        $this->assertSame(['1099511639284' => true, '1099845342604' => true, '584955579139649880' => false],
            $stores->mapWithKeys(fn ($s) => [$s->store_pingwin_id => $s->active])->sortKeys()->all());
        $line = PingwinProductBomLine::where('bom_pingwin_id', $bom->bom_pingwin_id)->sole();
        $this->assertSame(['100366', 'COCA-COLA ZERO GARRAFA', 1.0, 1.0, false], [$line->component_code, $line->component_description, $line->qnt, $line->qnt_base, $line->is_bom]);
        $costs = PingwinProductBomLineCost::where('bom_line_id', $line->id)->orderBy('warehouse_pingwin_id')->get();
        $this->assertSame(['' => 0.5275, 'W1' => 1.055], $costs->mapWithKeys(fn ($c) => [$c->warehouse_pingwin_id => $c->cost])->all());
        $this->assertSame('Armazém 1', PingwinWarehouse::where('pingwin_id', 'W1')->value('description'));

        $item->refresh();
        $this->assertSame(['11001', 'W1', 236, false], [$item->base_unit_id, $item->cost_warehouse_id, $item->saleprice_wo_tax_cents, $item->forpurchase]);
        $this->assertNotNull($item->bom_synced_at);
        $this->assertSame(1.0, PingwinUnit::where('pingwin_id', 'CX24')->first()->parent_qnt);
        $this->assertSame('11001', PingwinUnit::where('pingwin_id', 'CX24')->value('parent_pingwin_id'));
    }

    public function test_a_new_read_replaces_the_article_versions(): void
    {
        $this->persistBom($this->company->id, $this->coca());
        // Na PingWin: criada uma versão nova e apagada a antiga.
        $next = $this->bom('1649601156985', [['00000001', '584955579139801484', '2026-10-15', ['1099511639284' => true],
            [$this->bomLine('584955579139631978', 1), $this->bomLine('X', 2)]]]);
        $this->persistBom($this->company->id, $next);

        $this->assertSame(['584955579139801484'], PingwinProductBom::pluck('bom_pingwin_id')->all());
        $this->assertSame(2, PingwinProductBomLine::count());
        $this->assertSame(0, PingwinProductBomStore::where('bom_pingwin_id', '584955579139626657')->count());
        $this->assertSame(4, PingwinProductBomLineCost::count()); // 2 linhas × ("" + W1), sem órfãos

        // Sem ficha na PingWin: o espelho do artigo fica vazio.
        $this->persistBom($this->company->id, $this->bom('1649601156985', []));
        $this->assertSame(0, PingwinProductBom::count() + PingwinProductBomLine::count() + PingwinProductBomLineCost::count());
    }

    public function test_command_reads_in_batches_with_semi_products_and_failures_do_not_stop(): void
    {
        foreach (['A', 'B', 'C'] as $id) {
            $this->catalog($this->company->id, $id, ['has_bom' => true]);
        }
        $this->boms['A'] = $this->bom('A', [['00000000', 'BA', '2026-05-01', ['S1' => true], [$this->bomLine('SEMI', 1, ['isbom' => 1])]]]);
        $this->boms['SEMI'] = $this->bom('SEMI', [['00000000', 'BS', '2026-05-01', ['S1' => true], [$this->bomLine('X', 1)]]]);
        $this->boms['C'] = $this->bom('C', [['00000000', 'BC', '2026-05-01', ['S1' => true], [$this->bomLine('X', 1)]]]);
        $this->errors['B'] = 'HTTP 500';

        $this->artisan('pingwin:sync-boms', ['company' => $this->company->id])
            ->expectsOutputToContain('Artigos a ler: 3')
            ->expectsOutputToContain('B: HTTP 500')
            ->assertExitCode(1);

        $this->assertSame(['A', 'C', 'SEMI'], PingwinProductBom::orderBy('product_pingwin_id')->pluck('product_pingwin_id')->all());
        $this->assertSame(['A', 'B', 'C'], $this->requests[0]['product_ids']);
        $this->assertSame('boms', $this->requests[0]['mode']);
        $this->assertSame(5, $this->requests[0]['max_depth']);
    }

    public function test_command_product_limit_and_dry_run(): void
    {
        $this->boms['A'] = $this->bom('A', [['00000000', 'BA', '2026-05-01', ['S1' => true], [$this->bomLine('X', 1, ['cost' => 0.5])]]]);
        $this->boms['B'] = $this->bom('B', [['00000000', 'BB', '2026-05-01', ['S1' => true], [$this->bomLine('X', 1)]]]);

        $this->artisan('pingwin:sync-boms', ['company' => $this->company->id, '--product' => ['A,B'], '--limit' => 1, '--dry-run' => true])
            ->expectsOutputToContain('Artigos a ler: 1 (dry-run: não grava)')
            ->expectsOutputToContain('custo PingWin 0.5000 €')
            ->assertExitCode(0);
        $this->assertSame(['A'], $this->requests[0]['product_ids']);
        $this->assertSame(0, PingwinProductBom::count());

        $this->artisan('pingwin:sync-boms', ['company' => $this->company->id, '--product' => ['B']])->assertExitCode(0);
        $this->assertSame(['B'], PingwinProductBom::pluck('product_pingwin_id')->all());
    }

    public function test_nightly_dispatch_chains_batches_until_done(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $id = sprintf('P%02d', $i);
            $this->catalog($this->company->id, $id, ['has_bom' => true]);
            $this->boms[$id] = $this->bom($id, [['00000000', "B{$id}", '2026-05-01', ['S1' => true], [$this->bomLine('X', 1)]]]);
        }

        // Os lotes encadeiam-se (cada um despacha o seguinte): corre-os um a um, como o worker.
        Bus::fake([SyncPingwinBomsJob::class]);
        $sync = app(\App\Services\PingwinBomSyncService::class);
        (new DispatchBomSyncJob())->handle($sync);
        $ran = 0;
        while ($job = Bus::dispatched(SyncPingwinBomsJob::class)->get($ran)) {
            $job->handle($sync);
            $ran++;
        }

        $this->assertSame(2, $ran);                                                   // 25 + 5
        $this->assertSame([25, 5], array_map(fn ($r) => count($r['product_ids']), $this->requests));
        $this->assertSame(30, PingwinProductBom::count());
    }

    public function test_the_nightly_schedule_runs_at_05_45_lisbon_behind_the_external_switch(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'pingwin-boms-nightly');
        $this->assertNotNull($event);
        $this->assertSame('45 5 * * *', $event->expression);
        $this->assertSame('Europe/Lisbon', $event->timezone instanceof \DateTimeZone ? $event->timezone->getName() : $event->timezone);
        $this->assertContains('pingwin-boms-nightly', config('scheduler.external_job_names'));
    }

    public function test_the_job_class_is_queued_per_company(): void
    {
        Bus::fake();
        $this->catalog($this->company->id, 'A', ['has_bom' => true]);
        (new DispatchBomSyncJob())->handle(app(\App\Services\PingwinBomSyncService::class));
        Bus::assertDispatched(SyncPingwinBomsJob::class, fn ($j) => $j->companyId === $this->company->id && $j->remaining === ['A']);
    }
}
