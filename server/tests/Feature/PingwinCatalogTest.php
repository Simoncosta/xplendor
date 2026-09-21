<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncPingwinCatalogJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinCatalogItem;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — PingWin Artigos (Fase 1, só leitura): buscar+guardar o catálogo
 * (browserdataset paginado), UPSERT idempotente, preços em CÊNTIMOS, exibição
 * paginada (Laravel) + pesquisa/filtros, gate do módulo + tenancy. O Python é
 * substituído por um fake (override invoke).
 */
class PingwinCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Company $resto;
    private Company $auto;
    private User $restoUser;
    private User $autoUser;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->resto = Company::create(['nipc' => '500013100', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->auto = Company::create(['nipc' => '500013101', 'fiscal_name' => 'Auto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->resto->id, 'restaurant');
        $this->restoUser = User::factory()->create(['company_id' => $this->resto->id, 'role' => 'admin']);
        $this->autoUser = User::factory()->create(['company_id' => $this->auto->id, 'role' => 'admin']);

        CompanyIntegration::create([
            'company_id' => $this->resto->id, 'platform' => 'pingwin', 'status' => 'active',
            'access_token' => 'segredo', 'config' => ['username' => 'op', 'database' => 'yuko'],
        ]);
    }

    /** Fake do serviço: override de invoke() → resposta do browserdataset, sem docker. */
    private function fakeService(array $articles): PingwinService
    {
        return new class($articles) extends PingwinService {
            public array $seen = [];
            public function __construct(private array $arts) {}
            protected function invoke(array $payload): array
            {
                $this->seen = $payload;
                return ['ok' => true, 'mode' => 'catalog', 'articles' => $this->arts];
            }
        };
    }

    private function sampleArticles(): array
    {
        return [
            [
                'id' => '100', 'code' => 'CAF', 'description' => 'Café', 'family' => 'Bebidas', 'family_id' => '5',
                'forsale' => true, 'forpurchase' => false, 'isbom' => false, 'product_type' => 'simple',
                'saleprice' => 0.70, 'purchaseprice' => 0.20, 'saleunit' => 'UN', 'deleted' => false,
            ],
            [
                'id' => '200', 'code' => 'PRG', 'description' => 'Prego', 'family' => 'Pratos', 'family_id' => '9',
                'forsale' => true, 'forpurchase' => false, 'isbom' => true, 'product_type' => 'composed',
                'saleprice' => 6.50, 'purchaseprice' => null, 'saleunit' => 'UN', 'deleted' => false,
            ],
            [
                'id' => '300', 'code' => 'FAR', 'description' => 'Farinha', 'family' => 'Mercearia', 'family_id' => '3',
                'forsale' => false, 'forpurchase' => true, 'isbom' => false, 'product_type' => 'simple',
                'saleprice' => 0, 'purchaseprice' => 1.10, 'purchaseunit' => 'KG', 'deleted' => true,
            ],
        ];
    }

    public function test_sync_persists_articles_in_cents_and_requests_catalog_mode(): void
    {
        $fake = $this->fakeService($this->sampleArticles());
        $count = $fake->syncCatalog($this->resto->id);

        $this->assertSame(3, $count);
        $this->assertSame('catalog', $fake->seen['mode']); // pediu o modo certo ao Python
        $this->assertDatabaseCount('pingwin_catalog_items', 3);

        $cafe = PingwinCatalogItem::where('company_id', $this->resto->id)->where('pingwin_id', '100')->first();
        $this->assertSame('Café', $cafe->description);
        $this->assertSame('Bebidas', $cafe->family);
        $this->assertSame(70, $cafe->saleprice_cents);   // 0.70 € → 70 cêntimos
        $this->assertSame(20, $cafe->purchaseprice_cents);
        $this->assertTrue($cafe->forsale);
        $this->assertTrue($cafe->is_active);
        $this->assertNull($cafe->supplier_code);         // nullable (matching é fase futura)

        // preço em falta → NULL (não 0); is_active = NOT deleted.
        $prego = PingwinCatalogItem::where('pingwin_id', '200')->first();
        $this->assertNull($prego->purchaseprice_cents);
        $this->assertTrue($prego->has_bom);

        $farinha = PingwinCatalogItem::where('pingwin_id', '300')->first();
        $this->assertFalse($farinha->is_active);          // deleted → inativo
        $this->assertSame(110, $farinha->purchaseprice_cents);
    }

    public function test_sync_is_idempotent_upsert(): void
    {
        $this->fakeService($this->sampleArticles())->syncCatalog($this->resto->id);
        // Re-sync com preço alterado → atualiza, não duplica.
        $changed = $this->sampleArticles();
        $changed[0]['saleprice'] = 0.90;
        $this->fakeService($changed)->syncCatalog($this->resto->id);

        $this->assertDatabaseCount('pingwin_catalog_items', 3);
        $this->assertSame(90, PingwinCatalogItem::where('pingwin_id', '100')->first()->saleprice_cents);
    }

    public function test_list_endpoint_paginates_searches_and_filters(): void
    {
        $this->fakeService($this->sampleArticles())->syncCatalog($this->resto->id);
        $base = "/api/v1/companies/{$this->resto->id}/integrations/pingwin/catalog";

        // Paginação de exibição (Laravel).
        $this->actingAs($this->restoUser, 'sanctum')->getJson("{$base}?perPage=2&page=1")
            ->assertStatus(200)
            ->assertJsonPath('data.articles.current_page', 1)
            ->assertJsonPath('data.articles.last_page', 2)
            ->assertJsonPath('data.articles.total', 3)
            ->assertJsonCount(2, 'data.articles.data');

        // Pesquisa por código.
        $this->actingAs($this->restoUser, 'sanctum')->getJson("{$base}?search=CAF")
            ->assertStatus(200)
            ->assertJsonPath('data.articles.total', 1)
            ->assertJsonPath('data.articles.data.0.code', 'CAF');

        // Filtro por família.
        $this->actingAs($this->restoUser, 'sanctum')->getJson("{$base}?family=Pratos")
            ->assertStatus(200)
            ->assertJsonPath('data.articles.total', 1)
            ->assertJsonPath('data.articles.data.0.pingwin_id', '200');

        // Filtro forsale=1 → só os à venda (2 dos 3).
        $this->actingAs($this->restoUser, 'sanctum')->getJson("{$base}?forsale=1")
            ->assertStatus(200)
            ->assertJsonPath('data.articles.total', 2);

        // Filtro forpurchase=1 → só a farinha.
        $this->actingAs($this->restoUser, 'sanctum')->getJson("{$base}?forpurchase=1")
            ->assertStatus(200)
            ->assertJsonPath('data.articles.total', 1)
            ->assertJsonPath('data.articles.data.0.pingwin_id', '300');
    }

    public function test_sync_endpoint_queues_job(): void
    {
        Bus::fake();
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/catalog/sync")
            ->assertStatus(200)->assertJsonPath('data.queued', true);
        Bus::assertDispatched(SyncPingwinCatalogJob::class, fn ($j) => $j->companyId === $this->resto->id);
    }

    public function test_module_gate_and_tenancy(): void
    {
        // Empresa automotiva (sem módulo pingwin) → 403.
        $this->actingAs($this->autoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->auto->id}/integrations/pingwin/catalog")
            ->assertStatus(403);
    }
}
