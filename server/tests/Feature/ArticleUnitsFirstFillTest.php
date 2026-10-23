<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunUnitsAuditJob;
use App\Models\ArticleUnitChange;
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
use App\Services\Restaurant\ArticleUnitsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Unidades dos artigos: o primeiro preenchimento dos ids (de vazio para um valor, com os mesmos nomes que a
 * cópia já tinha) não é uma mudança, nos três caminhos (a leitura semanal de segunda-feira, abrir o artigo
 * e "Atualizar do PingWin"): o perfil do Guião não passa a "por rever", não fica registada uma mudança e a
 * unidade escolhida nas faturas por lançar fica. Depois, quando o id da unidade base muda, acontecem as
 * três. PingWin simulado.
 */
class ArticleUnitsFirstFillTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private PingwinCatalogItem $item;
    private ProductUnitProfile $profile;
    private OcrInvoice $invoice;
    /** O que a PingWin simulada devolve: a unidade base e a de compra (ids e nomes). */
    public array $pw = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([RunUnitsAuditJob::class]);
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '514148497', 'fiscal_name' => 'Restaurante', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'pingwin', 'status' => 'active', 'access_token' => 'x', 'config' => ['username' => 'op']]);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        foreach ([['11001', 'Unidade', 'UN'], ['11002', 'Quilograma', 'KG']] as [$id, $d, $sh]) {
            PingwinUnit::create(['company_id' => $this->company->id, 'pingwin_id' => $id, 'description' => $d, 'shortname' => $sh, 'is_active' => true]);
        }
        // A cópia de produção hoje: só os nomes (o catálogo não traz os ids).
        $this->item = PingwinCatalogItem::create(['company_id' => $this->company->id, 'pingwin_id' => '101208', 'code' => '101208', 'description' => 'CARNE PICADA',
            'is_active' => true, 'product_status' => 'Ativo', 'purchaseunit' => 'Quilograma', 'saleunit' => 'Unidade', 'synced_at' => '2026-10-19 05:00:00']);
        $this->profile = ProductUnitProfile::create(['company_id' => $this->company->id, 'product_pingwin_id' => '101208', 'state' => 'confirmado', 'base_confirmed' => 'kg',
            'confirmed_by' => $this->user->id, 'confirmed_at' => now()]);
        // Histórico de compras (o aviso dos 90 dias só existe num artigo com histórico).
        for ($i = 0; $i < 4; $i++) {
            DB::table('pingwin_supplier_document_lines')->insert(['company_id' => $this->company->id, 'docheader_id' => "D{$i}", 'line_number' => $i + 1,
                'product_pingwin_id' => '101208', 'description' => 'linha', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->invoice = OcrInvoice::create(['company_id' => $this->company->id, 'image_path' => 'x.pdf', 'status' => 'validada', 'supplier_nif' => '123456783',
            'number' => 'FT 1', 'doc_type' => 'FT', 'link_status' => 'nao_lancada']);
        $this->pw = ['base' => '11002', 'purchase' => '11002'];

        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $test) {}
            protected function invoke(array $payload): array
            {
                if (($payload['mode'] ?? '') !== 'article_units') {
                    throw new \LogicException('Chamada real ao PingWin num teste: ' . ($payload['mode'] ?? '?'));
                }
                $name = fn (string $id) => $id === '11002' ? 'Quilograma' : 'Unidade';

                return ['ok' => true, 'mode' => 'article_units', 'errors' => [], 'seconds' => [], 'units' => ['101208' => [
                    'base' => ['id' => $this->test->pw['base'], 'name' => $name($this->test->pw['base'])],
                    'purchase' => ['id' => $this->test->pw['purchase'], 'name' => $name($this->test->pw['purchase'])],
                    'sale' => ['id' => '11001', 'name' => 'Unidade'], 'stock' => ['id' => $this->test->pw['base'], 'name' => $name($this->test->pw['base'])],
                ]]];
            }
            public function readProduct(int $companyId, string $productId): array
            {
                $name = fn (string $id) => $id === '11002' ? 'Quilograma' : 'Unidade';
                $main = ['id' => '101208', 'code' => '101208', 'description' => 'CARNE PICADA', 'base_unit_id' => $this->test->pw['base'],
                    'default_purchase_unit_id' => $this->test->pw['purchase'], 'default_sale_unit_id' => '11001', 'default_stock_unit_id' => $this->test->pw['base'],
                    'default_purchase_unit_id_descr' => $name($this->test->pw['purchase']), 'default_sale_unit_id_descr' => 'Unidade'];

                return ['found' => true, 'maindataset' => $main, 'lookups' => ['units' => ['base' => [], 'purchase' => [], 'sale' => []]], 'supplier_prices' => [], 'supplier_tables' => []];
            }
        });
    }

    /** Os três caminhos que gravam os ids na cópia. Devolve o que o ecrã diz que mudou (a leitura semanal não diz). */
    private function read(string $path): array
    {
        $base = "/api/v1/companies/{$this->company->id}/integrations/pingwin/articles/";
        RateLimiter::clear("pingwin-article-refresh:{$this->company->id}");

        return match ($path) {
            'semanal' => [app(ArticleUnitsSyncService::class)->syncBatch($this->company->id, ['101208'])],
            'abrir' => $this->actingAs($this->user, 'sanctum')->getJson($this->base('read/' . $this->actingAs($this->user, 'sanctum')->getJson($base . '101208')->json('data.read_id')))->json('data.changes'),
            'atualizar' => $this->actingAs($this->user, 'sanctum')->getJson($this->base('read/' . $this->actingAs($this->user, 'sanctum')->postJson($base . '101208/refresh')->json('data.read_id')))->json('data.changes'),
        };
    }

    private function base(string $p): string
    {
        return "/api/v1/companies/{$this->company->id}/integrations/pingwin/articles/{$p}";
    }

    /** Volta ao ponto de partida: a cópia sem ids, o perfil confirmado, sem mudanças e com a unidade escolhida na fatura. */
    private function reset(): void
    {
        PingwinCatalogItem::whereKey($this->item->id)->update(['base_unit_id' => null, 'default_purchase_unit_id' => null, 'default_sale_unit_id' => null,
            'default_stock_unit_id' => null, 'purchaseunit' => 'Quilograma', 'saleunit' => 'Unidade']);
        ProductUnitProfile::whereKey($this->profile->id)->update(['state' => 'confirmado', 'reason' => null]);
        ArticleUnitChange::query()->delete();
        OcrInvoiceLine::where('ocr_invoice_id', $this->invoice->id)->delete();
        OcrInvoiceLine::create(['ocr_invoice_id' => $this->invoice->id, 'company_id' => $this->company->id, 'position' => 0, 'item' => 'Carne', 'quantity' => 8.09, 'unit' => 'KG',
            'unit_price' => '9.190000', 'line_total_cents' => 7437, 'vat_rate' => 23, 'article_id' => $this->item->id, 'link_state' => 'ligada', 'link_method' => 'mapa',
            'launch_unit_id' => '11002']);
        $this->pw = ['base' => '11002', 'purchase' => '11002'];
    }

    /** @return array{0: string, 1: int, 2: ?string} o estado do perfil, as mudanças registadas e a unidade escolhida na fatura */
    private function effects(): array
    {
        return [$this->profile->fresh()->state, ArticleUnitChange::count(), OcrInvoiceLine::where('ocr_invoice_id', $this->invoice->id)->value('launch_unit_id')];
    }

    public function test_filling_the_ids_for_the_first_time_is_not_a_change_and_a_new_base_unit_is(): void
    {
        foreach (['semanal', 'abrir', 'atualizar'] as $path) {
            $this->reset();

            // 1. Primeiro preenchimento, com os mesmos nomes: grava os ids e não acontece a, b nem c.
            $out = $this->read($path);
            $item = $this->item->fresh();
            $this->assertSame(['11002', '11002', '11001', '11002'], [$item->base_unit_id, $item->default_purchase_unit_id, $item->default_sale_unit_id, $item->default_stock_unit_id], $path);
            $this->assertSame(['confirmado', 0, '11002'], $this->effects(), "{$path}: o primeiro preenchimento não é uma mudança");
            if ($path === 'semanal') {
                $this->assertSame([1, 0], [$out[0]['filled'], $out[0]['changed']]);
            } else {
                $this->assertSame([], $out, "{$path}: o ecrã não assinala nada");
            }

            // 2. O id da unidade base muda (Quilograma → Unidade): acontecem as três.
            $this->pw['base'] = '11001';
            $out = $this->read($path);
            [$state, $changes, $launchUnit] = $this->effects();
            $this->assertSame('por_rever', $state, "{$path}: a. o perfil do Guião fica por rever");
            $this->assertSame('A unidade base mudou no PingWin (Quilograma (KG) → Unidade (UN)): confirme de novo.', $this->profile->fresh()->reason);
            $this->assertSame(1, $changes, "{$path}: b. a mudança fica registada");
            $change = ArticleUnitChange::sole();
            $this->assertSame(['base', '11002', '11001', 4], [$change->field, $change->from_unit_id, $change->to_unit_id, $change->history_lines]);
            $this->assertNull($launchUnit, "{$path}: c. a unidade escolhida na fatura por lançar cai");
            if ($path === 'semanal') {
                $this->assertSame([0, 1], [$out[0]['filled'], $out[0]['changed']]);
            } else {
                $this->assertSame(['base', 'stock'], array_column($out, 'field'), 'a simulação muda a base e o stock juntos');
            }
        }
    }

    public function test_a_first_fill_whose_name_differs_from_the_copy_is_a_change(): void
    {
        $this->reset();
        // A cópia dizia "Unidade" na compra; a PingWin diz Quilograma: o nome mudou.
        PingwinCatalogItem::whereKey($this->item->id)->update(['purchaseunit' => 'Unidade']);
        $this->read('atualizar');
        $this->assertNull(OcrInvoiceLine::where('ocr_invoice_id', $this->invoice->id)->value('launch_unit_id'));
        $this->assertSame('confirmado', $this->profile->fresh()->state, 'o perfil só volta a "por rever" quando a unidade base muda');
        $this->assertSame(0, ArticleUnitChange::count(), 'o aviso dos 90 dias é só para um id que muda');
    }
}
