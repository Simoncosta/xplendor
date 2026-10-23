<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunUnitsAuditJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\OcrInvoice;
use App\Models\OcrInvoiceLine;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinUnit;
use App\Models\ProductUnitProfile;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * PingWin: atualizar um artigo a pedido ("Atualizar do PingWin", com limite de pedidos) e ao abri-lo
 * (a leitura grava na cópia os ids e os nomes das unidades e relê as unidades do artigo); depois de as
 * unidades mudarem: a escolha guardada nas faturas por lançar cai, a auditoria de unidades corre e o
 * perfil do Guião fica "por rever" se a unidade base mudou. A cópia local abre logo, sem a PingWin.
 * PingWin simulado.
 */
class PingwinArticleRefreshTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $user;
    private User $otherUser;
    private PingwinCatalogItem $item;
    public array $reads = [];
    public array $main = [];

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '514148497', 'fiscal_name' => 'Restaurante', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500000001', 'fiscal_name' => 'Outra', 'plan_id' => $planId, 'subscription_status' => 'active']);
        foreach ([$this->company, $this->other] as $c) {
            app(CompanyModuleService::class)->applyPreset($c->id, 'restaurant');
            CompanyIntegration::create(['company_id' => $c->id, 'platform' => 'pingwin', 'status' => 'active', 'access_token' => 'x', 'config' => ['username' => 'op']]);
        }
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->otherUser = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        foreach ([['11001', 'Unidade', 'UN'], ['11002', 'Quilograma', 'KG']] as [$id, $d, $sh]) {
            PingwinUnit::create(['company_id' => $this->company->id, 'pingwin_id' => $id, 'description' => $d, 'shortname' => $sh, 'is_active' => true]);
        }
        // A cópia de 19/10: sem os ids das unidades (o catálogo só traz os nomes).
        $this->item = PingwinCatalogItem::create(['company_id' => $this->company->id, 'pingwin_id' => '101208', 'code' => '101208', 'description' => 'COMIDA STAFF 23%',
            'is_active' => true, 'product_status' => 'Ativo', 'purchaseunit' => 'Unidade', 'saleunit' => 'Unidade', 'synced_at' => '2026-10-19 05:00:00']);
        $this->main = ['id' => '101208', 'code' => '101208', 'description' => 'COMIDA STAFF 23%',
            'base_unit_id' => '11002', 'default_purchase_unit_id' => '11002', 'default_sale_unit_id' => '11001', 'default_stock_unit_id' => '11002',
            'default_purchase_unit_id_descr' => 'Quilograma', 'default_sale_unit_id_descr' => 'Unidade'];

        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $test) {}
            protected function invoke(array $payload): array
            {
                throw new \LogicException('Chamada real ao PingWin num teste: ' . ($payload['mode'] ?? '?'));
            }
            public function readProduct(int $companyId, string $productId): array
            {
                $this->test->reads[] = $productId;

                return ['found' => true, 'maindataset' => $this->test->main, 'lookups' => ['units' => [
                    'base' => [['id' => '11001', 'description' => 'Unidade', 'shortname' => 'UN'], ['id' => '11002', 'description' => 'Quilograma', 'shortname' => 'KG']],
                    'purchase' => [['id' => '7001', 'description' => 'Caixa 5 kg', 'shortname' => 'CX5', 'parent_id' => '11002', 'parent_qnt' => 5, 'product_id' => '101208']],
                    'sale' => [],
                ]], 'catalog_item_id' => $this->test->item()->id, 'supplier_prices' => [], 'supplier_tables' => []];
            }
        });
    }

    public function item(): PingwinCatalogItem
    {
        return $this->item;
    }

    private function url(string $path, ?Company $c = null): string
    {
        return '/api/v1/companies/' . ($c ?? $this->company)->id . '/integrations/pingwin/articles/' . $path;
    }

    private function pendingInvoice(string $linkStatus = 'nao_lancada'): OcrInvoice
    {
        $inv = OcrInvoice::create(['company_id' => $this->company->id, 'image_path' => 'x.pdf', 'status' => 'validada', 'supplier_nif' => '123456783',
            'number' => 'FT ' . uniqid(), 'doc_type' => 'FT', 'link_status' => $linkStatus]);
        OcrInvoiceLine::create(['ocr_invoice_id' => $inv->id, 'company_id' => $this->company->id, 'position' => 0, 'item' => 'Carne', 'quantity' => 8.09, 'unit' => 'KG',
            'unit_price' => '9.190000', 'line_total_cents' => 7437, 'vat_rate' => 23, 'article_id' => $this->item->id, 'link_state' => 'ligada', 'link_method' => 'mapa',
            'launch_unit_id' => '11001']);

        return $inv;
    }

    public function test_the_local_copy_opens_at_once_without_pingwin(): void
    {
        $this->actingAs($this->user, 'sanctum')->getJson($this->url('101208/copy'))->assertOk()
            ->assertJsonPath('data.description', 'COMIDA STAFF 23%')
            ->assertJsonPath('data.units.purchase.name', 'Unidade')
            ->assertJsonPath('data.units.purchase.id', null)
            ->assertJsonPath('data.live_read_at', null);
        $this->assertSame([], $this->reads);
        $this->actingAs($this->user, 'sanctum')->getJson($this->url('999999/copy'))->assertNotFound();
    }

    public function test_refresh_reads_the_article_writes_the_unit_ids_and_names_and_rereads_its_units(): void
    {
        Bus::fake([RunUnitsAuditJob::class]);
        $open = $this->pendingInvoice();
        $launched = $this->pendingInvoice('lancada');

        $token = $this->actingAs($this->user, 'sanctum')->postJson($this->url('101208/refresh'))->assertStatus(202)->json('data.read_id');
        $this->assertSame(['101208'], $this->reads);
        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->url("read/{$token}"))->assertOk()->json('data');
        $this->assertSame('ready', $res['status']);
        $this->assertSame(['Quilograma (KG)', 'Unidade (UN)'], [$res['copy']['units']['purchase']['name'], $res['copy']['units']['sale']['name']]);
        $this->assertContains(['field' => 'purchase', 'label' => 'Unidade de compra', 'from' => 'Unidade', 'to' => 'Quilograma (KG)'], $res['changes']);

        $item = $this->item->fresh();
        $this->assertSame(['11002', '11002', '11001', '11002', 'Quilograma'], [$item->base_unit_id, $item->default_purchase_unit_id, $item->default_sale_unit_id,
            $item->default_stock_unit_id, $item->purchaseunit]);
        $this->assertNotNull($item->live_read_at);
        $box = PingwinUnit::where('company_id', $this->company->id)->where('pingwin_id', '7001')->first();
        $this->assertSame(['Caixa 5 kg', 'CX5', '101208', '11002', 5.0], [$box->description, $box->shortname, $box->product_pingwin_id, $box->parent_pingwin_id, $box->parent_qnt]);

        // O que usa o artigo: a escolha guardada nas faturas por lançar cai; a auditoria de unidades corre.
        $this->assertNull(OcrInvoiceLine::where('ocr_invoice_id', $open->id)->value('launch_unit_id'));
        $this->assertSame('11001', OcrInvoiceLine::where('ocr_invoice_id', $launched->id)->value('launch_unit_id'));
        Bus::assertDispatched(RunUnitsAuditJob::class, fn ($j) => $j->companyId === $this->company->id);
    }

    public function test_opening_the_article_also_writes_the_copy_and_says_what_changed(): void
    {
        Bus::fake([RunUnitsAuditJob::class]);
        $token = $this->actingAs($this->user, 'sanctum')->getJson($this->url('101208'))->assertOk()->json('data.read_id');
        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->url("read/{$token}"))->json('data');
        $this->assertSame('11002', $res['article']['maindataset']['base_unit_id']);
        $this->assertNotEmpty($res['changes']);
        $this->assertSame('11002', $this->item->fresh()->default_purchase_unit_id);

        // A segunda leitura igual não muda nada nem volta a pedir a auditoria.
        Bus::fake([RunUnitsAuditJob::class]);
        $token = $this->actingAs($this->user, 'sanctum')->getJson($this->url('101208'))->json('data.read_id');
        $this->assertSame([], $this->actingAs($this->user, 'sanctum')->getJson($this->url("read/{$token}"))->json('data.changes'));
        Bus::assertNotDispatched(RunUnitsAuditJob::class);
    }

    public function test_the_guide_profile_goes_back_to_review_when_the_base_unit_changes(): void
    {
        Bus::fake([RunUnitsAuditJob::class]);
        $this->item->update(['base_unit_id' => '11001', 'default_purchase_unit_id' => '11001']);
        $profile = ProductUnitProfile::create(['company_id' => $this->company->id, 'product_pingwin_id' => '101208', 'state' => 'confirmado', 'base_confirmed' => 'un',
            'confirmed_by' => $this->user->id, 'confirmed_at' => now()]);
        $this->actingAs($this->user, 'sanctum')->postJson($this->url('101208/refresh'))->assertStatus(202);
        $profile->refresh();
        $this->assertSame('por_rever', $profile->state);
        $this->assertSame('A unidade base mudou no PingWin (Unidade (UN) → Quilograma (KG)): confirme de novo.', $profile->reason);
        $this->assertFalse($profile->isDecided());

        // Só a unidade de compra a mudar: o perfil confirmado fica como está.
        $profile->update(['state' => 'confirmado']);
        $this->main['default_purchase_unit_id'] = '11001';
        $this->main['default_purchase_unit_id_descr'] = 'Unidade';
        $this->actingAs($this->user, 'sanctum')->postJson($this->url('101208/refresh'))->assertStatus(202);
        $this->assertSame('confirmado', $profile->fresh()->state);
    }

    public function test_refresh_has_a_request_limit_per_company(): void
    {
        Bus::fake([RunUnitsAuditJob::class]);
        RateLimiter::clear('pingwin-article-refresh:' . $this->company->id);
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($this->user, 'sanctum')->postJson($this->url('101208/refresh'))->assertStatus(202);
        }
        $this->actingAs($this->user, 'sanctum')->postJson($this->url('101208/refresh'))->assertStatus(429)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Aguarde'));
        $this->assertCount(10, $this->reads);
    }

    public function test_another_company_gets_403_on_both_new_routes(): void
    {
        $this->actingAs($this->otherUser, 'sanctum')->getJson($this->url('101208/copy'))->assertForbidden();
        $this->actingAs($this->otherUser, 'sanctum')->postJson($this->url('101208/refresh'))->assertForbidden();
        $this->assertSame([], $this->reads);
    }
}
