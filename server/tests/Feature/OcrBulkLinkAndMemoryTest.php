<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\OcrInvoice;
use App\Models\OcrInvoiceLine;
use App\Models\PermissionProfile;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinSupplier;
use App\Models\SupplierDescriptionMemory;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\DescriptionMemoryService;
use App\Services\OcrLineArticleService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Parte 8 · B1: ligar várias linhas de uma vez (POST .../lines/article-bulk, em JSON, com a permissão
 * da ligação por linha e o isolamento por empresa). B2: memória por fornecedor e descrição para as
 * linhas sem código (aprende com a ligação à mão, entra depois do código e antes das sugestões, pode
 * desfazer-se, mudar a ligação muda-a, não aprende descrições genéricas). PingWin simulado.
 */
class OcrBulkLinkAndMemoryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $admin;
    private User $otherAdmin;
    private PingwinCatalogItem $envelope;
    private PingwinCatalogItem $box;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '514148497', 'fiscal_name' => 'Restaurante Exemplo Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500000001', 'fiscal_name' => 'Outra Exemplo Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        foreach ([$this->company, $this->other] as $c) {
            app(CompanyModuleService::class)->applyPreset($c->id, 'restaurant');
            CompanyIntegration::create(['company_id' => $c->id, 'platform' => 'pingwin', 'status' => 'active', 'access_token' => 'x', 'config' => ['username' => 'op']]);
            PingwinSupplier::create(['company_id' => $c->id, 'source' => 'pingwin', 'pingwin_id' => 'SUPE', 'name' => 'PAPELARIA EXEMPLO', 'tax_number' => '123456783', 'is_active' => true]);
        }
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->otherAdmin = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        $this->envelope = $this->article($this->company, 'E1', 'ENVELOPES DIVERSOS');
        $this->box = $this->article($this->company, 'C1', 'CAIXAS DE CARTAO');

        $this->app->instance(PingwinService::class, new class extends PingwinService {
            public function __construct() {}
            protected function invoke(array $payload): array
            {
                throw new \LogicException('Chamada real ao PingWin num teste: ' . ($payload['mode'] ?? '?'));
            }
        });
    }

    private function article(Company $c, string $code, string $desc): PingwinCatalogItem
    {
        return PingwinCatalogItem::create(['company_id' => $c->id, 'pingwin_id' => "P{$code}", 'code' => $code, 'description' => $desc,
            'is_active' => true, 'product_status' => 'Ativo', 'purchaseunit' => 'UN']);
    }

    /** Linhas sem código do fornecedor (como as faturas de serviços), por ligar, como depois da leitura. */
    private function invoice(array $items, ?Company $c = null): OcrInvoice
    {
        $c ??= $this->company;
        $inv = OcrInvoice::create(['company_id' => $c->id, 'image_path' => 'x.pdf', 'status' => 'por_validar', 'supplier_nif' => '123456783',
            'supplier_name' => 'PAPELARIA EXEMPLO', 'number' => 'FT TESTE/' . (OcrInvoice::count() + 1), 'issue_date' => '2026-10-05']);
        foreach ($items as $i => $item) {
            [$desc, $code] = is_array($item) ? $item : [$item, null];
            OcrInvoiceLine::create(['ocr_invoice_id' => $inv->id, 'company_id' => $c->id, 'position' => $i, 'item' => $desc, 'supplier_code' => $code,
                'quantity' => 1, 'unit_price' => '1.000000', 'line_total_cents' => 100, 'vat_rate' => 23, 'link_state' => 'por_ligar']);
        }

        return $inv->fresh();
    }

    private function read(OcrInvoice $inv): OcrInvoice
    {
        app(OcrLineArticleService::class)->linkInvoice($inv->fresh());

        return $inv->fresh();
    }

    private function url(OcrInvoice $inv, string $suffix, ?Company $c = null): string
    {
        return '/api/v1/companies/' . ($c ?? $this->company)->id . "/ocr/invoices/{$inv->id}{$suffix}";
    }

    private function bulk(User $u, OcrInvoice $inv, array $lineIds, int $articleId, ?Company $c = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum')->postJson($this->url($inv, '/lines/article-bulk', $c), ['line_ids' => $lineIds, 'article_id' => $articleId]);
    }

    private function userWith(array $permissions): User
    {
        $p = PermissionProfile::create(['company_id' => $this->company->id, 'side' => 'cliente', 'name' => 'P ' . implode(',', $permissions), 'is_system' => false, 'is_suggestion' => false]);
        $p->syncPermissions($permissions);

        return User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'profile_id' => $p->id]);
    }

    // ───────────────────────────────────────────────────────────── B1

    public function test_bulk_links_the_selected_lines_like_the_manual_link(): void
    {
        $inv = $this->invoice(['Envelope C5 branco', 'Envelope C4 kraft', 'Caixa 40x30']);
        $ids = $inv->lines->sortBy('position')->pluck('id')->all();

        $this->bulk($this->admin, $inv, [$ids[0], $ids[1]], $this->envelope->id)->assertOk()
            ->assertJsonPath('message', '2 linhas ligadas ao artigo.')->assertJsonPath('data.result.linked', 2)
            ->assertJsonPath('data.articles_summary.linked', 2)->assertJsonPath('data.line_links.0.link_method', 'manual');
        $lines = $inv->fresh()->lines->sortBy('position')->values();
        $this->assertSame([[$this->envelope->id, 'manual', $this->admin->id], [$this->envelope->id, 'manual', $this->admin->id], [null, null, null]],
            $lines->map(fn ($l) => [$l->article_id, $l->link_method, $l->linked_by])->all());
    }

    public function test_bulk_checks_the_permission_the_invoice_lines_and_the_article(): void
    {
        $inv = $this->invoice(['Envelope C5 branco', 'Caixa 40x30']);
        $ids = $inv->lines->pluck('id')->all();

        // A mesma permissão da ligação por linha (restauracao.criar).
        $viewer = $this->userWith(['restauracao.ver', 'restauracao.editar']);
        $this->bulk($viewer, $inv, $ids, $this->envelope->id)->assertForbidden();
        $creator = $this->userWith(['restauracao.ver', 'restauracao.criar']);
        $this->bulk($creator, $inv, [$ids[0]], $this->envelope->id)->assertOk()->assertJsonPath('message', '1 linha ligada ao artigo.');

        // Uma linha de outra fatura: nenhuma muda.
        $another = $this->invoice(['Fita adesiva castanha']);
        $this->bulk($this->admin, $inv, [$ids[1], $another->lines->first()->id], $this->box->id)->assertStatus(404);
        $this->assertNull($inv->fresh()->lines->firstWhere('id', $ids[1])->article_id);
        $this->assertNull($another->fresh()->lines->first()->article_id);

        // Artigo de outra empresa ou pedido incompleto: 422, nenhuma muda.
        $foreign = $this->article($this->other, 'X1', 'ENVELOPES DA OUTRA');
        $this->bulk($this->admin, $inv, [$ids[1]], $foreign->id)->assertStatus(422);
        $this->bulk($this->admin, $inv, [], $this->box->id)->assertStatus(422)->assertJsonValidationErrors(['line_ids']);
        $this->assertNull($inv->fresh()->lines->firstWhere('id', $ids[1])->article_id);
    }

    public function test_bulk_is_isolated_by_company(): void
    {
        $inv = $this->invoice(['Envelope C5 branco']);
        $ids = $inv->lines->pluck('id')->all();

        // Outra empresa: pelo URL desta (403) e pelo URL dela (a fatura não é dela: 404).
        $this->bulk($this->otherAdmin, $inv, $ids, $this->envelope->id)->assertForbidden();
        $this->bulk($this->otherAdmin, $inv, $ids, $this->envelope->id, $this->other)->assertNotFound();
        $this->assertNull($inv->fresh()->lines->first()->article_id);
    }

    // ───────────────────────────────────────────────────────────── B2

    public function test_the_next_invoice_of_the_same_supplier_links_by_memory(): void
    {
        $first = $this->invoice(['Envelope C5 branco', ['Caixa 40x30', 'CX4030']]);
        $this->bulk($this->admin, $first, $first->lines->pluck('id')->all(), $this->envelope->id)->assertOk();
        // Só a linha sem código aprende por descrição (a outra fica no mapa, pelo código).
        $this->assertSame(['envelope c5 branco'], SupplierDescriptionMemory::pluck('description_norm')->all());

        $next = $this->read($this->invoice(['ENVELOPE C5 BRANCO', 'Envelope C4 kraft']));
        $lines = $next->lines->sortBy('position')->values();
        $this->assertSame([$this->envelope->id, 'memoria', 'ligada'], [$lines[0]->article_id, $lines[0]->link_method, $lines[0]->link_state]);
        $this->assertNotSame('memoria', $lines[1]->link_method); // outra descrição: não se lembra

        // O ecrã mostra "ligada pela memória" e deixa desfazer: a memória esquece o par.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/companies/' . $this->company->id . "/ocr/invoices/{$next->id}")->assertOk()
            ->assertJsonPath('data.invoice.lines.0.link_method', 'memoria');
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'sanctum')->deleteJson($this->url($next, "/lines/{$lines[0]->id}/article"))->assertOk();
        $this->assertNull($lines[0]->fresh()->article_id);
        $this->assertSame(0, SupplierDescriptionMemory::count());

        // A outra empresa, com o mesmo fornecedor (NIF) e a mesma descrição, não usa esta memória.
        $theirs = $this->read($this->invoice(['Envelope C5 branco'], $this->other));
        $this->assertNotSame('memoria', $theirs->lines->first()->link_method);
    }

    public function test_memory_comes_after_the_code_and_before_the_suggestions(): void
    {
        // A descrição sugeria ENVELOPES DIVERSOS; a memória diz CAIXAS DE CARTAO e ganha.
        DB::table('supplier_description_memory')->insert(['company_id' => $this->company->id, 'supplier_nif' => '123456783', 'description_norm' => 'envelopes diversos',
            'article_id' => $this->box->id, 'created_at' => now(), 'updated_at' => now()]);
        $inv = $this->read($this->invoice(['Envelopes diversos', ['Envelopes diversos', 'ENV01']]));
        $lines = $inv->lines->sortBy('position')->values();
        $this->assertSame([$this->box->id, 'memoria'], [$lines[0]->article_id, $lines[0]->link_method]);
        // Com código do fornecedor, a memória não entra (fica o que hoje decide: aqui, a descrição igual).
        $this->assertSame([$this->envelope->id, 'descricao'], [$lines[1]->article_id, $lines[1]->link_method]);
    }

    public function test_a_generic_description_does_not_learn(): void
    {
        $inv = $this->invoice(['Diversos', 'Desconto comercial', 'Taxa', '123456 78', 'Artigos diversos']);
        $this->bulk($this->admin, $inv, $inv->lines->pluck('id')->all(), $this->box->id)->assertOk();
        $this->assertSame(0, SupplierDescriptionMemory::count());
        $this->assertNull(DescriptionMemoryService::key('Diversos'));
        $this->assertSame('portes de envio', DescriptionMemoryService::key('Portes de envio')); // por fornecedor: aponta sempre para o mesmo artigo
        $this->assertNull(DescriptionMemoryService::key('Taxa')); // menos de 6 caracteres
        $this->assertSame('envelope c5 branco', DescriptionMemoryService::key('Envelope C5 branco'));

        $next = $this->read($this->invoice(['Diversos']));
        $this->assertNotSame('memoria', $next->lines->first()->link_method);
    }

    public function test_changing_the_link_updates_the_memory(): void
    {
        $first = $this->invoice(['Envelope C5 branco']);
        $this->bulk($this->admin, $first, $first->lines->pluck('id')->all(), $this->envelope->id)->assertOk();

        // A fatura seguinte liga-se pela memória; a pessoa muda para outro artigo.
        $second = $this->read($this->invoice(['Envelope C5 branco']));
        $line = $second->lines->first();
        $this->assertSame('memoria', $line->link_method);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url($second, "/lines/{$line->id}/article"), ['article_id' => $this->box->id])->assertOk();
        $this->assertSame([$this->box->id], SupplierDescriptionMemory::pluck('article_id')->map(fn ($id) => (int) $id)->all());

        $third = $this->read($this->invoice(['Envelope C5 branco']));
        $this->assertSame([$this->box->id, 'memoria'], [$third->lines->first()->article_id, $third->lines->first()->link_method]);
    }
}
