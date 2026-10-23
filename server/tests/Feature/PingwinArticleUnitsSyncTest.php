<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunUnitsAuditJob;
use App\Jobs\SyncArticleUnitsJob;
use App\Models\ArticleUnitChange;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinUnit;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use App\Services\Restaurant\ArticleUnitsSyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Catálogo da PingWin: os ids das unidades (base, compra, venda e stock) em todos os artigos ativos, não só
 * nos que têm ficha técnica; a leitura é na noite de domingo para segunda, fora do horário de serviço; e o aviso no artigo e no
 * Guião quando a unidade de um artigo com histórico de compras muda. PingWin simulado.
 */
class PingwinArticleUnitsSyncTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    public array $payloads = [];
    public array $units = [];

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '514148497', 'fiscal_name' => 'Restaurante', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'pingwin', 'status' => 'active', 'access_token' => 'x', 'config' => ['username' => 'op']]);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        foreach ([['11001', 'Unidade', 'UN'], ['11002', 'Quilograma', 'KG'], ['GRD24', 'Grade 24', 'GRD24']] as [$id, $d, $sh]) {
            PingwinUnit::create(['company_id' => $this->company->id, 'pingwin_id' => $id, 'description' => $d, 'shortname' => $sh, 'is_active' => true]);
        }
        // Sem ficha técnica: o catálogo só tinha os nomes.
        PingwinCatalogItem::create(['company_id' => $this->company->id, 'pingwin_id' => '100415', 'code' => '100415', 'description' => 'COCA COLA ZERO',
            'is_active' => true, 'purchaseunit' => 'Grade 24']);
        PingwinCatalogItem::create(['company_id' => $this->company->id, 'pingwin_id' => '101208', 'code' => '101208', 'description' => 'COMIDA STAFF 23%',
            'is_active' => true, 'base_unit_id' => '11001', 'default_purchase_unit_id' => '11001', 'purchaseunit' => 'Unidade']);
        PingwinCatalogItem::create(['company_id' => $this->company->id, 'pingwin_id' => '900001', 'code' => 'X', 'description' => 'ANULADO', 'is_active' => false]);

        $this->units = [
            '100415' => ['base' => ['id' => '11001', 'name' => 'Unidade'], 'purchase' => ['id' => 'GRD24', 'name' => 'Grade 24'],
                'sale' => ['id' => '11001', 'name' => 'Unidade'], 'stock' => ['id' => '11001', 'name' => 'Unidade']],
            '101208' => ['base' => ['id' => '11002', 'name' => 'Quilograma'], 'purchase' => ['id' => '11002', 'name' => 'Quilograma'],
                'sale' => ['id' => null, 'name' => null], 'stock' => ['id' => '11002', 'name' => 'Quilograma']],
        ];
        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $test) {}
            protected function invoke(array $payload): array
            {
                $this->test->payloads[] = $payload;
                if (($payload['mode'] ?? '') !== 'article_units') {
                    throw new \LogicException('Chamada real ao PingWin num teste: ' . ($payload['mode'] ?? '?'));
                }

                return ['ok' => true, 'mode' => 'article_units', 'units' => array_intersect_key($this->test->units, array_flip($payload['product_ids'])),
                    'errors' => array_fill_keys(array_diff($payload['product_ids'], array_keys($this->test->units)), 'RuntimeError: falhou'), 'seconds' => []];
            }
        });
    }

    /** Histórico de compras do artigo: linhas de documentos de fornecedor. */
    private function history(string $pid, int $lines): void
    {
        for ($i = 0; $i < $lines; $i++) {
            DB::table('pingwin_supplier_document_lines')->insert(['company_id' => $this->company->id, 'docheader_id' => "D{$i}", 'line_number' => $i + 1,
                'product_pingwin_id' => $pid, 'description' => 'linha', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_the_unit_ids_are_filled_in_every_active_article_not_only_those_with_a_recipe(): void
    {
        Bus::fake([RunUnitsAuditJob::class]);
        $svc = app(ArticleUnitsSyncService::class);
        $this->assertSame(['100415', '101208'], $svc->candidates($this->company->id), 'só os ativos');

        $stats = $svc->syncBatch($this->company->id, ['100415', '101208', '555']);
        $this->assertSame(['articles' => 2, 'changed' => 2, 'failed' => 1], array_intersect_key($stats, array_flip(['articles', 'changed', 'failed'])));
        $this->assertSame(['mode' => 'article_units', 'product_ids' => ['100415', '101208', '555']], array_intersect_key($this->payloads[0], array_flip(['mode', 'product_ids'])));
        $coca = PingwinCatalogItem::where('pingwin_id', '100415')->first();
        $this->assertSame(['11001', 'GRD24', '11001', '11001', 'Grade 24', 'Unidade'], [$coca->base_unit_id, $coca->default_purchase_unit_id,
            $coca->default_sale_unit_id, $coca->default_stock_unit_id, $coca->purchaseunit, $coca->saleunit]);
        Bus::assertDispatchedTimes(RunUnitsAuditJob::class, 1);

        // A segunda leitura igual não muda nada nem pede a auditoria.
        Bus::fake([RunUnitsAuditJob::class]);
        $this->assertSame(0, $svc->syncBatch($this->company->id, ['100415', '101208'])['changed']);
        Bus::assertNotDispatched(RunUnitsAuditJob::class);
    }

    public function test_a_unit_change_on_an_article_with_history_is_recorded_and_shown_in_the_article_and_the_guide(): void
    {
        Bus::fake([RunUnitsAuditJob::class]);
        $this->history('101208', 19);
        app(ArticleUnitsSyncService::class)->syncBatch($this->company->id, ['100415', '101208']);

        // Só o artigo com histórico e com a unidade a mudar (o 100415 não tinha ids: não é uma mudança).
        $this->assertSame(2, ArticleUnitChange::count());
        $this->assertSame(['base', 'purchase'], ArticleUnitChange::orderBy('field')->pluck('field')->all());
        $change = ArticleUnitChange::where('field', 'purchase')->first();
        $this->assertSame(['101208', 'Unidade (UN)', 'Quilograma (KG)', 19], [$change->product_pingwin_id, $change->from_name, $change->to_name, $change->history_lines]);
        $this->assertStringStartsWith('A unidade de compra mudou de Unidade (UN) para Quilograma (KG) a ', $change->warning());
        $this->assertStringEndsWith(': as 19 linhas de compra anteriores estão em Unidade (UN).', $change->warning());

        $copy = $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/integrations/pingwin/articles/101208/copy")->assertOk()->json('data');
        $this->assertCount(2, $copy['unit_changes']);
        $this->assertSame([], $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/integrations/pingwin/articles/100415/copy")->json('data.unit_changes'));
    }

    public function test_without_history_a_unit_change_is_not_a_warning(): void
    {
        Bus::fake([RunUnitsAuditJob::class]);
        app(ArticleUnitsSyncService::class)->syncBatch($this->company->id, ['101208']);
        $this->assertSame(0, ArticleUnitChange::count());
        $this->assertSame('11002', PingwinCatalogItem::where('pingwin_id', '101208')->value('base_unit_id'));
    }

    public function test_the_job_goes_in_batches_for_each_company_with_pingwin(): void
    {
        Bus::fake([RunUnitsAuditJob::class]);
        (new SyncArticleUnitsJob())->handle(app(ArticleUnitsSyncService::class));
        $this->assertSame('GRD24', PingwinCatalogItem::where('pingwin_id', '100415')->value('default_purchase_unit_id'));
        $this->assertCount(1, $this->payloads, 'dois artigos ativos cabem num lote');
    }

    public function test_the_reading_is_on_monday_night_after_the_sunday_catalog_and_outside_service_hours(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'pingwin-article-units-weekly');
        $this->assertNotNull($event);
        $this->assertSame('30 1 * * 1', $event->expression);
        $this->assertSame('Europe/Lisbon', (string) $event->timezone);
    }
}
