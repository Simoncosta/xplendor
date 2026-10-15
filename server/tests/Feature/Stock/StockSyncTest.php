<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use App\Jobs\DispatchStockSyncJob;
use App\Jobs\SyncPingwinStockJob;
use App\Models\PingwinStock;
use App\Models\PingwinStockItem;
use App\Models\PingwinStockRun;
use App\Models\PingwinStockSnapshot;
use App\Models\PingwinWarehouse;
use App\Services\PingwinService;
use App\Services\Restaurant\StockSyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Fatia 7a — o espelho do stock (SÓ LEITURA): o resultado do "Python" (a forma do read_stock, com os
 * números do 100415 COCA COLA ZERO 0.35) grava uma linha por armazém, a fotografia do dia e o que a
 * leitura disse do artigo; quem entra na leitura completa; os armazéns com as lojas servidas e a
 * valorização; o comando e o agendamento das 04:15.
 */
class StockSyncTest extends TestCase
{
    use BuildsStock;
    use RefreshDatabase;

    /** @var list<array> pedidos ao "Python" */
    public array $calls = [];

    /** @var array<string, array> respostas por modo */
    public array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpYuko();
        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $test) {}

            protected function invoke(array $payload): array
            {
                $this->test->calls[] = $payload;

                return $this->test->responses[$payload['mode']] ?? ['ok' => false, 'error' => 'sem resposta'];
            }
        });
    }

    private function cocaZero(array $bo = []): array
    {
        return self::stockRead('584955579139631978', [
            array_merge(['warehouse_id' => 'WBO', 'warehouse_code' => '1', 'warehouse_name' => 'Armazém Yuko BO', 'qnt' => 5112.0, 'pcm' => 0.534564, 'pcu' => 0.542976, 'valorization_date' => '2026-10-12'], $bo),
            ['warehouse_id' => 'WB', 'warehouse_code' => 'BAIXA', 'warehouse_name' => 'Armazém Yuko Baixa', 'qnt' => 1896.0, 'pcm' => 0.527748, 'pcu' => 0.52775, 'valorization_date' => '2026-05-27'],
            ['warehouse_id' => 'WC', 'warehouse_code' => '2', 'warehouse_name' => 'Armazém Yuko', 'qnt' => 3432.0, 'pcm' => 0.527746, 'pcu' => 0.52775, 'valorization_date' => '2026-07-29'],
        ], ['code' => '100415', 'description' => 'COCA COLA ZERO 0.35']);
    }

    public function test_a_read_writes_one_row_per_warehouse_the_daily_snapshot_and_the_article_state(): void
    {
        $this->responses['stock'] = ['ok' => true, 'stock' => ['584955579139631978' => $this->cocaZero()], 'errors' => [], 'seconds' => ['584955579139631978' => 0.9]];
        $stats = app(StockSyncService::class)->syncBatch($this->c, ['584955579139631978']);

        $this->assertSame(['articles' => 1, 'rows' => 3, 'failed' => 0], array_intersect_key($stats, array_flip(['articles', 'rows', 'failed'])));
        $this->assertSame(['mode' => 'stock', 'product_ids' => ['584955579139631978']], array_intersect_key($this->calls[0], array_flip(['mode', 'product_ids'])));
        $rows = PingwinStock::where('company_id', $this->c)->orderBy('warehouse_pingwin_id')->get();
        $this->assertSame([['WB', 1896.0], ['WBO', 5112.0], ['WC', 3432.0]], $rows->map(fn ($r) => [$r->warehouse_pingwin_id, $r->qnt])->all());
        $this->assertSame(10440.0, $rows->sum('qnt'));
        $bo = $rows->firstWhere('warehouse_pingwin_id', 'WBO');
        $this->assertEqualsWithDelta(0.534564, $bo->pcm, 1e-9);
        $this->assertSame('2026-10-12', $bo->valorization_date->toDateString());
        $this->assertNull($bo->min_stock);
        $this->assertSame(3, PingwinStockSnapshot::where('company_id', $this->c)->where('snapshot_date', '2026-10-15')->count());
        $this->assertTrue(PingwinStockItem::where('company_id', $this->c)->where('product_pingwin_id', '584955579139631978')->value('movestock'));

        // A segunda leitura do dia substitui a fotografia; um armazém que deixou de vir sai do espelho.
        $read = $this->cocaZero(['qnt' => 5000.0]);
        unset($read['rows'][2]);
        $this->responses['stock']['stock']['584955579139631978'] = $read;
        app(StockSyncService::class)->syncBatch($this->c, ['584955579139631978']);
        $this->assertSame(2, PingwinStock::where('company_id', $this->c)->count());
        $this->assertSame(2, PingwinStockSnapshot::where('company_id', $this->c)->where('snapshot_date', '2026-10-15')->count());
        $this->assertSame(5000.0, PingwinStockSnapshot::where('company_id', $this->c)->where('warehouse_pingwin_id', 'WBO')->value('qnt'));
    }

    public function test_a_failed_article_is_recorded_and_does_not_stop_the_others(): void
    {
        $this->responses['stock'] = ['ok' => true, 'stock' => ['584955579139631978' => $this->cocaZero()], 'errors' => ['999' => 'RuntimeError: OPEN falhou (HTTP 500)'], 'seconds' => []];
        $stats = app(StockSyncService::class)->syncBatch($this->c, ['584955579139631978', '999']);
        $this->assertSame([1, 1], [$stats['articles'], $stats['failed']]);
        $this->assertSame('RuntimeError: OPEN falhou (HTTP 500)', PingwinStockItem::where('product_pingwin_id', '999')->value('last_error'));
    }

    public function test_candidates_bought_in_12_months_in_a_recipe_or_with_stock_and_not_known_without_stock(): void
    {
        foreach (['BOUGHT', 'OLD', 'COMP', 'STOCKED', 'NOSTOCK', 'INACTIVE', 'NOTHING', 'DISH'] as $pid) {
            $this->catalog($this->c, $pid, ['is_active' => $pid !== 'INACTIVE', 'forpurchase' => true, 'forsale' => $pid === 'DISH']);
        }
        $this->boughtIn('BOUGHT', 'WBO', '2026-09-01', 10, 1000);
        $this->boughtIn('OLD', 'WBO', '2025-08-01', 10, 1000);          // há mais de 12 meses
        $this->boughtIn('NOSTOCK', 'WBO', '2026-09-01', 1, 1000);
        $this->boughtIn('INACTIVE', 'WBO', '2026-09-01', 1, 1000);
        $this->movestock('NOSTOCK', false);                               // a leitura disse "Sem stock"
        $this->persistBom($this->c, $this->bom('DISH', [['00000000', 'BD', '2026-01-01', ['S1' => true], [$this->bomLine('COMP', 1)]]]));
        $this->stock('STOCKED', 'WB', 3);

        $this->assertSame(['BOUGHT', 'COMP', 'STOCKED'], app(StockSyncService::class)->candidates($this->c));
        $this->assertSame(836, StockSyncService::estimateSeconds(868));    // a leitura completa da Yuko no dev: ~14 min
    }

    public function test_warehouses_keep_the_served_stores_and_the_cost_methods(): void
    {
        $this->responses['warehouses'] = ['ok' => true, 'errors' => [], 'warehouses' => [
            ['id' => 'WBO', 'code' => '1', 'description' => 'Armazém Yuko BO', 'deleted' => 0, 'inv_costmethod' => 0, 'exit_costmethod' => 0, 'main_store_id' => null,
                'stores' => [['store_id' => 'BO', 'code' => 'BO', 'name' => 'Yuko BO', 'served' => true], ['store_id' => 'S1', 'code' => 'YUKOPORTO', 'name' => 'Tabern Yuko Baixa', 'served' => false]]],
            ['id' => 'WT', 'code' => '3', 'description' => 'XPLENDOR TESTE', 'deleted' => 1, 'inv_costmethod' => 1, 'exit_costmethod' => 1],
        ]];
        $this->assertSame(2, app(StockSyncService::class)->syncWarehouses($this->c));
        $bo = PingwinWarehouse::where('company_id', $this->c)->where('pingwin_id', 'WBO')->first();
        $this->assertSame(['BO'], $bo->servedStoreIds());
        $this->assertSame([0, 0, false], [$bo->inv_costmethod, $bo->exit_costmethod, $bo->deleted]);
        $t = PingwinWarehouse::where('company_id', $this->c)->where('pingwin_id', 'WT')->first();
        $this->assertSame([true, 1], [$t->deleted, $t->inv_costmethod]);
    }

    public function test_the_command_reads_saves_and_records_the_run(): void
    {
        $this->responses['warehouses'] = ['ok' => true, 'errors' => [], 'warehouses' => []];
        $this->responses['stock'] = ['ok' => true, 'stock' => ['584955579139631978' => $this->cocaZero()], 'errors' => [], 'seconds' => ['584955579139631978' => 0.87]];
        $this->artisan('pingwin:sync-stock', ['company' => $this->c, '--product' => ['584955579139631978']])
            ->expectsOutputToContain('Artigos a ler: 1. Tempo estimado')
            ->assertSuccessful();
        $this->assertSame(3, PingwinStock::where('company_id', $this->c)->count());
        $run = PingwinStockRun::where('company_id', $this->c)->latest('id')->first();
        $this->assertSame(['comando', 'done', 1, 1, 3], [$run->origin, $run->status, $run->products_total, $run->products_read, $run->rows]);

        // Dry-run: lê e mostra, não grava.
        PingwinStock::query()->delete();
        $this->artisan('pingwin:sync-stock', ['company' => $this->c, '--product' => ['584955579139631978'], '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, PingwinStock::count());
    }

    public function test_nightly_schedule_at_04_15_behind_the_external_switch_and_chained_batches(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'pingwin-stock-nightly');
        $this->assertNotNull($event);
        $this->assertSame('15 4 * * *', $event->expression);
        $this->assertSame('Europe/Lisbon', $event->timezone);
        $this->assertContains('pingwin-stock-nightly', config('scheduler.external_job_names'));

        Bus::fake([SyncPingwinStockJob::class]);
        $this->stock('STOCKED', 'WB', 3);
        $this->catalog($this->c, 'STOCKED');
        (new DispatchStockSyncJob())->handle(app(StockSyncService::class));
        Bus::assertDispatched(SyncPingwinStockJob::class, fn ($j) => $j->companyId === $this->c && $j->remaining === ['STOCKED'] && $j->withWarehouses);
        $this->assertSame('agendada', PingwinStockRun::latest('id')->value('origin'));
    }
}
